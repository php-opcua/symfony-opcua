---
eyebrow: 'Docs · Recipes'
lede:    'Production checklist for shipping symfony-opcua — hardware, systemd units, secrets, deploy hook, monitoring. The last page of the docs, the first you reach for when shipping.'

see_also:
  - { href: '../session-manager/production-supervisor.md',     meta: '6 min' }
  - { href: '../session-manager/monitoring-the-daemon.md',     meta: '5 min' }
  - { href: '../security/certificates.md',                     meta: '7 min' }

prev: { label: 'Dev with Docker',  href: './dev-with-docker.md' }
next: { label: 'Top of docs',      href: '../index.md' }
---

# Production deployment

A complete production checklist for shipping a Symfony app
backed by `symfony-opcua`.

## Hardware sizing

| Workload                          | CPU       | Memory     | Disk       |
| --------------------------------- | --------- | ---------- | ---------- |
| Single PLC, low traffic           | 1 vCPU    | 1 GB       | 20 GB      |
| 5 PLCs, real-time UI, auto-publish | 2 vCPU   | 2 GB       | 40 GB      |
| 50 PLCs, dashboard, history       | 4 vCPU    | 4 GB       | 100+ GB    |
| 500-PLC fleet                      | 8 vCPU   | 8 GB       | 500+ GB    |

Disk scales with `plc_readings`. Plan retention or aggregation.

## Required services

| Service               | Where             | Purpose                                |
| --------------------- | ----------------- | -------------------------------------- |
| PHP-FPM / FrankenPHP   | Web tier          | HTTP                                   |
| OPC UA daemon          | App host          | Session pooling                        |
| Redis                  | App tier           | Cache + Messenger + Mercure auth        |
| Mercure                | Web tier          | Real-time WebSocket / SSE               |
| Messenger workers      | App tier           | Queue processing                        |
| PostgreSQL              | DB tier           | Persistence                              |
| nginx / Caddy           | Web tier          | Reverse proxy                           |

For small deployments, all on one host. For larger, split into
tiers.

## Pre-deploy checklist

- [ ] OPC UA credentials in the Symfony secrets vault.
- [ ] Server cert pinned in trust store (`opcua:trust:add`).
- [ ] Client cert generated and registered server-side.
- [ ] `.env.prod` (or external secrets) lists every `OPCUA_*` key.
- [ ] systemd unit for the daemon in place.
- [ ] systemd units for Messenger workers in place.
- [ ] Doctrine migrations applied.
- [ ] Monolog `opcua` channel declared.
- [ ] Health endpoint `/health/opcua` reachable from monitoring.
- [ ] `bin/console cache:warmup` ran in the deploy.

## Initial deploy

<!-- @code-block language="bash" label="first deploy" -->
```bash
# 1. Code
git clone <repo> /var/www/html
cd /var/www/html
composer install --no-dev --optimize-autoloader --classmap-authoritative

# 2. Env
cp .env.prod.local.dist .env.prod.local
nano .env.prod.local        # set non-secret values

# 3. Secrets vault
php bin/console secrets:set OPCUA_PASSWORD
php bin/console secrets:set OPCUA_AUTH_TOKEN
php bin/console secrets:set MERCURE_JWT_SECRET

# 4. Cache + warmup
php bin/console cache:clear --env=prod --no-debug
php bin/console cache:warmup --env=prod --no-debug

# 5. Migrations
php bin/console doctrine:migrations:migrate --no-interaction

# 6. OPC UA setup
sudo mkdir -p /var/lib/opcua/trust
sudo chown www-data:www-data /var/lib/opcua/trust
php bin/console opcua:trust:add opc.tcp://plc.factory.local:4840 \
    --store=/var/lib/opcua/trust

# 7. Services
sudo systemctl daemon-reload
sudo systemctl enable --now opcua-session-manager
sudo systemctl enable --now messenger-opcua-data@{1..4}
sudo systemctl enable --now messenger-opcua-alarms
sudo systemctl enable --now php8.4-fpm
sudo systemctl enable --now nginx
```
<!-- @endcode-block -->

## Ongoing deploy script

<!-- @code-block language="bash" label="deploy.sh" -->
```bash
#!/bin/bash
set -e

cd /var/www/html

git fetch origin
git checkout origin/main

composer install --no-dev --optimize-autoloader --no-progress

# Migrations — abort on destructive changes
php bin/console doctrine:migrations:migrate --no-interaction

# Cache
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod

# Restart services
sudo systemctl restart opcua-session-manager
sudo systemctl reload-or-restart 'messenger-opcua-*'
sudo systemctl reload php8.4-fpm

# Verify
sleep 2
curl -fsS http://localhost/health/opcua | grep -q '"status":"up"' || (echo "Daemon not up" && exit 1)

echo "Deploy complete: $(git rev-parse HEAD)"
```
<!-- @endcode-block -->

## FrankenPHP variant

With FrankenPHP, no FPM — Caddy serves both static and PHP:

<!-- @code-block language="text" label="Caddyfile" -->
```text
{
    frankenphp
    order php_server before file_server
}

example.com {
    root * /var/www/html/public
    php_server
}
```
<!-- @endcode-block -->

Reload after deploy:

<!-- @code-block language="bash" label="reload" -->
```bash
sudo systemctl reload frankenphp
```
<!-- @endcode-block -->

The daemon needs separate restart — same as FPM.

## Secrets management

| Where                                | Secrets                                       |
| ------------------------------------ | --------------------------------------------- |
| `.env.prod.local` (gitignored)        | Non-secret config (paths, names)               |
| Symfony secrets vault                 | `OPCUA_PASSWORD`, `OPCUA_AUTH_TOKEN`, ...      |
| Filesystem (`mode=0600`)               | `client.key`, `cert.key`                       |
| Vault / AWS Secrets Manager → env     | Optional alternative to Symfony vault         |

Don't put real passwords in `.env.prod` or `.env.prod.local`
in production. The vault or external secrets manager only.

## systemd units

`/etc/systemd/system/opcua-session-manager.service`:

<!-- @code-block language="text" label="daemon unit" -->
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
Environment=APP_ENV=prod APP_DEBUG=0
EnvironmentFile=-/etc/opcua/symfony.env
ExecStartPre=/usr/bin/mkdir -p /var/run/opcua
ExecStartPre=/usr/bin/chown www-data:www-data /var/run/opcua
ExecStart=/usr/bin/php /var/www/html/bin/console opcua:session
ExecStartPost=/usr/bin/php /var/www/html/bin/console app:opcua:resubscribe

Restart=on-failure
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=30

ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
NoNewPrivileges=true
ReadWritePaths=/var/run/opcua /var/log /var/www/html/var /var/lib/opcua

[Install]
WantedBy=multi-user.target
```
<!-- @endcode-block -->

`/etc/systemd/system/messenger-opcua-data@.service`:

<!-- @code-block language="text" label="worker template" -->
```text
[Unit]
Description=Symfony Messenger Worker (opcua_data, instance %i)
After=opcua-session-manager.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/html
Environment=APP_ENV=prod
EnvironmentFile=-/etc/opcua/symfony.env
ExecStart=/usr/bin/php /var/www/html/bin/console messenger:consume opcua_data \
    --time-limit=3600 --memory-limit=512M

Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```
<!-- @endcode-block -->

Run multiple instances:

<!-- @code-block language="bash" label="instances" -->
```bash
sudo systemctl enable --now messenger-opcua-data@{1..4}
```
<!-- @endcode-block -->

## Permissions matrix

| Path                                   | Mode        | Owner          |
| -------------------------------------- | ----------- | -------------- |
| `/var/www/html/`                       | `755`       | `www-data`     |
| `/var/www/html/var/`                    | `775`       | `www-data`     |
| `/var/lib/opcua/trust/`                 | `750`       | `www-data`     |
| `/etc/opcua/client.pem`                  | `640`       | `www-data`     |
| `/etc/opcua/client.key`                  | `600`       | `www-data`     |
| `/etc/opcua/symfony.env`                  | `600`       | `root`          |
| `/var/run/opcua/sessions.sock`            | `660`       | `www-data`     |
| `/var/log/opcua.log`                       | `640`       | `www-data`     |

## Monitoring hooks

| What                                | How                                              |
| ----------------------------------- | ------------------------------------------------ |
| HTTP up                              | `GET /` returns 200                              |
| Daemon up                            | `GET /health/opcua` shows `"status":"up"`        |
| OPC UA reachable                     | `GET /health/opcua/detail` shows sessions > 0     |
| Event flow                           | `GET /health/opcua/flow` shows `stale: false`     |
| Queue backlog                        | `messenger:stats` / Redis `LLEN`                  |
| Cert expiry                          | `app:opcua:cert:check` exit code (cron daily)     |
| Failed messages                      | `messenger:failed:show` count                      |

Wire to your alerting (Slack via Notifier, PagerDuty, etc.).

## Rollback

If a deploy goes wrong:

<!-- @code-block language="bash" label="rollback" -->
```bash
cd /var/www/html
git checkout <previous-sha>
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate prev --no-interaction  # only if migrated
php bin/console cache:clear --env=prod

sudo systemctl restart opcua-session-manager
sudo systemctl reload-or-restart 'messenger-opcua-*'
sudo systemctl reload php8.4-fpm
```
<!-- @endcode-block -->

Rollback time: ~30 s.

## Production gotchas

| Symptom                                              | Cause                                                  |
| ---------------------------------------------------- | ------------------------------------------------------ |
| Daemon CPU climbs over time                          | A listener throws inside the daemon dispatcher          |
| Memory unbounded                                      | Cache without LRU, listener leak                         |
| Daemon socket file leaks after crash                  | Missing `RemoveOnExit` — clean manually                  |
| Workers can't connect after deploy                    | Daemon restarted slower than workers; brief failure window |
| `cache:warmup` slow                                   | Big container — install with `--classmap-authoritative` |
| Messenger backlog grows                                | Add workers, batch the handler                          |

## Cost model — one-host deployment

A representative EC2 m5.large equivalent:

| Service              | Approximate cost (USD/mo) |
| -------------------- | ------------------------- |
| Compute               | ~70                       |
| EBS                   | ~10                       |
| Network               | ~5                        |
| Mercure (if external) | ~5                        |
| **Total**             | **~90**                   |

Plus OPC UA server licensing (separate, vendor-dependent).

## Where to read next

You've reached the end. Useful next stops:

- The [package README on GitHub](https://github.com/php-opcua/symfony-opcua).
- [Top of docs](../index.md).
- Companion docs:
  - [`opcua-client`](https://github.com/php-opcua/opcua-client)
  - [`opcua-session-manager`](https://github.com/php-opcua/opcua-session-manager)
  - [`opcua-client-nodeset`](https://github.com/php-opcua/opcua-client-nodeset)
  - [`opcua-cli`](https://github.com/php-opcua/opcua-cli)
