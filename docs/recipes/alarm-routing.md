---
eyebrow: 'Docs · Recipes'
lede:    'End-to-end alarm pipeline: subscription, persistence, severity routing via Notifier, acknowledgement endpoint, audit chain. The Symfony-native shape most plants converge on.'

see_also:
  - { href: '../events/alarm-events.md',                meta: '5 min' }
  - { href: '../integrations/notifier.md',              meta: '6 min' }
  - { href: '../operations/method-calls.md',            meta: '6 min' }

prev: { label: 'Persistent tag history', href: './persistent-tag-history.md' }
next: { label: 'Mercure real-time dashboard', href: './mercure-realtime-dashboard.md' }
---

# Alarm routing

A complete alarm pipeline. From subscription → persistence →
severity routing (Notifier) → operator UI acknowledgement.

## Architecture

<!-- @code-block language="text" label="pipeline" -->
```text
OPC UA server     Daemon (auto-publish)              Symfony
─────────────     ─────────────────────              ─────────────────
EventNotifier ──► PSR-14: EventNotificationReceived  EventDispatcher
                                                       │
                                                       ├─► PersistAlarm        (Doctrine)
                                                       ├─► RouteAlarmToOps      (Notifier)
                                                       └─► BroadcastAlarm        (Mercure)

Operator UI ack
                                                       │
                                                       ▼
                                                AlarmAckController → callMethod(Acknowledge)
                                                       │
                                                       ▼  (server emits)
                                                EventNotificationReceived (isAcked=true)
                                                       │
                                                       └─► PersistAlarm updates is_acked
```
<!-- @endcode-block -->

## Doctrine schema

<!-- @code-block language="php" label="src/Entity/PlcAlarm.php" -->
```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'plc_alarms', indexes: [
    new ORM\Index(columns: ['is_active', 'is_acked', 'severity']),
])]
class PlcAlarm
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'bigint')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    public string $connection;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    public string $eventId;

    #[ORM\Column(type: 'string', length: 256, nullable: true)]
    public ?string $eventType = null;

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $sourceName = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $severity = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $message = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $occurredAt = null;

    #[ORM\Column(type: 'boolean')]
    public bool $isActive = true;

    #[ORM\Column(type: 'boolean')]
    public bool $isAcked = false;
}
```
<!-- @endcode-block -->

Plus an ack audit table:

<!-- @code-block language="php" label="src/Entity/PlcAlarmAck.php" -->
```php
#[ORM\Entity]
class PlcAlarmAck
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlcAlarm::class)]
    public PlcAlarm $alarm;

    #[ORM\Column(type: 'string', length: 100)]
    public string $userId;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $ackedAt;
}
```
<!-- @endcode-block -->

## Subscription setup

<!-- @code-block language="text" label="bundle config" -->
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
                    publishing_interval: 1000.0
                    event_monitored_items:
                        - node_id: 'ns=0;i=2253'
                          client_handle: 100
                          select_fields:
                              - EventId
                              - EventType
                              - SourceName
                              - Time
                              - Message
                              - Severity
                              - ActiveState/Id
                              - AckedState/Id
```
<!-- @endcode-block -->

## Listeners

### Persist

<!-- @code-block language="php" label="src/EventListener/PersistAlarm.php" -->
```php
namespace App\EventListener;

use App\Entity\PlcAlarm;
use Doctrine\ORM\EntityManagerInterface;
use PhpOpcua\Client\Events\EventNotificationReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class PersistAlarm
{
    public function __construct(private EntityManagerInterface $em) {}

    #[AsEventListener]
    public function __invoke(EventNotificationReceived $event): void
    {
        $alarm = $this->em->getRepository(PlcAlarm::class)
            ->findOneBy(['eventId' => $event->eventId]) ?? new PlcAlarm();

        $alarm->connection  = $event->connection;
        $alarm->eventId     = $event->eventId;
        $alarm->eventType   = $event->eventType;
        $alarm->sourceName  = $event->sourceName;
        $alarm->severity    = $event->severity;
        $alarm->message     = $event->message;
        $alarm->occurredAt  = $event->time;
        $alarm->isActive    = $event->isActive ?? true;
        $alarm->isAcked     = $event->isAcked  ?? false;

        $this->em->persist($alarm);
        $this->em->flush();
    }
}
```
<!-- @endcode-block -->

### Route

<!-- @code-block language="php" label="src/EventListener/RouteAlarm.php" -->
```php
namespace App\EventListener;

use App\Notification\PlcAlarmNotification;
use PhpOpcua\Client\Events\EventNotificationReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;

final class RouteAlarm
{
    public function __construct(private NotifierInterface $notifier) {}

    #[AsEventListener]
    public function __invoke(EventNotificationReceived $event): void
    {
        if (!$event->isActive || ($event->severity ?? 0) < 400) {
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

`PlcAlarmNotification` is the `Notification` class — see
[Integrations · Notifier](../integrations/notifier.md).

### Broadcast

<!-- @code-block language="php" label="src/EventListener/BroadcastAlarm.php" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Events\EventNotificationReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class BroadcastAlarm
{
    public function __construct(private HubInterface $hub) {}

    #[AsEventListener]
    public function __invoke(EventNotificationReceived $event): void
    {
        $this->hub->publish(new Update(
            topics: ['/plc/alarms'],
            data: json_encode([
                'event_id'  => $event->eventId,
                'source'    => $event->sourceName,
                'severity'  => $event->severity,
                'message'   => $event->message,
                'is_active' => $event->isActive,
                'is_acked'  => $event->isAcked,
            ]),
            private: true,
        ));
    }
}
```
<!-- @endcode-block -->

## The acknowledge endpoint

<!-- @code-block language="php" label="src/Controller/AlarmAckController.php" -->
```php
namespace App\Controller;

use App\Entity\PlcAlarmAck;
use App\Service\AlarmAcknowledgeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AlarmAckController extends AbstractController
{
    public function __construct(
        private AlarmAcknowledgeService $svc,
        private EntityManagerInterface $em,
    ) {}

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

        $audit = (new PlcAlarmAck())
            ->setAlarm($this->em->getRepository(\App\Entity\PlcAlarm::class)->findOneBy(['eventId' => $eventId]))
            ->setUserId($this->getUser()->getUserIdentifier())
            ->setComment($comment)
            ->setAckedAt(new \DateTimeImmutable());
        $this->em->persist($audit);
        $this->em->flush();

        return $this->json(['status' => 'acked']);
    }
}
```
<!-- @endcode-block -->

## The ack service

<!-- @code-block language="php" label="src/Service/AlarmAcknowledgeService.php" -->
```php
namespace App\Service;

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\BuiltinType;

final class AlarmAcknowledgeService
{
    public function __construct(private OpcUaClientInterface $client) {}

    public function acknowledge(string $eventIdHex, string $comment): void
    {
        [$status, ] = $this->client->callBuilder()
            ->method('ns=0;i=2782', 'ns=0;i=9111')   // ConditionType, Acknowledge
            ->input(hex2bin($eventIdHex), BuiltinType::ByteString)
            ->input(['locale' => 'en', 'text' => $comment])
            ->execute();

        if ($status !== 0) {
            throw new \RuntimeException(sprintf('Ack failed: 0x%X', $status));
        }
    }
}
```
<!-- @endcode-block -->

## A Notifier-routed alert

See [Integrations · Notifier](../integrations/notifier.md) for
the `PlcAlarmNotification` class shape.

## Severity routing config

<!-- @code-block language="text" label="config/packages/notifier.yaml" -->
```text
framework:
    notifier:
        channel_policy:
            urgent: ['email', 'sms', 'chat/slack']   # sev >= 900
            high:   ['email', 'chat/slack']           # sev 700-899
            medium: ['email']                          # sev 400-699
            low:    ['email']
```
<!-- @endcode-block -->

The Notification's `importance` field maps to one of these.

## Where to read next

- [Mercure real-time dashboard](./mercure-realtime-dashboard.md) —
  the operator UI for the alarms.
- [Production deployment](./production-deployment.md) — shipping.
