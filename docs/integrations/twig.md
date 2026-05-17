---
eyebrow: 'Docs · Integrations'
lede:    'Twig templates for OPC UA data. Custom extensions, server-side rendering vs Mercure push, and the Twig Components pattern for reactive tiles.'

see_also:
  - { href: './mercure.md',                            meta: '6 min' }
  - { href: '../operations/reading.md',                meta: '6 min' }
  - { href: '../recipes/mercure-realtime-dashboard.md', meta: '7 min' }

prev: { label: 'Doctrine',          href: './doctrine.md' }
next: { label: 'Console and scheduler', href: './console-and-scheduler.md' }
---

# Twig

For server-rendered OPC UA data, Twig is the standard. Pair
with Mercure for real-time updates from a static template.

## Basic — render a value

<!-- @code-block language="php" label="controller" -->
```php
namespace App\Controller;

use PhpOpcua\Client\OpcUaClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(private OpcUaClientInterface $client) {}

    #[Route('/dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'speed'       => $this->client->read('ns=2;s=Speed')->getValue(),
            'temperature' => $this->client->read('ns=2;s=Temperature')->getValue(),
            'pressure'    => $this->client->read('ns=2;s=Pressure')->getValue(),
        ]);
    }
}
```
<!-- @endcode-block -->

<!-- @code-block language="text" label="templates/dashboard/index.html.twig" -->
```text
{% extends 'base.html.twig' %}

{% block body %}
<div class="grid grid-cols-3 gap-4">
    <div class="tile">
        <h3>Speed</h3>
        <div class="value">{{ speed|number_format(1) }}</div>
    </div>
    <div class="tile">
        <h3>Temperature</h3>
        <div class="value">{{ temperature|number_format(1) }}°C</div>
    </div>
    <div class="tile">
        <h3>Pressure</h3>
        <div class="value">{{ pressure|number_format(2) }} bar</div>
    </div>
</div>
{% endblock %}
```
<!-- @endcode-block -->

Static SSR — reload to refresh.

## With Mercure for live updates

Combine SSR for the initial render + Mercure for live updates:

<!-- @code-block language="text" label="dashboard with Mercure" -->
```text
{% extends 'base.html.twig' %}

{% block body %}
<div class="grid grid-cols-3 gap-4">
    {% for tag in tags %}
    <div class="tile" data-node-id="{{ tag.nodeId }}">
        <h3>{{ tag.displayName }}</h3>
        <div class="value">{{ tag.value|number_format(1) }}</div>
    </div>
    {% endfor %}
</div>

<script>
const url = new URL("{{ mercure('/plc/all')|escape('js') }}");
const es = new EventSource(url);
es.onmessage = (e) => {
    const data = JSON.parse(e.data);
    const tile = document.querySelector(`[data-node-id="${data.node_id}"] .value`);
    if (tile && data.value !== undefined) {
        tile.textContent = data.value.toFixed(1);
    }
};
</script>
{% endblock %}
```
<!-- @endcode-block -->

Initial values from the controller; live updates via Mercure.

## A Twig extension for OPC UA

For one-off `{{ opcua_read(...) }}` in templates (use sparingly
— don't put OPC UA calls in templates as a rule):

<!-- @code-block language="php" label="src/Twig/OpcuaExtension.php" -->
```php
namespace App\Twig;

use PhpOpcua\Client\OpcUaClientInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OpcuaExtension extends AbstractExtension
{
    public function __construct(private OpcUaClientInterface $client) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('opcua_read', $this->read(...)),
            new TwigFunction('opcua_status', $this->statusName(...)),
        ];
    }

    public function read(string $nodeId): mixed
    {
        try {
            return $this->client->read($nodeId)->getValue();
        } catch (\Throwable) {
            return null;
        }
    }

    public function statusName(int $statusCode): string
    {
        return \PhpOpcua\Client\Types\StatusCode::name($statusCode);
    }
}
```
<!-- @endcode-block -->

Usage:

<!-- @code-block language="text" label="usage" -->
```text
Current speed: {{ opcua_read('ns=2;s=Speed') }}
```
<!-- @endcode-block -->

<!-- @callout type="warning" -->
**Don't read OPC UA from templates in production.** Each read
is a network round-trip. Put values in the controller and pass
to the template, or cache aggressively.
<!-- @endcallout -->

## A Symfony UX Twig Component

For a reactive component that auto-updates via Mercure:

<!-- @code-block language="bash" label="install" -->
```bash
composer require symfony/ux-twig-component
composer require symfony/ux-live-component
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="src/Twig/Components/TagTile.php" -->
```php
namespace App\Twig\Components;

use PhpOpcua\Client\OpcUaClientInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PreMount;

#[AsTwigComponent]
final class TagTile
{
    public string $nodeId;
    public string $label = '';
    public string $unit = '';
    public mixed $value = null;
    public bool $good = false;

    public function __construct(private OpcUaClientInterface $client) {}

    #[PreMount]
    public function fetch(array $data): array
    {
        $dv = $this->client->read($data['nodeId']);
        $data['value'] = $dv->getValue();
        $data['good']  = $dv->statusCode === 0;
        return $data;
    }
}
```
<!-- @endcode-block -->

<!-- @code-block language="text" label="templates/components/TagTile.html.twig" -->
```text
<div class="tile {{ good ? '' : 'tile-bad' }}" data-node-id="{{ nodeId }}">
    <h3>{{ label }}</h3>
    <div class="value">
        {{ value is not null ? value|number_format(2) ~ ' ' ~ unit : '—' }}
    </div>
</div>
```
<!-- @endcode-block -->

In any template:

<!-- @code-block language="text" label="usage" -->
```text
<twig:TagTile nodeId="ns=2;s=Speed"       label="Speed"       unit="m/min" />
<twig:TagTile nodeId="ns=2;s=Temperature" label="Temperature" unit="°C" />
<twig:TagTile nodeId="ns=2;s=Pressure"    label="Pressure"    unit="bar" />
```
<!-- @endcode-block -->

Combine with **Live Components** for full reactivity without
hand-written JS — see the [Symfony UX docs](https://ux.symfony.com/live-component).

## Formatting OPC UA `DataValue`

<!-- @code-block language="text" label="formatters" -->
```text
{% set dv = some_data_value %}

Value:           {{ dv.value }}
Status:          {{ dv.statusCode == 0 ? 'Good' : 'Bad (' ~ dv.statusCode ~ ')' }}
Source time:     {{ dv.sourceTimestamp|date('Y-m-d H:i:s.v') }}
Server time:     {{ dv.serverTimestamp|date('Y-m-d H:i:s.v') }}
```
<!-- @endcode-block -->

`dv.value` works because `DataValue::getValue()` is exposed as
a property in Twig.

## A render filter for status codes

<!-- @code-block language="php" label="status filter" -->
```php
public function getFilters(): array
{
    return [
        new TwigFilter('opcua_status', $this->statusName(...)),
    ];
}
```
<!-- @endcode-block -->

Usage:

<!-- @code-block language="text" label="usage" -->
```text
{{ 0x80020000|opcua_status }}    {# Bad_InternalError #}
```
<!-- @endcode-block -->

## Where to read next

- [Console and scheduler](./console-and-scheduler.md) — for
  scheduled tasks running headless.
- [Recipes · Mercure real-time dashboard](../recipes/mercure-realtime-dashboard.md) —
  canonical end-to-end real-time UI.
