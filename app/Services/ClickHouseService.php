<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClickHouseService
{
    protected string $host;
    protected int $port;
    protected string $username;
    protected string $password;
    protected string $database;

    public function __construct()
    {
        $this->host = config('clickhouse.host', 'localhost');
        $this->port = config('clickhouse.port', 8123);
        $this->username = config('clickhouse.username', 'event_user');
        $this->password = config('clickhouse.password', 'event_password');
        $this->database = config('clickhouse.database', 'events_db');
    }

    protected function getBaseUrl(): string
    {
        return "http://{$this->host}:{$this->port}/";
    }

    public function executeQuery(string $query, array $params = [])
    {
        $response = Http::withBasicAuth($this->username, $this->password)
            ->withOptions(['query' => ['database' => $this->database]])
            ->post($this->getBaseUrl(), $this->bindParams($query, $params));

        if ($response->failed()) {
            throw new \Exception("ClickHouse Query Failed: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Executes bulk insert where $data is an array of strings in CSV or JSONEachRow format,
     * or raw query string. We will use JSONEachRow.
     */
    public function insertBatch(string $table, array $rows)
    {
        if (empty($rows)) {
            return;
        }

        $payload = implode("\n", array_map(function($row) {
            return json_encode($row);
        }, $rows));

        $query = "INSERT INTO {$table} FORMAT JSONEachRow";

        // Synchronous insert: we already batch rows before calling this method,
        // so async_insert is redundant and hides errors (it ACKs before data is written).
        $response = Http::withBasicAuth($this->username, $this->password)
            ->withOptions([
                'query' => [
                    'database' => $this->database,
                    'query'    => $query,
                ]
            ])
            ->withBody($payload, 'application/x-ndjson')
            ->post($this->getBaseUrl());

        if ($response->failed()) {
            Log::error("ClickHouse Batch Insert Failed: " . $response->body());
            throw new \Exception("ClickHouse Batch Insert Failed: " . $response->body());
        }
    }

    /**
     * Replace named params internally. Example: SELECT * FROM table WHERE col = {param:String}
     * Since native ClickHouse HTTP params can be passed via URL query like `param_name=value`,
     * we will simplify here. The Go version used explicit bindings.
     */
    protected function bindParams(string $query, array $params = []): string
    {
        // For simplicity and matching Go's exact setup, we can inject formatted strings 
        // into the query directly for the metrics since we know the types. 
        // Note: Production should use parameter interpolation supported by CH via format {name:Type}
        // Here we just replace @param manually for dates/strings for our exact use cases.
        
        foreach ($params as $key => $value) {
            if (is_string($value)) {
                $value = "'" . addslashes($value) . "'";
            } elseif (is_bool($value)) {
                $value = $value ? 1 : 0;
            } elseif ($value === null) {
                $value = 'NULL';
            }
            $query = str_replace('@' . $key, $value, $query);
        }
        
        return $query . ' FORMAT JSON';
    }
}
