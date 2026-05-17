---
eyebrow: 'Docs · Getting started'
lede:    'Composer require, bundle registration, minimal YAML — three commands to a working OPC UA-aware Symfony application.'

see_also:
  - { href: './quick-start.md',                           meta: '5 min' }
  - { href: '../configuration/bundle-yaml.md',            meta: '6 min' }
  - { href: '../configuration/environment-variables.md',  meta: '5 min' }

prev: { label: 'Overview',     href: '../overview.md' }
next: { label: 'Quick start',  href: './quick-start.md' }
---

# Installation

## Requirements

| Dependency        | Minimum                                                    |
| ----------------- | ---------------------------------------------------------- |
| PHP               | 8.2                                                        |
| Symfony framework | 6.4 LTS, 7.x, or 8.x                                       |
| Extensions        | `ext-openssl`, `ext-sockets`                                |
| Optional          | `monolog-bundle` (auto-channel routing), `symfony/cache`   |

The bundle declares all of its own transitive dependencies —
you don't need to install `opcua-client` or `opcua-session-manager`
separately.

## Composer

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require php-opcua/symfony-opcua
```
<!-- @endcode-block -->

Symfony Flex registers the bundle automatically. Confirm the
entry in `config/bundles.php`:

<!-- @code-block language="php" label="config/bundles.php" -->
```php
return [
    // ...
    PhpOpcua\SymfonyOpcua\PhpOpcuaSymfonyOpcuaBundle::class => ['all' => true],
];
```
<!-- @endcode-block -->

If Flex is disabled (rare), add the line manually.

## Minimal configuration

Create `config/packages/php_opcua_symfony_opcua.yaml`:

<!-- @code-block language="text" label="config/packages/php_opcua_symfony_opcua.yaml" -->
```text
php_opcua_symfony_opcua:
    connections:
        default:
            endpoint: '%env(OPCUA_ENDPOINT)%'
```
<!-- @endcode-block -->

And in `.env`:

<!-- @code-block language="bash" label=".env" -->
```bash
OPCUA_ENDPOINT=opc.tcp://127.0.0.1:4840
```
<!-- @endcode-block -->

That's all you need to autowire `OpcuaManager` anywhere in the
app. The bundle handles the rest:

- Picks up the default Monolog logger (`logger` service).
- Wraps Symfony's `cache.app` pool as PSR-16 and injects it.
- Resolves the optional `EventDispatcherInterface`.
- Probes the session-manager daemon socket and routes
  accordingly.

## What lands on disk

| Path                                                | Created by  | Notes                                |
| --------------------------------------------------- | ----------- | ------------------------------------ |
| `config/packages/php_opcua_symfony_opcua.yaml`       | You          | Required                              |
| `.env` `OPCUA_*` keys                                | You          | Required (or use a secrets vault)     |
| `var/opcua-session-manager.sock`                     | The daemon  | Created only when the daemon runs    |

The bundle ships **no migrations, no public assets, no
templates** — it's a service-only bundle. The first time you
need persistence (alarm history, etc.) you wire Doctrine
yourself — see [Recipes · Persistent tag history](../recipes/persistent-tag-history.md).

## Verify the install

In a Symfony command or controller:

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console debug:autowiring opcua
```
<!-- @endcode-block -->

Expected output (excerpt):

<!-- @code-block language="text" label="output" -->
```text
PhpOpcua\Client\OpcUaClientInterface
    PhpOpcua\Client\OpcUaClientInterface (opcua)

PhpOpcua\SymfonyOpcua\OpcuaManager
    PhpOpcua\SymfonyOpcua\OpcuaManager (opcua)
```
<!-- @endcode-block -->

Both services are autowired. Inject either into any service or
controller and the container resolves them.

## Verify the config

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console debug:config php_opcua_symfony_opcua
```
<!-- @endcode-block -->

Prints the fully-resolved config tree, including defaults.

## Optional: monolog channel

For a dedicated OPC UA log channel:

<!-- @code-block language="text" label="config/packages/monolog.yaml" -->
```text
monolog:
    channels: ['opcua']

    handlers:
        opcua:
            level: info
            channels: ['opcua']
            type: stream
            path: '%kernel.logs_dir%/opcua-%kernel.environment%.log'
```
<!-- @endcode-block -->

Then wire the bundle to use it (per-connection, or globally):

<!-- @code-block language="text" label="bundle config" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        log_channel: opcua
    connections:
        default:
            endpoint: '%env(OPCUA_ENDPOINT)%'
            log_channel: opcua
```
<!-- @endcode-block -->

See [Observability · Logging](../observability/logging.md).

## Optional: session-manager daemon

If you want connections to persist across requests:

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console opcua:session
```
<!-- @endcode-block -->

This is **completely optional**. Without it the bundle uses
direct connections — see
[Session manager · Overview](../session-manager/overview.md).

## Removing the bundle

<!-- @code-block language="bash" label="terminal" -->
```bash
composer remove php-opcua/symfony-opcua
```
<!-- @endcode-block -->

Flex removes the bundle from `config/bundles.php`. You may
delete `config/packages/php_opcua_symfony_opcua.yaml` and the
`OPCUA_*` env keys by hand — Flex won't touch them.

## Where to read next

- [Quick start](./quick-start.md) — the first connection.
- [How symfony-opcua fits](./how-symfony-opcua-fits.md) — the
  layered architecture.
