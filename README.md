# Table4All

[![tests](https://github.com/Esqulo/Table4All/actions/workflows/tests.yml/badge.svg)](https://github.com/Esqulo/Table4All/actions/workflows/tests.yml)
[![linter](https://github.com/Esqulo/Table4All/actions/workflows/lint.yml/badge.svg)](https://github.com/Esqulo/Table4All/actions/workflows/lint.yml)

**Table4All** is a web app that lets restaurants, bars and counter-service venues run table management, QR-code self-ordering, kitchen queues and sales analytics - without needing dedicated POS hardware.

A restaurant signs up, adds a menu, and opens a table. Each table gets a code and a QR code. Diners scan it, browse the live menu on their own phone, and send their order straight to the kitchen - no app install, no waiter needed to take the order down. Staff track everything that needs to leave the kitchen on a shared delivery board, and the owner gets a revenue dashboard for the day, week, or month.

## Who it's for

- **Restaurant & bar owners** running dine-in service who want table-side ordering and basic sales analytics without buying a POS system.
- **Counter-service spots, food trucks and cafeterias** that want customers to order and track their own tab from their phone.
- **Waitstaff and kitchen/bar staff** who need a simple shared queue for what to prepare and what's ready to deliver, instead of shouted orders or paper tickets.
- **Diners**, who join a table by code or QR scan and order for themselves - no account required to view a menu, though ordering as a recognized guest requires signing in.

The app ships with Portuguese (pt-BR) and English locales, and its route/URL structure (`mesa`, `garçom`, `cardápio`, `promoções`) reflects a primary focus on the Brazilian market.

## Core concepts

Every account has a role (`admin`, `restaurant`, `waiter`, `customer`) that determines what it can see:

| Role | Can do |
|---|---|
| **Restaurant** | Full control of their own venue: products, categories, menus, prep queues, tables, promotions, waiter invites, deliveries, and the analytics dashboard. |
| **Waiter** | Invited by a restaurant owner via email; scoped to that restaurant's tables and delivery board, but not menu/product/staff management. |
| **Customer** | Joins a table via its access code/QR to browse the menu and place self-service orders. |
| **Admin** | Manages the global product category taxonomy shared across restaurants. |

### How a table order flows

1. A restaurant opens a table (`/restaurant/mesas`) and gets a unique access code + QR code.
2. A diner scans the code (`/mesa/{code}`) and, once signed in, opens the full ordering view (`/customer/mesa/{code}`).
3. The diner adds items from the live menu - prices automatically reflect any active promotion.
4. Orders route into a preparation queue. Products assigned to a queue (kitchen, bar, etc.) show up for kitchen staff to mark **done**; everything else waits directly for a waiter.
5. Staff work the shared **delivery board** (`/restaurant/entregas`), marking items **delivered** as they leave the kitchen/bar.
6. Payments (cash, card, etc.) are logged against the table as they come in; a table can only be closed once payments cover the total.
7. Closed tables feed the **dashboard** (`/restaurant/painel`): revenue, tables closed, items sold, revenue by day, top products, and payment method mix, filterable by a 7/30/90-day window.

## Features

- Product catalog with categories, pictures, and flexible pricing units (unit, kg, 100g, liter, portion)
- Printable / shareable menus, independent of table ordering
- Time-boxed promotions - either a recurring weekly window or a one-off scheduled date range
- Kitchen/bar prep queues with a done → delivered workflow
- Table management with access codes, QR codes, split/partial payments, and per-table order history
- Self-service customer ordering with a live running total and payment status
- Waiter invitations via emailed, tokenized accept links
- Revenue dashboard with configurable time windows
- Full account security: email verification, two-factor authentication, and passkey (WebAuthn) sign-in via Laravel Fortify

## Tech stack

- **Backend:** PHP 8.3+, Laravel 13, PostgreSQL
- **Frontend:** React 19 + TypeScript, rendered server-driven via Inertia.js (no separate API layer)
- **UI:** Tailwind CSS v4, Radix UI primitives, `dnd-kit` for drag-and-drop, `qrcode.react` for table QR codes
- **Auth:** Laravel Fortify (2FA, passkeys)
- **i18n:** `react-i18next` (en, pt-BR)

## Getting started

Two ways to run the project: **Docker** (nothing to install but Docker itself — recommended, and the same on every machine) or a **native toolchain** on your host.

### Option A — Docker (development)

**Requirements:** Docker Desktop (or Docker Engine + Compose v2).

```bash
git clone https://github.com/Esqulo/Table4All.git
cd Table4All
docker compose up --build      # add -d to run in the background
```

That's the whole setup. `docker compose up` builds one `app` container (nginx + PHP-FPM + a `queue:listen` worker + the Vite dev server, supervised) and one `db` container (PostgreSQL), then:

- installs PHP + JS dependencies inside the container,
- waits for the database and runs migrations,
- serves the app at **http://localhost:8080** with the Vite dev server on **http://localhost:5173**.

The working tree is bind-mounted into the container, so **edits to PHP or React are picked up live** — Vite HMR for the frontend, no opcache for the backend. `php artisan serve`'s host is `8080` here, not `8000`.

Configuration for this stack lives in [`.env.docker.dev`](.env.docker.dev) (committed, throwaway values). You do **not** need a `.env` file for it.

**Everyday commands** (`app` is the service name):

```bash
docker compose exec app php artisan tinker        # any artisan command
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app composer require vendor/pkg   # deps must be changed *inside* the container
docker compose exec app npm install some-lib          # (host node_modules/vendor aren't shared)
docker compose exec app supervisorctl status      # nginx / php-fpm / queue / vite
docker compose logs -f app                         # combined logs
docker compose down            # stop, keep data
docker compose down -v         # stop and wipe the database + uploaded files
```

> **Windows/macOS:** the bind mount goes through a VM, so PHP is noticeably slower than native. If it bothers you, keep the repo inside the Docker VM's filesystem (WSL2 on Windows: clone under `\\wsl$\...`).

### Connecting a SQL client (DBeaver, TablePlus, …)

The `db` container publishes Postgres on host port **5433** (not 5432, so it won't clash with a Postgres you already have installed):

| Field | Value |
|---|---|
| Host | `localhost` |
| Port | `5433` |
| Database | `table4all` |
| User | `table4all` |
| Password | `table4all` |

### Option B — native toolchain

**Requirements:** PHP 8.3+ (with `pdo_pgsql`), Composer, Node 22+, PostgreSQL.

```bash
git clone https://github.com/Esqulo/Table4All.git
cd Table4All
composer run setup   # installs PHP + JS deps, creates .env, generates app key, migrates, builds assets
composer run dev     # server + queue worker + Vite, on http://localhost:8000
```

## Docker (production)

The same image, built without the dev tooling, runs the production stack: one `app` container (nginx + PHP-FPM + queue worker + scheduler, assets baked in) and one `db` container. Built for a plain `docker compose up` deploy to a single host (VPS, EC2/Droplet, Coolify, …), not for a TLS-terminating edge — put a reverse proxy in front for HTTPS.

Production runs the **base compose file only** — `-f docker-compose.yml` keeps the development `docker-compose.override.yml` from loading:

```bash
cp .env.docker.example .env.docker
# edit .env.docker: at minimum set DB_DATABASE/DB_USERNAME/DB_PASSWORD and APP_URL

docker compose -f docker-compose.yml --env-file .env.docker run --rm app php artisan key:generate --show
# paste the printed key into APP_KEY in .env.docker

docker compose -f docker-compose.yml --env-file .env.docker up -d --build
```

The app becomes available at `http://localhost:8080` (override the host port with `APP_PORT` in `.env.docker`). On every start the `app` container runs pending migrations and rebuilds the config/route/view cache from `.env.docker` — no manual `artisan` step for a fresh deploy.

Two named volumes persist data across `up`/`down` cycles: `db_data` (the Postgres database) and `storage_data` (uploaded product/avatar pictures). `docker compose -f docker-compose.yml ... down -v` deletes both — only do that if you mean to wipe the data.

```bash
docker compose -f docker-compose.yml --env-file .env.docker exec app php artisan tinker
docker compose -f docker-compose.yml --env-file .env.docker logs -f app
docker compose -f docker-compose.yml --env-file .env.docker down
```

## Testing & code quality

```bash
php artisan test        # PHPUnit
composer types:check    # PHPStan (Larastan)
composer lint:check     # Laravel Pint (PHP style)
npm run lint:check      # ESLint
npm run format:check    # Prettier
npm run types:check     # tsc
```

`composer test` runs config clearing, lint check, static analysis, and the test suite together in one go. CI runs the same checks (plus frontend lint/format) on every push and pull request to `main`/`develop`.

## License

No license has been published for this repository yet. All rights reserved unless a `LICENSE` file is added.
