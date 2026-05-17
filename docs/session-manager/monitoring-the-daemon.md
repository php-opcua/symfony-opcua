---
eyebrow: 'Docs · Session manager'
lede:    'Liveness probes, session counts, log channels, and a Symfony-native /health/opcua endpoint pattern.'

see_also:
  - { href: './production-supervisor.md',                meta: '6 min' }
  - { href: '../observability/logging.md',               meta: '5 min' }
  - { href: '../observability/profiler-and-data-collectors.md', meta: '5 min' }

prev: { label: 'Production supervisor', href: './production-supervisor.md' }
next: { label: 'Events overview',       href: '../events/overview.md' }
---

# Monitoring the daemon

A production daemon needs five questions answered at any moment:

1. Is it up?
2. How many sessions are open?
3. Are notifications flowing?
4. What was the last error?
5. How is memory looking?

This page covers the Symfony-native answers.

## 1 — Liveness probe controller

<!-- @code-block language="php" label="src/Controller/HealthController.php" -->
```php
namespace App\Controller;

use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    public function __construct(private OpcuaManager $opcua) {}

    #[Route('/health/opcua', methods: ['GET'])]
    public function opcua(): JsonResponse
    {
        try {
            $alive = $this->opcua->isSessionManagerRunning();
        } catch (\Throwable $e) {
            return $this->json(['status' => 'down', 'error' => $e->getMessage()], 503);
        }

        return $this->json([
            'status' => $alive ? 'up' : 'direct-mode',
        ]);
    }
}
```
<!-- @endcode-block -->

- `up` — daemon responds to ping.
- `direct-mode` — daemon unreachable, the bundle falls through.
- `503` — probe itself failed.

Wire into your liveness aggregator (Kubernetes probe, Pingdom,
Better Uptime).

## 2 — Detailed health probe

A more useful endpoint shows session count and uptime:

<!-- @code-block language="php" label="detail probe" -->
```php
#[Route('/health/opcua/detail', methods: ['GET'])]
public function detail(): JsonResponse
{
    if (!$this->opcua->isSessionManagerRunning()) {
        return $this->json(['status' => 'direct-mode']);
    }

    // daemonStats() pings the daemon and returns its session count + uptime
    $stats = $this->opcua->daemonStats();

    return $this->json([
        'status'       => 'up',
        'sessions'     => $stats['sessions']       ?? 0,
        'uptime'       => $stats['uptime']         ?? 0.0,
        'auto_publish' => $stats['auto_publish']   ?? false,
    ]);
}
```
<!-- @endcode-block -->

## 3 — Notification flow

Track event throughput with a tiny listener + cache counter:

<!-- @code-block language="php" label="src/EventListener/TrackOpcuaFlow.php" -->
```php
namespace App\EventListener;

use PhpOpcua\Client\Events\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class TrackOpcuaFlow
{
    public function __construct(private CacheInterface $cache) {}

    #[AsEventListener]
    public function __invoke(DataChangeReceived $event): void
    {
        $total = $this->cache->get('opcua.notif.total', function (ItemInterface $i) {
            $i->expiresAfter(86400);
            return 0;
        });
        $this->cache->delete('opcua.notif.total');
        $this->cache->get('opcua.notif.total', function (ItemInterface $i) use ($total) {
            $i->expiresAfter(86400);
            return $total + 1;
        });

        $this->cache->delete('opcua.notif.last');
        $this->cache->get('opcua.notif.last', fn(ItemInterface $i) =>
            $i->expiresAfter(3600) ?? (new \DateTimeImmutable())->format('c')
        );
    }
}
```
<!-- @endcode-block -->

Then probe:

<!-- @code-block language="php" label="flow endpoint" -->
```php
#[Route('/health/opcua/flow', methods: ['GET'])]
public function flow(CacheInterface $cache): JsonResponse
{
    $total = $cache->get('opcua.notif.total', fn() => 0);
    $last  = $cache->get('opcua.notif.last', fn() => null);

    $stale = $last !== null
        && (new \DateTimeImmutable())->diff(new \DateTimeImmutable($last))->i > 5;

    return $this->json([
        'total'             => $total,
        'last_notification' => $last,
        'stale'             => $stale,
    ], $stale ? 503 : 200);
}
```
<!-- @endcode-block -->

If you expect notifications every few seconds and the last one
was 5 minutes ago, something's wrong upstream.

For high-volume deployments, **don't use the cache contract for
counters** — use a dedicated Redis counter or a metrics
exporter (see below). The example above is fine for moderate
throughput.

## 4 — Last error

The daemon logs to your Monolog `OPCUA_LOG_CHANNEL`. For
real-time error visibility:

<!-- @code-block language="text" label="config/packages/monolog.yaml" -->
```text
monolog:
    channels: ['opcua']
    handlers:
        opcua_file:
            type: rotating_file
            path: '%kernel.logs_dir%/opcua.log'
            max_files: 14
            channels: ['opcua']
            level: info
        opcua_slack:
            type: slack
            token: '%env(SLACK_TOKEN)%'
            channel: '#ops-alerts'
            channels: ['opcua']
            level: error
            include_extra: true
```
<!-- @endcode-block -->

Daemon errors land both in the daily file (for forensics) and
in Slack (for immediate response).

## 5 — Memory

The daemon's memory grows with session count + monitored items.
5 sessions, 100 items = ~80-150 MB. Spikes warrant a look.

Probe via the OS:

<!-- @code-block language="bash" label="terminal" -->
```bash
systemctl status opcua-session-manager   # shows RSS
ps -o rss,pid,command -p "$(pgrep -f opcua:session)"
```
<!-- @endcode-block -->

…or via `daemonStats()` if you've added memory to its response.

## Prometheus exporter

A scheduled Symfony command exports daemon stats to a
Prometheus push gateway:

<!-- @code-block language="php" label="src/Command/ExportOpcuaMetricsCommand.php" -->
```php
namespace App\Command;

use PhpOpcua\SymfonyOpcua\OpcuaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:opcua:metrics')]
final class ExportOpcuaMetricsCommand extends Command
{
    public function __construct(
        private OpcuaManager $opcua,
        private HttpClientInterface $http,
        private string $pushGatewayUrl,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->opcua->isSessionManagerRunning()) {
            return Command::SUCCESS;
        }

        $stats = $this->opcua->daemonStats();

        $metrics = sprintf(
            "opcua_sessions %d\nopcua_uptime_seconds %f\n",
            $stats['sessions'] ?? 0,
            $stats['uptime'] ?? 0.0,
        );

        $this->http->request('POST', $this->pushGatewayUrl . '/metrics/job/opcua', [
            'body' => $metrics,
        ]);

        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Schedule every 15 s via Symfony Scheduler:

<!-- @code-block language="php" label="schedule" -->
```php
use Symfony\Component\Console\Messenger\RunCommandMessage;

#[AsSchedule('opcua-metrics')]
final class OpcuaMetricsSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('15 seconds', new RunCommandMessage('app:opcua:metrics')));
    }
}
```
<!-- @endcode-block -->

## Profiler / data collectors

Symfony's WebProfilerBundle can show OPC UA calls — see
[Observability · Profiler and data collectors](../observability/profiler-and-data-collectors.md).

## Common alarm rules

| Rule                                              | Trigger / threshold                  | Likely cause                          |
| ------------------------------------------------- | ------------------------------------ | ------------------------------------- |
| `isSessionManagerRunning()` returns false          | > 1 minute                            | Daemon down, socket gone              |
| `daemonStats().sessions == 0`                     | > 5 minutes with auto-publish on      | All sessions lost, server unreachable  |
| No `DataChangeReceived`                            | > 5 minutes                           | Subscriptions dropped                 |
| ERROR-level logs                                   | > 10/minute                           | Something's actively wrong            |
| Daemon memory                                      | > 1.5× normal                         | Leak in a third-party module/listener  |

## Debugging from the terminal

For one-shot diagnostics:

<!-- @code-block language="bash" label="netcat probe" -->
```bash
echo '{"id":1,"t":"req","method":"ping","args":[]}' \
    | nc -U /var/run/opcua/sessions.sock | jq .
```
<!-- @endcode-block -->

See `opcua-session-manager`'s
[debugging-with-netcat recipe](https://github.com/php-opcua/opcua-session-manager/blob/master/docs/recipes/debugging-with-netcat.md).

## Where to read next

You've finished **Session manager**. Continue with
[Events overview](../events/overview.md) for the listener side.
