---
eyebrow: 'Docs · Events'
lede:    'EventNotificationReceived and AlarmActivated — the alarm pipeline. Severity-based routing via the Notifier component, acknowledgement endpoints, audit chains.'

see_also:
  - { href: './data-events.md',                meta: '6 min' }
  - { href: '../operations/method-calls.md',   meta: '6 min' }
  - { href: '../recipes/alarm-routing.md',     meta: '7 min' }

prev: { label: 'Data events',                  href: './data-events.md' }
next: { label: 'Async listeners with Messenger', href: './async-listeners-with-messenger.md' }
---

# Alarm events

OPC UA distinguishes **data changes** (a value moved) from
**events** (something happened: a threshold was crossed, a
condition fired). Events arrive as `EventNotificationReceived`;
alarm transitions also fire dedicated `AlarmActivated` /
`AlarmAcknowledged` events.

<!-- @callout type="note" -->
**Managed-mode only.** Like data events, alarm events require
auto-publish.
<!-- @endcallout -->

## Subscribing to events

Subscribe to an event-notifier node:

<!-- @code-block language="php" label="event subscription" -->
```php
$sub = $this->opcua->connect()->subscribe(publishingInterval: 1000);

$sub->monitorEvents('ns=0;i=2253', [    // Server node
    'fields' => [
        'EventId', 'EventType', 'SourceNode', 'SourceName',
        'Time', 'ReceiveTime', 'LocalTime',
        'Message', 'Severity',
        'ConditionName', 'AckedState', 'ActiveState',
    ],
    'filter' => 'Severity > 500',
]);
```
<!-- @endcode-block -->

## Field reference

`EventNotificationReceived`:

| Field             | Type                  | Meaning                                 |
| ----------------- | --------------------- | --------------------------------------- |
| `connection`       | string                | Connection name                          |
| `subscriptionId`   | int                   | Subscription ID                          |
| `fields`           | array                 | Raw `{name → value}` map                |
| `eventId`          | ?string               | `bin2hex(EventId)`                      |
| `eventType`        | ?string               | NodeId of EventType                       |
| `sourceNodeId`     | ?string               | Source NodeId                            |
| `sourceName`       | ?string               | Source name                              |
| `severity`         | ?int                  | 1-1000                                   |
| `message`          | ?string               | Event message                            |
| `time`             | `?DateTimeImmutable`   | Event time                                |
| `isActive`         | ?bool                  | For alarms — active state                 |
| `isAcked`          | ?bool                  | For alarms — acked state                  |

## Listener — record alarms

<!-- @code-block language="php" label="src/EventListener/RecordAlarm.php" -->
```php
namespace App\EventListener;

use App\Message\RecordAlarm as RecordAlarmMessage;
use PhpOpcua\Client\Events\EventNotificationReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecordAlarm
{
    public function __construct(private MessageBusInterface $bus) {}

    #[AsEventListener]
    public function __invoke(EventNotificationReceived $event): void
    {
        $this->bus->dispatch(new RecordAlarmMessage(
            connection:  $event->connection,
            eventId:     $event->eventId,
            eventType:   $event->eventType,
            source:      $event->sourceName,
            severity:    $event->severity,
            message:     $event->message,
            occurredAt:  $event->time,
            isActive:    $event->isActive,
            isAcked:     $event->isAcked,
        ));
    }
}
```
<!-- @endcode-block -->

Handler persists to `PlcAlarm` Doctrine entity — see
[Recipes · Alarm routing](../recipes/alarm-routing.md).

## Severity-based routing via Notifier

<!-- @code-block language="text" label="config/notifier.yaml" -->
```text
framework:
    notifier:
        chatter_transports:
            slack: '%env(SLACK_DSN)%'
        texter_transports:
            twilio: '%env(TWILIO_DSN)%'
        channel_policy:
            urgent: ['email', 'sms']
            high:   ['email', 'chat/slack']
            low:    ['email']
```
<!-- @endcode-block -->

The Notification class:

<!-- @code-block language="php" label="src/Notification/PlcAlarmNotification.php" -->
```php
namespace App\Notification;

use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\EmailMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Notification\ChatNotificationInterface;
use Symfony\Component\Notifier\Notification\EmailNotificationInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Notification\SmsNotificationInterface;
use Symfony\Component\Notifier\Recipient\RecipientInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\Notification\ChatMessage as ChatMsgFromBuilder;

final class PlcAlarmNotification extends Notification implements
    EmailNotificationInterface,
    ChatNotificationInterface,
    SmsNotificationInterface
{
    public function __construct(
        public readonly string $source,
        public readonly int $severity,
        public readonly string $message,
    ) {
        parent::__construct("PLC alarm — sev {$severity}");
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

    public function asEmailMessage(RecipientInterface $r, ?string $transport = null): ?EmailMessage
    {
        return EmailMessage::fromNotification($this, $r);
    }

    public function asChatMessage(RecipientInterface $r, ?string $transport = null): ?ChatMessage
    {
        return new ChatMessage("PLC alarm sev={$this->severity}: {$this->source} — {$this->message}");
    }

    public function asSmsMessage(RecipientInterface $r, ?string $transport = null): ?SmsMessage
    {
        return new SmsMessage($r->getPhone(), "PLC sev={$this->severity}: {$this->source}");
    }
}
```
<!-- @endcode-block -->

The listener:

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
        if (($event->severity ?? 0) < 400) return;

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
based on the notification's importance.

## Acknowledgement endpoint

<!-- @code-block language="php" label="src/Controller/AlarmAckController.php" -->
```php
namespace App\Controller;

use App\Service\AlarmAcknowledgeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AlarmAckController extends AbstractController
{
    public function __construct(private AlarmAcknowledgeService $svc) {}

    #[Route('/api/alarms/{eventId}/ack', methods: ['POST'])]
    #[IsGranted('ROLE_OPERATOR')]
    public function ack(string $eventId, Request $request): JsonResponse
    {
        $comment = (string) ($request->toArray()['comment'] ?? '');

        try {
            $this->svc->acknowledge($eventId, $comment);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json(['status' => 'acked']);
    }
}
```
<!-- @endcode-block -->

`AlarmAcknowledgeService` calls the OPC UA method — see
[Operations · Method calls](../operations/method-calls.md).

## Mercure broadcast for alarms

<!-- @code-block language="php" label="alarm broadcast" -->
```php
#[AsEventListener]
public function __invoke(AlarmActivated $event): void
{
    $this->hub->publish(new Update(
        topics: ['/plc/alarms'],
        data: json_encode([
            'event_id' => $event->eventId,
            'source'   => $event->sourceName,
            'severity' => $event->severity,
            'message'  => $event->message,
            'at'       => $event->time?->format('c'),
        ]),
        private: true,
    ));
}
```
<!-- @endcode-block -->

The browser subscribes to the private topic (with auth) and
updates in real time.

## Filtering — server-side beats client-side

For a noisy plant emitting thousands of low-severity events,
push the filter to the **server**:

<!-- @code-block language="php" label="server-side filter" -->
```php
$sub->monitorEvents('ns=0;i=2253', [
    'fields' => [/* ... */],
    'filter' => "Severity > 500 AND EventType = 'ns=2;s=PlcAlarmType'",
]);
```
<!-- @endcode-block -->

Server-side filtering drops events before they hit the wire —
your listener never sees them.

## Where to read next

- [Async listeners with Messenger](./async-listeners-with-messenger.md) —
  scaling rules.
- [Recipes · Alarm routing](../recipes/alarm-routing.md) — full
  pipeline.
- [Integrations · Notifier](../integrations/notifier.md) — the
  routing surface in detail.
