# Changelog

## [4.2.0] - 2026-04-17

### Changed

- Bumped `php-opcua/opcua-client` from `^4.1.1` to `^4.2.0` and `php-opcua/opcua-session-manager` from `^4.1` to `^4.2.0`. Picks up the Kernel + ServiceModule architecture, the Wire-serialization pipeline, the describe/invoke IPC commands that make third-party modules reachable through `ManagedClient::__call()`, and the cross-platform IPC transport (Unix socket on Linux/macOS, TCP loopback on Windows).

### Added

- **Cross-platform session manager out of the box.** `php_opcua_symfony_opcua.session_manager.socket_path` now accepts:
  - `unix://<path>` (explicit Unix-domain socket)
  - `tcp://127.0.0.1:<port>` (loopback-only — non-loopback hosts are refused by `TcpLoopbackTransport` on the client and by `SessionManagerDaemon` on the daemon)
  - scheme-less path (interpreted as `unix://<path>`, backwards-compatible with pre-v4.2.0 configs)

  `OpcuaManager::isSessionManagerRunning()` and `OpcuaManager::shouldUseSessionManager()` now inspect the endpoint URI via `TransportFactory::toUnixPath()` — for Unix endpoints they keep the historical `file_exists()` check; TCP endpoints can't be filesystem-probed, so presence is assumed (a missing daemon surfaces as a clear `DaemonException` on the first IPC call).
- **`php bin/console opcua:session` reflects the endpoint kind.** The startup table shows "Endpoint" instead of "Socket", and the "Socket Mode" row is only printed for Unix-socket endpoints. The parent-directory `mkdir` is skipped for TCP endpoints.

### Tests / CI

- CI workflow aligned with `opcua-client` / `opcua-session-manager`: `unit` job cross-OS on `ubuntu-latest` / `macos-latest` / `windows-latest` × PHP 8.2–8.5 × Symfony 7.3 / 7.4 / 8.0 (with the existing exclusion matrix); `integration` job stays Ubuntu-only (Docker-hosted OPC UA servers) with `needs: unit` gating. `[DOC]` commits skip CI. `codecov/codecov-action` bumped from `v5` to `v6`.
- Unit tests (`tests/Unit/`) are fully cross-OS. Integration tests (`tests/Integration/`) remain Docker-dependent and run only in the integration job.
- Full suite: **244 passing, 0 failing** on Linux.

## [4.1.0] - 2026-04-14

### Added

- **Initial release of `php-opcua/symfony-opcua`.** Symfony bundle equivalent of `php-opcua/laravel-opcua`, providing full OPC UA integration for Symfony 6.4+ and 7.x applications.

#### Bundle

- **`PhpOpcuaSymfonyOpcuaBundle`** — modern `AbstractBundle` with semantic YAML configuration via TreeBuilder and service registration via `loadExtension()`.
- **`OpcuaManager`** — manages named connections, auto-detects session manager daemon, configures both direct (`ClientBuilder`) and managed (`ManagedClient`) modes. Autowirable as a service.
- **`OpcUaClientInterface` autowiring** — factory-based service definition that returns the default connection, injectable anywhere via constructor injection.
- **`opcua` alias** — convenience alias for `OpcuaManager`.
- **PSR-16 cache adapter** — automatically wraps a Symfony PSR-6 cache pool (`cache.app` by default) into `Psr16Cache` for the OPC UA client.
- **PSR-3 logging** — Monolog logger injected automatically. Configurable via `log_channel` to use a specific Monolog channel (`monolog.logger.<channel>`).
- **PSR-14 events** — Symfony's `EventDispatcherInterface` injected automatically when available. All 47 OPC UA events dispatched through Symfony's event system.

#### Console Command

- **`opcua:session`** — Symfony console command to start the session manager daemon. Supports `--timeout`, `--cleanup-interval`, `--max-sessions`, `--socket-mode` options.
- **Auto-publish support** — when `auto_publish` is enabled, the daemon automatically calls `publish()` for sessions with active subscriptions and dispatches PSR-14 events.
- **Auto-connect support** — connections with `auto_connect: true` are established on daemon startup with declarative subscription configuration.

#### Configuration

- Full YAML-based semantic configuration with TreeBuilder validation:
  - `default` — default connection name
  - `session_manager` — daemon settings (enabled, socket_path, timeout, cleanup_interval, auth_token, max_sessions, socket_mode, allowed_cert_dirs, log_channel, cache_pool, auto_publish)
  - `connections` — named connections with endpoint, security (10 policies including ECC), authentication, timeouts, trust store, write auto-detection, metadata cache, auto-connect, and declarative subscriptions
- Reference configuration file at `config/opcua.yaml`

#### Security

- All 10 security policies supported: `None`, `Basic128Rsa15`, `Basic256`, `Basic256Sha256`, `Aes128Sha256RsaOaep`, `Aes256Sha256RsaPss`, `ECC_nistP256`, `ECC_nistP384`, `ECC_brainpoolP256r1`, `ECC_brainpoolP384r1`.
- 3 security modes: `None`, `Sign`, `SignAndEncrypt`.
- 3 authentication methods: anonymous, username/password, X.509 certificate.
- Trust store with `FileTrustStore`, configurable `TrustPolicy` (`fingerprint`, `fingerprint+expiry`, `full`), auto-accept/TOFU mode.
- Auto-generated client certificates when `client_certificate`/`client_key` are omitted.

#### Features (inherited from opcua-client v4.1.1)

- **All OPC UA operations** — browse, read, write, call, subscriptions, events, history, path resolution, type discovery.
- **String NodeIds** — `'i=2259'`, `'ns=2;s=MyNode'` everywhere.
- **Fluent Builder API** — `readMulti()`, `writeMulti()`, `createMonitoredItems()`, `translateBrowsePaths()`.
- **Write auto-detection** — omit the type parameter on `write()`.
- **Read metadata cache** — cached node metadata with `refresh` parameter.
- **Type discovery** — `discoverDataTypes()` for server-defined structured types.
- **Subscription management** — `createMonitoredItems()`, `modifyMonitoredItems()`, `setTriggering()`, `transferSubscriptions()`, `republish()`.
- **Certificate trust management** — `trustCertificate()`, `untrustCertificate()`.
- **Auto-batching** — `readMulti`/`writeMulti` transparently split when exceeding server limits.
- **Recursive browse** — `browseAll()`, `browseRecursive()` with depth control.
- **Path resolution** — `resolveNodeId('/Objects/Server/ServerStatus')`.
- **MockClient** — test without a server.

#### Testing

- 144 unit tests with Pest PHP and Mockery.
- `OpcuaManagerTest` — 97 tests covering connection management, configuration, security resolution.
- `BundleTest` — 12 tests covering TreeBuilder, service registration, alias, factory definitions.
- `ConfigurationTest` — 10 tests covering defaults, custom values, subscriptions, validation.
- `SessionCommandTest` — 25 tests covering config passthrough, CLI overrides, auto-publish, auto-connect, daemon config mapping.

#### Documentation

- 10 documentation files (`doc/01-introduction.md` through `doc/10-auto-publish.md`) covering introduction, installation, usage, connections, session manager, logging & caching, security, testing, examples, and auto-publish.
- Full README with feature table, code examples, ecosystem overview, and AI-Ready section.

#### Dependencies

- `php-opcua/opcua-client` ^4.1.1
- `php-opcua/opcua-session-manager` ^4.1
- `symfony/framework-bundle` ^6.4|^7.0
- `symfony/console` ^6.4|^7.0
- `symfony/dependency-injection` ^6.4|^7.0
- `symfony/config` ^6.4|^7.0
- `symfony/cache` ^6.4|^7.0
