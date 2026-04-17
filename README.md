<h1 align="center"><strong>OPC UA Symfony Bundle</strong></h1>

<div align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="assets/logo-light.svg">
    <img alt="OPC UA Symfony Bundle" src="assets/logo-light.svg" width="435">
  </picture>
</div>

<p align="center">
  <a href="https://github.com/php-opcua/symfony-opcua/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/php-opcua/symfony-opcua/tests.yml?branch=master&label=tests&style=flat-square" alt="Tests"></a>
  <a href="https://codecov.io/gh/php-opcua/symfony-opcua"><img src="https://img.shields.io/codecov/c/github/php-opcua/symfony-opcua?style=flat-square&logo=codecov" alt="Coverage"></a>
  <a href="https://packagist.org/packages/php-opcua/symfony-opcua"><img src="https://img.shields.io/packagist/v/php-opcua/symfony-opcua?style=flat-square&label=packagist" alt="Latest Version"></a>
  <a href="https://packagist.org/packages/php-opcua/symfony-opcua"><img src="https://img.shields.io/packagist/php-v/php-opcua/symfony-opcua?style=flat-square" alt="PHP Version"></a>
  <img src="https://img.shields.io/badge/symfony-7.3%20|%207.4%20|%208.0-black?style=flat-square&logo=symfony" alt="Symfony Version">
  <a href="LICENSE"><img src="https://img.shields.io/github/license/php-opcua/symfony-opcua?style=flat-square" alt="License"></a>
</p>

<p align="center">
  <img src="https://custom-icon-badges.demolab.com/badge/Linux-✓-2ea44f?style=flat-square&logo=linux&logoColor=white" alt="Linux">
  <img src="https://custom-icon-badges.demolab.com/badge/macOS-✓-2ea44f?style=flat-square&logo=apple&logoColor=white" alt="macOS">
  <img src="https://custom-icon-badges.demolab.com/badge/Windows-✓-2ea44f?style=flat-square&logo=windows11&logoColor=white" alt="Windows">
</p>

---

Symfony integration for [OPC UA](https://opcfoundation.org/about/opc-technologies/opc-ua/) built on [`opcua-client`](https://github.com/php-opcua/opcua-client) and [`opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager). Connect your Symfony app to PLCs, SCADA systems, sensors, and IoT devices with a familiar developer experience: YAML-based semantic configuration, autowirable services, named connections, and a console command for the optional session manager daemon.

**What you get:**

- **Dependency injection** — autowire `OpcuaManager` or `OpcUaClientInterface` anywhere in your application
- **Named connections** — define multiple OPC UA servers and switch between them, just like Doctrine connections
- **Transparent session management** — when the daemon is running, connections persist across HTTP requests; when it's not, direct per-request connections with zero code changes
- **Symfony-native logging and caching** — your Monolog channel and cache pool are automatically injected into every OPC UA client
- **All OPC UA operations** — browse, read, write, method calls, subscriptions, events, history, path resolution, type discovery
- **PSR-14 events** — 47 dispatched events covering every OPC UA operation for observability and extensibility
- **Auto-publish** — daemon monitors subscriptions automatically and dispatches PSR-14 events to your Symfony listeners — no manual publish loop
- **Auto-connect** — define subscriptions in YAML config per connection and the daemon sets them up at startup
- **Trust store** — certificate trust management with configurable policies and auto-accept modes
- **Write auto-detection** — omit the type parameter and let the client detect the correct OPC UA type automatically

> **Note:** This bundle wraps the full [opcua-client](https://github.com/php-opcua/opcua-client) API with Symfony conventions. For the underlying protocol details, types, and advanced features, see the [client documentation](https://github.com/php-opcua/opcua-client/tree/master/doc).

<table>
<tr>
<td>

### Tested against the OPC UA reference implementation

The underlying [opcua-client](https://github.com/php-opcua/opcua-client) is integration-tested against **[UA-.NETStandard](https://github.com/OPCFoundation/UA-.NETStandard)** — the **reference implementation** maintained by the OPC Foundation, the organization that defines the OPC UA specification. This is the same stack used by major industrial vendors to certify their products.

This Symfony bundle is additionally integration-tested via [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) in both direct and managed (daemon) modes, ensuring full compatibility across all connection strategies. Like [opcua-client](https://github.com/php-opcua/opcua-client) and [opcua-session-manager](https://github.com/php-opcua/opcua-session-manager), unit tests run cross-OS — **Linux, macOS, and Windows** across PHP 8.2–8.5 × Symfony 7.3 / 7.4 / 8.0 — on every push. Integration tests stay on Linux (Docker-hosted OPC UA servers).

</td>
</tr>
</table>

<table>
<tr>
<td>

### Runs on Linux, macOS, and Windows

The session manager IPC auto-selects the right transport per platform — zero app-side changes.

| Platform | Default transport | Endpoint URI |
|---|---|---|
| Linux / macOS | Unix-domain socket | `unix://<project_dir>/var/opcua-session-manager.sock` (scheme-less paths are accepted too) |
| Windows | TCP loopback | `tcp://127.0.0.1:9990` |

Under `php_opcua_symfony_opcua.session_manager.socket_path` you can set either a `unix://<path>`, a `tcp://127.0.0.1:<port>` (loopback-only — non-loopback hosts are refused on both client and daemon sides), or a scheme-less path (= `unix://<path>`, backwards-compatible with pre-v4.2.0 configs). For Windows deployments either set the value explicitly to `tcp://127.0.0.1:9990` or rely on `TransportFactory::defaultEndpoint()` in your bootstrap.

</td>
</tr>
</table>

## Requirements

| Dependency | Version |
|---|---|
| PHP | >= 8.2 |
| ext-openssl | * |
| Symfony | 7.3, 7.4, 8.0+ |

> **Symfony 6.4 / 7.0 / 7.1 / 7.2:** the bundle source code is compatible with these versions and will install correctly, but they are **not tested in CI** due to a transitive dependency constraint in Pest 3. If you use one of these versions and encounter any issue, please [open a bug report](https://github.com/php-opcua/symfony-opcua/issues/new?template=bug_report.md) — we will evaluate and fix on a case-by-case basis. See [ROADMAP.md](ROADMAP.md#ci-testing-on-symfony-64--70--71--72) for the full rationale.

## Quick Start

```bash
composer require php-opcua/symfony-opcua
```

```yaml
# config/packages/php_opcua_symfony_opcua.yaml
php_opcua_symfony_opcua:
    connections:
        default:
            endpoint: '%env(OPCUA_ENDPOINT)%'
```

```php
use PhpOpcua\SymfonyOpcua\OpcuaManager;

class PlcController
{
    public function __construct(private readonly OpcuaManager $opcua) {}

    public function index(): Response
    {
        $client = $this->opcua->connect();
        $value = $client->read('i=2259');
        echo $value->getValue(); // 0 = Running
        $client->disconnect();
    }
}
```

That's it. Autowire, YAML config, connect, read. Everything else is optional.

## See It in Action

### Browse the address space

```php
$client = $this->opcua->connect();

$refs = $client->browse('i=85'); // Objects folder
foreach ($refs as $ref) {
    echo "{$ref->displayName} ({$ref->nodeId})\n";
}

$client->disconnect();
```

### Read multiple values with fluent builder

```php
$client = $this->opcua->connect();

$results = $client->readMulti()
    ->node('i=2259')->value()
    ->node('ns=2;i=1001')->displayName()
    ->execute();

foreach ($results as $dv) {
    echo $dv->getValue() . "\n";
}

$client->disconnect();
```

### Write to a PLC

```php
use PhpOpcua\Client\Types\BuiltinType;

$client = $this->opcua->connect();
$client->write('ns=2;i=1001', 42, BuiltinType::Int32);

// Or let the client auto-detect the type
$client->write('ns=2;i=1001', 42);

$client->disconnect();
```

### Call a method on the server

```php
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;

$client = $this->opcua->connect();

$result = $client->call(
    'i=2253',   // Server object
    'i=11492',  // Method
    [new Variant(BuiltinType::UInt32, 1)],
);

echo $result->statusCode;               // 0
echo $result->outputArguments[0]->value; // [1001, 1002, ...]

$client->disconnect();
```

### Subscribe to data changes

```php
$client = $this->opcua->connect();

$sub = $client->createSubscription(publishingInterval: 500.0);
$client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => 'ns=2;i=1001'],
]);

$response = $client->publish();
foreach ($response->notifications as $notif) {
    echo $notif['dataValue']->getValue() . "\n";
}

$client->deleteSubscription($sub->subscriptionId);
$client->disconnect();
```

### Auto-publish — no manual publish loop

With `auto_publish` enabled, the daemon handles subscriptions automatically. Just register Symfony event listeners:

```yaml
# config/packages/php_opcua_symfony_opcua.yaml
php_opcua_symfony_opcua:
    session_manager:
        auto_publish: true

    connections:
        plc-1:
            endpoint: 'opc.tcp://192.168.1.10:4840'
            auto_connect: true
            subscriptions:
                - publishing_interval: 500.0
                  monitored_items:
                      - { node_id: 'ns=2;s=Temperature', client_handle: 1 }
```

```php
use PhpOpcua\Client\Event\DataChangeReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class StoreSensorReading
{
    public function __invoke(DataChangeReceived $e): void
    {
        // Store $e->dataValue->getValue() with $e->clientHandle
    }
}
```

```bash
php bin/console opcua:session  # connects, subscribes, publishes — all automatic
```

### Switch connections

```php
// Named connection from config
$client = $this->opcua->connect('plc-line-1');
$value = $client->read('ns=2;i=1001');
$this->opcua->disconnect('plc-line-1');

// Ad-hoc connection at runtime
$client = $this->opcua->connectTo('opc.tcp://10.0.0.50:4840', [
    'username' => 'operator',
    'password' => 'secret',
], as: 'temp-plc');

$this->opcua->disconnectAll();
```

### Inject the default connection directly

```php
use PhpOpcua\Client\OpcUaClientInterface;

class PlcService
{
    public function __construct(private readonly OpcUaClientInterface $client) {}

    public function readServerState(): int
    {
        return $this->client->read('i=2259')->getValue();
    }
}
```

### Test without a real server

```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;

$mock = MockClient::create()
    ->onRead('i=2259', fn() => DataValue::ofInt32(0));

// Inject into OpcuaManager via DI or reflection
$value = $mock->read('i=2259');
echo $value->getValue(); // 0
echo $mock->callCount('read'); // 1
```

## Features

| Feature | What it does |
|---|---|
| **Autowiring** | Inject `OpcuaManager` or `OpcUaClientInterface` (default connection) anywhere via constructor injection |
| **Named Connections** | Define multiple servers in YAML config, switch with `$opcua->connection('plc-2')` |
| **Ad-hoc Connections** | `$opcua->connectTo('opc.tcp://...')` for endpoints not in config |
| **Session Manager** | Console command `php bin/console opcua:session` for daemon-based session persistence |
| **Transparent Fallback** | Daemon available? ManagedClient. Not available? Direct Client. Zero code changes |
| **String NodeIds** | `'i=2259'`, `'ns=2;s=MyNode'` everywhere a `NodeId` is accepted |
| **Fluent Builder API** | `readMulti()`, `writeMulti()`, `createMonitoredItems()`, `translateBrowsePaths()` chain |
| **PSR-3 Logging** | Monolog channel injected automatically via `log_channel` config |
| **PSR-14 Events** | 47 dispatched events covering every OPC UA operation for observability and extensibility |
| **PSR-16 Caching** | Symfony cache pool wrapped as PSR-16 and injected automatically. Per-call `useCache` on browse ops |
| **Write Auto-Detection** | `write('ns=2;i=1001', 42)` — omit the type and the client detects it automatically |
| **Read Metadata Cache** | Cached node metadata avoids redundant reads; refresh on demand with `read($nodeId, refresh: true)` |
| **Trust Store** | `FileTrustStore` with configurable `TrustPolicy`, auto-accept modes, and certificate management |
| **Type Discovery** | `discoverDataTypes()` auto-detects custom server structures |
| **Auto-Publish** | Daemon auto-publishes for sessions with subscriptions, dispatches PSR-14 events to Symfony listeners |
| **Auto-Connect** | Per-connection `auto_connect` with declarative `subscriptions` config — daemon sets up monitoring on startup |
| **Subscription Management** | `createMonitoredItems()`, `modifyMonitoredItems()`, `setTriggering()`, `transferSubscriptions()` |
| **MockClient** | Test without a server — register handlers, assert calls |
| **Timeout & Retry** | Per-connection `timeout`, `auto_retry` via YAML config |
| **Auto-Batching** | `readMulti`/`writeMulti` transparently split when exceeding server limits |
| **Recursive Browse** | `browseAll()`, `browseRecursive()` with depth control and cycle detection |
| **Path Resolution** | `resolveNodeId('/Objects/Server/ServerStatus')` |
| **Security** | 10 policies (RSA + ECC), 3 auth modes, auto-generated certs, certificate trust management |
| **History Read** | Raw, processed, and at-time historical queries |
| **Typed Returns** | All service responses return `public readonly` DTOs |

> **ECC disclaimer:** ECC security policies (`ECC_nistP256`, `ECC_nistP384`, `ECC_brainpoolP256r1`, `ECC_brainpoolP384r1`) are fully implemented and tested against the OPC Foundation's UA-.NETStandard reference stack. However, no commercial OPC UA vendor supports ECC endpoints yet. When using ECC, client certificates are auto-generated if `client_certificate`/`client_key` are omitted, and username/password authentication uses the `EccEncryptedSecret` protocol automatically.

## Documentation

| # | Document | Covers |
|---|----------|--------|
| 01 | [Introduction](doc/01-introduction.md) | Overview, requirements, architecture, quick start |
| 02 | [Installation & Configuration](doc/02-installation.md) | Composer, YAML config, connections, session manager |
| 03 | [Usage](doc/03-usage.md) | Reading, writing, browsing, methods, subscriptions, history |
| 04 | [Connections](doc/04-connections.md) | Named, ad-hoc, switching, disconnect, dependency injection |
| 05 | [Session Manager](doc/05-session-manager.md) | Daemon, console command, systemd, architecture |
| 06 | [Logging & Caching](doc/06-logging-caching.md) | PSR-3/PSR-16, Symfony integration, per-call cache control |
| 07 | [Security](doc/07-security.md) | Policies, modes, certificates, authentication |
| 08 | [Testing](doc/08-testing.md) | MockClient, DataValue factories, unit and integration tests |
| 09 | [Examples](doc/09-examples.md) | Complete code examples for all features |
| 10 | [Auto-Publish & Monitoring](doc/10-auto-publish.md) | Auto-publish, auto-connect, event listeners, real-world use case |

## Testing

144+ unit tests with **99%+ code coverage**. Integration tests run against [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) — a Docker-based OPC UA environment built on the OPC Foundation's UA-.NETStandard reference implementation — in both direct and managed (daemon) modes.

```bash
./vendor/bin/pest tests/Unit/                              # unit only
./vendor/bin/pest tests/Integration/ --group=integration   # integration only
./vendor/bin/pest                                          # everything
```

## Ecosystem

| Package | Description |
|---------|-------------|
| [opcua-client](https://github.com/php-opcua/opcua-client) | Pure PHP OPC UA client |
| [opcua-cli](https://github.com/php-opcua/opcua-cli) | CLI tool — browse, read, write, watch, discover endpoints, manage certificates, generate code from NodeSet2.xml |
| [opcua-session-manager](https://github.com/php-opcua/opcua-session-manager) | Daemon-based session persistence across PHP requests. Keeps OPC UA connections alive between short-lived PHP processes via a ReactPHP daemon and Unix sockets. |
| [opcua-client-nodeset](https://github.com/php-opcua/opcua-client-nodeset) | Pre-generated PHP types from 51 OPC Foundation companion specifications (DI, Robotics, Machinery, MachineTool, ISA-95, CNC, MTConnect, and more). 807 PHP files — NodeId constants, enums, typed DTOs, codecs, registrars with automatic dependency resolution. |
| [laravel-opcua](https://github.com/php-opcua/laravel-opcua) | Laravel integration — service provider, facade, config |
| [symfony-opcua](https://github.com/php-opcua/symfony-opcua) | Symfony integration — bundle, autowiring, YAML config (this package) |
| [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) | Docker-based OPC UA test servers (UA-.NETStandard) for integration testing |

## Community

Have questions, ideas, or want to share what you've built? Join the [GitHub Discussions](https://github.com/php-opcua/symfony-opcua/discussions).

**Connected a PLC, SCADA system, or OPC UA server?** We're building a community-driven list of tested hardware and software. Share your experience in [Tested Hardware & Software](https://github.com/php-opcua/symfony-opcua/discussions/categories/tested-hardware-software) — even a one-liner like "Siemens S7-1500, works fine" helps other users know what to expect.

## Contributing

Contributions welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

## AI-Ready

This package ships with machine-readable documentation designed for AI coding assistants (Claude, Cursor, Copilot, ChatGPT, and others). Feed these files to your AI so it knows how to use the library correctly:

| File | Purpose |
|------|---------|
| [`llms.txt`](llms.txt) | Compact project summary — architecture, autowiring, configuration, session manager. Optimized for LLM context windows with minimal token usage. |
| [`llms-full.txt`](llms-full.txt) | Comprehensive technical reference — every config key, method, DTO, event, trust store, managed client. For deep dives and complex questions. |
| [`llms-skills.md`](llms-skills.md) | Task-oriented recipes — step-by-step instructions for common tasks (install, read, write, browse, named connections, session manager, security, testing, events). Written so an AI can generate correct, production-ready code from a user's intent. |

**How to use:** copy the files you need into your project's AI configuration directory. The files are located in `vendor/php-opcua/symfony-opcua/` after `composer install`.

- **Claude Code**: reference per-session with `--add-file vendor/php-opcua/symfony-opcua/llms-skills.md`
- **Cursor**: copy into your project's rules directory — `cp vendor/php-opcua/symfony-opcua/llms-skills.md .cursor/rules/symfony-opcua.md`
- **GitHub Copilot**: copy or append the content into your project's `.github/copilot-instructions.md` file (create the file and directory if they don't exist). Copilot reads this file automatically for project-specific context
- **Other tools**: paste the content into your system prompt, project knowledge base, or context configuration

## Versioning

This package follows the same version numbering as [`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client). Each release of `symfony-opcua` is aligned with the corresponding release of the client library to ensure full compatibility.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

[MIT](LICENSE)
