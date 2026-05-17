---
eyebrow: 'Docs · Reference'
lede:    'Every method on PhpOpcua\\SymfonyOpcua\\OpcuaManager — connection management, runtime logger override, the magic __call proxy.'

see_also:
  - { href: './bundle-services.md',                       meta: '4 min' }
  - { href: './exceptions.md',                            meta: '4 min' }
  - { href: '../using-the-client/manager-vs-interface.md', meta: '5 min' }

prev: { label: 'Notifier',         href: '../integrations/notifier.md' }
next: { label: 'Bundle services',  href: './bundle-services.md' }
---

# OpcuaManager API

Reference for `PhpOpcua\SymfonyOpcua\OpcuaManager`. Most
methods autowire via the manager service (`opcua`).

## Construction

<!-- @code-block language="php" label="constructor" -->
```php
public function __construct(
    array $config,
    ?LoggerInterface $defaultLogger = null,
    ?CacheInterface $defaultCache = null,
    ?EventDispatcherInterface $defaultEventDispatcher = null,
    ?\Closure $loggerResolver = null,
);
```
<!-- @endcode-block -->

Wired by the bundle's `loadExtension()` — you rarely construct
this yourself outside of tests.

| Parameter                | Type                              | Purpose                                              |
| ------------------------ | --------------------------------- | ---------------------------------------------------- |
| `$config`                 | `array`                            | The merged YAML config tree                          |
| `$defaultLogger`           | `?LoggerInterface`                 | The default PSR-3 logger                              |
| `$defaultCache`            | `?CacheInterface`                  | The PSR-16-wrapped cache pool                         |
| `$defaultEventDispatcher`   | `?EventDispatcherInterface`        | Symfony's EventDispatcher (PSR-14)                    |
| `$loggerResolver`           | `?\Closure(string): ?LoggerInterface` | Maps `log_channel` strings to Monolog services        |

## Connection management

### `connect()`

<!-- @code-block language="php" label="connect" -->
```php
public function connect(?string $name = null): OpcUaClientInterface
```
<!-- @endcode-block -->

Returns a **connected** client for the named connection. In
managed mode, opens the daemon session if not yet opened.

`$name` defaults to `config('php_opcua_symfony_opcua.default')`,
which defaults to `'default'`.

Throws `InvalidArgumentException` if the name isn't in
`connections.*`.

### `connection()`

<!-- @code-block language="php" label="connection" -->
```php
public function connection(?string $name = null): OpcUaClientInterface
```
<!-- @endcode-block -->

Returns a client without forcing connection (in managed mode).
For direct mode, equivalent to `connect()`.

### `connectTo()`

<!-- @code-block language="php" label="connectTo" -->
```php
public function connectTo(
    string $endpointUrl,
    array $config = [],
    ?string $as = null,
): OpcUaClientInterface
```
<!-- @endcode-block -->

Opens an **ad-hoc** connection from a config array. `$as`
caches under that key — calls with the same `$as` return the
same client.

Config keys: same as a YAML `connections.*` entry.

### `disconnect()`

<!-- @code-block language="php" label="disconnect" -->
```php
public function disconnect(string|OpcUaClientInterface|null $target = null): void
```
<!-- @endcode-block -->

Closes a connection. Accepts a name, a client instance, or
`null` (default).

### `disconnectAll()`

<!-- @code-block language="php" label="disconnectAll" -->
```php
public function disconnectAll(): void
```
<!-- @endcode-block -->

Closes every cached connection.

### `isSessionManagerRunning()`

<!-- @code-block language="php" label="probe daemon" -->
```php
public function isSessionManagerRunning(): bool
```
<!-- @endcode-block -->

Probes the configured daemon endpoint. `true` if reachable
(Unix socket exists, or TCP — assumed reachable).

### `getDefaultConnection()`

<!-- @code-block language="php" label="default name" -->
```php
public function getDefaultConnection(): string
```
<!-- @endcode-block -->

Returns the default connection name.

## Logger surface (v4.3+)

### `setLogger()`

<!-- @code-block language="php" label="setLogger" -->
```php
public function setLogger(LoggerInterface $logger): self
```
<!-- @endcode-block -->

Sets a **runtime** logger that overrides any per-connection
`log_channel` / `logger` config. Propagated to existing
connections via `method_exists($client, 'setLogger')`.

Returns `$this` for chaining.

### `getLogger()`

<!-- @code-block language="php" label="getLogger" -->
```php
public function getLogger(): ?LoggerInterface
```
<!-- @endcode-block -->

Returns the runtime logger override, or `null` if none.

### `useConsoleLogger()`

<!-- @code-block language="php" label="useConsoleLogger" -->
```php
public function useConsoleLogger(
    OutputInterface $output,
    array $verbosityMap = [],
    array $formatLevelMap = [],
    ?string $dateFormat = 'Y-m-d H:i:s.v',
): self
```
<!-- @endcode-block -->

Attaches a Symfony `ConsoleLogger` (respecting `-v` / `-vv` /
`-vvv`). By default wraps in `TimestampedLogger`. Pass
`dateFormat: null` to disable the wrap.

Returns `$this` for chaining.

## Magic `__call`

`OpcuaManager::__call($method, $args)` forwards to the default
connection's client. So:

<!-- @code-block language="php" label="implicit proxy" -->
```php
$opcua->read('ns=2;s=Speed');
// is internally:
$opcua->connection()->read('ns=2;s=Speed');
```
<!-- @endcode-block -->

That's how `Opcua::read()` works without the manager defining
`read()` explicitly.

This works for any method on `OpcUaClientInterface` — `write`,
`browse`, `subscribe`, `callMethod`, etc.

## Subclassing

For runtime customisation:

<!-- @code-block language="php" label="custom manager" -->
```php
final class CustomOpcuaManager extends OpcuaManager
{
    public function connect(?string $name = null): OpcUaClientInterface
    {
        $client = parent::connect($name);
        // Custom logging / metrics / etc.
        return $client;
    }
}
```
<!-- @endcode-block -->

Register the subclass via service decoration — see
[Configuration · Parameters and overrides](../configuration/parameters-and-overrides.md).

## Where to read next

- [Bundle services](./bundle-services.md) — all the services
  the bundle registers.
- [Console commands](./console-commands.md) — CLI surface.
- [Exceptions](./exceptions.md) — what can be thrown.
