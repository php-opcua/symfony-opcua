---
eyebrow: 'Docs · Events'
lede:    'Connection lifecycle events — open, close, failure, recovery. Fired in both direct and managed mode. Useful for audit logs, ops alerts, dashboard tiles.'

see_also:
  - { href: './overview.md',                       meta: '5 min' }
  - { href: '../using-the-client/connection-lifecycle.md', meta: '5 min' }
  - { href: '../observability/logging.md',         meta: '5 min' }

prev: { label: 'Events overview', href: './overview.md' }
next: { label: 'Data events',     href: './data-events.md' }
---

# Connection events

Four events fire on connection lifecycle. They work in **both
direct and managed mode** — from the bundle's connection layer,
not from subscriptions.

## ConnectionEstablished

Fires the first time a connection produces a successful operation.

| Field            | Type    | Meaning                                          |
| ---------------- | ------- | ------------------------------------------------ |
| `connection`      | string  | `'plc-line-a'` or hashed key for ad-hoc           |
| `endpoint`        | string  | `opc.tcp://...`                                  |
| `managed`         | bool    | `true` in managed mode                            |
| `sessionId`       | ?string | Server-side session ID (managed mode)             |
| `serverProduct`   | ?string | E.g. `open62541`, `UA-.NETStandard`               |

Listener example:

<!-- @code-block language="php" label="src/EventListener/LogConnections.php" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Events\ConnectionEstablished;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class LogConnections
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.opcua')]
        private LoggerInterface $logger,
    ) {}

    #[AsEventListener]
    public function __invoke(ConnectionEstablished $event): void
    {
        $this->logger->info("OPC UA connected: {$event->connection}", [
            'endpoint' => $event->endpoint,
            'managed'  => $event->managed,
            'server'   => $event->serverProduct,
        ]);
    }
}
```
<!-- @endcode-block -->

`#[Autowire]` is Symfony's way to inject a specific Monolog
channel into a service.

## ConnectionLost

Fires on explicit `disconnect()`, `disconnectAll()`, or worker
shutdown.

| Field        | Type    | Meaning                                              |
| ------------ | ------- | ---------------------------------------------------- |
| `connection`  | string  | Name                                                  |
| `endpoint`    | string  | URL                                                   |
| `reason`      | string  | `'explicit'`, `'shutdown'`, `'error'`                   |

A `reason` of `'error'` typically follows `ConnectionFailed`.

## ConnectionFailed

Fires when an open attempt fails or an established connection
breaks.

| Field            | Type     | Meaning                                            |
| ---------------- | -------- | -------------------------------------------------- |
| `connection`      | string   | Name                                                |
| `endpoint`        | string   | URL                                                  |
| `reason`          | string   | Exception class name                                 |
| `message`         | string   | Exception message                                    |
| `duringOperation` | bool     | `false`: at open. `true`: during use                |

Common `reason` values:

| reason                     | Cause                                            |
| -------------------------- | ------------------------------------------------ |
| `ConnectionException`       | TCP failure, server unreachable                  |
| `PolicyException`           | Security policy mismatch                          |
| `CertificateException`      | Server cert rejected / expired                    |
| `InactiveSessionException`  | Server-side timeout                                |
| `AuthenticationException`   | Wrong username/password                            |

Critical alarms typically route on this:

<!-- @code-block language="php" label="src/EventListener/AlertOpsTeam.php" -->
```php
namespace App\EventListener;

use App\Notification\PlcConnectionLostNotification;
use PhpOpcua\Client\Events\ConnectionFailed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;

final class AlertOpsTeam
{
    public function __construct(private NotifierInterface $notifier) {}

    #[AsEventListener]
    public function __invoke(ConnectionFailed $event): void
    {
        if (!str_starts_with($event->connection, 'plc-line-')) {
            return;
        }

        $notif = new PlcConnectionLostNotification(
            connection: $event->connection,
            reason:     $event->reason,
            message:    $event->message,
        );

        $this->notifier->send(
            $notif,
            new Recipient('ops@example.com', '+15551234567'),
        );
    }
}
```
<!-- @endcode-block -->

See [Integrations · Notifier](../integrations/notifier.md).

## Reconnected

Fires when a previously-failed connection successfully reopens.

| Field          | Type    | Meaning                                  |
| -------------- | ------- | ---------------------------------------- |
| `connection`    | string  | Name                                      |
| `endpoint`      | string  | URL                                        |
| `downSeconds`   | int     | Seconds since the disconnect              |

The "all clear" complement to `ConnectionFailed`:

<!-- @code-block language="php" label="NoteReconnect listener" -->
```php
#[AsEventListener]
public function __invoke(Reconnected $event): void
{
    $this->notifier->send(
        new PlcReconnectedNotification($event->connection, $event->downSeconds),
        new Recipient('ops@example.com'),
    );
}
```
<!-- @endcode-block -->

Together with `ConnectionFailed`, you build "PLC was down for
N minutes" timeline events.

## Patterns

### Audit log

A single subscriber for the whole lifecycle:

<!-- @code-block language="php" label="src/EventListener/WriteConnectionAudit.php" -->
```php
namespace App\EventListener;

use App\Entity\ConnectionAudit;
use Doctrine\ORM\EntityManagerInterface;
use PhpOpcua\Client\Events\{ConnectionEstablished, ConnectionFailed, ConnectionLost, Reconnected};
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class WriteConnectionAudit implements EventSubscriberInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ConnectionEstablished::class => 'logEstablished',
            ConnectionLost::class        => 'logLost',
            ConnectionFailed::class      => 'logFailed',
            Reconnected::class           => 'logReconnected',
        ];
    }

    public function logEstablished(ConnectionEstablished $e): void
    {
        $this->store($e->connection, $e->endpoint, 'connected', null);
    }
    public function logLost(ConnectionLost $e): void
    {
        $this->store($e->connection, $e->endpoint, 'disconnected', $e->reason);
    }
    public function logFailed(ConnectionFailed $e): void
    {
        $this->store($e->connection, $e->endpoint, 'failed', $e->message);
    }
    public function logReconnected(Reconnected $e): void
    {
        $this->store($e->connection, $e->endpoint, 'reconnected', "down for {$e->downSeconds}s");
    }

    private function store(string $connection, string $endpoint, string $state, ?string $detail): void
    {
        $entry = (new ConnectionAudit())
            ->setConnection($connection)
            ->setEndpoint($endpoint)
            ->setState($state)
            ->setDetail($detail)
            ->setLoggedAt(new \DateTimeImmutable());

        $this->em->persist($entry);
        $this->em->flush();
    }
}
```
<!-- @endcode-block -->

### Dashboard tile via Symfony cache

<!-- @code-block language="php" label="src/EventListener/TrackConnectionState.php" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Events\{ConnectionEstablished, ConnectionFailed, ConnectionLost, Reconnected};
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class TrackConnectionState implements EventSubscriberInterface
{
    public function __construct(private CacheInterface $cache) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ConnectionEstablished::class => 'up',
            Reconnected::class           => 'up',
            ConnectionLost::class        => 'down',
            ConnectionFailed::class      => 'failed',
        ];
    }

    public function up($event): void     { $this->state($event->connection, 'up'); }
    public function down($event): void   { $this->state($event->connection, 'down'); }
    public function failed($event): void { $this->state($event->connection, 'failed'); }

    private function state(string $connection, string $value): void
    {
        $this->cache->delete("opcua.state.{$connection}");
        $this->cache->get("opcua.state.{$connection}", function (ItemInterface $i) use ($value) {
            $i->expiresAfter(3600);
            return ['state' => $value, 'at' => (new \DateTimeImmutable())->format('c')];
        });
    }
}
```
<!-- @endcode-block -->

A `/plant/status` controller reads from cache for a real-time
tile.

### Flap detection

<!-- @code-block language="php" label="src/EventListener/DetectFlapping.php" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Events\Reconnected;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class DetectFlapping
{
    public function __construct(
        private CacheInterface $cache,
        #[Autowire(service: 'monolog.logger.opcua')]
        private LoggerInterface $logger,
    ) {}

    #[AsEventListener]
    public function __invoke(Reconnected $event): void
    {
        $key = "opcua.flap.{$event->connection}";
        $count = $this->cache->get($key, function (ItemInterface $i) {
            $i->expiresAfter(600);    // 10 minutes
            return 0;
        });
        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $i) use ($count) {
            $i->expiresAfter(600);
            return $count + 1;
        });

        if ($count + 1 >= 5) {
            $this->logger->warning("Flapping: {$event->connection}");
            $this->cache->delete($key);
        }
    }
}
```
<!-- @endcode-block -->

## Performance notes

Connection events fire once per lifecycle event — inherently
low-volume. Queue them only if listeners do **expensive** work
(Slack notifications, external HTTP).

A simple log-channel listener can stay synchronous —
microseconds.

## Where to read next

- [Data events](./data-events.md) — high-frequency subscription
  events.
- [Alarm events](./alarm-events.md) — alarm pipeline.
- [Notifier integration](../integrations/notifier.md) — the
  routing surface for connection alerts.
