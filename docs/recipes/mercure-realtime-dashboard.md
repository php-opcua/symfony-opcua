---
eyebrow: 'Docs · Recipes'
lede:    'Full plant overview dashboard — multiple tag tiles, alarm bar, line-state widget, real-time updates via Mercure. Pure Symfony + Twig + a sprinkle of vanilla JS.'

see_also:
  - { href: '../integrations/mercure.md',       meta: '6 min' }
  - { href: '../integrations/twig.md',          meta: '5 min' }
  - { href: './alarm-routing.md',                meta: '7 min' }

prev: { label: 'Alarm routing',     href: './alarm-routing.md' }
next: { label: 'Multi-plant tenant', href: './multi-plant-tenant.md' }
---

# Mercure real-time dashboard

A production-quality plant dashboard. Initial values
server-rendered; live updates pushed via Mercure.

## Prerequisites

- Mercure hub set up — see [Integrations · Mercure](../integrations/mercure.md).
- Subscription listener publishing to Mercure topics — see
  [Events · Data events](../events/data-events.md).
- The cache-fill listener from
  [Persistent tag history](./persistent-tag-history.md) (or
  equivalent) populating "latest value" in Symfony cache.

## The route

<!-- @code-block language="php" label="src/Controller/DashboardController.php" -->
```php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;

#[IsGranted('ROLE_OPERATOR')]
final class DashboardController extends AbstractController
{
    public function __construct(private CacheInterface $cache) {}

    #[Route('/dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $tags = [
            ['node' => 'ns=2;s=Speed',       'label' => 'Line Speed',  'unit' => 'm/min'],
            ['node' => 'ns=2;s=Temperature', 'label' => 'Temperature', 'unit' => '°C'],
            ['node' => 'ns=2;s=Pressure',    'label' => 'Pressure',    'unit' => 'bar'],
            ['node' => 'ns=2;s=Output',      'label' => 'Output',      'unit' => 'units/h'],
        ];

        foreach ($tags as &$tag) {
            $tag['data'] = $this->cache->get('plc.latest.' . hash('xxh3', $tag['node']), fn() => null);
        }

        return $this->render('dashboard/index.html.twig', [
            'tags' => $tags,
        ]);
    }
}
```
<!-- @endcode-block -->

## The template

<!-- @code-block language="text" label="templates/dashboard/index.html.twig" -->
```text
{% extends 'base.html.twig' %}

{% block body %}
<div class="p-6 space-y-4">

    {# Alarm bar #}
    <div id="alarm-bar" class="space-y-2">
        {# server-rendered on load #}
    </div>

    {# Tag tiles #}
    <div class="grid grid-cols-4 gap-3">
        {% for tag in tags %}
        <div class="tile" data-node="{{ tag.node }}">
            <h3>{{ tag.label }}</h3>
            <div class="value">
                {% if tag.data %}
                    {{ tag.data.value|number_format(2) }}
                    <span class="unit">{{ tag.unit }}</span>
                {% else %}
                    —
                {% endif %}
            </div>
            <div class="status {{ tag.data and tag.data.status == 0 ? 'good' : 'bad' }}"></div>
        </div>
        {% endfor %}
    </div>

</div>

<script>
const url = new URL("{{ mercure(['/plc/all', '/plc/alarms'])|escape('js') }}");

const es = new EventSource(url, { withCredentials: true });

es.addEventListener('message', (e) => {
    const data = JSON.parse(e.data);

    if (data.event_id) {
        // alarm event
        addAlarmToBar(data);
    } else if (data.node_id) {
        // tag update
        updateTile(data);
    }
});

function updateTile(d) {
    const tile = document.querySelector(`[data-node="${d.node_id}"]`);
    if (!tile) return;
    const value  = tile.querySelector('.value');
    const status = tile.querySelector('.status');

    value.firstChild.nodeValue = d.value !== null ? d.value.toFixed(2) + ' ' : '— ';
    status.classList.toggle('good', d.good);
    status.classList.toggle('bad', !d.good);
}

function addAlarmToBar(d) {
    const bar = document.getElementById('alarm-bar');
    const row = document.createElement('div');
    row.className = 'alarm sev-' + Math.floor(d.severity / 100);
    row.innerHTML = `
        <span class="badge">SEV ${d.severity}</span>
        <strong>${d.source}</strong>
        <span>${d.message}</span>
        <button class="ack-btn" data-event-id="${d.event_id}">Ack</button>
    `;
    bar.prepend(row);
}

document.addEventListener('click', (e) => {
    if (!e.target.matches('.ack-btn')) return;
    const eventId = e.target.dataset.eventId;

    fetch(`/api/alarms/${eventId}/ack`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ comment: '' }),
    }).then(r => {
        if (r.ok) e.target.closest('.alarm').remove();
    });
});
</script>
{% endblock %}
```
<!-- @endcode-block -->

## The publisher listener

Already covered in [Events · Data events](../events/data-events.md):

<!-- @code-block language="php" label="snippet" -->
```php
#[AsEventListener]
public function __invoke(DataChangeReceived $event): void
{
    $this->hub->publish(new Update(
        topics: ['/plc/all'],
        data: json_encode([
            'node_id' => $event->nodeId,
            'value'   => $event->dataValue->getValue(),
            'good'    => $event->dataValue->statusCode === 0,
            'at'      => $event->dataValue->sourceTimestamp?->format('c'),
        ]),
    ));
}
```
<!-- @endcode-block -->

Alarm broadcaster:

<!-- @code-block language="php" label="alarm broadcast" -->
```php
#[AsEventListener]
public function __invoke(EventNotificationReceived $event): void
{
    $this->hub->publish(new Update(
        topics: ['/plc/alarms'],
        data: json_encode([
            'event_id' => $event->eventId,
            'source'   => $event->sourceName,
            'severity' => $event->severity,
            'message'  => $event->message,
        ]),
        private: true,
    ));
}
```
<!-- @endcode-block -->

## Mercure auth for private topics

`config/packages/security.yaml`:

<!-- @code-block language="text" label="security" -->
```text
security:
    firewalls:
        main:
            # ... your firewall
            mercure:
                topics:
                    - '/plc/alarms'
                    - '/plc/operator-only'
```
<!-- @endcode-block -->

…which signs the JWT with the user's permitted topics. Mercure
checks them before pushing.

## Symfony UX Live Components alternative

For a full Symfony-native experience without hand-written JS,
combine `TagTile` Twig Component (see
[Integrations · Twig](../integrations/twig.md)) with the Mercure
auto-update pattern. Live Components handle the JS for you —
just need a Mercure-driven trigger.

## Performance characteristics

| Component       | Update mechanism                | Network cost                                    |
| --------------- | ------------------------------- | ----------------------------------------------- |
| Tile values     | Mercure broadcast               | One WS / SSE event per change                   |
| Alarm bar       | Mercure private topic           | One per alarm                                    |
| Initial load    | Server-side from cache          | One HTTP request                                 |

For a 6-tile dashboard with 5 tags/sec arrival rate, ~30
EventSource events/sec per connected operator. Comfortable.

## Per-user filtering

To show only tags for the user's assigned line:

<!-- @code-block language="php" label="scoped controller" -->
```php
public function index(): Response
{
    $user = $this->getUser();
    $tags = $this->tagRepository->forLine($user->getAssignedLine());

    foreach ($tags as &$tag) {
        $tag['data'] = $this->cache->get('plc.latest.' . hash('xxh3', $tag['node']), fn() => null);
    }

    return $this->render('dashboard/index.html.twig', [
        'tags'  => $tags,
        'topic' => '/plc/line/' . $user->getAssignedLine()->getSlug(),
    ]);
}
```
<!-- @endcode-block -->

…and update the JS topic accordingly.

## Where to read next

- [Multi-plant tenant](./multi-plant-tenant.md) — per-tenant
  isolation.
- [Production deployment](./production-deployment.md) — running
  it on a server.
