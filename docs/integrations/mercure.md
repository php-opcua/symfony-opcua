---
eyebrow: 'Docs · Integrations'
lede:    'Symfony Mercure for real-time push of OPC UA data to the browser. Hub setup, topic conventions, JWT for private channels, and an end-to-end dashboard pattern.'

see_also:
  - { href: '../events/data-events.md',                       meta: '6 min' }
  - { href: '../session-manager/auto-publish.md',             meta: '5 min' }
  - { href: '../recipes/mercure-realtime-dashboard.md',       meta: '7 min' }

prev: { label: 'Messenger',     href: './messenger.md' }
next: { label: 'Doctrine',      href: './doctrine.md' }
---

# Mercure

[Mercure](https://mercure.rocks) is Symfony's recommended way
to push real-time data from server to browser. It plays
naturally with the OPC UA bundle: a `DataChangeReceived` event
listener publishes to a Mercure hub; the browser subscribes via
`EventSource`.

## Setting up Mercure

<!-- @code-block language="bash" label="install" -->
```bash
composer require symfony/mercure-bundle
```
<!-- @endcode-block -->

`config/packages/mercure.yaml`:

<!-- @code-block language="text" label="mercure config" -->
```text
mercure:
    hubs:
        default:
            url:       '%env(MERCURE_URL)%'
            public_url: '%env(MERCURE_PUBLIC_URL)%'
            jwt:
                secret: '%env(MERCURE_JWT_SECRET)%'
                publish: '*'        # publisher can write any topic
                subscribe: ['*']    # subscriber can read any topic by default
```
<!-- @endcode-block -->

`.env`:

<!-- @code-block language="bash" label=".env" -->
```bash
MERCURE_URL=http://localhost:3000/.well-known/mercure
MERCURE_PUBLIC_URL=http://localhost:3000/.well-known/mercure
MERCURE_JWT_SECRET=!ChangeThisMercureHubJWTSecretKey!
```
<!-- @endcode-block -->

For development, the Symfony binary ships a Mercure hub via the
`symfony/cli` tool. Run:

<!-- @code-block language="bash" label="terminal" -->
```bash
symfony serve --dir=public
```
<!-- @endcode-block -->

…and the local hub binds automatically.

## Topic conventions

| Topic                            | Purpose                                       |
| -------------------------------- | --------------------------------------------- |
| `/plc/all`                        | Every tag update — for plant overview         |
| `/plc/tag/{nodeId}`               | Single tag — for individual widgets            |
| `/plc/line/{name}`                | Per-line filtering                             |
| `/plc/alarms`                     | Alarm stream                                   |
| `/plc/connections`                | Connection lifecycle events                    |

Use URI-style topics for natural namespacing.

## Publishing on data change

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
        $payload = json_encode([
            'node_id'   => $event->nodeId,
            'value'     => $event->dataValue->getValue(),
            'good'      => $event->dataValue->statusCode === 0,
            'source_at' => $event->dataValue->sourceTimestamp?->format('c'),
        ]);

        $this->hub->publish(new Update(
            topics: [
                '/plc/all',
                '/plc/tag/' . $event->nodeId,
            ],
            data: $payload,
        ));
    }
}
```
<!-- @endcode-block -->

The browser subscribes via Twig's `mercure()` helper.

## Subscribing — browser side

<!-- @code-block language="text" label="templates/dashboard.html.twig" -->
```text
{% extends 'base.html.twig' %}

{% block body %}
<div id="speed">--</div>
<div id="temperature">--</div>

<script>
const url = new URL("{{ mercure('/plc/all')|escape('js') }}");

const es = new EventSource(url);
es.onmessage = e => {
    const data = JSON.parse(e.data);
    if (data.node_id === 'ns=2;s=Speed') {
        document.getElementById('speed').textContent = data.value;
    }
    if (data.node_id === 'ns=2;s=Temperature') {
        document.getElementById('temperature').textContent = data.value;
    }
};
</script>
{% endblock %}
```
<!-- @endcode-block -->

`mercure('/plc/all')` generates a URL with a JWT signed for that
topic. The browser uses native `EventSource`.

## Per-tile subscriptions

For tighter wiring (fewer client-side filters), subscribe to
**one tile** per channel:

<!-- @code-block language="text" label="single-tag widget" -->
```text
<div id="speed-tile">--</div>

<script>
const url = new URL("{{ mercure(['/plc/tag/ns=2;s=Speed'])|escape('js') }}");

new EventSource(url).onmessage = e => {
    const data = JSON.parse(e.data);
    document.getElementById('speed-tile').textContent = data.value;
};
</script>
```
<!-- @endcode-block -->

Two widgets = two `EventSource` connections, each filtered to
its tag. Less client-side logic.

## Private topics

For operator-only data:

<!-- @code-block language="php" label="private publish" -->
```php
$this->hub->publish(new Update(
    topics: ['/plc/operator-only'],
    data: $payload,
    private: true,
));
```
<!-- @endcode-block -->

In Twig — set the topic in the JWT's `subscribe` claim:

<!-- @code-block language="text" label="private subscribe" -->
```text
<script>
const url = new URL("{{ mercure(['/plc/operator-only'], { subscribe: ['/plc/operator-only'] })|escape('js') }}");
new EventSource(url, { withCredentials: true });
</script>
```
<!-- @endcode-block -->

The Mercure JWT — signed by the bundle — encodes the user's
permitted topics. Pair with Symfony Security to gate.

## Throttling — flooding the wire

A high-frequency tag (50 ms) is 1200 events/min. Browsers don't
need that. Throttle in the listener:

<!-- @code-block language="php" label="throttled" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class BroadcastTagUpdate
{
    public function __construct(
        private HubInterface $hub,
        private CacheInterface $cache,
    ) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        $throttleKey = 'mercure.throttle.' . hash('xxh3', $event->nodeId);

        $blocked = $this->cache->get($throttleKey, function (ItemInterface $i) {
            $i->expiresAfter(0.25);   // 250 ms
            return false;
        });

        if ($blocked) return;

        $this->cache->delete($throttleKey);
        $this->cache->get($throttleKey, function (ItemInterface $i) {
            $i->expiresAfter(0.25);
            return true;
        });

        $this->hub->publish(new Update(
            topics: ['/plc/tag/' . $event->nodeId, '/plc/all'],
            data: json_encode([/* ... */]),
        ));
    }
}
```
<!-- @endcode-block -->

Max 4 broadcasts/sec per tag.

## Batching updates

For multi-tag dashboards, fire one batch update per second
instead of N individual:

<!-- @code-block language="php" label="batcher" -->
```php
#[AsEventListener]
public function __invoke(DataChangeReceived $event): void
{
    // Buffer in Redis
    $this->redis->rpush('mercure.batch', json_encode([
        'node_id' => $event->nodeId,
        'value'   => $event->dataValue->getValue(),
        'at'      => $event->dataValue->sourceTimestamp?->format('c'),
    ]));
}

// Drain via a scheduled command every second:
#[AsCommand('app:plc:flush-mercure')]
final class FlushMercureCommand extends Command
{
    public function __construct(private Redis $redis, private HubInterface $hub) { parent::__construct(); }
    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $items = $this->redis->multi()
            ->lrange('mercure.batch', 0, -1)
            ->del('mercure.batch')
            ->exec()[0];

        if (!empty($items)) {
            $this->hub->publish(new Update(
                topics: ['/plc/dashboard'],
                data:   json_encode(['updates' => array_map('json_decode', $items)]),
            ));
        }
        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Schedule with `RecurringMessage::every('1 second', ...)`.

## Production deployment

| Component         | Where                              |
| ----------------- | ---------------------------------- |
| Mercure hub        | Separate service (Docker container) |
| HTTPS proxy        | nginx in front of the hub on 443    |
| JWT secret         | Vault / env injection                |

The hub is a standalone Go binary; the Symfony app talks to it
via HTTP. See the [Mercure deployment docs](https://mercure.rocks/docs/hub/install).

## Connection lifecycle on the browser

`EventSource` reconnects automatically on disconnect. For
**custom reconnect logic** (exponential backoff, UI feedback),
use the [Mercure JS SDK](https://github.com/dunglas/mercure/tree/main/spec):

<!-- @code-block language="text" label="JS SDK" -->
```text
import { Hub } from '@symfony/mercure';

const hub = new Hub(new URL("{{ mercure('/plc/all')|escape('js') }}"));
hub.onMessage(data => { /* ... */ });
hub.onError(err => { console.error('mercure error', err); });
```
<!-- @endcode-block -->

## Where to read next

- [Doctrine](./doctrine.md) — persistence for the same events.
- [Recipes · Mercure real-time dashboard](../recipes/mercure-realtime-dashboard.md) —
  full end-to-end UI.
