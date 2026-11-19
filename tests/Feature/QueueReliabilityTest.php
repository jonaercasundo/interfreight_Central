<?php

namespace Tests\Feature;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class Phase16QueueProbeJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $key) {}

    public function handle(): void
    {
        $redis = Redis::connection();
        $attempt = (int) $redis->incr($this->key.':attempts');
        if ($attempt === 1) {
            throw new \RuntimeException('Synthetic first-attempt failure.');
        }
        if ($redis->setnx($this->key.':effect', '1')) {
            $redis->expire($this->key.':effect', 3600);
            $redis->incr($this->key.':effect_count');
        }
    }
}

class Phase17QueueBackoffProbeJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60];

    public function handle(): void
    {
        throw new \RuntimeException('Synthetic backoff probe failure.');
    }
}

class QueueReliabilityTest extends TestCase
{
    public function test_redis_worker_applies_configured_backoff_after_a_synthetic_failure(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('The host PHP Redis extension is unavailable; run this integration test in the PHP-FPM image.');
        }

        try {
            Redis::connection()->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('No isolated Redis test service is reachable.');
        }

        $queue = 'phase17-backoff-'.bin2hex(random_bytes(6));
        config(['queue.default' => 'redis', 'queue.connections.redis.queue' => $queue, 'queue.failed.driver' => 'database-uuids', 'queue.failed.database' => config('database.default'), 'queue.failed.table' => 'failed_jobs']);

        try {
            dispatch((new Phase17QueueBackoffProbeJob)->onQueue($queue));
            Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--tries' => 3, '--sleep' => 0, '--timeout' => 10]);

            $delayed = Redis::connection()->zRange('queues:'.$queue.':delayed', 0, -1, true);
            $this->assertCount(1, $delayed, 'The failed job should be placed in Redis delayed queue.');
            $dueAt = (float) array_values($delayed)[0];
            $remainingBackoffSeconds = $dueAt - microtime(true);
            $this->assertGreaterThan(10.0, $remainingBackoffSeconds, 'The failed job must remain delayed rather than become immediately available.');
            $this->assertLessThanOrEqual(30.5, $remainingBackoffSeconds, 'The first retry should follow its configured thirty-second backoff.');
        } finally {
            Redis::connection()->del('queues:'.$queue, 'queues:'.$queue.':delayed', 'queues:'.$queue.':reserved', 'queues:'.$queue.':notify', 'queues:'.$queue.':paused');
        }
    }

    public function test_redis_job_failure_is_persisted_retried_and_duplicate_effect_is_idempotent(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('The host PHP Redis extension is unavailable; run this integration test in the PHP-FPM image.');
        }

        try {
            Redis::connection()->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('No isolated Redis test service is reachable.');
        }

        $queue = 'phase16-'.bin2hex(random_bytes(6));
        $key = 'ifc:phase16:'.bin2hex(random_bytes(12));
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => $queue,
            'queue.connections.redis.after_commit' => false,
            'queue.failed.driver' => 'database-uuids',
            'queue.failed.database' => config('database.default'),
            'queue.failed.table' => 'failed_jobs',
        ]);

        try {
            dispatch((new Phase16QueueProbeJob($key))->onQueue($queue));
            Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--tries' => 1, '--sleep' => 0, '--timeout' => 10]);
            $failed = DB::table('failed_jobs')->latest('failed_at')->first();
            $this->assertNotNull($failed, 'The exhausted attempt should be persisted in failed_jobs.');

            $retryExit = Artisan::call('queue:retry', ['id' => $failed->uuid]);
            $retryOutput = Artisan::output();
            Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--tries' => 2, '--sleep' => 0, '--timeout' => 10]);
            $this->assertSame(0, $retryExit, $retryOutput);
            $this->assertSame('2', (string) Redis::get($key.':attempts'), 'The retry should execute the same job a second time. '.Artisan::output());
            $this->assertSame('1', (string) Redis::get($key.':effect_count'));

            dispatch((new Phase16QueueProbeJob($key))->onQueue($queue));
            dispatch((new Phase16QueueProbeJob($key))->onQueue($queue));
            Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--tries' => 2, '--sleep' => 0, '--timeout' => 10]);
            Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--tries' => 2, '--sleep' => 0, '--timeout' => 10]);
            $this->assertSame('1', (string) Redis::get($key.':effect_count'), 'Duplicate job delivery must not duplicate the synthetic side effect.');
            $this->assertSame(0, DB::table('failed_jobs')->where('uuid', $failed->uuid)->count());
        } finally {
            Redis::del($key.':attempts', $key.':effect', $key.':effect_count');
        }
    }
}
