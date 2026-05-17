---
eyebrow: 'Docs · Observability'
lede:    'The bundle uses Symfony cache pools for metadata, trust-store, and protocol state. What gets cached, where, and what to flush when.'

see_also:
  - { href: './logging.md',                          meta: '5 min' }
  - { href: '../configuration/bundle-yaml.md',       meta: '6 min' }
  - { href: '../security/trust-store.md',            meta: '6 min' }

prev: { label: 'Logging',           href: './logging.md' }
next: { label: 'Debugging',         href: './debugging.md' }
---

# Caching

The bundle uses Symfony cache pools (via PSR-16 wrapping) for
two purposes:

1. **Metadata caching** — node attribute reads, browse results.
2. **Protocol state** — per-server chunk sizes, certificate
   validation outcomes, trust-store fingerprints.

Both are optional. With caching off, the bundle issues extra
network calls but works correctly.

## Choice of cache pool

The bundle wraps a Symfony PSR-6 pool as PSR-16 via the
`php_opcua.psr16_cache` service. The pool name comes from
`session_manager.cache_pool` (default `cache.app`).

Recommended pools:

| Pool          | When                                                       |
| ------------- | ---------------------------------------------------------- |
| `cache.redis`  | Multi-process apps (FPM, Messenger). The canonical choice.  |
| `cache.adapter.doctrine_dbal` | When Redis isn't an option                  |
| `cache.app`    | Default — file adapter in dev, the framework default in prod |
| `cache.array`  | Tests — in-memory                                           |

Configure a dedicated pool:

<!-- @code-block language="text" label="config/packages/cache.yaml" -->
```text
framework:
    cache:
        pools:
            cache.opcua:
                adapter: cache.adapter.redis
                provider: 'redis://%env(REDIS_URL)%'
                default_lifetime: 3600
```
<!-- @endcode-block -->

Bundle config:

<!-- @code-block language="text" label="bundle config" -->
```text
php_opcua_symfony_opcua:
    session_manager:
        cache_pool: cache.opcua
```
<!-- @endcode-block -->

## What gets cached

### Node metadata

Reads of non-Value attributes (`DisplayName`, `Description`,
`DataType`, `NodeClass`, etc.) are cached when
`read_metadata_cache: true` on the connection:

<!-- @code-block language="text" label="enable" -->
```text
connections:
    default:
        read_metadata_cache: true
```
<!-- @endcode-block -->

Cache key: `opcua.meta.{connection}.{nodeId}.{attribute}`.
Default TTL: 1 hour.

The Value attribute is **never** cached — values must be live.

### Browse results

`browseRecursive()` doesn't auto-cache. Do it explicitly:

<!-- @code-block language="php" label="manual browse cache" -->
```php
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

public function tree(string $root): array
{
    return $this->cache->get(
        'opcua.browse.' . hash('xxh3', $root),
        function (ItemInterface $item) use ($root) {
            $item->expiresAfter(3600);
            return $this->client->browseRecursive($root, maxDepth: 10);
        },
    );
}
```
<!-- @endcode-block -->

Browse trees are stable for hours/days — cache aggressively.

### Certificate validation

Cert chains are validated once per `(cert fingerprint, trust
store state)` and cached:

| Cache key                                          | TTL    |
| -------------------------------------------------- | ------ |
| `opcua.cert.valid.{fingerprint}.{store-hash}`       | 1 day  |
| `opcua.cert.invalid.{fingerprint}`                  | 1 hour |

### Trust-store hashes

The list of pinned server certs is hashed and cached so the
trust check is O(1) during connection open:

| Cache key                            | TTL   |
| ------------------------------------ | ----- |
| `opcua.trust.store-hash.{path}`       | 5 min |

Invalidated automatically by trust-store-modify operations.

### Per-server protocol features

After the first connection:

| Cache key                                | TTL   |
| ---------------------------------------- | ----- |
| `opcua.server.{endpoint}.chunk`           | 1 day |
| `opcua.server.{endpoint}.caps`             | 1 day |
| `opcua.server.{endpoint}.product`          | 1 day |

Rarely changes; second connection per day is faster than the
first.

## Flushing

<!-- @code-block language="bash" label="flush a pool" -->
```bash
php bin/console cache:pool:clear cache.opcua
```
<!-- @endcode-block -->

For all pools:

<!-- @code-block language="bash" label="flush all" -->
```bash
php bin/console cache:pool:clear --all
```
<!-- @endcode-block -->

For dev iteration (Symfony container cache):

<!-- @code-block language="bash" label="dev clear" -->
```bash
php bin/console cache:clear
```
<!-- @endcode-block -->

## When NOT to cache

- **Live values.** Already covered. Never cache `read()` Value
  results.
- **Per-request browse results.** If the operator is browsing
  interactively, fresh browse is what they want.
- **Cert validation during trust-store setup.** Disable
  metadata caching while configuring — a stale validation
  result blocks you for the TTL.

## v4.3 cache codec hardening

OPC UA client v4.3.0 removed `unserialize()` from every cache
code path. JSON gated by a type allowlist (`WireCacheCodec`)
is the new default.

**Pre-v4.3 cache entries are silently discarded** on first
access. After upgrading, flush persistent pools to avoid a
brief cold-cache window:

<!-- @code-block language="bash" label="upgrade flush" -->
```bash
php bin/console cache:pool:clear cache.opcua
```
<!-- @endcode-block -->

See [Upgrading](../getting-started/upgrading.md).

## Octane / FrankenPHP — long-process cache

In FrankenPHP / RoadRunner workers, an array-driver cache
**persists across requests** — desirable for metadata caches.
The bundle's caches are happy with this.

`connectTo()`-driven ad-hoc configs each get their own cache
entry — beware of unbounded growth in fleet apps. Periodic
worker recycle (via `--time-limit`) keeps memory bounded.

## Multi-instance coordination

| Topology              | Pool             | Coherent?                          |
| --------------------- | ---------------- | ---------------------------------- |
| Single host           | `file`           | Yes                                 |
| Single host           | `redis` (local)  | Yes                                 |
| Multi-host            | `file`           | No — each host has its own cache    |
| Multi-host            | `redis` (shared) | Yes                                 |
| Multi-host            | `doctrine`       | Yes                                 |

For multi-host deployments, use shared Redis.

## Cache size

Per-cached-server state is ~1 KB. 1000 cached metadata entries
= 1 MB. 100 000 = 100 MB. Set
`MAXMEMORY_POLICY=allkeys-lru` on Redis to bound it.

## A custom cache pool example

For OPC UA only, with a longer default TTL:

<!-- @code-block language="text" label="dedicated pool" -->
```text
framework:
    cache:
        pools:
            cache.opcua_metadata:
                adapter: cache.adapter.redis
                provider: '%env(REDIS_URL)%'
                default_lifetime: 86400
                tags: true
```
<!-- @endcode-block -->

The `tags: true` option enables tag-based invalidation if your
custom logic uses tags for cache groups.

## Where to read next

- [Debugging](./debugging.md) — when cache makes diagnosis
  harder.
- [Profiler and data collectors](./profiler-and-data-collectors.md) —
  see cache hits/misses in the WebProfiler.
- [Trust store](../security/trust-store.md) — the cache layer
  over the trust store.
