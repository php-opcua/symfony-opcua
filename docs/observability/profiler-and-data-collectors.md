---
eyebrow: 'Docs · Observability'
lede:    'The WebProfilerBundle shows OPC UA calls when you wire a tiny DataCollector. The recipe + a Twig template you can drop in.'

see_also:
  - { href: './logging.md',                          meta: '5 min' }
  - { href: './debugging.md',                        meta: '5 min' }
  - { href: '../configuration/parameters-and-overrides.md', meta: '5 min' }

prev: { label: 'Debugging',                       href: './debugging.md' }
next: { label: 'Security · Policies and modes',   href: '../security/policies-and-modes.md' }
---

# Profiler and data collectors

The bundle doesn't ship a Symfony Profiler DataCollector — but
the architecture makes one easy to add. The trade-off is a few
hundred lines of code for "see every OPC UA call in the
WebProfilerBundle toolbar".

This page shows a minimal collector you can drop in.

## What you'll see

After wiring the collector below:

- Number of OPC UA calls in the request.
- Total OPC UA time vs total request time.
- Per-call breakdown (node, duration, status).
- The connection that served each call (direct vs managed).

It looks like the Doctrine collector — a tab in the toolbar
with a counter.

## The collector class

<!-- @code-block language="php" label="src/DataCollector/OpcuaDataCollector.php" -->
```php
namespace App\DataCollector;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\{Request, Response};

final class OpcuaDataCollector extends AbstractDataCollector
{
    private array $calls = [];

    public function record(string $operation, string $nodeId, float $durationMs, ?int $statusCode = null): void
    {
        $this->calls[] = [
            'op'       => $operation,
            'node'     => $nodeId,
            'duration' => $durationMs,
            'status'   => $statusCode,
        ];
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = [
            'calls'        => $this->calls,
            'total_ms'     => array_sum(array_column($this->calls, 'duration')),
            'count'        => count($this->calls),
            'failure_count' => count(array_filter($this->calls, fn($c) => ($c['status'] ?? 0) !== 0)),
        ];
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->data = [];
    }

    public function getName(): string
    {
        return 'opcua';
    }

    public function getCalls(): array      { return $this->data['calls']        ?? []; }
    public function getCount(): int        { return $this->data['count']        ?? 0; }
    public function getTotalMs(): float    { return $this->data['total_ms']     ?? 0.0; }
    public function getFailureCount(): int { return $this->data['failure_count'] ?? 0; }

    public static function getTemplate(): ?string
    {
        return '@App/data_collector/opcua.html.twig';
    }
}
```
<!-- @endcode-block -->

## Decorating the manager to record calls

The collector needs to be told about each call. Decorate
`OpcuaManager` and intercept:

<!-- @code-block language="php" label="src/Opcua/RecordingOpcuaManager.php" -->
```php
namespace App\Opcua;

use App\DataCollector\OpcuaDataCollector;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator(decorates: OpcuaManager::class)]
final class RecordingOpcuaManager extends OpcuaManager
{
    public function __construct(
        private readonly OpcuaManager $inner,
        private readonly OpcuaDataCollector $collector,
    ) {
        // Don't call parent — delegate everything.
    }

    public function connect(?string $name = null): OpcUaClientInterface
    {
        $client = $this->inner->connect($name);
        return new RecordingClient($client, $this->collector);
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->inner->$method(...$args);
    }
}
```
<!-- @endcode-block -->

…and a `RecordingClient` proxy that times each operation:

<!-- @code-block language="php" label="src/Opcua/RecordingClient.php" -->
```php
namespace App\Opcua;

use App\DataCollector\OpcuaDataCollector;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\DataValue;

final class RecordingClient implements OpcUaClientInterface
{
    public function __construct(
        private OpcUaClientInterface $inner,
        private OpcuaDataCollector $collector,
    ) {}

    public function read(\PhpOpcua\Client\Types\NodeId|string $nodeId, bool $refresh = false): DataValue
    {
        $start = microtime(true);
        $dv = $this->inner->read($nodeId, $refresh);
        $this->collector->record(
            'read',
            is_string($nodeId) ? $nodeId : (string) $nodeId,
            (microtime(true) - $start) * 1000,
            $dv->statusCode,
        );
        return $dv;
    }

    // Implement write, browse, etc., similarly.
    // Then delegate everything else:
    public function __call(string $method, array $args): mixed
    {
        return $this->inner->$method(...$args);
    }
}
```
<!-- @endcode-block -->

For a full implementation, intercept `read`, `write`, `browse`,
`callMethod`, and any other operation you want visible.

## The Twig template

<!-- @code-block language="text" label="templates/data_collector/opcua.html.twig" -->
```text
{% extends '@WebProfiler/Profiler/layout.html.twig' %}

{% block toolbar %}
    {% set icon %}
        <span class="sf-toolbar-info-piece">
            <span class="sf-toolbar-label">OPC UA</span>
            <span class="sf-toolbar-value">{{ collector.count }}</span>
        </span>
        {% if collector.failureCount > 0 %}
            <span class="sf-toolbar-info-piece sf-toolbar-status-red">
                {{ collector.failureCount }} failed
            </span>
        {% endif %}
    {% endset %}
    {% set text %}
        <div class="sf-toolbar-info-piece">
            <b>Calls</b>
            <span>{{ collector.count }}</span>
        </div>
        <div class="sf-toolbar-info-piece">
            <b>Total time</b>
            <span>{{ collector.totalMs|number_format(1) }} ms</span>
        </div>
    {% endset %}
    {{ include('@WebProfiler/Profiler/toolbar_item.html.twig', { link: profiler_url }) }}
{% endblock %}

{% block menu %}
    <span class="label">
        <span class="icon">⚡</span>
        <strong>OPC UA</strong>
        <span class="count"><span>{{ collector.count }}</span></span>
    </span>
{% endblock %}

{% block panel %}
    <h2>OPC UA Calls</h2>

    <table>
        <thead>
            <tr><th>Op</th><th>Node</th><th>Duration</th><th>Status</th></tr>
        </thead>
        <tbody>
            {% for call in collector.calls %}
                <tr>
                    <td>{{ call.op }}</td>
                    <td><code>{{ call.node }}</code></td>
                    <td>{{ call.duration|number_format(1) }} ms</td>
                    <td>{% if call.status == 0 %}Good{% else %}0x{{ call.status|number_format(0,'.','') }}{% endif %}</td>
                </tr>
            {% endfor %}
        </tbody>
    </table>
{% endblock %}
```
<!-- @endcode-block -->

## Register

<!-- @code-block language="text" label="services.yaml" -->
```text
services:
    App\DataCollector\OpcuaDataCollector:
        autoconfigure: true
```
<!-- @endcode-block -->

`autoconfigure` is on by default in modern Symfony — Symfony
sees `AbstractDataCollector` and registers the collector
automatically.

## Cost

| Aspect                    | Cost                                       |
| ------------------------- | ------------------------------------------ |
| Dev / debug mode          | Trivial — per-call array push              |
| Prod (Profiler disabled)   | Zero — collector isn't instantiated       |

WebProfilerBundle and the collector only load with
`APP_DEBUG=1`. Production is unaffected.

## Sampling in prod

If you want collector-style visibility in production:

1. Use **OpenTelemetry** instead — see the
   [otel-php SDK](https://github.com/open-telemetry/opentelemetry-php).
   Wire spans around the OPC UA calls.
2. **Symfony Stopwatch** for ad-hoc benchmarking:

<!-- @code-block language="php" label="stopwatch" -->
```php
use Symfony\Component\Stopwatch\Stopwatch;

public function show(Stopwatch $stopwatch): JsonResponse
{
    $stopwatch->start('opcua.read');
    $dv = $this->client->read('ns=2;s=Speed');
    $event = $stopwatch->stop('opcua.read');

    return $this->json([
        'value' => $dv->getValue(),
        'took_ms' => $event->getDuration(),
    ]);
}
```
<!-- @endcode-block -->

## When to bother

A data collector is worth it if:

- Your team uses the WebProfilerBundle daily.
- OPC UA latency is part of your bug-hunting workflow.
- You want to see correlation between HTTP requests and OPC UA
  calls (the toolbar makes it obvious).

For pure production monitoring, Prometheus / OpenTelemetry is
the better path.

## Where to read next

You've finished **Observability**. Next:
[Security · Policies and modes](../security/policies-and-modes.md).
