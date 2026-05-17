---
eyebrow: 'Docs · Reference'
lede:    'Every Symfony Console command the bundle adds. Signatures, options, common invocations.'

see_also:
  - { href: '../session-manager/starting-the-daemon.md',    meta: '5 min' }
  - { href: '../integrations/console-and-scheduler.md',      meta: '6 min' }

prev: { label: 'Bundle services',  href: './bundle-services.md' }
next: { label: 'Exceptions',       href: './exceptions.md' }
---

# Console commands

| Command                   | Purpose                                                |
| ------------------------- | ------------------------------------------------------ |
| `opcua:session`            | Run the OPC UA session manager daemon                  |
| `opcua:trust:add`          | Pin a server certificate                               |
| `opcua:trust:list`         | List pinned server certificates                        |
| `opcua:trust:remove`       | Remove a pinned server certificate                     |

## `opcua:session`

Start the daemon.

<!-- @code-block language="bash" label="signature" -->
```bash
php bin/console opcua:session
    [--timeout=<sec>]
    [--cleanup-interval=<sec>]
    [--max-sessions=<n>]
    [--socket-mode=<oct>]
```
<!-- @endcode-block -->

CLI flags override YAML config for that invocation. All values
fall back to `session_manager.*` in the bundle config.

Detailed in [Starting the daemon](../session-manager/starting-the-daemon.md).

## `opcua:trust:add`

Pin a server certificate.

<!-- @code-block language="bash" label="signature" -->
```bash
php bin/console opcua:trust:add <endpoint> [--force] [--store=PATH]
```
<!-- @endcode-block -->

Connects unsecured, fetches the server cert, shows subject /
fingerprint / expiry, asks confirmation, writes to the trust
store.

`--force` skips the confirmation prompt (use in CI scripts only
when input is known-good).

Detailed in [Security · Trust store](../security/trust-store.md).

## `opcua:trust:list`

<!-- @code-block language="bash" label="signature" -->
```bash
php bin/console opcua:trust:list [--store=PATH] [--json]
```
<!-- @endcode-block -->

Lists pinned server certs in a readable table or as JSON.

## `opcua:trust:remove`

<!-- @code-block language="bash" label="signature" -->
```bash
php bin/console opcua:trust:remove <fingerprint-or-subject> [--store=PATH]
```
<!-- @endcode-block -->

Matches by fingerprint (full or prefix) or subject substring.

## Common patterns

### Healthcheck script

<!-- @code-block language="bash" label="healthcheck" -->
```bash
#!/bin/bash
php bin/console opcua:session --help > /dev/null 2>&1 || exit 1
# Daemon registered = bundle loaded properly
```
<!-- @endcode-block -->

### Pin every configured endpoint

<!-- @code-block language="bash" label="bulk pin" -->
```bash
#!/bin/bash
for endpoint in $(php bin/console debug:config php_opcua_symfony_opcua \
    | grep 'endpoint:' | awk '{print $2}'); do
    php bin/console opcua:trust:add --force "$endpoint"
done
```
<!-- @endcode-block -->

Or use a static endpoint list in `config/opcua-endpoints.txt`.

## App-defined commands (suggested)

The bundle leaves room for app-specific commands. Common ones:

| Command (app-defined)       | Purpose                                          |
| --------------------------- | ------------------------------------------------ |
| `app:plc:discover`          | Walk the address space, populate `plc_tags`       |
| `app:plc:sample`            | One-shot read across all configured tags          |
| `app:opcua:resubscribe`     | Re-create subscriptions after daemon restart      |
| `app:opcua:metrics`          | Export metrics to Prometheus                       |
| `app:opcua:cert:check`        | Cert expiry monitor                                 |

See [Recipes](../recipes/persistent-tag-history.md) for
worked examples.

## Verbosity in commands

When debugging, raise verbosity:

<!-- @code-block language="bash" label="verbose" -->
```bash
php bin/console opcua:session -v       # Notices
php bin/console opcua:session -vv      # Notices + info
php bin/console opcua:session -vvv     # Everything (debug)
```
<!-- @endcode-block -->

For your own commands, use `useConsoleLogger()` to forward
the verbosity into the OPC UA client's logger — see
[Console and scheduler](../integrations/console-and-scheduler.md).

## Non-interactive mode

For deploy scripts / CI:

<!-- @code-block language="bash" label="non-interactive" -->
```bash
php bin/console opcua:trust:add --no-interaction --force \
    opc.tcp://plc.factory.local:4840
```
<!-- @endcode-block -->

`--no-interaction` is a global Symfony Console flag; `--force`
is per-command for trust ops.

## Where to read next

- [Exceptions](./exceptions.md) — what commands can throw.
- [Console and scheduler](../integrations/console-and-scheduler.md) —
  how to author your own commands.
