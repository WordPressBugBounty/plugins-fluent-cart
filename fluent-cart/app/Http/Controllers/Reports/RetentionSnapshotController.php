<?php

namespace FluentCart\App\Http\Controllers\Reports;

use FluentCart\App\Services\Report\RetentionSnapshotService;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Http\Controllers\Controller;

class RetentionSnapshotController extends Controller
{
    /**
     * Generate retention snapshots via Action Scheduler (background job)
     */
    public function generate(Request $request)
    {
        $productId = $request->get('product_id');
        if ($productId) {
            $productId = (int) $productId;
        }

        // Check if Action Scheduler is available
        if (!function_exists('as_enqueue_async_action')) {
            // Fallback: run synchronously if Action Scheduler not available
            $service = new RetentionSnapshotService();
            $result = $service->generate($productId, null);

            return [
                'success' => $result['success'],
                'message' => $result['message'],
                'stats' => $result['stats'] ?? [],
                'mode' => 'synchronous',
            ];
        }

        // Use timestamp as tracking ID - create it ONCE
        $trackingId = time();

        // Queue the job via Action Scheduler
        // Note: Action Scheduler passes array values as separate arguments to the callback
        as_schedule_single_action(
            time(),
            'fluent_cart_generate_retention_snapshots',
            [$productId, $trackingId], // Will be passed as generateSnapshots($productId, $trackingId)
            'fluent-cart-snapshots'
        );
        
        // Store job start time
        update_option('fluent_cart_snapshot_job_' . $trackingId, [
            'status' => 'pending',
            'started_at' => current_time('mysql'),
            'product_id' => $productId,
        ]);

        return [
            'success' => true,
            'message' => 'Snapshot generation queued',
            'job_id' => $trackingId,
            'mode' => 'background',
        ];
    }

    /**
     * Check status of a snapshot generation job
     */
    public function checkStatus(Request $request)
    {
        $jobId = $request->get('params.job_id');
        
        if (!$jobId) {
            return [
                'success' => false,
                'message' => 'Job ID required',
            ];
        }

        $jobData = get_option('fluent_cart_snapshot_job_' . $jobId);

        if (!$jobData) {
            return [
                'success' => false,
                'message' => 'Job not found',
                'job_id' => $jobId,
            ];
        }

        // If job data shows completed or failed, return that status
        if (isset($jobData['status']) && in_array($jobData['status'], ['completed', 'failed'])) {
            return [
                'success' => true,
                'status' => $jobData['status'],
                'message' => $jobData['message'] ?? 'Job ' . $jobData['status'],
                'stats' => $jobData['stats'] ?? [],
                'data' => $jobData,
            ];
        }

        // Otherwise, it's still running
        return [
            'success' => true,
            'status' => 'running',
            'message' => 'Job is still running',
            'data' => $jobData,
        ];
    }
}
