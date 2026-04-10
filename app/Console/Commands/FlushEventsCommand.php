<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use App\Services\ClickHouseService;
use Illuminate\Support\Facades\Log;

class FlushEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'events:flush';

    /**
     * The console command description.
     */
    protected $description = 'Worker to pop events from Redis and batch insert them into ClickHouse';

    /**
     * Execute the console command.
     */
    public function handle(ClickHouseService $clickhouse)
    {
        $this->info('Starting event flusher worker...');
        $redisKey = 'events_buffer';
        $batchSize = 1000;
        $maxWaitMs = 50; // 50ms flush interval

        while (true) {
            $batch = [];
            $startTime = microtime(true);

            while (count($batch) < $batchSize) {
                // Non-blocking pop; in production, consider `blpop` or Pipeline for performance.
                $item = Redis::lpop($redisKey);
                if ($item) {
                    $batch[] = json_decode($item, true);
                } else {
                    // if no item, check wait time. if elapsed, break.
                    $elapsedMs = (microtime(true) - $startTime) * 1000;
                    if ($elapsedMs >= $maxWaitMs) {
                        break;
                    }
                    usleep(5000); // sleep 5ms
                }
            }

            if (!empty($batch)) {
                try {
                    $clickhouse->insertBatch('events', $batch);
                    if (env('APP_DEBUG')) {
                        $this->info("Flushed " . count($batch) . " events.");
                    }
                } catch (\Exception $e) {
                    Log::error("Flush Error: " . $e->getMessage());
                    $this->error("Flush Error: " . $e->getMessage());
                    // On error, push back to Redis to avoid data loss.
                    foreach ($batch as $failedItem) {
                        Redis::rpush($redisKey, json_encode($failedItem));
                    }
                    sleep(1); // Wait before retrying
                }
            }
        }
    }
}
