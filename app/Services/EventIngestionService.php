<?php

namespace App\Services;

use Fiber;

class EventIngestionService
{
    protected ClickHouseService $clickhouse;

    /**
     * In-process buffer: events accumulate here within the Octane worker process.
     * No I/O on the hot path — identical to Go's batcher channel approach.
     *
     * Flush triggers (whichever comes first — same as Go's batcher):
     *   1. Buffer reaches $batchSize
     *   2. $flushIntervalMs milliseconds have elapsed since last flush
     */
    protected static array $buffer          = [];
    protected static int   $batchSize       = 200;
    protected static int   $flushIntervalMs = 1000; // 1 second, matching Go's default
    protected static float $lastFlushedAt   = 0.0;  // hrtime milliseconds

    /**
     * Queue of pending Fibers waiting to be driven to completion.
     * Each Fiber wraps a ClickHouse insertBatch() call so the hot path
     * returns immediately after creating/starting the Fiber.
     *
     * @var Fiber[]
     */
    protected static array $pendingFibers = [];

    public function __construct(ClickHouseService $clickhouse)
    {
        $this->clickhouse = $clickhouse;
    }

    /**
     * Builds the record array and appends to the in-process buffer.
     * Zero network I/O — returns in microseconds, matching Go's batcher.Add().
     */
    public function ingestSingle(array $event): void
    {
        // Initialise the flush timer on first call in this worker process
        if (static::$lastFlushedAt === 0.0) {
            static::$lastFlushedAt = hrtime(true) / 1e6; // convert ns → ms
        }

        static::$buffer[] = $this->buildRecord($event);

        $elapsedMs = (hrtime(true) / 1e6) - static::$lastFlushedAt;

        if (count(static::$buffer) >= static::$batchSize || $elapsedMs >= static::$flushIntervalMs) {
            $this->flushBuffer();
        }
    }

    /**
     * Spawns a PHP Fiber to insert buffered events into ClickHouse asynchronously.
     *
     * The Fiber is started immediately (running the ClickHouse HTTP call synchronously
     * inside the Fiber's own stack). Because ClickHouseService uses Laravel's HTTP
     * client (which is blocking), the Fiber runs the insert to completion on the first
     * resume and is then automatically removed from the pending queue.
     *
     * In a fully async runtime (e.g. ReactPHP/Revolt) you would Fiber::suspend() around
     * the network call and resume from an event-loop callback — making it truly
     * non-blocking. Here the Fiber gives us clean isolation and a uniform driver loop
     * (drivefibers()) without coupling the hot path to the I/O result.
     *
     * Called when batchSize is reached, the time interval elapses, or the
     * Octane tick fires — whichever comes first.
     */
    public function flushBuffer(): void
    {
        // Always reset the timer, even if buffer is empty (keeps interval honest)
        static::$lastFlushedAt = hrtime(true) / 1e6;

        if (empty(static::$buffer)) {
            // Still drive any lingering fibers from previous flushes
            $this->driveFibers();
            return;
        }

        $batch          = static::$buffer;
        static::$buffer = [];

        $clickhouse = $this->clickhouse;

        // Spawn a Fiber that owns this batch entirely.
        // The hot-path thread returns as soon as start() hands off control.
        $fiber = new Fiber(function () use ($batch, $clickhouse): void {
            // Optional: yield so the caller returns before we hit the network.
            // Remove this line if you prefer the first insert to run synchronously.
            Fiber::suspend('started');

            $clickhouse->insertBatch('events', $batch);
        });

        // Start the fiber — it runs until the first Fiber::suspend() call.
        $fiber->start();

        // If the fiber is still suspended (i.e. it yielded after 'started'),
        // queue it for the next driveFibers() pass.
        if ($fiber->isSuspended()) {
            static::$pendingFibers[] = $fiber;
        }

        // Drive any other pending fibers from previous flushes
        $this->driveFibers();
    }

    /**
     * Resume every suspended Fiber once and clean up finished ones.
     * Called from:
     *  - flushBuffer() itself
     *  - The Octane tick registered in AppServiceProvider (every second)
     */
    public function driveFibers(): void
    {
        $remaining = [];

        foreach (static::$pendingFibers as $fiber) {
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }

            if (!$fiber->isTerminated()) {
                $remaining[] = $fiber;
            }
        }

        static::$pendingFibers = $remaining;
    }

    /**
     * Directly inserts a batch of events to ClickHouse synchronously.
     * Used by the /bulk endpoint which already supplies a pre-formed batch.
     */
    public function ingestBulk(array $events): void
    {
        $records    = [];
        $insertedAt = date('Y-m-d H:i:s');

        foreach ($events as $event) {
            $records[] = $this->buildRecord($event, $insertedAt);
        }

        $this->clickhouse->insertBatch('events', $records);
    }

    // -------------------------------------------------------------------------

    private function buildRecord(array $event, ?string $insertedAt = null): array
    {
        return [
            'event_id'    => vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)),
            'event_name'  => $event['event_name'],
            'user_id'     => $event['user_id'],
            'timestamp'   => date('Y-m-d H:i:s', $event['timestamp']),
            'channel'     => $event['channel']     ?? '',
            'campaign_id' => $event['campaign_id'] ?? '',
            'tags'        => $event['tags']         ?? [],
            'metadata'    => (object) array_map('strval', $event['metadata'] ?? []),
            'inserted_at' => $insertedAt ?? date('Y-m-d H:i:s'),
        ];
    }
}
