---
eyebrow: 'Docs · Events'
lede:    'Every OPC UA event the bundle dispatches — connection lifecycle, data changes, alarms — through Symfony''s EventDispatcher with #[AsEventListener] support.'

see_also:
  - { href: './connection-events.md',                meta: '5 min' }
  - { href: './data-events.md',                      meta: '6 min' }
  - { href: './alarm-events.md',                     meta: '5 min' }
  - { href: './async-listeners-with-messenger.md',   meta: '5 min' }

prev: { label: 'Monitoring the daemon', href: '../session-manager/monitoring-the-daemon.md' }
next: { label: 'Connection events',     href: './connection-events.md' }
---

# Events overview

The OPC UA client emits **PSR-14 events**. The bundle wires
Symfony's `EventDispatcher` as the PSR-14 dispatcher, so every
event flows through Symfony's listener mechanism naturally.

## Event categories

| Category               | When fired                                          | Page                                |
| ---------------------- | --------------------------------------------------- | ----------------------------------- |
| Connection lifecycle    | Open, close, reconnect, error                       | [Connection events](./connection-events.md) |
| Data subscription      | Monitored item value changes                        | [Data events](./data-events.md)     |
| Alarm subscription     | OPC UA alarm / event notifications                  | [Alarm events](./alarm-events.md)   |

## The class list

| Event class                                              | Direct mode | Managed mode |
| -------------------------------------------------------- | ----------- | ------------ |
| `PhpOpcua\Client\Events\ConnectionEstablished`            | ✓           | ✓            |
| `PhpOpcua\Client\Events\ConnectionLost`                   | ✓           | ✓            |
| `PhpOpcua\Client\Events\ConnectionFailed`                 | ✓           | ✓            |
| `PhpOpcua\Client\Events\Reconnected`                      | ✓           | ✓            |
| `PhpOpcua\Client\Events\DataChangeReceived`               | -           | ✓ (auto-pub) |
| `PhpOpcua\Client\Events\EventNotificationReceived`        | -           | ✓ (auto-pub) |
| `PhpOpcua\Client\Events\AlarmActivated`                   | -           | ✓ (auto-pub) |
| `PhpOpcua\Client\Events\AlarmAcknowledged`                | -           | ✓ (auto-pub) |
| `PhpOpcua\Client\Events\SubscriptionStatusChanged`        | -           | ✓ (auto-pub) |
| `PhpOpcua\Client\Events\KeepAliveReceived`                | -           | ✓ (auto-pub) |

Connection events fire in both modes. Subscription events fire
only in managed mode with `auto_publish: true`.

The full list is ~47 events — these are the most-listened. See
the upstream `opcua-client` docs for the complete catalogue.

## Listening — `#[AsEventListener]`

The modern way:

<!-- @code-block language="php" label="listener" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class StoreSpeedReading
{
    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        // ...
    }
}
```
<!-- @endcode-block -->

Symfony's autoconfiguration finds the attribute. No
`services.yaml` entry needed.

## Listening — `EventSubscriberInterface` style

For listeners that handle multiple events:

<!-- @code-block language="php" label="subscriber" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Events\{ConnectionEstablished, ConnectionLost};
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ConnectionAuditSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ConnectionEstablished::class => 'onConnected',
            ConnectionLost::class        => 'onDisconnected',
        ];
    }

    public function onConnected(ConnectionEstablished $event): void { /* ... */ }
    public function onDisconnected(ConnectionLost $event): void { /* ... */ }
}
```
<!-- @endcode-block -->

The subscriber is autoconfigured via the `EventSubscriberInterface`
interface — no `services.yaml` work.

## Old-style YAML registration

For backwards compatibility or non-standard cases:

<!-- @code-block language="text" label="services.yaml" -->
```text
services:
    App\EventListener\StoreSpeedReading:
        tags:
            - { name: kernel.event_listener, event: 'PhpOpcua\Client\Events\DataChangeReceived', method: '__invoke' }
```
<!-- @endcode-block -->

Prefer the attribute — it lives next to the class.

## Per-connection filtering

All events carry a `connection` field — the connection name. Use
it in listeners to filter:

<!-- @code-block language="php" label="filter by connection" -->
```php
#[AsEventListener]
public function __invoke(DataChangeReceived $event): void
{
    if ($event->connection !== 'plc-line-a') {
        return;
    }
    // ...
}
```
<!-- @endcode-block -->

For ad-hoc connections, `$event->connection` is the hashed
config key, not a friendly name — use named connections when
you need to filter by connection.

## Per-node filtering

<!-- @code-block language="php" label="filter by node" -->
```php
#[AsEventListener]
public function __invoke(DataChangeReceived $event): void
{
    if ($event->nodeId !== 'ns=2;s=Speed') {
        return;
    }
    // ...
}
```
<!-- @endcode-block -->

For coarser grouping, listen on a single class and route
within:

| Listener role                       | Filter                                        |
| ----------------------------------- | --------------------------------------------- |
| Persistence — every value           | No filter                                      |
| UI broadcast — high-freq tags        | `$event->nodeId` in a whitelist                |
| Alerts — thresholds                  | `$event->nodeId` + value comparison           |

## Async listeners

For listeners that do non-trivial work, route through Messenger:

<!-- @code-block language="php" label="async listener" -->
```php
namespace App\EventListener;

use App\Message\StoreReadingMessage;
use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

final class DispatchDataChange
{
    public function __construct(private MessageBusInterface $bus) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        $this->bus->dispatch(new StoreReadingMessage(
            nodeId:     $event->nodeId,
            value:      $event->dataValue->getValue(),
            statusCode: $event->dataValue->statusCode,
            at:         $event->dataValue->sourceTimestamp,
        ));
    }
}
```
<!-- @endcode-block -->

The listener returns immediately; the work runs on a worker. See
[Async listeners with Messenger](./async-listeners-with-messenger.md).

## Mercure broadcasting

Push events to the browser:

<!-- @code-block language="php" label="Mercure listener" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class BroadcastDataChange
{
    public function __construct(private HubInterface $hub) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        $this->hub->publish(new Update(
            topics: ['/plc/' . $event->nodeId],
            data: json_encode([
                'value' => $event->dataValue->getValue(),
                'at'    => $event->dataValue->sourceTimestamp?->format('c'),
            ]),
        ));
    }
}
```
<!-- @endcode-block -->

See [Integrations · Mercure](../integrations/mercure.md).

## When events fire

| Event                                | Fires when…                                                |
| ------------------------------------ | ---------------------------------------------------------- |
| `ConnectionEstablished`               | First successful operation on a connection                  |
| `ConnectionLost`                      | Explicit `disconnect()` or shutdown                         |
| `ConnectionFailed`                    | Open attempt failed                                          |
| `Reconnected`                         | Previously failed connection re-established                  |
| `DataChangeReceived`                  | Daemon receives a publish response with data                |
| `EventNotificationReceived`           | Same, for OPC UA events                                     |
| `SubscriptionStatusChanged`            | Subscription state change server-side                       |
| `KeepAliveReceived`                   | Server keep-alive on an idle subscription                    |

All synchronous by default. Long listeners block the publish
loop — use `Messenger` for anything heavier than a few ms.

## Debug listener registration

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console debug:event-dispatcher "PhpOpcua\Client\Events\DataChangeReceived"
```
<!-- @endcode-block -->

Shows all listeners + priorities for that event class.

## Where to read next

- [Connection events](./connection-events.md) — lifecycle
  events.
- [Data events](./data-events.md) — subscription value changes.
- [Alarm events](./alarm-events.md) — alarm pipeline.
- [Async listeners with Messenger](./async-listeners-with-messenger.md) —
  scaling the listener side.
