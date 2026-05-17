---
eyebrow: 'Docs · Session manager'
lede:    'Production-grade orchestration — systemd, Supervisor, Docker. Plus how the daemon coexists with Messenger workers, FrankenPHP, and your deploy script.'

see_also:
  - { href: './starting-the-daemon.md',           meta: '5 min' }
  - { href: './monitoring-the-daemon.md',         meta: '5 min' }
  - { href: '../recipes/production-deployment.md', meta: '7 min' }

prev: { label: 'Auto-publish',          href: './auto-publish.md' }
next: { label: 'Monitoring the daemon', href: './monitoring-the-daemon.md' }
---

# Production supervisor

The daemon is a long-running PHP process. It needs:

- A supervisor to keep it up.
- Log rotation.
- A deploy hook that restarts it on code updates.

Two recommended supervisors: **systemd** (the OS-level choice)
and **Supervisor** (the convention Symfony Messenger docs
typically reach for).

## systemd — recommended

`/etc/systemd/system/opcua-session-manager.service`:

<!-- @code-block language="text" label="systemd unit" -->
```text
[Unit]
Description=OPC UA Session Manager (Symfony)
After=network-online.target redis-server.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/html
Environment="APP_ENV=prod"
Environment="APP_DEBUG=0"
EnvironmentFile=/etc/opcua/symfony.env
ExecStartPre=/usr/bin/mkdir -p /var/run/opcua
ExecStartPre=/usr/bin/chown www-data:www-data /var/run/opcua
ExecStart=/usr/bin/php /var/www/html/bin/console opcua:session

Restart=on-failure
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=30

# Hardening
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
NoNewPrivileges=true
ReadWritePaths=/var/run/opcua /var/log /var/www/html/var

[Install]
WantedBy=multi-user.target
```
<!-- @endcode-block -->

Apply:

<!-- @code-block language="bash" label="terminal" -->
```bash
sudo systemctl daemon-reload
sudo systemctl enable opcua-session-manager
sudo systemctl start opcua-session-manager
sudo systemctl status opcua-session-manager
sudo journalctl -u opcua-session-manager -f
```
<!-- @endcode-block -->

Critical settings:

- `KillSignal=SIGTERM` + `TimeoutStopSec=30` — graceful shutdown.
- `Restart=on-failure` + `RestartSec=5` — recover from crashes.
- `EnvironmentFile=` — load secrets without exposing them in the
  unit file.
- `ReadWritePaths=` — minimal filesystem access (drop the
  hardening if it bites you, but keep it as a goal).

## Supervisor — Symfony Messenger pattern

If you already run Messenger workers under Supervisor, the
daemon fits the same convention.

`/etc/supervisor/conf.d/opcua-session-manager.conf`:

<!-- @code-block language="text" label="supervisor" -->
```text
[program:opcua-session-manager]
process_name=%(program_name)s
command=php /var/www/html/bin/console opcua:session
environment=APP_ENV=prod,APP_DEBUG=0
directory=/var/www/html
autostart=true
autorestart=true
startretries=10
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/opcua-session-manager.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=10
stopwaitsecs=30
stopsignal=TERM
```
<!-- @endcode-block -->

Apply:

<!-- @code-block language="bash" label="terminal" -->
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start opcua-session-manager
sudo supervisorctl status
```
<!-- @endcode-block -->

Use systemd or Supervisor, not both.

## Secrets injection

For `auth_token` and OPC UA passwords:

### Option A — `EnvironmentFile` (systemd)

`/etc/opcua/symfony.env` (mode `0600`, root-owned):

<!-- @code-block language="bash" label="EnvironmentFile" -->
```bash
OPCUA_AUTH_TOKEN=abc123def456...
OPCUA_PASSWORD=...
DATABASE_URL=...
```
<!-- @endcode-block -->

Referenced in the unit:

<!-- @code-block language="text" label="unit" -->
```text
EnvironmentFile=/etc/opcua/symfony.env
```
<!-- @endcode-block -->

### Option B — Symfony secrets vault

Bake secrets into `config/secrets/<env>/` and ship them. Decrypt
on the host with the production key. The Symfony command picks
them up automatically.

Choose one approach per project — don't mix.

## Log rotation

Two log surfaces:

1. **Supervisor stdout** — Supervisor rotates with
   `stdout_logfile_maxbytes` / `stdout_logfile_backups`.
2. **Monolog channel** — Symfony's Monolog handler rotates via
   `rotating_file`:

<!-- @code-block language="text" label="monolog rotation" -->
```text
monolog:
    handlers:
        opcua:
            type: rotating_file
            path: '%kernel.logs_dir%/opcua.log'
            max_files: 14
            level: info
            channels: ['opcua']
```
<!-- @endcode-block -->

For systemd, journald rotates by default — set `SystemMaxUse=`
in `/etc/systemd/journald.conf` to bound disk use.

Don't double-rotate (Supervisor + Monolog + logrotate). Pick one.

## Re-subscribe on restart

If you use auto-publish with imperative subscriptions, register
a startup hook:

<!-- @code-block language="text" label="systemd ExecStartPost" -->
```text
ExecStartPost=/usr/bin/php /var/www/html/bin/console app:opcua:resubscribe
```
<!-- @endcode-block -->

`app:opcua:resubscribe` is your own command — see [Auto-publish](./auto-publish.md).

For declarative subscriptions (`auto_connect: true` +
`subscriptions:` in YAML), no extra hook needed — the daemon
re-creates them at boot.

## Deploy hook

Add a daemon restart to your deploy script after the code update:

### Symfony Cloud / Platform.sh / Web hosting with deploy hooks

<!-- @code-block language="bash" label="deploy hook" -->
```bash
sudo systemctl restart opcua-session-manager
```
<!-- @endcode-block -->

### Symfony Deployer

<!-- @code-block language="text" label="deploy.php" -->
```text
task('opcua:restart', function () {
    run('sudo systemctl restart opcua-session-manager');
});

after('deploy:cleanup', 'opcua:restart');
```
<!-- @endcode-block -->

### Capistrano-style

<!-- @code-block language="bash" label="deploy hook" -->
```bash
{{release_path}}/bin/console opcua:session-restart
```
<!-- @endcode-block -->

(Where `opcua:session-restart` is a wrapper command that calls
`Process::run('systemctl restart ...')`.)

### Why restart?

PHP doesn't auto-reload on file changes. A long-running daemon
keeps the **old** code in memory until restart.

For zero-downtime, run two daemons on different sockets, switch
traffic at the config layer. Most plant deployments accept a
5-30 s blip.

## Coexistence with Messenger

The daemon is **independent** of Messenger's worker
supervision. They coexist:

| Supervisor    | Manages                                  |
| ------------- | ---------------------------------------- |
| systemd       | OPC UA daemon, Messenger workers          |
| Supervisor     | Same — different programs, same supervisor |

For Messenger workers consuming OPC UA-derived messages:

<!-- @code-block language="text" label="Messenger worker unit" -->
```text
[Unit]
Description=Symfony Messenger Worker (opcua-data)
After=opcua-session-manager.service

[Service]
Type=simple
User=www-data
ExecStart=/usr/bin/php /var/www/html/bin/console messenger:consume async_opcua \
    --time-limit=3600 --memory-limit=512M

Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```
<!-- @endcode-block -->

The `After=` directive ensures the daemon starts first — but
both can run independently. Failed daemon doesn't crash workers
(they fall back to direct mode).

## Coexistence with FrankenPHP / Caddy

FrankenPHP is just another PHP runtime — same daemon, same
client behaviour:

<!-- @code-block language="text" label="FrankenPHP unit (sketch)" -->
```text
[Service]
ExecStart=/usr/local/bin/frankenphp run --config /etc/frankenphp/Caddyfile
EnvironmentFile=/etc/opcua/symfony.env
```
<!-- @endcode-block -->

The OPC UA daemon doesn't need to know about FrankenPHP.

## Docker

For containerised deployments, run the daemon in its own
container:

<!-- @code-block language="text" label="docker-compose.yml (snippet)" -->
```text
services:
  app:
    image: my-symfony-app:latest
    # ...

  opcua-daemon:
    image: my-symfony-app:latest
    command: php bin/console opcua:session
    user: www-data
    volumes:
      - opcua-sockets:/var/run/opcua
    restart: unless-stopped
    environment:
      APP_ENV: prod
      OPCUA_SOCKET_PATH: /var/run/opcua/sessions.sock
      OPCUA_AUTH_TOKEN: ${OPCUA_AUTH_TOKEN}

  messenger:
    image: my-symfony-app:latest
    command: php bin/console messenger:consume async_opcua --time-limit=3600
    volumes:
      - opcua-sockets:/var/run/opcua    # share the socket
    environment:
      OPCUA_SOCKET_PATH: /var/run/opcua/sessions.sock

volumes:
  opcua-sockets:
```
<!-- @endcode-block -->

See [Recipes · Production deployment](../recipes/production-deployment.md).

## Health checks

Liveness probe (works against Unix socket or TCP):

<!-- @code-block language="bash" label="terminal — probe" -->
```bash
echo '{"id":1,"t":"req","method":"ping","args":[]}' \
    | nc -U /var/run/opcua/sessions.sock | grep -q '"ok":true'
echo $?
```
<!-- @endcode-block -->

`0` = healthy. Wire into your monitoring — Datadog, Prometheus
blackbox, etc. See [Monitoring the daemon](./monitoring-the-daemon.md).

## Resource limits

`LimitNOFILE=` for file descriptors under load:

<!-- @code-block language="text" label="limits" -->
```text
[Service]
LimitNOFILE=65536
```
<!-- @endcode-block -->

Memory budget: 100-300 MB for typical workloads.

## Where to read next

- [Monitoring the daemon](./monitoring-the-daemon.md) — metrics
  and dashboards.
- [Recipes · Production deployment](../recipes/production-deployment.md) —
  end-to-end FPM + daemon + Messenger.
