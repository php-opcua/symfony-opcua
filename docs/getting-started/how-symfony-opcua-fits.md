---
eyebrow: 'Docs · Getting started'
lede:    'Three layers, one switch. The architecture diagram and the rules for which mode runs when.'

see_also:
  - { href: '../using-the-client/manager-vs-interface.md',  meta: '5 min' }
  - { href: '../session-manager/overview.md',               meta: '6 min' }
  - { href: '../events/overview.md',                        meta: '5 min' }

prev: { label: 'Quick start',  href: './quick-start.md' }
next: { label: 'Upgrading',    href: './upgrading.md' }
---

# How symfony-opcua fits

The bundle is a thin layer over two upstream packages. Knowing
which layer does what helps when you read the source or diagnose
production issues.

## The three layers

<!-- @code-block language="text" label="layered architecture" -->
```text
┌──────────────────────────────────────────────────────────────┐
│                     Your Symfony app                          │
│                                                                │
│  Controllers · Commands · Messenger handlers · Listeners       │
│  All inject OpcuaManager or OpcUaClientInterface (autowired)   │
│                                                                │
├──────────────────────────────────────────────────────────────┤
│                    php-opcua/symfony-opcua                     │
│                                                                │
│  - PhpOpcuaSymfonyOpcuaBundle (config tree + service wiring)   │
│  - OpcuaManager (resolveLogger, named/ad-hoc connections,      │
│                  setLogger/useConsoleLogger runtime override)  │
│  - Command/SessionCommand (php bin/console opcua:session)      │
│  - Logging/{TimestampedLogger, LoggerResolverFactory}           │
│                                                                │
├──────────────────────────────────────────────────────────────┤
│                     php-opcua/opcua-client                     │
│  Direct mode: Client, ClientBuilder, modules, wire codec        │
│                                                                │
│   - or -                                                       │
│                                                                │
│              php-opcua/opcua-session-manager                   │
│  Managed mode: ManagedClient (IPC) + daemon over Unix/TCP       │
└──────────────────────────────────────────────────────────────┘
```
<!-- @endcode-block -->

The bundle wires three Symfony-side concerns into the lower-level
packages:

1. **YAML semantic config** → typed PHP arrays the manager understands.
2. **Container services** → autowired `OpcuaManager` and
   `OpcUaClientInterface`.
3. **PSR-* infra** → Monolog logger, Symfony cache pool wrapped as
   PSR-16, Symfony EventDispatcher as PSR-14 dispatcher.

## Direct mode

<!-- @code-block language="text" label="direct" -->
```text
Symfony controller / handler
    │
    │  $this->opcua->connect()
    ▼
OpcuaManager::makeConnection()
    │
    │  ClientBuilder::create()
    │      ->setSecurityPolicy(...)
    │      ->setSecurityMode(...)
    │      ->setUserCredentials(...)
    │      ->setLogger(...)
    │      ->setCache(...)
    │      ->setEventDispatcher(...)
    │      ->connect($endpointUrl)
    ▼
   Client (opcua-client) ─── TCP ───► OPC UA server
```
<!-- @endcode-block -->

A fresh TCP connection per Symfony request. Cheap in setup, but
the full OPC UA handshake (TCP, Hello/Ack, OpenSecureChannel,
CreateSession, ActivateSession) costs 50–200 ms per request.

For low-frequency endpoints this is fine. For high-frequency or
real-time data, prefer managed mode.

## Managed mode

<!-- @code-block language="text" label="managed" -->
```text
Symfony controller / handler              opcua-session-manager daemon
    │                                       (long-running process)
    │  $this->opcua->connect()                   ▲
    ▼                                            │ holds OPC UA session
OpcuaManager::makeConnection()                   │ across requests
    │                                            │
    │  new ManagedClient($socketPath)            │
    │      ->setSecurityPolicy(...)              │
    │      ->setUserCredentials(...)             │
    ▼                                            │
   ManagedClient ───── Unix socket / TCP loopback ─────► Daemon
                          (NDJSON IPC)                     │
                                                            │ TCP
                                                            ▼
                                                       OPC UA server
```
<!-- @endcode-block -->

The first Symfony request opens a session on the daemon. Every
subsequent request — across workers, across deploys — reuses
that same session. The handshake cost amortises to zero.

You start the daemon with:

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console opcua:session
```
<!-- @endcode-block -->

…and run it under systemd, Supervisor, Docker, or Kubernetes.
See [Production supervisor](../session-manager/production-supervisor.md).

## The switch

`OpcuaManager::shouldUseSessionManager()` decides at every
`connect()` call:

1. Is `session_manager.enabled` `true`? If not → direct mode.
2. Is the configured socket / TCP endpoint reachable?
   - Unix socket: `file_exists($path)`.
   - TCP loopback: presence is **assumed** — first IPC call
     surfaces the connectivity error.
3. If reachable → managed mode. Otherwise → direct mode.

**The same controller code works in both modes.** The bundle
abstracts the choice.

## What lives where

| Concern                                | Lives in                               | Touch when…                          |
| -------------------------------------- | -------------------------------------- | ------------------------------------ |
| YAML schema & DI wiring                 | `PhpOpcuaSymfonyOpcuaBundle`            | Adding a new config key              |
| Connection caching & manager API        | `OpcuaManager`                          | Adding a new lifecycle hook          |
| `opcua:session` command                  | `Command/SessionCommand`                | Adding a new daemon CLI option       |
| Runtime logger override / TimestampedLogger | `Logging/`                            | Customising logger plumbing          |
| Connection / session protocol            | `opcua-client`                          | OPC UA wire / module changes         |
| Daemon process & IPC                     | `opcua-session-manager`                 | Daemon hardening, transport features |

The bundle stays **thin**. Anything not Symfony-specific lives
in the upstream packages.

## Where Symfony hooks in

| Hook                              | Used for                                          |
| --------------------------------- | ------------------------------------------------- |
| `AbstractBundle::configure()`      | YAML schema via `DefinitionConfigurator`           |
| `AbstractBundle::loadExtension()`  | Service registration                               |
| `ContainerBuilder::setDefinition()` | Logger ServiceLocator + resolver factory closure   |
| `Symfony\Bundle\FrameworkBundle\Console\Command` | The `opcua:session` command           |
| `Symfony\Component\EventDispatcher` | PSR-14 events flow through it transparently       |
| `Symfony\Component\Cache\Psr16Cache` | Wraps Symfony cache pool to expose PSR-16        |

No service-tag introspection magic, no compiler passes (yet) —
the bundle's wiring is straight-line and easy to read.

## What you don't get out of the box

Things you might expect from a Symfony bundle that this one
**deliberately doesn't ship**:

- **Doctrine entities** — persistence shape is your call; see
  [Recipes · Persistent tag history](../recipes/persistent-tag-history.md).
- **Twig templates** — no UI is shipped.
- **A Symfony Profiler data collector** — not yet. See
  [Observability · Profiler](../observability/profiler-and-data-collectors.md)
  for a small one you can drop in.
- **Migrations** — no schema to migrate.

Everything else (Messenger handlers, Mercure publishers, Console
commands, Notifier channels) you write yourself, with the
manager autowired — see [Integrations](../integrations/messenger.md).

## Where to read next

- [Manager vs interface](../using-the-client/manager-vs-interface.md) —
  which type to inject.
- [Session manager · Overview](../session-manager/overview.md) —
  managed mode in detail.
