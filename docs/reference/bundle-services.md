---
eyebrow: 'Docs · Reference'
lede:    'Every container service the bundle registers — public and private. Useful when overriding, decorating, or debugging the DI tree.'

see_also:
  - { href: './opcua-manager-api.md',                       meta: '5 min' }
  - { href: '../configuration/parameters-and-overrides.md', meta: '5 min' }

prev: { label: 'OpcuaManager API',  href: './opcua-manager-api.md' }
next: { label: 'Console commands',  href: './console-commands.md' }
---

# Bundle services

The bundle registers a handful of services into the container.
This page lists each one with its purpose.

## Discover yourself

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console debug:container --show-arguments PhpOpcua\\SymfonyOpcua\\
```
<!-- @endcode-block -->

…or:

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console debug:autowiring opcua
```
<!-- @endcode-block -->

## Public services

| Service ID                              | Class                                       | Purpose                              |
| --------------------------------------- | ------------------------------------------- | ------------------------------------ |
| `PhpOpcua\SymfonyOpcua\OpcuaManager`     | `OpcuaManager`                               | The manager                           |
| `opcua`                                  | alias of `OpcuaManager`                       | Short id (legacy + convenience)      |
| `PhpOpcua\Client\OpcUaClientInterface`    | factory → `$manager->connection()`            | Default-connection client             |
| `PhpOpcua\SymfonyOpcua\Command\SessionCommand` | `SessionCommand`                       | The `opcua:session` console command   |

## Private / internal services

| Service ID                              | Class                                | Purpose                                  |
| --------------------------------------- | ------------------------------------ | ---------------------------------------- |
| `php_opcua.psr16_cache`                  | `Symfony\Component\Cache\Psr16Cache`  | PSR-16 wrapper around the cache pool      |
| `php_opcua.logger_locator`               | `Symfony\Component\DependencyInjection\ServiceLocator` | Maps `log_channel` → Monolog logger; only registered if at least one connection declares a `log_channel` |
| `php_opcua.logger_resolver`              | `\Closure`                            | Closure returned by `LoggerResolverFactory::create` — only registered with the locator |

These are private — Symfony marks them as `public: false` by
default. Accessible only via service injection or
`getContainer()` in test mode.

## Service wiring overview

<!-- @code-block language="text" label="DI graph" -->
```text
┌─────────────────────────────────────────────────────────────────────┐
│ PhpOpcua\SymfonyOpcua\OpcuaManager                                  │
│   $config              ← merged YAML tree                            │
│   $defaultLogger        ← Monolog "logger" or "monolog.logger.X"    │
│   $defaultCache         ← @php_opcua.psr16_cache                     │
│   $defaultEventDispatcher ← @event_dispatcher (nullable)             │
│   $loggerResolver       ← @php_opcua.logger_resolver (nullable)      │
└─────────────────────────────────────────────────────────────────────┘
        ▲
        │ aliased as "opcua"
        │
        │ factory call → connection()
        │
┌─────────────────────────────────────────────────────────────────────┐
│ PhpOpcua\Client\OpcUaClientInterface                                │
│   Returned by OpcuaManager::connection(null)                         │
└─────────────────────────────────────────────────────────────────────┘
```
<!-- @endcode-block -->

## Logger resolver wiring

When at least one connection declares `log_channel: <name>`:

<!-- @code-block language="text" label="logger resolver" -->
```text
php_opcua.logger_locator: ServiceLocator
    { foo: ServiceClosure(@monolog.logger.foo, NULL_ON_INVALID_REFERENCE),
      bar: ServiceClosure(@monolog.logger.bar, NULL_ON_INVALID_REFERENCE) }

php_opcua.logger_resolver: Closure
    factory: [App\Logging\LoggerResolverFactory, create]
    args:    [@php_opcua.logger_locator]
```
<!-- @endcode-block -->

The closure receives a channel name → looks up in the locator
→ returns `LoggerInterface` or `null` (graceful fallback).

## Overriding services

See [Configuration · Parameters and overrides](../configuration/parameters-and-overrides.md)
for the full pattern. Quick summary:

### Decorate the manager

<!-- @code-block language="php" label="decorator" -->
```php
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator(decorates: OpcuaManager::class)]
final class LoggingOpcuaManager extends OpcuaManager
{
    public function __construct(
        private OpcuaManager $inner,
        // ... your deps
    ) {}

    public function connect(?string $name = null): OpcUaClientInterface
    {
        // ... wrap
        return $this->inner->connect($name);
    }
}
```
<!-- @endcode-block -->

### Replace `php_opcua.psr16_cache`

<!-- @code-block language="text" label="services.yaml" -->
```text
services:
    php_opcua.psr16_cache:
        class: App\Opcua\CustomPsr16Cache
        arguments: ['@my_cache_pool']
```
<!-- @endcode-block -->

## Container parameter — exposing the config

The bundle doesn't expose the full config as a container
parameter by default. If you need it (for a `HealthController`
listing connections), mirror it manually:

<!-- @code-block language="text" label="services.yaml" -->
```text
parameters:
    app.opcua_config_connections:
        # mirror here, or pull from container build
```
<!-- @endcode-block -->

Or, more practically, inject the full container parameter via
`%kernel.bundles_metadata%` shenanigans — but that's overkill.
The simplest is to pass the connection list explicitly to the
controller.

## Where to read next

- [Console commands](./console-commands.md) — bundle commands.
- [Exceptions](./exceptions.md) — exception hierarchy.
