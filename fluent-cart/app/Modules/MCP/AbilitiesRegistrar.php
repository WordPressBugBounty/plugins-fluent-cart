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
            $args = [
                'label'               => $definition['label'],
                'description'         => $definition['description'],
                'category'            => 'fluent-cart',
                'execute_callback'    => self::wrapExecuteCallback($name, $definition['execute_callback']),
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
                // snake_case keys; the adapter's McpAnnotationMapper translates
                // readonly -> readOnlyHint, destructive -> destructiveHint, etc.
                $args['meta']['annotations'] = $definition['annotations'];
            }

            wp_register_ability($name, $args);
        }
    }

    /**
     * Convert any unhandled \Throwable from a tool into a structured WP_Error
     * carrying the real message (and, under WP_DEBUG, the file + a short trace).
     * Without this the agent only sees the adapter's generic failure surface and
     * retries blindly against tools that may have partially succeeded.
     */
    private static function wrapExecuteCallback($toolName, $callback)
    {
        return function ($params) use ($toolName, $callback) {
            try {
                return call_user_func($callback, $params);
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
