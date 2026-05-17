---
eyebrow: 'Docs · Events'
lede:    'Route OPC UA event listeners through Messenger to avoid blocking the dispatcher. Transports, retry, batching, and worker tuning for high-throughput subscriptions.'

see_also:
  - { href: './data-events.md',                       meta: '6 min' }
  - { href: './alarm-events.md',                      meta: '5 min' }
  - { href: '../integrations/messenger.md',            meta: '6 min' }

prev: { label: 'Alarm events',  href: './alarm-events.md' }
next: { label: 'Logging',       href: '../observability/logging.md' }
---

# Async listeners with Messenger

OPC UA subscriptions can produce thousands of events per minute.
Synchronous listeners that do non-trivial work choke the
publish loop. The fix: route through Symfony Messenger.

## The pattern

A thin **listener** dispatches a message; a **handler** runs on
a worker:

<!-- @code-block language="text" label="flow" -->
```text
DataChangeReceived
    │
    │  (sync — microseconds)
    ▼
EventListener
    │
    │  $bus->dispatch(new StoreReading(...))
    ▼
Messenger transport (async)
    │
    │  (async — milliseconds to seconds)
    ▼
Worker process
    │
    │  MessageHandler (Doctrine, HTTP, notifier, etc.)
    ▼
Side effects
```
<!-- @endcode-block -->

The listener returns in microseconds; the work happens on a
worker.

## The message

<!-- @code-block language="php" label="src/Message/StoreReading.php" -->
```php
namespace App\Message;

final readonly class StoreReading
{
    public function __construct(
        public string $connection,
        public string $nodeId,
        public mixed $value,
        public int $statusCode,
        public ?\DateTimeImmutable $at,
    ) {}
}
```
<!-- @endcode-block -->

Use `readonly` and primitive types. Messages are serialised — no
entity references (use IDs instead).

## The listener

<!-- @code-block language="php" label="dispatch listener" -->
```php
namespace App\EventListener;

use App\Message\StoreReading;
use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

final class DispatchReading
{
    public function __construct(private MessageBusInterface $bus) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        $this->bus->dispatch(new StoreReading(
            connection: $event->connection,
            nodeId:     $event->nodeId,
            value:      $event->dataValue->getValue(),
            statusCode: $event->dataValue->statusCode,
            at:         $event->dataValue->sourceTimestamp,
        ));
    }
}
```
<!-- @endcode-block -->

## The handler

<!-- @code-block language="php" label="handler" -->
```php
namespace App\MessageHandler;

use App\Entity\PlcReading;
use App\Message\StoreReading;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StoreReadingHandler
{
    public function __construct(private EntityManagerInterface $em) {}

    public function __invoke(StoreReading $msg): void
    {
        if ($msg->statusCode !== 0) return;

        $reading = (new PlcReading())
            ->setConnection($msg->connection)
            ->setNodeId($msg->nodeId)
            ->setValue($msg->value)
            ->setSourceAt($msg->at);

        $this->em->persist($reading);
        $this->em->flush();
    }
}
```
<!-- @endcode-block -->

## Transport config

<!-- @code-block language="text" label="config/packages/messenger.yaml" -->
```text
framework:
    messenger:
        transports:
            async_opcua:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    use_notify: true
                    check_delayed_interval: 60000
                retry_strategy:
                    max_retries: 3
                    multiplier: 2

        routing:
            App\Message\StoreReading: async_opcua
            App\Message\RecordAlarm:  async_opcua
```
<!-- @endcode-block -->

`.env`:

<!-- @code-block language="bash" label="DSN options" -->
```bash
# Redis (recommended for OPC UA-driven traffic)
MESSENGER_TRANSPORT_DSN=redis://localhost:6379/messages

# Doctrine table (simpler, lower throughput)
# MESSENGER_TRANSPORT_DSN=doctrine://default

# RabbitMQ
# MESSENGER_TRANSPORT_DSN=amqp://guest:guest@localhost:5672/%2f/messages
```
<!-- @endcode-block -->

Redis is the sweet spot for OPC UA throughput — sub-millisecond
enqueue, easy to monitor.

## Running the worker

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console messenger:consume async_opcua \
    --time-limit=3600 \
    --memory-limit=512M \
    --limit=10000
```
<!-- @endcode-block -->

| Flag             | Purpose                                  |
| ---------------- | ---------------------------------------- |
| `--time-limit`    | Restart after N seconds                  |
| `--memory-limit`  | Restart after N MB                       |
| `--limit`         | Restart after N messages                  |

Worker exits cleanly; the supervisor (systemd / Supervisor)
restarts it. All three bound long-running PHP from leaking
forever.

## Per-handler retry

<!-- @code-block language="php" label="retry strategy" -->
```php
#[AsMessageHandler]
final class StoreReadingHandler
{
    public function __invoke(StoreReading $msg): void
    {
        try {
            // ...
        } catch (\PDOException $e) {
            throw new RecoverableMessageHandlingException(
                'DB transient error', 0, $e
            );
        } catch (\InvalidArgumentException $e) {
            throw new UnrecoverableMessageHandlingException(
                'Bad message', 0, $e
            );
        }
    }
}
```
<!-- @endcode-block -->

| Exception                                  | Effect                                  |
| ------------------------------------------ | --------------------------------------- |
| `RecoverableMessageHandlingException`       | Retried per retry strategy              |
| `UnrecoverableMessageHandlingException`     | Sent to failed transport immediately    |
| Any other                                    | Retried per retry strategy              |

## Failed messages

<!-- @code-block language="text" label="failure transport" -->
```text
framework:
    messenger:
        failure_transport: failed

        transports:
            failed: 'doctrine://default?queue_name=failed'

        routing:
            App\Message\StoreReading: async_opcua
```
<!-- @endcode-block -->

Inspect / retry:

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console messenger:failed:show
php bin/console messenger:failed:retry
php bin/console messenger:failed:remove
```
<!-- @endcode-block -->

## Multiple queues per priority

Route different message types to different queues:

<!-- @code-block language="text" label="multi-queue" -->
```text
framework:
    messenger:
        transports:
            opcua_data:    '%env(MESSENGER_DSN)%?queue_name=opcua_data'
            opcua_alarms:  '%env(MESSENGER_DSN)%?queue_name=opcua_alarms'
            opcua_history: '%env(MESSENGER_DSN)%?queue_name=opcua_history'

        routing:
            App\Message\StoreReading:        opcua_data
            App\Message\RecordAlarm:         opcua_alarms
            App\Message\FetchDailyHistory:   opcua_history
```
<!-- @endcode-block -->

Run separate workers per queue:

<!-- @code-block language="bash" label="separate workers" -->
```bash
php bin/console messenger:consume opcua_data    --time-limit=3600
php bin/console messenger:consume opcua_alarms  --time-limit=3600
php bin/console messenger:consume opcua_history --time-limit=3600
```
<!-- @endcode-block -->

Heavy history reads can't starve fast data inserts.

## Worker scaling — multiple processes per queue

Run N processes against the same queue:

<!-- @code-block language="bash" label="parallel workers" -->
```bash
for i in 1 2 3 4; do
    php bin/console messenger:consume opcua_data --time-limit=3600 &
done
wait
```
<!-- @endcode-block -->

For systemd, use a template unit
`opcua-worker@.service` and `enable --now opcua-worker@{1..4}`.

## Throughput tuning

| Subscription rate    | Per-job time     | Workers per queue          |
| -------------------- | ---------------- | -------------------------- |
| 100 events / sec     | 5 ms             | 1-2                        |
| 1000 events / sec    | 10 ms            | 4-8                        |
| 5000 events / sec    | 20 ms            | 16-32 (consider batching)   |

Watch the queue depth via `messenger:stats` or Redis CLI to size
correctly.

## Batched handlers

For very high throughput, buffer in Redis and flush periodically:

<!-- @code-block language="php" label="batched handler" -->
```php
#[AsMessageHandler]
final class StoreReadingBatchedHandler
{
    private const BATCH_SIZE = 100;

    public function __invoke(StoreReading $msg): void
    {
        // Push into a Redis list
        $this->redis->rpush('opcua.batch', json_encode($msg));

        $length = $this->redis->llen('opcua.batch');
        if ($length >= self::BATCH_SIZE) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        $items = $this->redis->multi()
            ->lrange('opcua.batch', 0, -1)
            ->del('opcua.batch')
            ->exec()[0];

        $rows = array_map(fn(string $j) => json_decode($j, true), $items);
        $this->em->getConnection()->executeStatement(
            "INSERT INTO plc_readings (node_id, value, source_at) VALUES " . /* ... */
        );
    }
}
```
<!-- @endcode-block -->

…with a scheduled command to drain the buffer every 5 seconds
even if the threshold isn't hit. See
[Recipes · Persistent tag history](../recipes/persistent-tag-history.md).

## Idempotency

If a handler can be retried, it must be idempotent. Use natural
keys for upserts:

<!-- @code-block language="php" label="upsert" -->
```php
$em->getConnection()->executeStatement(
    "INSERT INTO plc_readings (node_id, source_at, value)
     VALUES (:n, :a, :v)
     ON CONFLICT (node_id, source_at) DO UPDATE SET value = EXCLUDED.value",
    ['n' => $msg->nodeId, 'a' => $msg->at, 'v' => $msg->value],
);
```
<!-- @endcode-block -->

`source_at` is naturally unique per node.

## Monitoring

| Metric                            | Where                                  | Alert threshold              |
| --------------------------------- | -------------------------------------- | ---------------------------- |
| Queue depth                        | `messenger:stats` or Redis             | > 1000 messages              |
| Failed messages                    | `failed_messages` table                 | > 10 / hour                  |
| Worker memory                       | systemd MemoryCurrent                   | Above your normal +50%       |
| Worker restart count                | systemd journal                         | > 5 in 10 minutes            |

Wire into Prometheus / Datadog / your monitoring of choice.

## Where to read next

- [Integrations · Messenger](../integrations/messenger.md) —
  Messenger configuration in depth.
- [Logging](../observability/logging.md) — what to log about
  worker runtime.
- [Recipes · Persistent tag history](../recipes/persistent-tag-history.md) —
  the canonical batched-persistence pattern.
