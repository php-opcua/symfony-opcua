---
eyebrow: 'Docs · Events'
lede:    'DataChangeReceived — the most common subscription event. Field reference, listener patterns, persistence to Doctrine, broadcast via Mercure, and the throughput rules.'

see_also:
  - { href: '../operations/subscriptions.md',                  meta: '7 min' }
  - { href: '../session-manager/auto-publish.md',              meta: '5 min' }
  - { href: './async-listeners-with-messenger.md',             meta: '5 min' }
  - { href: '../recipes/persistent-tag-history.md',            meta: '7 min' }

prev: { label: 'Connection events',  href: './connection-events.md' }
next: { label: 'Alarm events',       href: './alarm-events.md' }
---

# Data events

`DataChangeReceived` fires every time the daemon receives a
monitored-item value change. In a typical real-time UI it's the
event that drives everything.

<!-- @callout type="note" -->
**Managed-mode only.** Direct-mode subscriptions deliver via the
callback you pass to `subscribe()`, not as events.
<!-- @endcallout -->

## Field reference

| Field            | Type                  | Meaning                                |
| ---------------- | --------------------- | -------------------------------------- |
| `connection`      | string                | `'plc-line-a'` or hashed key            |
| `nodeId`          | string                | `'ns=2;s=Speed'`                        |
| `dataValue`       | `DataValue`            | The reading                             |
| `clientHandle`    | int                   | Monitored item handle                   |
| `subscriptionId`  | int                   | Server subscription id                  |

The `DataValue` carries `getValue()`, `statusCode`,
`sourceTimestamp`, `serverTimestamp`, `type`.

## Simple log listener

<!-- @code-block language="php" label="log listener" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Events\DataChangeReceived;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class LogDataChange
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.opcua_data')]
        private LoggerInterface $logger,
    ) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        $this->logger->info(
            sprintf('%s = %s', $event->nodeId, json_encode($event->dataValue->getValue())),
            ['status' => $event->dataValue->statusCode],
        );
    }
}
```
<!-- @endcode-block -->

## Persistence — Doctrine

For any non-trivial work, implement `ShouldQueue`-style routing
via Messenger:

<!-- @code-block language="php" label="src/Message/StoreReading.php" -->
```php
namespace App\Message;

final readonly class StoreReading
{
    public function __construct(
        public string $connection,
        public string $nodeId,
        public mixed  $value,
        public int    $statusCode,
        public ?\DateTimeImmutable $at,
    ) {}
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="src/EventListener/DispatchReading.php" -->
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

<!-- @code-block language="php" label="src/MessageHandler/StoreReadingHandler.php" -->
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
        if ($msg->statusCode !== 0) return;    // skip bad readings

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

Routing the listener through Messenger keeps the
EventDispatcher fast.

## Broadcasting via Mercure

For real-time UI updates:

<!-- @code-block language="php" label="src/EventListener/BroadcastTagUpdate.php" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class BroadcastTagUpdate
{
    public function __construct(private HubInterface $hub) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        $this->hub->publish(new Update(
            topics: [
                '/plc/' . $event->nodeId,
                '/plc/all',
            ],
            data: json_encode([
                'node_id'   => $event->nodeId,
                'value'     => $event->dataValue->getValue(),
                'good'      => $event->dataValue->statusCode === 0,
                'source_at' => $event->dataValue->sourceTimestamp?->format('c'),
            ]),
        ));
    }
}
```
<!-- @endcode-block -->

The browser subscribes to `/plc/all` for a dashboard or
`/plc/ns=2;s=Speed` for a single tile. See
[Integrations · Mercure](../integrations/mercure.md).

## Caching latest value

A pattern that pairs well with broadcasting — any reader can
query the latest value cheaply:

<!-- @code-block language="php" label="src/EventListener/CacheLatestValue.php" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class CacheLatestValue
{
    public function __construct(private CacheInterface $cache) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        $this->cache->delete('plc.latest.' . hash('xxh3', $event->nodeId));
        $this->cache->get(
            'plc.latest.' . hash('xxh3', $event->nodeId),
            function (ItemInterface $item) use ($event) {
                $item->expiresAfter(300);
                return [
                    'value'  => $event->dataValue->getValue(),
                    'status' => $event->dataValue->statusCode,
                    'at'     => $event->dataValue->sourceTimestamp?->format('c'),
                ];
            },
        );
    }
}
```
<!-- @endcode-block -->

…then in a controller:

<!-- @code-block language="php" label="controller" -->
```php
#[Route('/api/tags/{node}/latest', methods: ['GET'], requirements: ['node' => '.+'])]
public function latest(string $node, CacheInterface $cache): JsonResponse
{
    $key = 'plc.latest.' . hash('xxh3', $node);
    $data = $cache->get($key, fn() => null);

    return $this->json($data);
}
```
<!-- @endcode-block -->

No OPC UA round-trip — the cache is always within
`publishingInterval` ms of fresh.

## Threshold-based alerting

<!-- @code-block language="php" label="src/EventListener/AlertHighTemperature.php" -->
```php
namespace App\EventListener;

use App\Notification\HighTemperatureNotification;
use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class AlertHighTemperature
{
    public function __construct(
        private NotifierInterface $notifier,
        private CacheInterface $cache,
    ) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        if ($event->nodeId !== 'ns=2;s=Temperature') return;

        $temp = (float) $event->dataValue->getValue();
        if ($temp < 90.0) return;

        // Throttle: one alert per 5 minutes
        $key = "alert.fired.{$event->nodeId}";
        $alreadyFired = $this->cache->get($key, function (ItemInterface $i) {
            $i->expiresAfter(300);
            return false;
        });

        if ($alreadyFired) return;

        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $i) {
            $i->expiresAfter(300);
            return true;
        });

        $this->notifier->send(
            new HighTemperatureNotification($temp),
            new Recipient('ops@example.com'),
        );
    }
}
```
<!-- @endcode-block -->

The throttle cache stops fluctuating sensors from firing 100
alerts per second.

## KeepAliveReceived

When a subscription is idle, the server sends keep-alives. The
bundle surfaces them as `KeepAliveReceived` events — useful for
"the connection is alive" health checks:

<!-- @code-block language="php" label="keep-alive listener" -->
```php
use PhpOpcua\Client\Events\KeepAliveReceived;

#[AsEventListener]
public function __invoke(KeepAliveReceived $event): void
{
    // Touch a heartbeat timestamp
}
```
<!-- @endcode-block -->

Usually you don't need to listen — but useful if you build a
custom freshness probe.

## What NOT to do in a listener

| Avoid                                  | Why                                |
| -------------------------------------- | ---------------------------------- |
| Synchronous HTTP                       | Queue it (Messenger)                |
| Synchronous DB transactions             | Queue it                            |
| Blocking I/O                           | Queue it                            |
| Heavy computation                       | Queue it                            |
| In-memory transforms                    | ✅ fine sync                         |
| Single-row insert (low-volume)          | ✅ fine sync                         |

The rule: if it can take more than 5 ms at p99, queue it.

## Where to read next

- [Alarm events](./alarm-events.md) — alarm-specific events.
- [Async listeners with Messenger](./async-listeners-with-messenger.md) —
  scaling rules.
- [Recipes · Persistent tag history](../recipes/persistent-tag-history.md) —
  full Doctrine persistence pattern.
