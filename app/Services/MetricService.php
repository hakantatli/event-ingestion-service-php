<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class MetricService
{
    protected ClickHouseService $clickhouse;

    public function __construct(ClickHouseService $clickhouse)
    {
        $this->clickhouse = $clickhouse;
    }

    public function getMetrics(int $from, int $to, string $eventName, string $groupBy = '')
    {
        $cacheKey = "metrics:{$from}:{$to}:{$eventName}:{$groupBy}";

        return Cache::remember($cacheKey, 10, function () use ($from, $to, $eventName, $groupBy) {
            $query = "SELECT count(*) as total_event_count, uniqExact(user_id) as unique_event_count";

            if ($groupBy !== '') {
                $allowedGroups = [
                    'channel' => true,
                    'campaign_id' => true,
                    'toStartOfHour(timestamp)' => true,
                    'toStartOfDay(timestamp)' => true,
                ];

                if (!isset($allowedGroups[$groupBy])) {
                    throw new \InvalidArgumentException("Invalid group by column");
                }
                $query .= ", {$groupBy} as grouped_by";
            }

            $query .= " FROM events FINAL WHERE event_name = @event_name";

            if ($from > 0) {
                $query .= " AND timestamp >= toDateTime(@from)";
            }

            if ($to > 0) {
                $query .= " AND timestamp <= toDateTime(@to)";
            }

            if ($groupBy !== '') {
                $query .= " GROUP BY {$groupBy}";
            }

            $params = [
                'event_name' => $eventName,
                'from' => $from,
                'to' => $to,
            ];

            $result = $this->clickhouse->executeQuery($query, $params);

            $response = [
                'total_event_count' => 0,
                'unique_event_count' => 0,
                'grouped_data' => []
            ];

            // ClickHouse JSON response format has 'data' array
            $data = $result['data'] ?? [];

            foreach ($data as $row) {
                $totalCount = (int)$row['total_event_count'];
                $uniqueCount = (int)$row['unique_event_count'];

                if ($groupBy !== '') {
                    $response['grouped_data'][] = [
                        'group' => $row['grouped_by'],
                        'total_event_count' => $totalCount,
                        'unique_event_count' => $uniqueCount,
                    ];
                    $response['total_event_count'] += $totalCount;
                    $response['unique_event_count'] += $uniqueCount;
                } else {
                    $response['total_event_count'] = $totalCount;
                    $response['unique_event_count'] = $uniqueCount;
                }
            }

            return $response;
        });
    }
}
