# Laravel Octane Event Ingestion Service

This repository houses the high-performance PHP adaptation of the Event Ingestion Service, engineered explicitly for ultra-high concurrency and horizontal scaling. By replacing native monolithic Go mechanics with a highly orchestrated PHP infrastructure, this stack effortlessly processes over **10,000+ streaming events per second**.

## 🏗 Architecture (The Buffer-and-Batch Pattern)

ClickHouse is notoriously slow at natively processing individual single-row inserts but is exceptionally agile when processing massive JSON blocks. To solve for this, this infrastructure utilizes a robust **hybrid layout**:

1. **FrankenPHP (Laravel Octane):** 50 persistent PHP workers sit securely behind a Caddy reverse proxy holding the HTTP layer open, stripping all traditional Laravel framework overhead and preventing socket timeouts under maximum concurrency.
2. **High-Speed Buffering (Redis):** Instead of stalling connections on ClickHouse I/O, Octane accepts single-ingestion JSON payloads, normalizes the data, and performs a native `rpush` to an in-memory Redis buffer (`events_buffer`) in under 2 milliseconds, immediately returning a `202 Accepted` to the client.
3. **Persistent Socket Connections:** Octane uniquely holds 50 dedicated Keep-Alive TCP connections natively bound to the Redis container natively preventing Alpine port exhaustion (TIME_WAIT saturation) even when continuously streaming >1,000,000 requests. 
4. **Asynchronous Bulk Inserts (The Artisan Worker):** A wholly isolated Docker container constantly runs `php artisan events:flush` in the background. It non-blockingly drains up to 1,000 events continuously off the Redis queue every 50ms and streams them cleanly to ClickHouse using strict JSON `async_insert` blocks natively.

## 🚀 Getting Started

The entire stack relies purely on Docker Compose to orchestrate zero-configuration booting.

### 1. Boot the Stack
Navigate to the root directory and build the microservices:
```bash
docker-compose up -d --build
```
*This will spin up `php-api`, `worker`, `redis`, and `clickhouse` containers.*

### 2. Verify Database Status
The ClickHouse events table has explicitly been configured. You can manually push an event natively to the instance:
```bash
curl -X POST http://localhost:8000/events \
     -H "Content-Type: application/json" \
     -d '{"event_name": "page_view", "user_id": "test_1", "timestamp": '$(date +%s)'}'
```

### 3. Load Testing
A native Go script exists for hammering both synchronous and asynchronous buffering endpoints automatically:
* **Single Mode (Redis Buffering):** `go run ../scripts/loadtest.go -mode=single -url=http://localhost:8000`
* **Bulk Mode (Direct ClickHouse Async):** `go run ../scripts/loadtest.go -mode=bulk -url=http://localhost:8000`
