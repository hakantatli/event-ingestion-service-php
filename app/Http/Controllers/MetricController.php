<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\MetricService;

class MetricController extends Controller
{
    protected MetricService $service;

    public function __construct(MetricService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $request->validate([
            'event_name' => 'required|string',
            'from' => 'nullable|integer',
            'to' => 'nullable|integer',
            'group_by' => 'nullable|string',
        ]);

        $eventName = $request->query('event_name');
        $from = (int) $request->query('from', 0);
        $to = (int) $request->query('to', 0);
        $groupBy = $request->query('group_by', '');

        try {
            $metrics = $this->service->getMetrics($from, $to, $eventName, $groupBy);
            return response()->json($metrics);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
}
