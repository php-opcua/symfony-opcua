---
eyebrow: 'Docs · Integrations'
lede:    'Symfony Messenger for OPC UA work — transports, async listeners, retry strategies, worker tuning. Complete end-to-end fleet-sampling pattern.'

see_also:
  - { href: '../events/async-listeners-with-messenger.md', meta: '5 min' }
  - { href: './doctrine.md',                                meta: '5 min' }
  - { href: '../recipes/persistent-tag-history.md',         meta: '7 min' }

prev: { label: 'Kernel tests',  href: '../testing/kernel-tests.md' }
next: { label: 'Mercure',       href: './mercure.md' }
---

# Messenger

OPC UA work that's bursty (fleet sampling, large history reads,
recipe loads) belongs on Messenger. Async transports, retries,
and worker tuning are the standard tools.

## Transport topology

Different OPC UA workloads have different SLAs. Separate them:

| Transport          | What's on it                                | Priority             |
| ------------------ | ------------------------------------------- | -------------------- |
| `opcua_control`     | Setpoint writes, method calls                | High — operator-visible |
| `opcua_data`        | Tag reads, periodic samples                  | Normal               |
| `opcua_history`     | History reads, bulk samples                  | Low                  |
| `opcua_alarms`      | Alarm-event processing                        | High                 |

Separating prevents slow history reads from blocking setpoint
changes.

## Config

<!-- @code-block language="text" label="config/packages/messenger.yaml" -->
```text
framework:
    messenger:
        default_bus: messenger.bus.default

        failure_transport: failed

        transports:
            opcua_control:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%?queue_name=opcua_control'
                retry_strategy:
                    max_retries: 1     # control ops aren't idempotent
            opcua_data:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%?queue_name=opcua_data'
                retry_strategy:
                    max_retries: 3
                    multiplier: 2
            opcua_history:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%?queue_name=opcua_history'
                retry_strategy:
                    max_retries: 3
                    delay: 5000
            opcua_alarms:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%?queue_name=opcua_alarms'
                retry_strategy:
                    max_retries: 5
            failed:
                dsn: 'doctrine://default?queue_name=failed'

        routing:
            App\Message\WriteSetpoint:     opcua_control
            App\Message\LoadRecipe:         opcua_control
            App\Message\SamplePlc:          opcua_data
            App\Message\StoreReading:       opcua_data
            App\Message\FetchDailyHistory:  opcua_history
            App\Message\RecordAlarm:        opcua_alarms
```
<!-- @endcode-block -->

`.env`:

<!-- @code-block language="bash" label="DSN" -->
```bash
# Redis — recommended
MESSENGER_TRANSPORT_DSN=redis://localhost:6379/messages

# Or Doctrine
# MESSENGER_TRANSPORT_DSN=doctrine://default
```
<!-- @endcode-block -->

## End-to-end — fleet sampler

A scheduled job dispatches one sample message per PLC; handlers
run on the `opcua_data` queue.

### The PlcUnit entity (Doctrine)

<!-- @code-block language="php" label="src/Entity/PlcUnit.php" -->
```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class PlcUnit
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    public string $serial;

    #[ORM\Column(type: 'string', length: 255)]
    public string $endpoint;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    public ?string $username = null;

    #[ORM\Column(type: 'boolean')]
    public bool $active = true;

    public function toConnectionConfig(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'username' => $this->username,
            // Password fetched from vault by serial
            'timeout'  => 8.0,
        ];
    }
}
```
<!-- @endcode-block -->

### The message

<!-- @code-block language="php" label="src/Message/SamplePlc.php" -->
```php
namespace App\Message;

final readonly class SamplePlc
{
    /**
     * @param string[] $nodeIds
     */
    public function __construct(
        public string $serial,
        public array $nodeIds,
    ) {}
}
```
<!-- @endcode-block -->

### The handler

<!-- @code-block language="php" label="src/MessageHandler/SamplePlcHandler.php" -->
```php
namespace App\MessageHandler;

use App\Entity\PlcReading;
use App\Entity\PlcUnit;
use App\Message\SamplePlc;
use App\Repository\PlcUnitRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\InactiveSessionException;
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

#[AsMessageHandler]
final class SamplePlcHandler
{
    public function __construct(
        private OpcuaManager $opcua,
        private PlcUnitRepository $repo,
        private EntityManagerInterface $em,
    ) {}

    public function __invoke(SamplePlc $message): void
    {
        $unit = $this->repo->findOneBy(['serial' => $message->serial]);
        if ($unit === null || !$unit->active) {
            return;
        }

        try {
            $client = $this->opcua->connectTo(
                $unit->endpoint,
                $unit->toConnectionConfig(),
                as: "fleet:{$message->serial}",
            );

            $builder = $client->readBuilder();
            foreach ($message->nodeIds as $node) {
                $builder->node($node);
            }
            $results = $builder->executeMany();
        } catch (ConnectionException | InactiveSessionException $e) {
            throw new RecoverableMessageHandlingException(
                "PLC {$message->serial} unreachable", 0, $e,
            );
        }

        foreach ($message->nodeIds as $i => $node) {
            $reading = (new PlcReading())
                ->setPlcSerial($message->serial)
                ->setNodeId($node)
                ->setValue($results[$i]->getValue())
                ->setStatusCode($results[$i]->statusCode)
                ->setSourceAt($results[$i]->sourceTimestamp ?? new \DateTimeImmutable());

            $this->em->persist($reading);
        }
        $this->em->flush();
    }
}
```
<!-- @endcode-block -->

The handler decides what's retryable: transport errors throw
`RecoverableMessageHandlingException` (retry per strategy);
logic errors propagate as-is (sent to failure transport).

### Scheduling the dispatch

<!-- @code-block language="php" label="src/Scheduler/PlcFleetSchedule.php" -->
```php
namespace App\Scheduler;

use App\Message\SamplePlc;
use App\Repository\PlcUnitRepository;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('plc-fleet')]
final class PlcFleetSchedule implements ScheduleProviderInterface
{
    public function __construct(private PlcUnitRepository $repo) {}

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();
        $nodes = ['ns=2;s=Speed', 'ns=2;s=Temperature', 'ns=2;s=Pressure'];

        foreach ($this->repo->findActive() as $i => $unit) {
            // Stagger by serial-hash modulo 60 — spreads over the minute
            $offset = abs(crc32($unit->serial)) % 60;
            $schedule->add(
                RecurringMessage::cron("$offset * * * *", new SamplePlc($unit->serial, $nodes))
            );
        }

        return $schedule;
    }
}
```
<!-- @endcode-block -->

Each PLC gets sampled once a minute, spread over the 60-second
window.

### Running workers

<!-- @code-block language="bash" label="systemd template" -->
```bash
# /etc/systemd/system/messenger-opcua-data@.service
[Service]
ExecStart=/usr/bin/php /var/www/html/bin/console messenger:consume opcua_data \
    --time-limit=3600 --memory-limit=512M --limit=10000

[Install]
WantedBy=multi-user.target
```
<!-- @endcode-block -->

<!-- @code-block language="bash" label="terminal" -->
```bash
sudo systemctl enable --now messenger-opcua-data@{1..4}
```
<!-- @endcode-block -->

4 parallel workers on `opcua_data`.

### Running the scheduler

<!-- @code-block language="bash" label="scheduler worker" -->
```bash
php bin/console messenger:consume scheduler_plc_fleet --time-limit=3600
```
<!-- @endcode-block -->

Dispatches messages at the scheduled cadence.

## Watching Messenger

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console messenger:stats
php bin/console messenger:failed:show
php bin/console messenger:failed:retry
```
<!-- @endcode-block -->

For a UI, install
[`symfony/messenger-monitor`](https://github.com/zenstruck/messenger-monitor) —
a Symfony bundle that adds a dashboard.

## Per-message retry tuning

Override the global retry strategy in the message:

<!-- @code-block language="php" label="per-message" -->
```php
use Symfony\Component\Messenger\Stamp\TransportConfigurationStamp;

$bus->dispatch(
    new SamplePlc($serial, $nodes),
    [new TransportConfigurationStamp([
        'retry_strategy.max_retries' => 5,
    ])],
);
```
<!-- @endcode-block -->

Useful for known-flaky destinations.

## Failure handling

| Failure                                                | Effect                                |
| ------------------------------------------------------ | ------------------------------------- |
| `RecoverableMessageHandlingException`                   | Retried per strategy                  |
| `UnrecoverableMessageHandlingException`                 | Sent to `failed` immediately           |
| Other exception                                         | Retried per strategy                  |
| Retry exhausted                                         | Sent to `failed`                       |

For OPC UA writes — **don't retry**. Set `max_retries: 0` on
`opcua_control`.

## Per-handler logging

<!-- @code-block language="php" label="WithMonologChannel" -->
```php
use Monolog\Attribute\WithMonologChannel;

#[AsMessageHandler]
#[WithMonologChannel('opcua_data')]
final class SamplePlcHandler
{
    public function __construct(
        private OpcuaManager $opcua,
        private LoggerInterface $logger,    // Auto-injected from opcua_data channel
    ) {}
}
```
<!-- @endcode-block -->

The handler's own logs go to the `opcua_data` Monolog channel.

## Tuning throughput

| Subscription rate    | Per-job time     | Workers per queue          |
| -------------------- | ---------------- | -------------------------- |
| 100 msg / sec         | 5 ms             | 1-2                        |
| 1 000 msg / sec        | 10 ms            | 4-8                        |
| 5 000 msg / sec        | 20 ms            | 16-32 (consider batching)  |

Watch `messenger:stats` and Redis `INFO` for backlog growth.

## Where to read next

- [Mercure](./mercure.md) — pushing handler results to the
  browser.
- [Doctrine](./doctrine.md) — the persistence side.
- [Recipes · Persistent tag history](../recipes/persistent-tag-history.md) —
  full canonical pattern.
