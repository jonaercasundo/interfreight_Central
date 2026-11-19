<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

class QueueHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $queue = (string) config('queue.connections.'.config('queue.default').'.queue', 'default');

        try {
            $backlog = Queue::size($queue);
            $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

            return response()->json([
                'status' => 'available',
                'connection' => (string) config('queue.default'),
                'queue' => $queue,
                'backlog' => $backlog,
                'failed_jobs' => $failedJobs,
                'checked_at' => now()->toIso8601String(),
            ]);
        } catch (Throwable) {
            return response()->json([
                'status' => 'unavailable',
                'connection' => (string) config('queue.default'),
                'queue' => $queue,
                'backlog' => null,
                'failed_jobs' => null,
                'checked_at' => now()->toIso8601String(),
            ], 503);
        }
    }
}
