---
eyebrow: 'Docs · Session manager'
lede:    'Auto-publish — the bridge that turns daemon-side OPC UA subscription notifications into Symfony EventDispatcher events. The feature that makes real-time UIs from Symfony feasible.'

see_also:
  - { href: '../operations/subscriptions.md',                meta: '7 min' }
  - { href: '../events/data-events.md',                      meta: '6 min' }
  - { href: '../events/async-listeners-with-messenger.md',   meta: '5 min' }
  - { href: '../recipes/mercure-realtime-dashboard.md',       meta: '7 min' }

prev: { label: 'Starting the daemon', href: './starting-the-daemon.md' }
next: { label: 'Production supervisor', href: './production-supervisor.md' }
---

# Auto-publish

When the daemon receives subscription notifications, it
dispatches them to a PSR-14 event bus — which is **Symfony's
EventDispatcher**. So notifications arrive in your listeners as
if you dispatched them yourself.

## What it gives you

<!-- @code-block language="text" label="data flow" -->
```text
OPC UA Server              Daemon                          Symfony app
   │                        │                                  │
   │ PublishResponse        │                                  │
   ├───────────────────────►│                                  │
   │                        │ PSR-14: DataChangeReceived       │
   │                        ├─────────────────────────────────►│
   │                        │                                  │ EventDispatcher
   │                        │                                  ├─► #[AsEventListener]
```
<!-- @endcode-block -->

In application code, you write a listener:

<!-- @code-block language="php" label="src/EventListener/StoreSpeedReading.php" -->
```php
namespace App\EventListener;

use App\Entity\PlcReading;
use Doctrine\ORM\EntityManagerInterface;
use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class StoreSpeedReading
{
    public function __construct(private EntityManagerInterface $em) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        $reading = (new PlcReading())
            ->setNodeId($event->nodeId)
            ->setValue($event->dataValue->getValue())
            ->setStatusCode($event->dataValue->statusCode)
            ->setSourceAt($event->dataValue->sourceTimestamp);

        $this->em->persist($reading);
        $this->em->flush();
    }
}
```
<!-- @endcode-block -->

The daemon does the OPC UA work; your listener just reacts.

## Enabling

Two requirements:

1. `session_manager.auto_publish: true` in the bundle config.
2. The daemon launched via `php bin/console opcua:session` (so
   the Symfony EventDispatcher is wired in).

<!-- @code-block language="text" label="bundle config" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        auto_publish: '%env(bool:OPCUA_AUTO_PUBLISH)%'
```
<!-- @endcode-block -->

`.env`:

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_AUTO_PUBLISH=true
```
<!-- @endcode-block -->

That's it. Any `$opcua->subscribe()` call from application code
opts in automatically.

## Event types

| Event class                                          | OPC UA notification                          |
| ---------------------------------------------------- | -------------------------------------------- |
| `PhpOpcua\Client\Events\DataChangeReceived`           | Monitored item data change                   |
| `PhpOpcua\Client\Events\EventNotificationReceived`    | Alarm / event from event-notifier            |
| `PhpOpcua\Client\Events\SubscriptionStatusChanged`    | Server-reported subscription status change   |
| `PhpOpcua\Client\Events\AlarmActivated`                | Alarm transitioned to active                 |
| `PhpOpcua\Client\Events\AlarmAcknowledged`             | Alarm acknowledged                            |

See [Events overview](../events/overview.md) for the field
reference on each.

## Listener registration

`#[AsEventListener]` is the modern way:

<!-- @code-block language="php" label="auto-discovered" -->
```php
use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
public function __invoke(DataChangeReceived $event): void { /* ... */ }
```
<!-- @endcode-block -->

Symfony's autoconfiguration finds the attribute and registers
the listener.

Old-style `services.yaml` works too:

<!-- @code-block language="text" label="services.yaml" -->
```text
services:
    App\EventListener\StoreSpeedReading:
        tags:
            - { name: kernel.event_listener, event: 'PhpOpcua\Client\Events\DataChangeReceived' }
```
<!-- @endcode-block -->

Prefer the attribute — it lives next to the listener code.

## Subscription lifecycle in managed mode

<!-- @code-block language="php" label="end-to-end" -->
```php
// 1. Subscribe (from anywhere — controller, command, listener)
$sub = $this->opcua->connect()->subscribe(publishingInterval: 500);
$sub->monitor('ns=2;s=Speed');

// 2. Done — daemon holds the subscription, your listener gets the events.
```
<!-- @endcode-block -->

The subscription survives across requests, worker restarts, and
deploys — as long as the daemon stays up.

## Declarative subscriptions

For subscriptions you want **always running** (and recoverable
on daemon restart), declare in YAML:

<!-- @code-block language="text" label="declarative" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        auto_publish: true
    connections:
        default:
            endpoint:     '%env(OPCUA_ENDPOINT)%'
            auto_connect: true
            subscriptions:
                -
                    publishing_interval: 500.0
                    max_keep_alive_count: 10
                    monitored_items:
                        - { node_id: 'ns=2;s=Speed',        client_handle: 1 }
                        - { node_id: 'ns=2;s=Temperature',  client_handle: 2 }
                        - { node_id: 'ns=2;s=Pressure',     client_handle: 3 }
                    event_monitored_items:
                        - node_id: 'ns=0;i=2253'
                          client_handle: 100
                          select_fields: [EventId, EventType, SourceName, Time, Message, Severity]
```
<!-- @endcode-block -->

When the daemon starts, it:

1. Connects to the endpoint.
2. Creates the subscription with the specified parameters.
3. Adds all monitored items.
4. Starts publishing.

Per-connection `auto_connect: true` + at least one
`subscriptions:` entry is the recipe.

## Async listeners via Messenger

For listeners that do non-trivial work (DB writes, broadcasts,
HTTP), route through Messenger to avoid blocking the
dispatcher:

<!-- @code-block language="php" label="async listener" -->
```php
namespace App\EventListener;

use App\Message\StoreReading;
use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

final class DispatchDataChange
{
    public function __construct(private MessageBusInterface $bus) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        $this->bus->dispatch(new StoreReading(
            nodeId:     $event->nodeId,
            value:      $event->dataValue->getValue(),
            statusCode: $event->dataValue->statusCode,
            at:         $event->dataValue->sourceTimestamp,
        ));
    }
}
```
<!-- @endcode-block -->

See [Events · Async listeners with Messenger](../events/async-listeners-with-messenger.md).

## Broadcasting via Mercure

Pair with Symfony's Mercure component for real-time browser
updates:

<!-- @code-block language="php" label="Mercure broadcaster" -->
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
            topics: ['/plc/' . $event->nodeId, '/plc/all'],
            data: json_encode([
                'node_id'   => $event->nodeId,
                'value'     => $event->dataValue->getValue(),
                'good'      => $event->dataValue->statusCode === 0,
                'at'        => $event->dataValue->sourceTimestamp?->format('c'),
            ]),
        ));
    }
}
```
<!-- @endcode-block -->

See [Integrations · Mercure](../integrations/mercure.md) and
[Recipes · Mercure real-time dashboard](../recipes/mercure-realtime-dashboard.md).

## Performance

| Workload                                           | What to watch                            |
| -------------------------------------------------- | ---------------------------------------- |
| 10 monitored items, 1 Hz                            | Trivial                                  |
| 100 items, 1 Hz                                     | Watch listener time, queue if > 100 ms   |
| 1000 items, 1 Hz                                    | Queue listeners; batch DB writes         |
| 100 items at 100 Hz                                 | Rare — heavy queuing, deadband           |

The daemon handles thousands of notifications per second easily.
The bottleneck is what listeners do.

## Recovery after daemon restart

When the daemon restarts, all subscriptions are gone. If you
used declarative `auto_connect` + `subscriptions:`, they're
recreated on the next startup automatically.

For programmatic subscriptions, register a `ExecStartPost` hook
on the systemd unit that calls a Symfony command to re-subscribe:

<!-- @code-block language="text" label="systemd snippet" -->
```text
ExecStartPost=/usr/bin/php /var/www/html/bin/console app:opcua:resubscribe
```
<!-- @endcode-block -->

…with the command opening subscriptions from a Doctrine table or
hard-coded list.

## When NOT to enable auto-publish

- You don't use subscriptions (only on-demand reads/writes).
- You run the daemon as a generic IPC service and don't want
  events leaking into Symfony.
- You're testing — turn it off to isolate.

The setting is **per daemon process**, not per connection.
Selective filtering happens in listeners (by `nodeId`,
`connection`, severity, etc.).

## Where to read next

- [Events · Data events](../events/data-events.md) — listener
  reference.
- [Events · Async listeners with Messenger](../events/async-listeners-with-messenger.md) —
  scaling the listener side.
- [Recipes · Mercure real-time dashboard](../recipes/mercure-realtime-dashboard.md) —
  the canonical end-to-end pattern.
