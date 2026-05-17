---
eyebrow: 'Docs · Integrations'
lede:    'Symfony Notifier for alerts from OPC UA events — Slack, email, SMS, Discord. Channel policy, severity routing, on-call rotation.'

see_also:
  - { href: '../events/alarm-events.md',          meta: '5 min' }
  - { href: '../events/connection-events.md',     meta: '5 min' }
  - { href: '../recipes/alarm-routing.md',        meta: '7 min' }

prev: { label: 'Console and scheduler', href: './console-and-scheduler.md' }
next: { label: 'OpcuaManager API',      href: '../reference/opcua-manager-api.md' }
---

# Notifier

Symfony Notifier turns plant-floor events into messages across
channels — Slack, mail, SMS, Discord, MS Teams, database. The
OPC UA bundle's events make this a small bridge.

## Install

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require symfony/notifier

# Channel-specific transports
composer require symfony/slack-notifier
composer require symfony/twilio-notifier
composer require symfony/discord-notifier
```
<!-- @endcode-block -->

## Configure transports

<!-- @code-block language="text" label="config/packages/notifier.yaml" -->
```text
framework:
    notifier:
        chatter_transports:
            slack:    '%env(SLACK_DSN)%'
            discord:  '%env(DISCORD_DSN)%'
        texter_transports:
            twilio:   '%env(TWILIO_DSN)%'
        channel_policy:
            # mapping importance → channels
            urgent: ['email', 'sms', 'chat/slack']
            high:   ['email', 'chat/slack']
            medium: ['email']
            low:    ['email']
        admin_recipients:
            - { email: ops@example.com, phone: '+15551234567' }
```
<!-- @endcode-block -->

`.env`:

<!-- @code-block language="bash" label="DSNs" -->
```bash
SLACK_DSN=slack://TOKEN@default?channel=ops-alerts
DISCORD_DSN=discord://TOKEN@CHANNEL_ID
TWILIO_DSN=twilio://ACCOUNT_SID:AUTH_TOKEN@default?from=PHONE
```
<!-- @endcode-block -->

## A Notification class

<!-- @code-block language="php" label="src/Notification/PlcAlarmNotification.php" -->
```php
namespace App\Notification;

use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Notification\ChatNotificationInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Notification\SmsNotificationInterface;
use Symfony\Component\Notifier\Recipient\RecipientInterface;

final class PlcAlarmNotification extends Notification implements
    ChatNotificationInterface,
    SmsNotificationInterface
{
    public function __construct(
        public readonly string $source,
        public readonly int $severity,
        public readonly string $message,
    ) {
        parent::__construct("[PLC alarm] $source");
        $this->content("$message (severity $severity)");
        $this->importance($this->mapImportance());
    }

    private function mapImportance(): string
    {
        return match (true) {
            $this->severity >= 900 => Notification::IMPORTANCE_URGENT,
            $this->severity >= 700 => Notification::IMPORTANCE_HIGH,
            $this->severity >= 400 => Notification::IMPORTANCE_MEDIUM,
            default                  => Notification::IMPORTANCE_LOW,
        };
    }

    public function asChatMessage(RecipientInterface $r, ?string $transport = null): ?ChatMessage
    {
        return new ChatMessage(
            sprintf("⚠️ PLC alarm — %s (severity %d): %s", $this->source, $this->severity, $this->message),
        );
    }

    public function asSmsMessage(RecipientInterface $r, ?string $transport = null): ?SmsMessage
    {
        return new SmsMessage(
            $r->getPhone(),
            sprintf("PLC sev=%d %s: %s", $this->severity, $this->source, $this->message),
        );
    }
}
```
<!-- @endcode-block -->

## The event listener

<!-- @code-block language="php" label="src/EventListener/RouteAlarmToOps.php" -->
```php
namespace App\EventListener;

use App\Notification\PlcAlarmNotification;
use PhpOpcua\Client\Events\AlarmActivated;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;

final class RouteAlarmToOps
{
    public function __construct(private NotifierInterface $notifier) {}

    #[AsEventListener]
    public function __invoke(AlarmActivated $event): void
    {
        if (($event->severity ?? 0) < 400) {
            return;
        }

        $this->notifier->send(
            new PlcAlarmNotification(
                source:   $event->sourceName ?? 'unknown',
                severity: $event->severity ?? 0,
                message:  $event->message ?? '',
            ),
            new Recipient('ops@example.com', '+15551234567'),
        );
    }
}
```
<!-- @endcode-block -->

`Notifier::send()` consults the `channel_policy` and routes
based on `importance`.

## Per-recipient routing

For routing different alerts to different teams:

<!-- @code-block language="php" label="per-team routing" -->
```php
$this->notifier->send($notif, ...$this->recipientsForSource($event->sourceName));

// ...

private function recipientsForSource(string $source): array
{
    return match (true) {
        str_starts_with($source, 'Line-A') => [
            new Recipient('line-a-team@example.com', '+15551111111'),
        ],
        str_starts_with($source, 'Line-B') => [
            new Recipient('line-b-team@example.com', '+15552222222'),
        ],
        default => [
            new Recipient('ops@example.com', '+15551234567'),
        ],
    };
}
```
<!-- @endcode-block -->

For dynamic team rosters, look up in a `Team` Doctrine entity.

## On-call rotation

For rotating on-call:

<!-- @code-block language="php" label="dynamic on-call" -->
```php
use App\Repository\OncallRosterRepository;

#[AsEventListener]
public function __invoke(AlarmActivated $event): void
{
    if (($event->severity ?? 0) < 800) return;

    $oncall = $this->oncallRepo->current();
    foreach ($oncall as $person) {
        $this->notifier->send(
            new PlcAlarmNotification($event->sourceName, $event->severity, $event->message),
            new Recipient($person->getEmail(), $person->getPhone()),
        );
    }
}
```
<!-- @endcode-block -->

`OncallRosterRepository::current()` is your domain — a query
like `WHERE start_at <= NOW() AND end_at >= NOW()`.

## Connection-failure alerts

<!-- @code-block language="php" label="connection alerts" -->
```php
namespace App\EventListener;

use App\Notification\PlcConnectionLostNotification;
use PhpOpcua\Client\Events\ConnectionFailed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class NotifyConnectionLost
{
    public function __construct(
        private NotifierInterface $notifier,
        private CacheInterface $cache,
    ) {}

    #[AsEventListener]
    public function __invoke(ConnectionFailed $event): void
    {
        // Throttle — one per connection per 10 minutes
        $key = "conn-fail.{$event->connection}";
        $blocked = $this->cache->get($key, function (ItemInterface $i) {
            $i->expiresAfter(600);
            return false;
        });
        if ($blocked) return;

        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $i) {
            $i->expiresAfter(600);
            return true;
        });

        $this->notifier->send(
            new PlcConnectionLostNotification($event->connection, $event->reason, $event->message),
            new Recipient('ops@example.com', '+15551234567'),
        );
    }
}
```
<!-- @endcode-block -->

Throttling is essential — flapping connections fire many events
per minute.

## All-clear notification

<!-- @code-block language="php" label="reconnect notice" -->
```php
namespace App\EventListener;

use App\Notification\PlcReconnectedNotification;
use PhpOpcua\Client\Events\Reconnected;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Cache\CacheInterface;

final class NotifyReconnected
{
    public function __construct(private $notifier, private CacheInterface $cache) {}

    #[AsEventListener]
    public function __invoke(Reconnected $event): void
    {
        $key = "conn-fail.{$event->connection}";
        $hasOpenAlert = $this->cache->getItem($key)->isHit();

        if ($hasOpenAlert) {
            $this->cache->delete($key);
            $this->notifier->send(
                new PlcReconnectedNotification($event->connection, $event->downSeconds),
                new Recipient('ops@example.com'),
            );
        }
    }
}
```
<!-- @endcode-block -->

Only fires "all clear" if we previously fired "lost".

## Database channel

For an audit trail of all notifications:

<!-- @code-block language="text" label="add db channel" -->
```text
framework:
    notifier:
        chatter_transports:
            slack: '%env(SLACK_DSN)%'
        # ...
        channel_policy:
            urgent: ['email', 'sms', 'chat/slack', 'database']
```
<!-- @endcode-block -->

You'd add a `database://` transport via a custom factory — see
the [Symfony Notifier docs](https://symfony.com/doc/current/notifier.html).

## Where to read next

You've finished **Integrations**. Next:
[Reference · OpcuaManager API](../reference/opcua-manager-api.md).
