---
eyebrow: 'Docs · Observability'
lede:    'When something doesn''t work — connection refused, write rejected, events silent. A Symfony-shaped decision tree from console commands to netcat.'

see_also:
  - { href: './logging.md',                              meta: '5 min' }
  - { href: '../session-manager/monitoring-the-daemon.md', meta: '5 min' }
  - { href: '../reference/exceptions.md',                meta: '4 min' }

prev: { label: 'Caching',           href: './caching.md' }
next: { label: 'Profiler and data collectors', href: './profiler-and-data-collectors.md' }
---

# Debugging

Symptom → cheapest probe → next step.

## "I can't read anything"

### 1. Confirm the bundle is wired

<!-- @code-block language="bash" label="terminal" -->
```bash
php bin/console debug:autowiring opcua
php bin/console debug:config php_opcua_symfony_opcua | head -40
```
<!-- @endcode-block -->

| Symptom                                       | Cause                                                       |
| --------------------------------------------- | ----------------------------------------------------------- |
| `debug:autowiring` shows nothing               | Bundle missing from `config/bundles.php`                     |
| `debug:config` says "no config"                | Missing `config/packages/php_opcua_symfony_opcua.yaml`        |
| Wrong endpoint                                  | Stale env — `bin/console cache:clear`                       |
| `OpcUaClientInterface` not autowired            | YAML config doesn't define a default connection             |

### 2. Try a raw read

<!-- @code-block language="php" label="quick probe" -->
```php
// In a controller or with bin/console debug:container:
$dv = $this->opcua->connect()->read('i=2256');   // ServerStatus_State
```
<!-- @endcode-block -->

`i=2256` always exists on a working OPC UA server. If this
fails but `ns=2;s=Speed` works, the issue is the node ID. If
this fails too, the connection itself is broken.

### 3. Check connection logs

<!-- @code-block language="bash" label="recent logs" -->
```bash
tail -50 var/log/opcua-$(date +%Y-%m-%d).log
# or
sudo journalctl -u opcua-session-manager -n 50
```
<!-- @endcode-block -->

Look for `ConnectionFailed` events — the `reason` field has the
next step.

## "Writes are rejected"

Read the node's attributes:

<!-- @code-block language="php" label="attribute check" -->
```php
use PhpOpcua\Client\Types\AttributeId;

$access = $client->readBuilder()
    ->node('ns=2;s=Setpoint')
    ->attribute(AttributeId::AccessLevel)
    ->execute()
    ->getValue();   // bit 1 must be set for write

$dataType = $client->readBuilder()
    ->node('ns=2;s=Setpoint')
    ->attribute(AttributeId::DataType)
    ->execute()
    ->getValue();   // what type does the server expect?
```
<!-- @endcode-block -->

| Status                  | Likely cause                                            |
| ----------------------- | ------------------------------------------------------- |
| `Bad_TypeMismatch`       | PHP type → BuiltinType mismatch — use explicit type     |
| `Bad_NotWritable`        | Node isn't writable                                      |
| `Bad_UserAccessDenied`   | Session lacks permission                                  |
| `Bad_OutOfRange`         | Value too high/low                                        |

See [Operations · Writing](../operations/writing.md#explicit-types).

## "Events stopped firing"

### 1. Daemon alive?

<!-- @code-block language="bash" label="probe daemon" -->
```bash
echo '{"id":1,"t":"req","method":"ping","args":[]}' \
    | nc -U /var/run/opcua/sessions.sock
```
<!-- @endcode-block -->

If `nc` hangs → daemon not listening. If sessions count is 0 →
no sessions held.

### 2. Auto-publish on?

<!-- @code-block language="bash" label="config check" -->
```bash
php bin/console debug:config php_opcua_symfony_opcua | grep auto_publish
```
<!-- @endcode-block -->

If `false`, the daemon doesn't emit events even though
subscriptions exist.

### 3. Listeners registered?

<!-- @code-block language="bash" label="listener check" -->
```bash
php bin/console debug:event-dispatcher "PhpOpcua\Client\Events\DataChangeReceived"
```
<!-- @endcode-block -->

Expected output:

<!-- @code-block language="text" label="expected" -->
```text
Listeners for "PhpOpcua\Client\Events\DataChangeReceived" event
========================================================================

 ------- -------------------------------- --------------------------
  Order   Callable                         Priority
 ------- -------------------------------- --------------------------
  #1      App\EventListener\StoreSpeed     0
```
<!-- @endcode-block -->

If listeners aren't listed, check the `#[AsEventListener]`
attribute is on the method or the class.

### 4. Messenger worker running?

<!-- @code-block language="bash" label="worker check" -->
```bash
systemctl status messenger-worker
# or with Supervisor
sudo supervisorctl status messenger-worker

php bin/console messenger:stats async_opcua
```
<!-- @endcode-block -->

If the queue is growing, the worker is down. If failed messages
are accumulating, check `messenger:failed:show`.

## "Works in `bin/console`, not in HTTP"

Likely causes:

1. **Different connection.** Tinker / commands use the default;
   the request uses a different one. Check
   `$opcua->connect(...)`.
2. **Container cache.** Run `php bin/console cache:clear` and
   try again.
3. **FrankenPHP / Octane stale state.** Restart workers.

## "Tests fail with connection errors"

Tests should not hit a real OPC UA server. Disable in `.env.test`:

<!-- @code-block language="bash" label=".env.test" -->
```bash
OPCUA_SESSION_MANAGER_ENABLED=false
```
<!-- @endcode-block -->

…with the bundle config pulling that env. See
[Testing · PHPUnit and Pest setup](../testing/phpunit-and-pest-setup.md).

## "InactiveSessionException after a while"

Server's `MaxSessionTimeout` fired. The bundle reopens on the
next call. If **frequent** in managed mode:

1. Daemon's `session_timeout` should be **less** than server's
   `MaxSessionTimeout` (clean teardown before server times
   out).
2. Daemon's cleanup loop should run — check logs for periodic
   cleanup entries.

## "Cert handshake fails"

| Error                                    | Likely cause                                          |
| ---------------------------------------- | ----------------------------------------------------- |
| `CertificateException — untrusted`        | Server cert not in trust store                         |
| `CertificateException — expired`          | Server cert past `NotAfter`                            |
| `CertificateException — hostname mismatch` | URL host doesn't match cert's SAN                     |
| `PolicyException — unsupported`            | Server doesn't offer the requested policy             |

Inspect server cert (against the bundle's cached fingerprint):

<!-- @code-block language="bash" label="inspect" -->
```bash
openssl s_client -connect plc.factory.local:4840 -showcerts < /dev/null
```
<!-- @endcode-block -->

See [Security · Trust store](../security/trust-store.md).

## "Memory leak over time"

In long-running workers (Messenger, FrankenPHP), this happens.
Sources:

1. **Cache without LRU.** Use Redis with `allkeys-lru`.
2. **Doctrine entity growth in event listeners.** Don't accumulate
   in static state; call `$em->clear()` periodically inside
   batched handlers.
3. **Builder reuse.** Each `readBuilder()` call returns a fresh
   instance — don't accumulate across iterations.

Bound workers with `--memory-limit=512`.

## Symfony Profiler

`APP_DEBUG=1` plus a controller hit shows the WebProfilerBundle
toolbar. The OPC UA bundle doesn't ship a data collector yet,
but you can add a small one — see
[Profiler and data collectors](./profiler-and-data-collectors.md).

For now, the **Logs** tab shows OPC UA log lines from the
channel you configured.

## See the wire

For protocol-level debugging:

<!-- @code-block language="bash" label="tcpdump" -->
```bash
sudo tcpdump -i any -A 'port 4840' -w opcua.pcap
```
<!-- @endcode-block -->

Open in Wireshark with the OPC UA dissector enabled (bundled
since Wireshark ≥ 3.0).

## Intermittent failures

When timing is unclear:

1. **Increase logging** — set `OPCUA_LOG_LEVEL=debug`
   temporarily.
2. **Watch the daemon's session count** — sudden drops indicate
   server-side teardown.
3. **Add a scheduled `app:opcua:ping`** command that records
   ping latency over time.

## Failure escalation

When the failure is in the OPC UA library, capture:

1. Exception class + full message.
2. Connection config (redacted of secrets).
3. Relevant log lines at `debug` level.
4. Server product/version from `Server.ServerStatus.BuildInfo`.

File against upstream:
[github.com/php-opcua/opcua-client/issues](https://github.com/php-opcua/opcua-client/issues)
or
[github.com/php-opcua/opcua-session-manager/issues](https://github.com/php-opcua/opcua-session-manager/issues).

## Where to read next

- [Profiler and data collectors](./profiler-and-data-collectors.md) —
  request-level observability.
- [Reference · Exceptions](../reference/exceptions.md) — what
  each exception means.
