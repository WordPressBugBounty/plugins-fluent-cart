<?php

namespace FluentCart\App\Modules\MCP;

use FluentCart\App\Modules\MCP\Tools\ContextTools;
use FluentCart\App\Modules\MCP\Tools\OrderTools;
use FluentCart\App\Modules\MCP\Tools\CustomerTools;
use FluentCart\App\Modules\MCP\Tools\ProductTools;
use FluentCart\App\Modules\MCP\Tools\SubscriptionTools;
use FluentCart\App\Modules\MCP\Tools\CouponTools;
use FluentCart\App\Modules\MCP\Tools\LabelTools;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use FluentCart\App\Modules\MCP\Tools\ProductFinancialsTools;
use FluentCart\App\Modules\MCP\Tools\PaymentScheduleTools;
use FluentCart\App\Modules\MCP\Tools\TransactionTools;

/**
 * Single source of truth for every FluentCart MCP ability.
 *
 * Each tool class owns its own `definitions()` slice (schema next to code);
 * this class merges them, wraps every execute_callback so unhandled exceptions
 * become structured WP_Errors the agent can read (instead of the adapter's
 * generic "Tool execution failed"), and registers each as a WP ability.
 *
 * Pro tools are NOT listed here — FluentCart Pro pushes its abilities via the
 * `fluent_cart/mcp_loaded` action + `fluent_cart/mcp_ability_names` filter.
 */
class AbilitiesRegistrar
{
    /** Tool classes that expose a static definitions() method. */
    private static function toolClasses()
    {
        return [
            ContextTools::class,
            OrderTools::class,
            CustomerTools::class,
            ProductTools::class,
            SubscriptionTools::class,
            CouponTools::class,
            LabelTools::class,
            ReportTools::class,
            ProductFinancialsTools::class,
            PaymentScheduleTools::class,
            TransactionTools::class,
        ];
    }

    public static function getDefinitions()
    {
        $defs = [];

        foreach (self::toolClasses() as $class) {
            if (class_exists($class) && method_exists($class, 'definitions')) {
                $defs = array_merge($defs, (array) $class::definitions());
            }
        }

        return $defs;
    }

    public static function register()
    {
        foreach (self::getDefinitions() as $name => $definition) {
            try {
                self::registerAbility($name, $definition);
            } catch (\Throwable $e) {
                // Registration runs on wp_abilities_api_init, which the adapter
                // fires lazily from INSIDE our own create_server() call — so an
                // uncaught throw here doesn't just drop this one ability: it
                // aborts every later callback on the action (other plugins'
                // abilities included) and kills the FluentCart MCP server
                // itself, 404ing the endpoint. One malformed definition must
                // never take the whole surface down: skip it, log it, move on.
                fluent_cart_error_log(
                    'MCP ability registration failed: ' . $name,
                    get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine()
                );

                /**
                 * Fires when a single MCP ability fails to register. The
                 * remaining abilities still register; this lets sites alert on
                 * the gap.
                 *
                 * @since 1.0.0
                 *
                 * @param array $context { exception: \Throwable, ability: string }
                 */
                do_action('fluent_cart/mcp_ability_registration_failed', [
                    'exception' => $e,
                    'ability'   => $name,
                ]);
            }
        }
    }

    /**
     * Register one ability definition with the Abilities API. Kept separate
     * from register() so its try/catch stays a thin skip-and-continue shell.
     */
    private static function registerAbility($name, $definition)
    {
        // Cast before array_keys: no-arg tools declare properties as
        // stdClass (so the schema serializes as {} not []), which
        // array_keys() rejects on PHP 8 with a TypeError.
        $declaredParams = isset($definition['input_schema']['properties'])
            ? array_keys((array) $definition['input_schema']['properties'])
            : [];

        $args = [
            'label'               => $definition['label'],
            'description'         => $definition['description'],
            'category'            => 'fluent-cart',
            'execute_callback'    => self::wrapExecuteCallback($name, $definition['execute_callback'], $declaredParams),
            'permission_callback' => $definition['permission_callback'],
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ];

        if (!empty($definition['input_schema'])) {
            $args['input_schema'] = $definition['input_schema'];
        }

        if (!empty($definition['output_schema'])) {
            $args['output_schema'] = $definition['output_schema'];
        }

        if (!empty($definition['annotations'])) {
            $mapped = self::mapAnnotations($definition['annotations']);
            if (!empty($mapped)) {
                $args['meta']['annotations'] = $mapped;
            }
        }

        wp_register_ability($name, $args);
    }

    /**
     * Translate a tool's readable snake_case behavior hints into the MCP tool
     * annotation keys clients actually read.
     *
     * Tool classes declare intent as readonly / destructive / idempotent /
     * open_world / title. The MCP spec names them readOnlyHint / destructiveHint
     * / idempotentHint / openWorldHint, and the WP MCP adapter forwards
     * meta.annotations VERBATIM (it does not translate), so an unmapped
     * 'readonly' key would never reach a client as a real hint. Unknown keys
     * (e.g. a stray 'bulk') are dropped rather than emitted as noise a client
     * cannot act on.
     *
     * @param array $annotations snake_case behavior hints from the tool definition
     * @return array MCP-standard annotation keys
     */
    private static function mapAnnotations($annotations)
    {
        $map = [
            'readonly'    => 'readOnlyHint',
            'destructive' => 'destructiveHint',
            'idempotent'  => 'idempotentHint',
            'open_world'  => 'openWorldHint',
        ];

        $out = [];
        foreach ((array) $annotations as $key => $value) {
            if ($key === 'title') {
                $out['title'] = (string) $value;
            } elseif (isset($map[$key])) {
                $out[$map[$key]] = (bool) $value;
            }
        }

        // A read-only tool cannot be destructive. destructiveHint defaults to
        // true when absent (MCP spec), so state it explicitly for read tools —
        // otherwise a client gating on destructiveHint would treat every report
        // as dangerous.
        if (!empty($out['readOnlyHint']) && !isset($out['destructiveHint'])) {
            $out['destructiveHint'] = false;
        }

        return $out;
    }

    /**
     * Portable params an agent naturally carries from one tool to a sibling but
     * which only some tools accept. input_schema sets no additionalProperties, so
     * an unsupported one is silently ignored and the agent gets a full,
     * wrong-shaped result with no signal it wasn't filtered. We surface exactly
     * these as meta.warnings. We deliberately do NOT warn on every unknown key:
     * that risks false positives against a param a tool reads but doesn't declare,
     * and would turn a typo into noise instead of a helpful correction.
     */
    const PORTABLE_PARAMS = ['product_id', 'variation_id', 'summary_only', 'fields', 'mode'];

    /**
     * Append a meta.warnings entry for each portable param the caller passed that
     * this tool does not declare (and therefore ignored). Untouched when the
     * result isn't a success envelope (e.g. a WP_Error) or nothing was ignored,
     * so a tool's own warnings (list-reference-data) are preserved.
     *
     * @param mixed $result         the tool's return value
     * @param mixed $params         the raw input params
     * @param array $declaredParams input_schema property names this tool declares
     * @return mixed
     */
    private static function annotateIgnoredParams($result, $params, $declaredParams)
    {
        if (!is_array($result) || !isset($result['meta']) || !is_array($result['meta']) || !is_array($params)) {
            return $result;
        }

        $ignored = [];
        foreach (self::PORTABLE_PARAMS as $p) {
            if (array_key_exists($p, $params) && !in_array($p, $declaredParams, true)) {
                $ignored[] = $p;
            }
        }

        if (empty($ignored)) {
            return $result;
        }

        $warnings = (isset($result['meta']['warnings']) && is_array($result['meta']['warnings']))
            ? $result['meta']['warnings']
            : [];

        foreach ($ignored as $p) {
            $warnings[] = sprintf(
                /* translators: %1$s: the parameter name that was ignored */
                __('The "%1$s" parameter is not supported by this tool and was ignored — the result is not filtered by it. Check the tool schema for the parameters this tool accepts.', 'fluent-cart'),
                $p
            );
        }

        $result['meta']['warnings'] = $warnings;

        return $result;
    }

    /**
     * Convert any unhandled \Throwable from a tool into a structured WP_Error
     * carrying the real message (and, under WP_DEBUG, the file + a short trace).
     * Without this the agent only sees the adapter's generic failure surface and
     * retries blindly against tools that may have partially succeeded.
     */
    private static function wrapExecuteCallback($toolName, $callback, $declaredParams = [])
    {
        return function ($params) use ($toolName, $callback, $declaredParams) {
            try {
                $result = call_user_func($callback, $params);
                return self::annotateIgnoredParams($result, $params, $declaredParams);
            } catch (\Throwable $e) {
                /**
                 * Fires when an MCP tool throws. Lets sites log/alert before the
                 * structured error reaches the agent.
                 *
                 * @since 1.0.0
                 *
                 * @param array $context { exception: \Throwable, tool: string, params: mixed }
                 */
                do_action('fluent_cart/mcp_tool_exception', [
                    'exception' => $e,
                    'tool'      => $toolName,
                    'params'    => $params,
                ]);

                // Unexpected exceptions are treated as transient (retryable):
                // the agent may legitimately retry once.
                $details = ['tool' => $toolName, 'exception' => get_class($e), 'retryable' => true];

                // File/line/trace help an operator debug, but this payload is
                // forwarded to the remote agent/LLM — raw paths would leak the
                // server's filesystem layout. So it's off by default and opt-in
                // only; full detail is always available server-side via the
                // action above. When enabled, the file is reduced to a basename.
                $exposeDetails = apply_filters('fluent_cart/mcp_expose_error_details', false);
                if ($exposeDetails) {
                    $details['file']  = basename($e->getFile()) . ':' . $e->getLine();
                    $details['trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 5);
                }

                return \FluentCart\App\Modules\MCP\Support\MCPHelper::error('tool_failed', $e->getMessage(), $details);
            }
        };
    }
}
