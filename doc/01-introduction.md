# Introduction

Symfony integration for [OPC UA](https://opcfoundation.org/about/opc-technologies/opc-ua/) built on [`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client) and [`php-opcua/opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager).

This bundle wraps the full OPC UA client API with Symfony conventions: YAML-based semantic configuration, dependency injection, autowiring, and a console command for the optional session manager daemon.

## Requirements

| Dependency | Version |
|---|---|
| PHP | >= 8.2 |
| ext-openssl | * |
| Symfony | 6.4 or 7.x |

## Quick Start

```bash
composer require php-opcua/symfony-opcua
```

Add the bundle to `config/bundles.php` (auto-registered with Symfony Flex):

```php
return [
    // ...
    PhpOpcua\SymfonyOpcua\PhpOpcuaSymfonyOpcuaBundle::class => ['all' => true],
];
```

Create `config/packages/php_opcua_symfony_opcua.yaml`:

```yaml
php_opcua_symfony_opcua:
    connections:
        default:
            endpoint: '%env(OPCUA_ENDPOINT)%'
```

Inject and use:

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class PlcController
{
    public function index(OpcuaManager $opcua): Response
    {
        $client = $opcua->connect();
        $value = $client->read('i=2259');
        echo $value->getValue(); // 0 = Running
        $client->disconnect();
    }
}
```

## Features

- **Dependency injection** with autowiring for `OpcuaManager` and `OpcUaClientInterface`
- **Named connections** — define multiple OPC UA servers in YAML config
- **Ad-hoc connections** — `connectTo()` for runtime endpoints
- **Transparent session management** — daemon-based persistence, automatic fallback to direct connections
- **String NodeIds** — `'i=2259'`, `'ns=2;s=MyNode'` everywhere
- **Fluent Builder API** — `readMulti()`, `writeMulti()`, `createMonitoredItems()`, `translateBrowsePaths()`
- **PSR-3 Logging** — Symfony Monolog logger injected automatically
- **PSR-16 Caching** — Symfony cache pool wrapped as PSR-16 and injected automatically
- **PSR-14 Events** — 47 lifecycle and operation events dispatched through Symfony's event system
- **Trust Store** — `FileTrustStore` with configurable `TrustPolicy` for certificate trust management
- **Write Auto-Detection** — `write()` infers the OPC UA type automatically when the type parameter is omitted
- **Read Metadata Cache** — cached node metadata avoids redundant server round-trips on repeated reads
- **Type Discovery** — `discoverDataTypes()` for server-defined structured types
- **Subscription Transfer** — `transferSubscriptions()` / `republish()` for session recovery
- **Advanced Subscriptions** — `modifyMonitoredItems()`, `setTriggering()` for fine-grained monitoring control
- **Certificate Trust Management** — `trustCertificate()` / `untrustCertificate()` for runtime certificate handling
- **MockClient** — test without a server
- **All OPC UA operations** — browse, read, write, call, subscriptions, events, history

## Architecture

```
HTTP Request
    |
    v
$opcuaManager->connect()
    |
    +-- socket exists? --> YES --> ManagedClient (Unix socket IPC to daemon)
    |                                     |
    +-- socket missing? -> NO  --> ClientBuilder::create()->...->connect($url)
                                          |
                                          v
                                  OPC UA Server
```

The `OpcuaManager` checks for the session manager daemon's Unix socket at connection time. If the socket exists, traffic routes through the daemon for session persistence. If not, a `ClientBuilder` constructs and connects a direct client. No code changes needed to switch between modes.

## Documentation Index

| # | Document | Covers |
|---|----------|--------|
| 01 | [Introduction](01-introduction.md) | This page |
| 02 | [Installation & Configuration](02-installation.md) | Composer, YAML config, connections |
| 03 | [Usage](03-usage.md) | Reading, writing, browsing, methods, subscriptions, history |
| 04 | [Connections](04-connections.md) | Named, ad-hoc, switching, dependency injection |
| 05 | [Session Manager](05-session-manager.md) | Daemon, console command, systemd |
| 06 | [Logging & Caching](06-logging-caching.md) | PSR-3/PSR-16, Symfony integration |
| 07 | [Security](07-security.md) | Policies, modes, certificates, authentication |
| 08 | [Testing](08-testing.md) | MockClient, DataValue factories, test infrastructure |
| 09 | [Examples](09-examples.md) | Complete code examples |
| 10 | [Auto-Publish & Monitoring](10-auto-publish.md) | Auto-publish, auto-connect, event listeners |

## Ecosystem

| Package | Description |
|---------|-------------|
| [php-opcua/opcua-client](https://github.com/php-opcua/opcua-client) | Pure PHP OPC UA client — the core protocol implementation |
| [php-opcua/opcua-session-manager](https://github.com/php-opcua/opcua-session-manager) | Daemon-based session persistence |
| [php-opcua/laravel-opcua](https://github.com/php-opcua/laravel-opcua) | Laravel integration |
| [php-opcua/symfony-opcua](https://github.com/php-opcua/symfony-opcua) | Symfony integration (this package) |
| [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) | Docker-based OPC UA test servers |
