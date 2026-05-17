---
eyebrow: 'Docs · Recipes'
lede:    'Local Symfony dev with the OPC UA test-suite as a Docker sidecar. Docker Compose, the env wiring, and the simplest dev loop.'

see_also:
  - { href: '../getting-started/installation.md',        meta: '4 min' }
  - { href: '../testing/kernel-tests.md',                meta: '6 min' }
  - { href: './production-deployment.md',                meta: '7 min' }

prev: { label: 'Using companion specs',  href: './using-companion-specs.md' }
next: { label: 'Production deployment',  href: './production-deployment.md' }
---

# Dev with Docker

A typical Symfony app benefits from running the OPC UA test
suite as a local sidecar. Two containers, one `docker compose
up`, full dev environment.

## docker-compose.yml

<!-- @code-block language="text" label="docker-compose.yml" -->
```text
services:
  app:
    build:
      context: .
      target: app-dev
    volumes:
      - .:/var/www/html
    ports:
      - "8000:80"
    environment:
      APP_ENV: dev
      DATABASE_URL: postgresql://app:app@db:5432/app
      OPCUA_ENDPOINT: opc.tcp://opcua-test:4840
      OPCUA_USERNAME: admin
      OPCUA_PASSWORD: admin123
    depends_on:
      - db
      - opcua-test

  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_USER:     app
      POSTGRES_PASSWORD: app
      POSTGRES_DB:       app
    ports:
      - "5432:5432"

  opcua-test:
    image: ghcr.io/php-opcua/uanetstandard-test-suite:v1.2.0
    ports:
      - "4840:4840"   # No security
      - "4841:4841"   # Secured
    healthcheck:
      test: ["CMD", "sh", "-c", "nc -z localhost 4840"]
      interval: 5s
      timeout: 2s
      retries: 10

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"
```
<!-- @endcode-block -->

Bring it up:

<!-- @code-block language="bash" label="terminal" -->
```bash
docker compose up -d
```
<!-- @endcode-block -->

## .env in the Symfony app

<!-- @code-block language="bash" label=".env" -->
```bash
APP_ENV=dev
APP_DEBUG=true

DATABASE_URL=postgresql://app:app@db:5432/app
MESSENGER_TRANSPORT_DSN=redis://redis:6379/messages
MERCURE_URL=http://mercure:80/.well-known/mercure
MERCURE_PUBLIC_URL=http://localhost:3000/.well-known/mercure

OPCUA_ENDPOINT=opc.tcp://opcua-test:4840
OPCUA_USERNAME=admin
OPCUA_PASSWORD=admin123
```
<!-- @endcode-block -->

Note: hostnames are **service names** (`opcua-test`, `db`), not
`localhost` — Docker Compose's internal DNS resolves them.

## Adding Mercure

<!-- @code-block language="text" label="add Mercure" -->
```text
services:
  mercure:
    image: dunglas/mercure:latest
    environment:
      SERVER_NAME: ':80'
      MERCURE_PUBLISHER_JWT_KEY: '!ChangeMe!'
      MERCURE_SUBSCRIBER_JWT_KEY: '!ChangeMe!'
    ports:
      - "3000:80"
```
<!-- @endcode-block -->

## Running tests against the suite

<!-- @code-block language="bash" label="tests" -->
```bash
docker compose exec app php bin/console doctrine:database:create --env=test
docker compose exec app php bin/console doctrine:migrations:migrate --env=test --no-interaction

# Unit + functional
docker compose exec app vendor/bin/phpunit --testsuite=unit
docker compose exec app vendor/bin/phpunit --testsuite=functional

# Integration (against opcua-test)
docker compose exec app vendor/bin/phpunit --testsuite=integration
```
<!-- @endcode-block -->

The integration tests target `opcua-test:4840` (inside the
network) or `localhost:4840` (from the host) — both work.

## Symfony binary alternative

If you prefer running PHP locally and Docker only for services:

<!-- @code-block language="bash" label="symfony binary" -->
```bash
# Start just the OPC UA test server + db + redis
docker compose up -d opcua-test db redis

# Run Symfony locally (uses the Symfony binary's PHP)
symfony serve --dir=public
```
<!-- @endcode-block -->

`.env` then targets `localhost:4840` instead of `opcua-test:4840`.

## Running the daemon locally

Inside the Symfony app container:

<!-- @code-block language="bash" label="daemon" -->
```bash
docker compose exec app php bin/console opcua:session
```
<!-- @endcode-block -->

The daemon binds to `var/opcua-session-manager.sock` inside the
container. With the host bind-mount, the socket is visible at
`./var/opcua-session-manager.sock` on the host.

## Multi-container Symfony app + daemon + workers

<!-- @code-block language="text" label="extended compose" -->
```text
services:
  app:        # FPM
    # ... as before

  daemon:
    build:
      context: .
      target: app-dev
    command: php bin/console opcua:session
    volumes:
      - .:/var/www/html
    environment:
      APP_ENV: dev
      OPCUA_ENDPOINT: opc.tcp://opcua-test:4840
      OPCUA_SOCKET_PATH: /var/www/html/var/opcua-session-manager.sock
    depends_on:
      opcua-test:
        condition: service_healthy

  worker:
    build:
      context: .
      target: app-dev
    command: php bin/console messenger:consume async_opcua --time-limit=3600
    volumes:
      - .:/var/www/html
    environment:
      APP_ENV: dev
      MESSENGER_TRANSPORT_DSN: redis://redis:6379/messages
    depends_on:
      - daemon
      - redis
```
<!-- @endcode-block -->

`app`, `daemon`, and `worker` all share the same image and the
same Symfony codebase. Different commands run different roles.

## A useful Makefile

<!-- @code-block language="text" label="Makefile" -->
```text
.PHONY: up down logs sh tests

up:
	docker compose up -d

down:
	docker compose down

logs:
	docker compose logs -f

sh:
	docker compose exec app bash

tests:
	docker compose exec app vendor/bin/phpunit

migrate:
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```
<!-- @endcode-block -->

## Trust-store flow under Docker

The trust store lives in the bind-mounted directory, so both
the container and host see the same files:

<!-- @code-block language="bash" label="terminal" -->
```bash
docker compose exec app php bin/console opcua:trust:add \
    opc.tcp://opcua-test:4841
```
<!-- @endcode-block -->

Pinned certs land in `./var/opcua-trust/` on the host.

## CI compose

For CI runs, use `docker-compose.ci.yml` with the test suite:

<!-- @code-block language="text" label="docker-compose.ci.yml" -->
```text
services:
  opcua-test:
    image: ghcr.io/php-opcua/uanetstandard-test-suite:v1.2.0
    ports:
      - "4840:4840"
      - "4841:4841"
```
<!-- @endcode-block -->

…then in GitHub Actions:

<!-- @code-block language="text" label="workflow" -->
```text
services:
  opcua-test:
    image: ghcr.io/php-opcua/uanetstandard-test-suite:v1.2.0
    ports: ['4840:4840', '4841:4841']
    options: >-
      --health-cmd "nc -z localhost 4840"
      --health-interval 5s
```
<!-- @endcode-block -->

## Tearing down

<!-- @code-block language="bash" label="terminal" -->
```bash
docker compose down
docker compose down -v   # also remove volumes
```
<!-- @endcode-block -->

## Where to read next

- [Production deployment](./production-deployment.md) — same
  patterns, real iron.
- [Testing · Kernel tests](../testing/kernel-tests.md) — running
  tests against this setup.
