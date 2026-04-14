# Auto-Publish & Monitoring

## Overview

Auto-publish eliminates the need for manual `publish()` loops when using OPC UA subscriptions with the session manager daemon. When enabled, the daemon automatically calls `publish()` for sessions that have active subscriptions and dispatches PSR-14 events (`DataChangeReceived`, `EventNotificationReceived`, `AlarmActivated`, etc.) through Symfony's event system. Combined with per-connection `auto_connect`, you can define your entire monitoring setup declaratively in YAML config — no application code needed.

## How It Works

```
┌──────────────────────────────────────────────────────────────────────────┐
│                       Symfony Application                                │
│                                                                          │
│  Event Listeners (via #[AsEventListener] or services.yaml)               │
│    DataChangeReceived → StoreSensorReading                               │
│    AlarmActivated → HandleAlarm                                          │
│                                                                          │
│  config/packages/php_opcua_symfony_opcua.yaml                            │
│    auto_publish: true                                                    │
│    connections:                                                          │
│      plc-1: { auto_connect: true, subscriptions: [...] }                 │
│      historian: { }  ← on-demand only                                    │
│                                                                          │
├──────────────────────────────────────────────────────────────────────────┤
│  php bin/console opcua:session                                           │
│                                                                          │
│  Daemon (ReactPHP event loop)                                            │
│    ├── Auto-connect: plc-1 → TCP → OPC UA Server                         │
│    ├── Create subscriptions + monitored items                            │
│    ├── AutoPublisher:                                                    │
│    │     ├── Timer → publish() → events dispatched                       │
│    │     ├── Acknowledgements tracked                                    │
│    │     └── Recovery on connection errors                               │
│    └── IPC socket for runtime requests                                   │
│                                                                          │
│  Events dispatched:                                                      │
│    DataChangeReceived → Symfony listener → DB / notification / etc.      │
│    AlarmActivated → Symfony listener → alert operators                   │
│    SubscriptionKeepAlive → (optional logging)                            │
└──────────────────────────────────────────────────────────────────────────┘
```

1. The daemon starts with `auto_publish: true` and a PSR-14 event dispatcher (resolved from Symfony's container)
2. Connections with `auto_connect: true` are established on the first event loop tick
3. Subscriptions and monitored items defined in `subscriptions` are created
4. The daemon's `AutoPublisher` starts a self-rescheduling timer for each session with subscriptions
5. On each publish cycle, the OPC UA client calls `publish()` which internally dispatches PSR-14 events
6. Your Symfony event listeners handle the notifications (store in DB, send alerts, broadcast to frontend, etc.)

## Configuration

### Enable auto-publish

```yaml
# config/packages/php_opcua_symfony_opcua.yaml
php_opcua_symfony_opcua:
    session_manager:
        auto_publish: true
```

Or via environment variable:

```dotenv
OPCUA_AUTO_PUBLISH=true
```

```yaml
php_opcua_symfony_opcua:
    session_manager:
        auto_publish: '%env(bool:OPCUA_AUTO_PUBLISH)%'
```

### Define auto-connect connections

```yaml
# config/packages/php_opcua_symfony_opcua.yaml
php_opcua_symfony_opcua:
    session_manager:
        enabled: true
        socket_path: '%kernel.project_dir%/var/opcua-session-manager.sock'
        timeout: 600
        auth_token: '%env(OPCUA_AUTH_TOKEN)%'
        auto_publish: true

    connections:
        plc-linea-1:
            endpoint: '%env(PLC1_ENDPOINT)%'
            username: '%env(PLC1_USER)%'
            password: '%env(PLC1_PASS)%'
            timeout: 3.0
            auto_retry: 3

            # Per-connection auto-connect (requires auto_publish to be enabled)
            auto_connect: true

            # Subscriptions to create on daemon startup
            subscriptions:
                -   publishing_interval: 500.0    # ms
                    max_keep_alive_count: 5       # reduces max publish blocking to 2.5s

                    monitored_items:
                        - { node_id: 'ns=2;s=Temperature',  client_handle: 1 }
                        - { node_id: 'ns=2;s=Pressure',     client_handle: 2 }
                        - { node_id: 'ns=2;s=MachineState', client_handle: 3 }

                    event_monitored_items:
                        -   node_id: 'i=2253'         # Server object
                            client_handle: 10
                            select_fields:
                                - EventId
                                - EventType
                                - SourceName
                                - Time
                                - Message
                                - Severity
                                - ActiveState
                                - AckedState
                                - ConfirmedState

        # Connection without auto_connect — used on-demand from application code
        historian:
            endpoint: '%env(HISTORIAN_ENDPOINT)%'
            timeout: 10.0
```

### Configuration Reference

#### Session manager keys

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `auto_publish` | `OPCUA_AUTO_PUBLISH` | `false` | Enable automatic publishing for sessions with subscriptions |

#### Per-connection keys

| Key | Default | Description |
|-----|---------|-------------|
| `auto_connect` | `false` | Auto-connect this endpoint when the daemon starts (requires `auto_publish`) |
| `subscriptions` | *(none)* | Array of subscription definitions with `monitored_items` and `event_monitored_items` |

#### Subscription definition keys

| Key | Default | Description |
|-----|---------|-------------|
| `publishing_interval` | `500.0` | Publishing interval in milliseconds |
| `lifetime_count` | `2400` | Subscription lifetime count |
| `max_keep_alive_count` | `10` | Max keep-alive count (lower = less publish blocking) |
| `max_notifications_per_publish` | `0` | Max notifications per publish (0 = unlimited) |
| `priority` | `0` | Subscription priority |

#### Monitored item keys

| Key | Default | Description |
|-----|---------|-------------|
| `node_id` | *(required)* | Node ID string (`'ns=2;s=Temperature'`, `'i=2259'`) |
| `client_handle` | `0` | Client-assigned handle (returned in notifications) |
| `sampling_interval` | `250.0` | Sampling interval in milliseconds |
| `queue_size` | `1` | Queue size for buffered notifications |
| `attribute_id` | `13` | OPC UA attribute to monitor (13 = Value) |

#### Event monitored item keys

| Key | Default | Description |
|-----|---------|-------------|
| `node_id` | *(required)* | Node ID of the event source (`'i=2253'` for Server object) |
| `client_handle` | `1` | Client-assigned handle |
| `select_fields` | `['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity']` | Event fields to select |

## Event Listeners

### Using the #[AsEventListener] Attribute

Register listeners using Symfony's `#[AsEventListener]` attribute:

```php
namespace App\EventListener;

use PhpOpcua\Client\Event\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class StoreSensorReading
{
    public function __invoke(DataChangeReceived $event): void
    {
        // $event->subscriptionId  — subscription that generated this notification
        // $event->sequenceNumber   — sequence number for acknowledgement tracking
        // $event->clientHandle     — the handle you assigned in monitored_items config
        // $event->dataValue        — DataValue with getValue(), statusCode, sourceTimestamp, serverTimestamp
    }
}
```

```php
namespace App\EventListener;

use PhpOpcua\Client\Event\EventNotificationReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class HandleEventNotification
{
    public function __invoke(EventNotificationReceived $event): void
    {
        // $event->subscriptionId
        // $event->clientHandle
        // $event->eventFields     — Variant[] with values for each select_field
    }
}
```

```php
namespace App\EventListener;

use PhpOpcua\Client\Event\AlarmActivated;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class HandleAlarm
{
    public function __invoke(AlarmActivated $event): void
    {
        // $event->subscriptionId, $event->clientHandle
        // $event->sourceName, $event->message, $event->severity, $event->eventType, $event->time
    }
}
```

### Using services.yaml Registration

Alternatively, register listeners in `config/services.yaml`:

```yaml
services:
    App\EventListener\StoreSensorReading:
        tags:
            - { name: kernel.event_listener, event: PhpOpcua\Client\Event\DataChangeReceived }

    App\EventListener\HandleAlarm:
        tags:
            - { name: kernel.event_listener, event: PhpOpcua\Client\Event\AlarmActivated }
```

### Available Events

| Event | Description |
|-------|-------------|
| `DataChangeReceived` | A monitored item's value changed |
| `EventNotificationReceived` | An OPC UA event was received |
| `AlarmActivated` | An alarm condition became active |
| `AlarmDeactivated` | An alarm condition was deactivated |
| `AlarmAcknowledged` | An alarm was acknowledged |
| `SubscriptionKeepAlive` | Server confirms the subscription is alive (no data) |

## Runtime Subscriptions

Auto-publish also works for subscriptions created at runtime via the `OpcuaManager`. Any session that gains a subscription is automatically published by the daemon:

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class RuntimeSubscriptionService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function startMonitoring(): void
    {
        $client = $this->opcua->connect('historian');

        $sub = $client->createSubscription(publishingInterval: 2000.0);
        $client->createMonitoredItems($sub->subscriptionId, [
            ['nodeId' => 'ns=4;s=HistorianStatus', 'clientHandle' => 200],
        ]);

        // No publish() loop needed — daemon handles it automatically.
        // DataChangeReceived events dispatched to your listeners.
    }
}
```

## Blocking Behavior

`Client::publish()` is a synchronous call that blocks the daemon's ReactPHP event loop until the OPC UA server responds. The maximum blocking time depends on the subscription's `maxKeepAliveCount x publishingInterval`:

| `max_keep_alive_count` | `publishing_interval` | Max block time |
|------------------------|----------------------|----------------|
| 10 (default) | 500ms | 5.0s |
| 5 | 500ms | 2.5s |
| 3 | 500ms | 1.5s |
| 5 | 250ms | 1.25s |

During the block, incoming IPC requests from your application queue up but are not lost (30s IPC timeout). In practice, when data is changing frequently, `publish()` returns almost immediately (~5ms).

**Recommendation:** use `max_keep_alive_count: 5` or lower to reduce maximum blocking time.

## Error Handling

The `AutoPublisher` handles errors automatically:

| Scenario | Behavior |
|----------|----------|
| Connection lost | Attempts reconnection + subscription transfer. Reschedules after 1s |
| Recovery failed | Auto-publish stops for that session. Logged as error |
| Transient error | Retries with 5s backoff |
| 5 consecutive errors | Auto-publish stops for that session. Logged as error |
| Handler exception | Logged, but auto-publish continues (handler bug should not kill monitoring) |

Connection recovery includes automatic `transferSubscriptions()` and `republish()` for missed notifications.

---

## Real-World Use Case: Industrial Production Line Monitoring

This example demonstrates a complete industrial monitoring setup for a bottling plant with two PLC-controlled production lines and a historian server.

### Scenario

- **Line 1** (PLC at `192.168.1.10:4840`): monitors temperature, pressure, fill level, and machine state. Needs alarms for over-temperature and over-pressure.
- **Line 2** (PLC at `192.168.1.11:4840`): monitors motor speed and vibration. Needs alerts on excessive vibration.
- **Historian** (at `192.168.1.20:4840`): queried on-demand for historical reports — not auto-connected.

### Step 1: Configuration

```dotenv
# .env
OPCUA_AUTO_PUBLISH=true
OPCUA_AUTH_TOKEN=your-secret-token-here

PLC1_ENDPOINT=opc.tcp://192.168.1.10:4840
PLC1_USER=operator
PLC1_PASS=secret123

PLC2_ENDPOINT=opc.tcp://192.168.1.11:4840

HISTORIAN_ENDPOINT=opc.tcp://192.168.1.20:4840
```

```yaml
# config/packages/php_opcua_symfony_opcua.yaml
php_opcua_symfony_opcua:
    default: line-1

    session_manager:
        enabled: true
        socket_path: '%kernel.project_dir%/var/opcua-session-manager.sock'
        timeout: 600
        auth_token: '%env(OPCUA_AUTH_TOKEN)%'
        auto_publish: '%env(bool:OPCUA_AUTO_PUBLISH)%'

    connections:
        line-1:
            endpoint: '%env(PLC1_ENDPOINT)%'
            username: '%env(PLC1_USER)%'
            password: '%env(PLC1_PASS)%'
            timeout: 3.0
            auto_retry: 3
            auto_connect: true

            subscriptions:
                -   publishing_interval: 500.0
                    max_keep_alive_count: 5

                    monitored_items:
                        - { node_id: 'ns=2;s=Line1.Temperature',  client_handle: 1 }
                        - { node_id: 'ns=2;s=Line1.Pressure',     client_handle: 2 }
                        - { node_id: 'ns=2;s=Line1.FillLevel',    client_handle: 3 }
                        - { node_id: 'ns=2;s=Line1.MachineState', client_handle: 4 }

                    event_monitored_items:
                        -   node_id: 'i=2253'
                            client_handle: 100
                            select_fields:
                                - EventId
                                - EventType
                                - SourceName
                                - Time
                                - Message
                                - Severity
                                - ActiveState
                                - AckedState
                                - ConfirmedState

        line-2:
            endpoint: '%env(PLC2_ENDPOINT)%'
            timeout: 3.0
            auto_retry: 3
            auto_connect: true

            subscriptions:
                -   publishing_interval: 250.0
                    max_keep_alive_count: 3

                    monitored_items:
                        - { node_id: 'ns=3;s=Line2.MotorSpeed', client_handle: 10, sampling_interval: 100.0 }
                        - { node_id: 'ns=3;s=Line2.Vibration',  client_handle: 11, sampling_interval: 100.0 }

        historian:
            endpoint: '%env(HISTORIAN_ENDPOINT)%'
            timeout: 10.0
```

### Step 2: Doctrine Entity and Migration

```php
// src/Entity/SensorReading.php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sensor_readings')]
#[ORM\Index(columns: ['connection', 'client_handle', 'created_at'])]
class SensorReading
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $subscriptionId;

    #[ORM\Column]
    private int $clientHandle;

    #[ORM\Column(length: 50)]
    private string $connection;

    #[ORM\Column(nullable: true)]
    private ?float $value = null;

    #[ORM\Column]
    private int $statusCode;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sourceTime = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $serverTime = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ... getters and setters
}
```

```php
// src/Entity/AlarmLog.php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'alarm_log')]
class AlarmLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $source;

    #[ORM\Column(length: 255)]
    private string $message;

    #[ORM\Column]
    private int $severity;

    #[ORM\Column(length: 50)]
    private string $state;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $eventTime = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ... getters and setters
}
```

Generate and run the migration:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

### Step 3: Event Listeners

```php
// src/EventListener/StoreSensorReading.php
namespace App\EventListener;

use App\Entity\SensorReading;
use Doctrine\ORM\EntityManagerInterface;
use PhpOpcua\Client\Event\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class StoreSensorReading
{
    private const HANDLE_TO_CONNECTION = [
        1 => 'line-1', 2 => 'line-1', 3 => 'line-1', 4 => 'line-1',
        10 => 'line-2', 11 => 'line-2',
    ];

    public function __construct(private EntityManagerInterface $em) {}

    public function __invoke(DataChangeReceived $event): void
    {
        $reading = new SensorReading();
        $reading->setSubscriptionId($event->subscriptionId);
        $reading->setClientHandle($event->clientHandle);
        $reading->setConnection(self::HANDLE_TO_CONNECTION[$event->clientHandle] ?? 'unknown');
        $reading->setValue($event->dataValue->getValue());
        $reading->setStatusCode($event->dataValue->statusCode);
        $reading->setSourceTime($event->dataValue->sourceTimestamp);
        $reading->setServerTime($event->dataValue->serverTimestamp);

        $this->em->persist($reading);
        $this->em->flush();
    }
}
```

```php
// src/EventListener/HandleAlarm.php
namespace App\EventListener;

use App\Entity\AlarmLog;
use Doctrine\ORM\EntityManagerInterface;
use PhpOpcua\Client\Event\AlarmActivated;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class HandleAlarm
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(AlarmActivated $event): void
    {
        $alarm = new AlarmLog();
        $alarm->setSource($event->sourceName);
        $alarm->setMessage($event->message);
        $alarm->setSeverity($event->severity);
        $alarm->setState('active');
        $alarm->setEventTime($event->time);

        $this->em->persist($alarm);
        $this->em->flush();

        $this->logger->warning('OPC UA Alarm: {source} — {message}', [
            'source'   => $event->sourceName,
            'message'  => $event->message,
            'severity' => $event->severity,
        ]);
    }
}
```

```php
// src/EventListener/DetectVibrationAnomaly.php
namespace App\EventListener;

use PhpOpcua\Client\Event\DataChangeReceived;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Cache\CacheInterface;

#[AsEventListener]
class DetectVibrationAnomaly
{
    private const VIBRATION_HANDLE = 11;
    private const THRESHOLD = 5.0;

    public function __construct(
        private LoggerInterface $logger,
        private CacheInterface $cache,
    ) {}

    public function __invoke(DataChangeReceived $event): void
    {
        if ($event->clientHandle !== self::VIBRATION_HANDLE) {
            return;
        }

        $vibration = $event->dataValue->getValue();

        if ($vibration > self::THRESHOLD) {
            $key = "vibration_alert_{$event->clientHandle}";
            $this->cache->get($key, function () use ($vibration) {
                $this->logger->alert('High vibration detected on Line 2: {value} mm/s', [
                    'value' => $vibration,
                ]);

                return true;
            });
        }
    }
}
```

### Step 4: Register Listeners

When using `#[AsEventListener]` attributes (shown above), Symfony auto-registers the listeners if `autoconfigure: true` is set in `config/services.yaml` (the default). No additional registration is needed.

If you prefer explicit registration via `services.yaml`:

```yaml
# config/services.yaml
services:
    App\EventListener\StoreSensorReading:
        tags:
            - { name: kernel.event_listener, event: PhpOpcua\Client\Event\DataChangeReceived }

    App\EventListener\HandleAlarm:
        tags:
            - { name: kernel.event_listener, event: PhpOpcua\Client\Event\AlarmActivated }

    App\EventListener\DetectVibrationAnomaly:
        tags:
            - { name: kernel.event_listener, event: PhpOpcua\Client\Event\DataChangeReceived }
```

### Step 5: Start the Daemon

```bash
php bin/console opcua:session
```

Output:
```
Starting OPC UA Session Manager...
+---------------------+----------------------------------------------+
| Setting             | Value                                        |
+---------------------+----------------------------------------------+
| Socket              | var/opcua-session-manager.sock               |
| Timeout             | 600s                                         |
| Auth Token          | configured                                   |
| Auto-publish        | enabled                                      |
+---------------------+----------------------------------------------+
Auto-connecting 2 connection(s): line-1, line-2
Auto-connected "line-1" (session: a1b2c3d4...)
Auto-connected "line-2" (session: e5f6g7h8...)
Auto-publish enabled
OPC UA Session Manager started on var/opcua-session-manager.sock
```

The daemon:
1. Connects to both PLCs with the configured credentials
2. Creates subscriptions with the specified publishing intervals
3. Registers all monitored items and event monitors
4. Auto-publishes and dispatches events to your Symfony listeners
5. `StoreSensorReading` stores every data change to the database
6. `HandleAlarm` logs alarms and persists them to the alarm log table
7. `DetectVibrationAnomaly` watches vibration levels and alerts on threshold breach

The `historian` connection is not auto-connected — it can be used from a controller or service whenever historical data is needed:

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class HistoryReportService
{
    public function __construct(private OpcuaManager $opcua) {}

    public function getTemperatureHistory(): array
    {
        $client = $this->opcua->connect('historian');
        $history = $client->historyReadRaw(
            'ns=4;s=Line1.Temperature',
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable('now'),
            numValuesPerNode: 1000,
        );
        $client->disconnect();

        return $history;
    }
}
```

### Step 6: Production Deployment with systemd

```ini
# /etc/systemd/system/opcua-session.service
[Unit]
Description=OPC UA Session Manager Daemon
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php /var/www/app/bin/console opcua:session
Restart=always
RestartSec=5
Environment=APP_ENV=prod
EnvironmentFile=/var/www/app/.env.local

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable opcua-session
sudo systemctl start opcua-session
sudo systemctl status opcua-session
```
