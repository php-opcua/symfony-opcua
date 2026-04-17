# Roadmap

## v4.2.0 — 2026-04-17

- [x] Bumped `php-opcua/opcua-client` to `^4.2.0` and `php-opcua/opcua-session-manager` to `^4.2.0`.
- [x] **Cross-OS session manager IPC** — `session_manager.socket_path` accepts `unix://`, `tcp://127.0.0.1:<port>` (loopback-only), or a scheme-less path (BC = `unix://`). `OpcuaManager::isSessionManagerRunning()` and `shouldUseSessionManager()` inspect the endpoint kind via `TransportFactory::toUnixPath()`.
- [x] `php bin/console opcua:session` adapts its startup table (Endpoint label, Socket Mode shown only for Unix endpoints) and skips `mkdir` for TCP endpoints.
- [x] CI workflow aligned with `opcua-client`: `unit` cross-OS × PHP 8.2–8.5 × Symfony 7.3 / 7.4 / 8.0; `integration` Ubuntu-only with `needs: unit`; `[DOC]` commit skip; `codecov-action` v5 → v6.

## Planned

.......

## Completed in v4.1.1

- [x] Initial release with full feature parity with `laravel-opcua`
- [x] `AbstractBundle` with TreeBuilder semantic configuration
- [x] `OpcuaManager` with autowiring and `OpcUaClientInterface` factory alias
- [x] `opcua:session` console command with auto-publish and auto-connect
- [x] PSR-16 cache adapter wrapping Symfony PSR-6 cache pools
- [x] Monolog channel injection via `log_channel` config
- [x] PSR-14 event dispatcher injection
- [x] All 10 security policies (6 RSA + 4 ECC)
- [x] 144 unit tests, 10 integration test files
- [x] Full documentation (10 doc files)
- [x] GitHub Actions CI with Symfony 7.3 / 7.4 / 8.0 matrix

## Won't Do (by design)

### Merge opcua-client or session-manager code

This package is a thin Symfony wrapper. It delegates all OPC UA protocol work to `opcua-client` and all session persistence to `opcua-session-manager`. It will never reimplement or duplicate their logic. New OPC UA features are automatically available through the `__call()` proxy — no code changes in this package beyond documentation.

### Custom Cache / Logger Implementations

This package uses Symfony's cache pools (wrapped as PSR-16 via `Psr16Cache`) and Monolog logger. It will not ship its own cache drivers or logger implementations — that's what `config/packages/cache.yaml` and `config/packages/monolog.yaml` are for. The underlying `opcua-client` provides `InMemoryCache` and `FileCache` for use cases outside Symfony.

### Doctrine Models / Database Integration

OPC UA data is real-time process data, not relational data. Mapping it to Doctrine entities would create a misleading abstraction. If you need to persist OPC UA values to a database, read the values and store them yourself — the two concerns should remain separate.

### CI testing on Symfony 6.4 / 7.0 / 7.1 / 7.2

The CI matrix tests against **Symfony 7.3+** only. The bundle source code does not use any API introduced after Symfony 6.4 and should work on older versions, but **we do not test or guarantee stability on Symfony < 7.3**.

The reason is a transitive dependency constraint: [Pest 3](https://pestphp.com) depends on `nunomaduro/collision` ^8.x, which requires `symfony/console ^7.1.3` as an absolute minimum. In practice, current Pest 3 releases resolve only with `symfony/console ^7.3` due to further constraints in `brianium/paratest`. This is an ecosystem-wide limitation — every PHP package that uses Pest 3 for testing faces the same floor.

Since the entire `php-opcua` ecosystem (`opcua-client`, `opcua-session-manager`, `laravel-opcua`) standardizes on Pest 3, switching to PHPUnit or downgrading to Pest 2 solely to run CI on older Symfony versions would break ecosystem consistency for marginal benefit.

**If you use Symfony 6.4 / 7.0 / 7.1 / 7.2:** the bundle will likely install and work correctly (`pestphp/pest` is a `require-dev` dependency and does not affect your project). However, these configurations are untested and unsupported — if you encounter an issue, please report it and we will evaluate on a case-by-case basis.

### WebSocket / Mercure Broadcasting of OPC UA Subscriptions

While OPC UA subscriptions produce real-time data, bridging them to Symfony Mercure or WebSockets requires a long-running worker process that holds the subscription open. This is better implemented in application code tailored to your specific use case (which nodes, what format, which topic) rather than as a generic bundle feature. The session manager daemon already keeps subscriptions alive — your worker just needs to call `publish()` in a loop.

---

Have a suggestion? Open an [issue](https://github.com/php-opcua/symfony-opcua/issues) or check the [contributing guide](CONTRIBUTING.md).
