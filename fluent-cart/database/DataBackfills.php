<?php

namespace FluentCart\Database;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\Framework\Database\Schema;
use FluentCart\Framework\Support\Arr;

/**
 * One-shot data backfills / repairs. Migrators stay schema-only.
 *
 * Delivery: nothing runs on normal requests. While a registered backfill is
 * pending, the admin app gets has_data_migrations = true and silently POSTs
 * data-backfills/run (manage_options via AdminPolicy) until the server says
 * completed. Completion is tracked per slug under the _db_migrations option
 * (fct_meta), marked only after a backfill's FINAL chunk.
 *
 * Every backfill MUST have (repairInstallmentBillTimes is the reference):
 *  - a registry entry (slug => title) with ship date and removal target
 *  - bounded chunks per request with a persisted keyset cursor (resume,
 *    never restart from id 0)
 *  - batched per-chunk lookups (whereIn) — per-row queries only for rows
 *    actually being repaired
 *  - idempotent row logic; the advisory lock below; id-keyed report merge
 *
 * Removal: delete the runner, its registry entry, and its cursor/report options.
 */
class DataBackfills
{
    /**
     * Registered backfills: slug => ['title' => ...] (title is for logging).
     */
    public static function getRegistry()
    {
        return [
            // 2026-07-03 — shipped with the installment bill_times fix (PR #2194).
            // Remove after one or two releases once affected installs have upgraded.
            'installment_payments' => [
                'title' => 'Installment Payments Backfill',
            ],
        ];
    }

    /**
     * @return array pending backfill slugs (registered but not completed)
     */
    public static function getPending()
    {
        $option = (array)fluent_cart_get_option('_db_migrations', []);
        $doneSlugs = array_keys(array_filter((array)Arr::get($option, 'backfills', [])));


        return array_values(array_diff(array_keys(self::getRegistry()), $doneSlugs));
    }

    public static function hasPending()
    {
        return (bool)self::getPending();
    }

    /**
     * Run pending backfills within this request's budget. Called from the
     * data-backfills/run REST endpoint — the admin app re-calls while the
     * returned status is 'running'.
     *
     * @return array ['status' => completed|running|locked, 'completed' => [], 'pending' => []]
     */
    public static function processPending()
    {
        $pending = self::getPending();

        if (!$pending) {
            return ['status' => 'completed', 'completed' => [], 'pending' => []];
        }

        if (!self::acquireBackfillLock()) {
            // another request/tab is already on it — let that one finish
            return ['status' => 'locked', 'completed' => [], 'pending' => $pending];
        }

        $completedNow = [];

        try {
            foreach ($pending as $slug) {
                if (!self::runBackfill($slug)) {
                    break; // request budget spent — the next call resumes from the cursor
                }

                self::markCompleted($slug);
                $completedNow[] = $slug;
            }
        } finally {
            self::releaseBackfillLock();
        }

        $stillPending = self::getPending();

        return [
            'status'    => $stillPending ? 'running' : 'completed',
            'completed' => $completedNow,
            'pending'   => $stillPending,
        ];
    }

    /**
     * @return bool true when the backfill finished; false = budget spent, more remains
     */
    private static function runBackfill($slug)
    {
        if ($slug === 'installment_payments') {
            return self::repairInstallmentBillTimes();
        }

        // registered slug without a runner — mark done so it can't wedge the
        // queue, but leave a trace since this is a programming error
        fluent_cart_add_log(
            'Data backfill has no runner',
            'Backfill "' . $slug . '" is registered but has no runner. Marked completed to unblock the queue.',
            'warning',
            [
                'module_name' => 'activity',
                'module_id'   => 0
            ]
        );

        return true;
    }

    private static function markCompleted($slug)
    {
        $option = (array)fluent_cart_get_option('_db_migrations', []);
        $backfills = (array)Arr::get($option, 'backfills', []);
        $backfills[$slug] = 'yes';
        $option['backfills'] = $backfills;

        fluent_cart_update_option('_db_migrations', $option);

        $title = Arr::get(self::getRegistry(), $slug . '.title', $slug);

        fluent_cart_add_log(
            $title . ' completed',
            'Data backfill "' . $slug . '" finished and was marked completed.',
            'info',
            [
                'module_name' => 'activity',
                'module_id'   => 0
            ]
        );
    }

    /**
     * Repair installment subscriptions whose bill_times was stored decremented
     * by the old discount/simulated-trial checkout (completion then fired one
     * installment early and canceled the remote subscription).
     *
     * Restores bill_times from the parent order item's other_info.times,
     * backfills billed_cycles_offset for fully-free first cycles ($0 order),
     * and recomputes bill_count with the same formula syncSubscriptionStates
     * uses. Only rows matching the exact bug signature (bill_times == times - 1)
     * are touched — which also makes re-runs idempotent. bill_times = 0 rows
     * (unlimited) are excluded; a times=1 product sold with a discount landed
     * there and stays unrepaired (accepted trade-off).
     *
     * @return bool true when the scan reached the end of the table
     */
    private static function repairInstallmentBillTimes()
    {
        $chunkSize = 500;
        $maxChunksPerRun = 5;
        $maxRepairsPerRun = 500;
        $chunksProcessed = 0;
        $lastId = (int)fluent_cart_get_option('_fluent_cart_installment_repair_cursor', 0);
        $offsetIds = [];
        $underCollectedIds = [];
        $anomalousIds = [];
        $repairedRows = [];

        do {
            $subscriptions = Subscription::query()
                ->where('id', '>', $lastId)
                ->where('bill_times', '>', 0)
                ->where('config', 'LIKE', '%is_trial_days_simulated%')
                ->orderBy('id', 'ASC')
                ->limit($chunkSize)
                ->get();

            if ($subscriptions->isEmpty()) {
                break;
            }

            // batched lookups — two queries per chunk, not per subscription
            $orderIds = [];
            foreach ($subscriptions as $subscription) {
                $orderIds[$subscription->parent_order_id] = $subscription->parent_order_id;
            }

            $itemsByOrderId = [];
            $orderItems = OrderItem::query()
                ->whereIn('order_id', array_values($orderIds))
                ->where('payment_type', 'subscription')
                ->get();
            foreach ($orderItems as $item) {
                $itemsByOrderId[$item->order_id][] = $item;
            }

            $ordersById = [];
            $parentOrders = Order::query()->whereIn('id', array_values($orderIds))->get();
            foreach ($parentOrders as $order) {
                $ordersById[$order->id] = $order;
            }

            $subscriptionIds = [];
            foreach ($subscriptions as $subscription) {
                $subscriptionIds[] = $subscription->id;
            }

            // batched per chunk, not per repaired row — grouped charge counts
            $billsCountBySubscriptionId = [];
            $transactionCounts = OrderTransaction::query()
                ->selectRaw('subscription_id, COUNT(*) as total')
                ->whereIn('subscription_id', $subscriptionIds)
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->where('status', Status::TRANSACTION_SUCCEEDED)
                ->where('total', '>', 0)
                ->groupBy('subscription_id')
                ->get();
            foreach ($transactionCounts as $row) {
                $billsCountBySubscriptionId[(int)$row->subscription_id] = (int)$row->total;
            }

            $earlyPaymentHistoryBySubscriptionId = [];
            $earlyPaymentMetaRows = SubscriptionMeta::query()
                ->whereIn('subscription_id', $subscriptionIds)
                ->where('meta_key', 'early_payment_history')
                ->get();
            foreach ($earlyPaymentMetaRows as $metaRow) {
                $earlyPaymentHistoryBySubscriptionId[(int)$metaRow->subscription_id] = $metaRow->meta_value;
            }

            foreach ($subscriptions as $subscription) {
                $lastId = $subscription->id;

                if (Arr::get($subscription->config, 'is_trial_days_simulated', 'no') !== 'yes') {
                    continue;
                }

                $orderSubscriptionItems = Arr::get($itemsByOrderId, $subscription->parent_order_id, []);

                $orderItem = null;
                foreach ($orderSubscriptionItems as $candidateItem) {
                    if ((int)$candidateItem->object_id === (int)$subscription->variation_id) {
                        $orderItem = $candidateItem;
                        break;
                    }
                }

                // fallback only when unambiguous — on a multi-subscription order the
                // wrong item's times could overwrite this subscription's count
                if (!$orderItem && count($orderSubscriptionItems) === 1) {
                    $orderItem = $orderSubscriptionItems[0];
                }

                if (!$orderItem) {
                    continue;
                }

                $originalTimes = (int)Arr::get((array)$orderItem->other_info, 'times', 0);

                // more than one below the sold count = the old admin flow's double
                // decrement OR a deliberate reduction — can't tell apart, surface only
                if ($originalTimes > 1 && (int)$subscription->bill_times < $originalTimes - 1) {
                    $anomalousIds[] = $subscription->id;

                    fluent_cart_add_log(
                        'Installment subscription needs manual review',
                        'Subscription #' . $subscription->id . ' has bill_times ' . (int)$subscription->bill_times
                        . ' but its order item was sold with ' . $originalTimes . ' installments. This does not match '
                        . 'the known miscount signature (exactly one less), so it was not auto-repaired. '
                        . 'Verify the intended installment count and adjust manually if needed.',
                        'warning',
                        [
                            'module_name' => 'subscription',
                            'module_id'   => $subscription->id
                        ]
                    );

                    continue;
                }

                // exact bug signature only — everything else (already repaired,
                // method-switch flag, deliberate adjustment) stays untouched
                if ($originalTimes < 1 || (int)$subscription->bill_times !== $originalTimes - 1) {
                    continue;
                }

                $parentOrder = Arr::get($ordersById, $subscription->parent_order_id);

                if (!$parentOrder) {
                    // can't tell a free first cycle from a paid one without the order
                    continue;
                }

                $isFreeFirstCycle = !(int)$parentOrder->total_amount && !(int)$subscription->signup_fee;
                if ($isFreeFirstCycle) {
                    $subscription->updateMeta('billed_cycles_offset', 1);
                    $offsetIds[] = $subscription->id;
                }

                $billsCount = Arr::get($billsCountBySubscriptionId, $subscription->id, 0);

                $earlyPaymentHistory = Arr::get($earlyPaymentHistoryBySubscriptionId, $subscription->id, []);
                foreach ((array)$earlyPaymentHistory as $earlyPayment) {
                    $paidCount = (int)Arr::get($earlyPayment, 'count', 1);
                    if ($paidCount > 1) {
                        $billsCount += ($paidCount - 1);
                    }
                }

                $billsCount += $isFreeFirstCycle ? 1 : 0;

                // before/after audit trail — the overwrite is otherwise irreversible
                $repairedRows[$subscription->id] = [
                    'status_before'     => $subscription->status,
                    'bill_times_before' => (int)$subscription->bill_times,
                    'bill_count_before' => (int)$subscription->bill_count,
                    'bill_times_after'  => $originalTimes,
                    'bill_count_after'  => $billsCount,
                ];

                $isUnderCollected = $subscription->status === Status::SUBSCRIPTION_COMPLETED
                    && $billsCount < $originalTimes;

                if ($isUnderCollected) {
                    // falsely completed (remote already canceled): back to active with a
                    // restored next_billing_date so the hourly expiry cron expires it
                    // through the production transition (events fire there, not here);
                    // the customer can then renew/reactivate to pay the remainder
                    $subscription->status = Status::SUBSCRIPTION_ACTIVE;
                    $subscription->next_billing_date = $subscription->guessNextBillingDate();
                }

                $subscription->bill_times = $originalTimes;
                $subscription->bill_count = $billsCount;
                $subscription->save();

                if ($isUnderCollected) {
                    $underCollectedIds[] = $subscription->id;

                    fluent_cart_add_log(
                        'Installment subscription under-collected',
                        'Subscription #' . $subscription->id . ' was completed early due to a bill_times miscount ('
                        . $billsCount . ' of ' . $originalTimes . ' installments collected) when a discount was applied '
                        . 'at checkout, and its remote subscription was canceled. Status set back to active; the hourly '
                        . 'expiry check will mark it expired, after which the customer can renew/reactivate to pay the '
                        . 'remaining installment(s).',
                        'warning',
                        [
                            'module_name' => 'subscription',
                            'module_id'   => $subscription->id
                        ]
                    );
                }
            }

            // cursor after every chunk — a timeout resumes here, never from id 0
            fluent_cart_update_option('_fluent_cart_installment_repair_cursor', $lastId);
            $chunksProcessed++;

            // chunks bound the scan, repairs bound the heavy per-row work
            $budgetSpent = $chunksProcessed >= $maxChunksPerRun
                || count($repairedRows) >= $maxRepairsPerRun;

            if ($subscriptions->count() >= $chunkSize && $budgetSpent) {
                self::mergeRepairReport($repairedRows, $offsetIds, $underCollectedIds, $anomalousIds);

                return false;
            }
        } while ($subscriptions->count() >= $chunkSize);

        $report = self::mergeRepairReport($repairedRows, $offsetIds, $underCollectedIds, $anomalousIds);

        if ($report) {
            fluent_cart_add_log(
                'Installment bill_times repair completed',
                'Restored bill_times on ' . (int)Arr::get($report, 'restored_count', 0)
                . ' subscription(s), backfilled free-first-cycle offset on ' . (int)Arr::get($report, 'offset_count', 0)
                . ', flagged ' . count((array)Arr::get($report, 'under_collected_ids', [])) . ' under-collected and '
                . count((array)Arr::get($report, 'anomalous_ids', [])) . ' for manual review (see individual warning logs).',
                'info',
                [
                    'module_name' => 'subscription',
                    'module_id'   => 0
                ]
            );
        }

        // done — the cursor has no further use
        Meta::query()
            ->where('object_type', 'option')
            ->where('meta_key', '_fluent_cart_installment_repair_cursor')
            ->delete();

        return true;
    }

    /**
     * Accumulate results into the report option across partial runs — rows are
     * keyed by subscription id, so a replayed chunk can't duplicate entries.
     *
     * @return array|null merged report, or null when nothing was ever repaired
     */
    private static function mergeRepairReport($repairedRows, $offsetIds, $underCollectedIds, $anomalousIds)
    {
        $report = (array)fluent_cart_get_option('_fluent_cart_installment_repair_report', []);

        if (!$repairedRows && !$anomalousIds && !$report) {
            return null;
        }

        $report = [
            'repaired_at'         => gmdate('Y-m-d H:i:s'),
            'rows'                => $repairedRows + (array)Arr::get($report, 'rows', []),
            // id-keyed + deduped, not a running sum — a re-scanned/replayed chunk
            // (concurrent runs, advisory lock fail-open) can't double-count a subscription
            'offset_ids'          => array_values(array_unique(array_merge(
                (array)Arr::get($report, 'offset_ids', []),
                $offsetIds
            ))),
            'under_collected_ids' => array_values(array_unique(array_merge(
                (array)Arr::get($report, 'under_collected_ids', []),
                $underCollectedIds
            ))),
            'anomalous_ids'       => array_values(array_unique(array_merge(
                (array)Arr::get($report, 'anomalous_ids', []),
                $anomalousIds
            ))),
        ];
        $report['restored_count'] = count($report['rows']);
        $report['offset_count'] = count($report['offset_ids']);

        fluent_cart_update_option('_fluent_cart_installment_repair_report', $report);

        return $report;
    }

    /**
     * Advisory lock — own name, so backfills never contend with schema migrations.
     */
    private static function acquireBackfillLock()
    {
        global $wpdb;

        if (Schema::isSqlite()) {
            // GET_LOCK is MySQL-only; SQLite has no concurrent writers.
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $acquired = $wpdb->get_var($wpdb->prepare(
            "SELECT GET_LOCK(%s, 0)",
            self::getBackfillLockName()
        ));

        // NULL means the server could not create the lock — fail open so a
        // locking hiccup can never block backfills entirely.
        return $acquired === null || (string)$acquired === '1';
    }

    private static function releaseBackfillLock()
    {
        global $wpdb;

        if (Schema::isSqlite()) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "SELECT RELEASE_LOCK(%s)",
            self::getBackfillLockName()
        ));
    }

    private static function getBackfillLockName()
    {
        global $wpdb;

        // GET_LOCK names are server-wide; scope to this site's DB and prefix
        // so two WordPress installs on one MySQL server can't block each other.
        $dbName = defined('DB_NAME') ? DB_NAME : '';

        return 'fct_db_backfill_' . md5($dbName . '|' . $wpdb->prefix);
    }
}
