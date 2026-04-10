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
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'event_name' => 'required|string',
            'user_id' => 'required|string',
            'timestamp' => 'required|integer',
            'channel' => 'nullable|string',
            'campaign_id' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $validated = $validator->validated();

        // Validate timestamp window (1 hour past/future)
        $now = time();
        if (abs($now - $validated['timestamp']) > 3660) {
            return response()->json(['error' => 'timestamp out of allowed window', 'now' => $now, 'event_timestamp' => $validated['timestamp']], 400);
        }

        try {
            $this->service->ingestSingle($validated);
            return response()->json(['status' => 'accepted'], 202);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }

    public function bulkStore(Request $request)
    {
        // Require an array of objects
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            '*' => 'array',
            '*.event_name' => 'required|string',
            '*.user_id' => 'required|string',
            '*.timestamp' => 'required|integer',
            '*.channel' => 'nullable|string',
            '*.campaign_id' => 'nullable|string',
            '*.tags' => 'nullable|array',
            '*.tags.*' => 'string',
            '*.metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $events = $validator->validated();

        if (empty($events)) {
            return response()->json(['status' => 'created', 'count' => 0], 201);
        }

        // Validate timestamp window (1 hour past/future)
        $now = time();
        foreach ($events as $event) {
            if (abs($now - $event['timestamp']) > 3660) {
                return response()->json(['error' => 'timestamp out of allowed window', 'now' => $now, 'event_timestamp' => $event['timestamp']], 400);
            }
        }

        try {
            $this->service->ingestBulk($events);
            return response()->json(['status' => 'created', 'count' => count($events)], 201);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }
}
