<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class EventIngestionService
{
    protected ClickHouseService $clickhouse;
    protected string $redisKey = 'events_buffer';

    public function __construct(ClickHouseService $clickhouse)
    {
        $this->clickhouse = $clickhouse;
    }

    /**
     * Pushes a single event to a Redis list and returns immediately.
     */
    public function ingestSingle(array $event)
    {
        $event['inserted_at'] = now($event['timezone'] ?? 'UTC')->toDateTimeString();
        $event['timestamp'] = date('Y-m-d H:i:s', $event['timestamp']);
        
        // Match Go Model: EventID, EventName, UserID, Timestamp, Channel, CampaignID, Tags, Metadata, InsertedAt
        $record = [
            'event_id' => bin2hex(random_bytes(16)), // or UUID
            'event_name' => $event['event_name'],
            'user_id' => $event['user_id'],
            'timestamp' => $event['timestamp'],
            'channel' => $event['channel'] ?? '',
            'campaign_id' => $event['campaign_id'] ?? '',
            'tags' => $event['tags'] ?? [],
            'metadata' => (object) array_map('strval', $event['metadata'] ?? []),
            'inserted_at' => $event['inserted_at'],
        ];

        Redis::rpush($this->redisKey, json_encode($record));
    }

    /**
     * Directly inserts an array of events to ClickHouse.
     */
    public function ingestBulk(array $events)
    {
        $records = [];
        $insertedAt = now('UTC')->toDateTimeString();

        foreach ($events as $event) {
            $records[] = [
                'event_id' => bin2hex(random_bytes(16)),
                'event_name' => $event['event_name'],
                'user_id' => $event['user_id'],
                'timestamp' => date('Y-m-d H:i:s', $event['timestamp']),
                'channel' => $event['channel'] ?? '',
                'campaign_id' => $event['campaign_id'] ?? '',
                'tags' => $event['tags'] ?? [],
                'metadata' => (object) array_map('strval', $event['metadata'] ?? []),
                'inserted_at' => $insertedAt,
            ];
        }

        $this->clickhouse->insertBatch('events', $records);
    }
}
