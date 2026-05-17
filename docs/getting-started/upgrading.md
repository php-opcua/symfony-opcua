---
eyebrow: 'Docs · Getting started'
lede:    'Symfony / PHP minimums, BC policy, and the v4.3 cache-flush note. Everything you need to track the upstream library bumps without breaking your app.'

see_also:
  - { href: '../reference/opcua-manager-api.md',   meta: '5 min' }
  - { href: '../observability/caching.md',         meta: '5 min' }
  - { href: '../session-manager/overview.md',      meta: '5 min' }

prev: { label: 'How symfony-opcua fits',  href: './how-symfony-opcua-fits.md' }
next: { label: 'Bundle YAML',              href: '../configuration/bundle-yaml.md' }
---

# Upgrading

The bundle versions in **lockstep** with `opcua-client` and
`opcua-session-manager`. A `v4.3.x` bundle uses `^4.3.x` of both
upstream packages.

## Composer constraint

In your project's `composer.json`:

<!-- @code-block language="text" label="composer.json" -->
```text
"php-opcua/symfony-opcua": "^4.3"
```
<!-- @endcode-block -->

Caret allows minor/patch updates within the 4.x train. Major
bumps (4 → 5) signal an intentional breaking change.

## Reading the CHANGELOG

Three files matter for an upgrade:

| File                                                                                          | Read for                                            |
| --------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| [`symfony-opcua/CHANGELOG.md`](https://github.com/php-opcua/symfony-opcua/blob/master/CHANGELOG.md) | Bundle-side changes (config keys, services, commands) |
| [`opcua-client/CHANGELOG.md`](https://github.com/php-opcua/opcua-client/blob/master/CHANGELOG.md)   | Wire protocol, modules, builder API                  |
| [`opcua-session-manager/CHANGELOG.md`](https://github.com/php-opcua/opcua-session-manager/blob/master/CHANGELOG.md) | Daemon features and security             |

A bundle entry references both upstream entries with the
"downstream impact" callouts, so reading just the bundle one
usually suffices.

## Symfony version matrix

| Bundle version   | Symfony support                                |
| ---------------- | ---------------------------------------------- |
| `^4.1`            | 6.4 LTS, 7.x                                   |
| `^4.2`, `^4.3`    | 6.4 LTS, 7.x, 8.x                              |

PHP 8.2 is the minimum across the entire 4.x line. PHP 8.3 / 8.4
work transparently.

## v4.2 → v4.3 — what to do

For most apps: **two steps**.

### 1. Bump and update

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require php-opcua/symfony-opcua:^4.3
php bin/console cache:clear
```
<!-- @endcode-block -->

The cache clear is for Symfony's container cache; nothing
OPC UA-specific yet.

### 2. Flush the persistent cache pool

The OPC UA wire-cache codec was hardened — `unserialize()` is
out, JSON behind an allowlist is in. **Pre-v4.3 cached entries
are discarded on first access.**

If you use a Redis / database / file pool for the bundle's
cache, flush it explicitly to avoid a brief cold-cache window:

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console cache:pool:clear cache.app
```
<!-- @endcode-block -->

Or your specific pool name (`cache.system`, `cache.redis`, etc.).

That's the whole upgrade. Everything else (NodeManagement in
defaults, `ServiceFault` decoding, daemon socket-permission fix,
…) is transparent.

## Symfony Flex recipe drift

If you forked the project's Flex recipe, compare against the
current recipe shape:

<!-- @code-block language="bash" label="terminal" -->
```bash
composer recipes:install php-opcua/symfony-opcua --reset
```
<!-- @endcode-block -->

`--reset` overwrites the recipe-managed files; review the diff
before committing.

## Removing deprecated config keys

The bundle has been **additive-only** through the 4.x train —
no config keys have been removed. Your existing
`config/packages/php_opcua_symfony_opcua.yaml` works unchanged
on each new minor.

If a future major (5.x) does deprecate keys, the corresponding
entry in `symfony-opcua/CHANGELOG.md` will spell out the
migration path.

## After upgrade

A short sanity check:

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console debug:config php_opcua_symfony_opcua | head -40
php bin/console debug:autowiring opcua
php bin/console opcua:session --help
```
<!-- @endcode-block -->

All three should succeed. If the third one fails, the bundle's
console command isn't registered — most often a stale
`var/cache/` (run `cache:clear`).

## Long-running workers — restart

After the upgrade, **restart** any long-running workers:

<!-- @code-block language="bash" label="terminal" -->
```bash
sudo systemctl restart opcua-session-manager
sudo systemctl reload-or-restart 'messenger@*'
```
<!-- @endcode-block -->

Workers cache the autoload index at boot. Without a restart they
keep running the pre-upgrade code until the next deploy.

## What "supported" means

The bundle's CI matrix runs against:

- PHP 8.2, 8.3, 8.4, 8.5 (release candidates)
- Symfony 6.4 LTS, 7.0, 7.x current, 8.x current
- Ubuntu, macOS, Windows

A version combination outside this matrix may work but isn't
guaranteed. File issues at
[github.com/php-opcua/symfony-opcua/issues](https://github.com/php-opcua/symfony-opcua/issues).

## Where to read next

- [Bundle YAML](../configuration/bundle-yaml.md) — the full
  config tree.
- [Caching](../observability/caching.md) — the v4.3 cache-codec
  details.
