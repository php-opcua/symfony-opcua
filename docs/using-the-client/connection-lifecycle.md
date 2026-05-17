---
eyebrow: 'Docs · Using the client'
lede:    'When connections open, when they close, what survives across requests and worker processes. Lifecycle rules for FPM, Messenger consumers, scheduler workers.'

see_also:
  - { href: '../session-manager/overview.md',      meta: '5 min' }
  - { href: '../integrations/messenger.md',         meta: '6 min' }

prev: { label: 'Ad-hoc connections',  href: './ad-hoc-connections.md' }
next: { label: 'Using builders',      href: './using-builders.md' }
---

# Connection lifecycle

How the bundle decides when to open, reuse, and close
connections — across the runtime shapes a Symfony app lives in.

## The lifecycle in five steps

1. **Resolve** — `$opcua->connect($name)` or `connectTo(...)`
   asks `OpcuaManager`.
2. **Open if needed** — if the cache is empty for that name,
   the manager opens a new client.
3. **Use** — calls go through; errors bubble as
   `OpcUaException` subclasses.
4. **Cache** — the client stays on the manager for the lifetime
   of the manager instance.
5. **Close** — `disconnect()`, end of request, or worker
   shutdown.

## Manager scope

`OpcuaManager` is bound as a **container service** (not a
singleton in the DI sense — Symfony 6+ services are
shared-by-default and instantiated lazily per kernel boot).

| Runtime                             | Container / kernel scope    | Manager lifetime           |
| ----------------------------------- | --------------------------- | -------------------------- |
| PHP-FPM (one request)                | One request                | One request                |
| Console command (one execution)      | One process                | One process                |
| Messenger consumer (`messenger:consume`) | Until restart / memory cap | Long-lived                |
| Scheduler worker                     | Until restart              | Long-lived                  |
| Functional tests (`KernelTestCase`)   | One test method             | One test method            |
| Reuse mode (`framework.session.handler_id` HTTP/2 worker etc.) | Worker boot | Long-lived |

The implication: in **FPM** the cache is per-request and
connections always open fresh. In **long-running workers** the
cache persists across messages — the desirable behaviour for
high-frequency work.

## Open semantics

`$opcua->connect($name)`:

1. Looks up `$name` in `connections.*`. Throws
   `InvalidArgumentException` if missing.
2. Returns the cached client if present.
3. Otherwise constructs and caches a new client:
   - **Direct mode**: builds via `ClientBuilder::create()` and
     calls `->connect($endpoint)`.
   - **Managed mode** (daemon reachable + `enabled = true`):
     constructs a `ManagedClient` pointing at the daemon socket
     and opens a session there.

Opening is **eager** on the first call. After that, calls reuse
the cached client. Disconnects are explicit (`disconnect`) or
implicit (worker shutdown).

## Use semantics

A live client holds the OPC UA session. Calls go over that
session until:

- An explicit `$opcua->disconnect()`.
- A network error severs the session (`ConnectionException`).
- The server times out the session
  (`InactiveSessionException`).

The bundle **does not auto-reconnect transparently**. A failed
call surfaces as an exception; the next call attempts a fresh
open if you didn't catch and act on the failure.

Configure `auto_retry` in YAML to get automatic retry on
`ConnectionException`:

<!-- @code-block language="text" label="auto_retry" -->
```text
connections:
    default:
        endpoint:   '%env(OPCUA_ENDPOINT)%'
        auto_retry: 3      # 3 attempts before bubbling
```
<!-- @endcode-block -->

## Disconnect semantics

<!-- @code-block language="php" label="disconnect surface" -->
```php
$opcua->disconnect();              // default
$opcua->disconnect('plc-line-b');  // named
$opcua->disconnect($clientInstance); // by instance (ad-hoc-friendly)
$opcua->disconnectAll();           // every cached connection
```
<!-- @endcode-block -->

After `disconnect`, the next `connect(...)` opens fresh. No
halfway state.

## Error semantics

| Exception                       | Manager response                                |
| ------------------------------- | ----------------------------------------------- |
| `ConnectionException`           | Cache entry invalidated; next call reopens     |
| `InactiveSessionException`      | Same                                            |
| `ServiceException`              | Cache stays — server reached, request rejected |
| `PolicyException` / `CertificateException` on open | Cache entry not populated; exception bubbles |

You don't need explicit `disconnect()` after a
`ConnectionException` — the manager handles it.

## Long-running workers — when to recycle

In Messenger consumers, the manager can hold a connection for
hours. Two reasons to proactively recycle:

1. **Server-side session timeout** — OPC UA servers enforce a
   `MaxSessionTimeout` (often 1-2 hours). The bundle only
   learns about it when the next call fails.
2. **Resource drift** — subscriptions may be invalidated
   server-side; cached metadata may grow stale.

Symfony-native ways to recycle:

### Bound the worker's lifetime

`messenger:consume` supports `--time-limit` and `--memory-limit`:

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console messenger:consume async --time-limit=3600 --memory-limit=512M
```
<!-- @endcode-block -->

After an hour or 512 MB, the worker exits cleanly — Supervisor
restarts it. All connections close gracefully.

### Periodic disconnect command

<!-- @code-block language="php" label="src/Command/RecycleOpcuaCommand.php" -->
```php
#[AsCommand(name: 'app:opcua:recycle')]
final class RecycleOpcuaCommand extends Command
{
    public function __construct(private readonly OpcuaManager $opcua)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->opcua->disconnectAll();
        $output->writeln('All OPC UA connections recycled.');
        return Command::SUCCESS;
    }
}
```
<!-- @endcode-block -->

Schedule it with Symfony Scheduler — see
[Console and scheduler](../integrations/console-and-scheduler.md).

## Managed mode — daemon-held sessions

When `session_manager.enabled = true` and the daemon is reachable,
"open" means **acquire a session from the daemon**. The daemon
holds the actual TCP connection; the Symfony process holds an
IPC handle.

Consequences:

- Multiple Symfony processes (FPM workers, Messenger consumers)
  can **share** the same daemon-held session — matched on
  `(endpoint + credentials + security)`.
- Worker restarts don't close server-side sessions.
- The daemon's `session_timeout` governs when an idle session
  is actually closed.

See [Session manager · Overview](../session-manager/overview.md).

## Tests

In `KernelTestCase`, each test boots a fresh kernel by default
— so each test starts with a clean `OpcuaManager`. No leaks
between tests.

When you mock `OpcuaManager` via `static::getContainer()->set(...)`,
the mock is per-test by construction.

See [Testing · Kernel tests](../testing/kernel-tests.md).

## Disconnect-on-shutdown

The `OpcuaManager` registers a `register_shutdown_function()`
that calls `disconnectAll()` on PHP shutdown. This catches FPM
end-of-request and Messenger worker exits, tidying daemon-side
IPC handles.

You don't need to do anything in `terminate()` or `__destruct`
— the bundle handles it.

## Octane / FrankenPHP / RoadRunner-style runtimes

Long-running PHP runtimes (Octane is a Laravel thing, but
FrankenPHP works the same way with Symfony) keep the kernel
alive across requests. The connection cache persists across
requests handled by the same worker — fast.

**Don't mutate global config from a request handler under
FrankenPHP/Swoole/RoadRunner.** The mutation leaks into the
next request handled by the same worker. Use named or ad-hoc
connections instead — those are request-scoped.

See [Recipes · Production deployment](../recipes/production-deployment.md)
for the FrankenPHP topology.

## A complete lifecycle picture

<!-- @code-block language="text" label="end-to-end" -->
```text
FPM worker boots                                        [Manager cache: ∅]
    ↓
HTTP request → controller injects OpcuaManager
    ↓
$opcua->connect('plc')  →  open Client                  [cache: {plc → Client}]
    ↓
$client->read(...)
    ↓
return response
    ↓
PHP shutdown → register_shutdown_function fires         [cache: ∅]
    →  Client::disconnect()
```
<!-- @endcode-block -->

For Messenger workers, the cache survives between messages —
only worker restart resets it.

## Where to read next

- [Using builders](./using-builders.md) — the fluent operation
  APIs.
- [Messenger](../integrations/messenger.md) — async workers'
  lifecycle.
- [Production supervisor](../session-manager/production-supervisor.md) —
  the daemon's lifecycle.
