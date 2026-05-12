<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\EventIngestionService;

class EventController extends Controller
{
    protected EventIngestionService $service;

    public function __construct(EventIngestionService $service)
    {
        $this->service = $service;
    }

    public function store(Request $request)
    {
        $data = $request->input();

        if (empty($data['event_name']) || !is_string($data['event_name'])
            || empty($data['user_id']) || !is_string($data['user_id'])
            || !isset($data['timestamp']) || !is_int($data['timestamp'])) {
            return response()->json(['error' => 'Missing or invalid required fields: event_name, user_id, timestamp'], 400);
        }

        // Validate timestamp window (1 hour past/future)
        $now = time();
        if (abs($now - $data['timestamp']) > 3660) {
            return response()->json(['error' => 'timestamp out of allowed window', 'now' => $now, 'event_timestamp' => $data['timestamp']], 400);
        }

        $validated = [
            'event_name'  => $data['event_name'],
            'user_id'     => $data['user_id'],
            'timestamp'   => $data['timestamp'],
            'channel'     => isset($data['channel']) && is_string($data['channel']) ? $data['channel'] : '',
            'campaign_id' => isset($data['campaign_id']) && is_string($data['campaign_id']) ? $data['campaign_id'] : '',
            'tags'        => isset($data['tags']) && is_array($data['tags']) ? $data['tags'] : [],
            'metadata'    => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [],
        ];

        try {
            $this->service->ingestSingle($validated);
            return response()->json(['status' => 'accepted'], 202);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function bulkStore(Request $request)
    {
        $raw = $request->input();

        if (empty($raw) || !is_array($raw)) {
            return response()->json(['status' => 'created', 'count' => 0], 201);
        }

        $now    = time();
        $events = [];

        foreach ($raw as $i => $event) {
            if (!is_array($event)
                || empty($event['event_name']) || !is_string($event['event_name'])
                || empty($event['user_id'])     || !is_string($event['user_id'])
                || !isset($event['timestamp'])  || !is_int($event['timestamp'])) {
                return response()->json(['error' => "Invalid event at index {$i}: missing event_name, user_id or timestamp"], 400);
            }

            if (abs($now - $event['timestamp']) > 3660) {
                return response()->json(['error' => 'timestamp out of allowed window', 'index' => $i, 'event_timestamp' => $event['timestamp']], 400);
            }

            $events[] = [
                'event_name'  => $event['event_name'],
                'user_id'     => $event['user_id'],
                'timestamp'   => $event['timestamp'],
                'channel'     => isset($event['channel']) && is_string($event['channel']) ? $event['channel'] : '',
                'campaign_id' => isset($event['campaign_id']) && is_string($event['campaign_id']) ? $event['campaign_id'] : '',
                'tags'        => isset($event['tags']) && is_array($event['tags']) ? $event['tags'] : [],
                'metadata'    => isset($event['metadata']) && is_array($event['metadata']) ? $event['metadata'] : [],
            ];
        }

        try {
            $this->service->ingestBulk($events);
            return response()->json(['status' => 'created', 'count' => count($events)], 201);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
