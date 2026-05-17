---
eyebrow: 'Docs · Operations'
lede:    'Subscriptions stream value changes. Direct mode runs the publish loop in PHP; managed mode delivers via Symfony''s EventDispatcher. Same surface, different ops profile.'

see_also:
  - { href: '../events/data-events.md',                  meta: '6 min' }
  - { href: '../session-manager/auto-publish.md',        meta: '5 min' }
  - { href: '../recipes/mercure-realtime-dashboard.md',  meta: '7 min' }

prev: { label: 'Method calls', href: './method-calls.md' }
next: { label: 'History',      href: './history.md' }
---

# Subscriptions

A subscription tells the OPC UA server: *send notifications when
this value changes or this event fires*. The bundle supports
two ways of consuming the stream.

## Two modes at a glance

| Aspect                          | Direct mode                              | Managed mode (with auto-publish)               |
| ------------------------------- | ----------------------------------------- | ---------------------------------------------- |
| Who runs the publish loop?      | Your PHP process                          | The daemon                                      |
| Where do callbacks fire?        | PHP process                               | PSR-14 → Symfony EventDispatcher                |
| Best for                        | Console / Messenger long-runners          | Real-time UIs, broadcasting, declarative subs   |
| Survives FPM request boundary?  | No                                        | Yes — daemon holds the subscription             |
| Setup complexity                | Low                                       | Medium (daemon + Supervisor + dispatcher wire)  |

## Direct mode subscription

A Symfony console command that runs a subscription loop:

<!-- @code-block language="php" label="src/Command/WatchSpeedCommand.php" -->
```php
namespace App\Command;

use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:plc:watch-speed')]
final class WatchSpeedCommand extends Command
{
    public function __construct(private readonly OpcuaManager $opcua)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->opcua->connect();

        $sub = $client->subscribe(
            publishingInterval: 500,
            onData: function (string $nodeId, DataValue $dv) use ($output) {
                $output->writeln("$nodeId = {$dv->getValue()}");
            },
        );

        $sub->monitor('ns=2;s=Speed');

        $sub->run();    // blocks until Ctrl+C
        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Run under Supervisor in production — see
[Session manager · Production supervisor](../session-manager/production-supervisor.md).

## Managed-mode subscription

The daemon holds the subscription; Symfony reacts to events.

### Subscribe from any service

<!-- @code-block language="php" label="subscribe imperatively" -->
```php
final class StartMonitoringService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function start(): void
    {
        $sub = $this->opcua->connect()->subscribe(publishingInterval: 500);

        $sub->monitor('ns=2;s=Speed');
        $sub->monitor('ns=2;s=Temperature');

        // Done — the daemon keeps the subscription alive
    }
}
```
<!-- @endcode-block -->

### Or declaratively in YAML

<!-- @code-block language="text" label="declarative subs" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        auto_publish: true
    connections:
        default:
            endpoint: '%env(OPCUA_ENDPOINT)%'
            auto_connect: true
            subscriptions:
                -
                    publishing_interval: 500.0
                    monitored_items:
                        - { node_id: 'ns=2;s=Speed',       client_handle: 1 }
                        - { node_id: 'ns=2;s=Temperature', client_handle: 2 }
```
<!-- @endcode-block -->

On daemon start, the subscription is created automatically.

### Listen with `#[AsEventListener]`

<!-- @code-block language="php" label="src/EventListener/StoreSpeed.php" -->
```php
namespace App\EventListener;

use App\Entity\PlcReading;
use Doctrine\ORM\EntityManagerInterface;
use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class StoreSpeed
{
    public function __construct(private EntityManagerInterface $em) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        if ($event->nodeId !== 'ns=2;s=Speed') return;

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

See [Events · Data events](../events/data-events.md).

## Subscription parameters

<!-- @code-block language="php" label="parameters" -->
```php
$sub = $client->subscribe(
    publishingInterval: 500,         // ms — server publish rate
    lifetimeCount: 60,               // publishes before tear-down
    keepAliveCount: 10,              // keep-alives without data
    maxNotificationsPerPublish: 100, // batch cap
    priority: 0,                     // client-side priority
);
```
<!-- @endcode-block -->

For most cases, defaults are fine.

## Monitoring parameters

<!-- @code-block language="php" label="monitor params" -->
```php
$sub->monitor('ns=2;s=Speed', [
    'samplingInterval'   => 250,   // ms
    'queueSize'          => 10,
    'discardOldest'      => true,
    'deadband'           => 0.5,   // suppress changes < 0.5
]);
```
<!-- @endcode-block -->

For noisy high-frequency tags, a `deadband` matched to the
engineering tolerance stops the wire from carrying noise.

## Event subscriptions

OPC UA event subscriptions track alarms / events rather than
value changes:

<!-- @code-block language="php" label="event subscription" -->
```php
$sub = $client->subscribe(publishingInterval: 1000);

$sub->monitorEvents('ns=0;i=2253', [    // Server node
    'fields' => ['EventId', 'Time', 'Severity', 'Message'],
    'filter' => 'Severity > 500',
]);
```
<!-- @endcode-block -->

In managed mode these arrive as `EventNotificationReceived` —
see [Events · Alarm events](../events/alarm-events.md).

## Lifecycle — managed mode

The subscription survives:

- HTTP request boundary.
- Symfony worker restarts.
- Application redeploys (daemon is separate).

It does **not** survive daemon restart. Recovery pattern: a
`ExecStartPost` hook on the daemon's systemd unit that re-runs
a "resubscribe" Symfony command. See
[Session manager · Auto-publish](../session-manager/auto-publish.md).

## Lifecycle — direct mode

A direct-mode subscription dies with the PHP process. `$sub->run()`
is the only thing keeping the OPC UA session alive.

Pattern: a Supervisor-managed Symfony command per subscription
target.

## Backpressure

Subscriptions can produce data faster than your app processes
it.

| Failure mode                    | Mitigation                                  |
| ------------------------------- | ------------------------------------------- |
| Server-side queue overflow      | Tune `discardOldest`, `queueSize`           |
| App-side listener blocking       | Make listeners async via Messenger          |

For non-trivial listener work, route through Messenger — see
[Events · Async listeners with Messenger](../events/async-listeners-with-messenger.md).

## Memory usage

A typical 100-tag subscription uses ~5-10 MB on top of the
worker. Memory grows linearly with monitored-item count and
`queueSize`. Set `--memory-limit` on long-running workers.

## When NOT to use subscriptions

- Reading once — use `$client->read()`.
- Reading every 5 minutes from a scheduled task — a scheduled
  command is simpler.
- Sub-50 ms latency UI — OPC UA's minimum practical publishing
  interval is ~50 ms. Tighter than that, use a different
  protocol.

## Where to read next

- [Events · Data events](../events/data-events.md) — the
  listener-side reference.
- [Recipes · Mercure real-time dashboard](../recipes/mercure-realtime-dashboard.md) —
  end-to-end real-time UI with Mercure.
- [Async listeners with Messenger](../events/async-listeners-with-messenger.md) —
  scaling the listener side.
