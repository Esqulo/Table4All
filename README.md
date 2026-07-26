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

**Requirements:** PHP 8.3+, Composer, Node 22+, PostgreSQL (or edit `.env` for another supported driver).

```bash
git clone https://github.com/Esqulo/Table4All.git
cd Table4All
composer run setup   # installs PHP + JS deps, creates .env, generates app key, migrates, builds assets
```

Then start everything (server, queue worker, Vite) with one command:

```bash
composer run dev
```

`php artisan serve` (started by the command above) listens on `http://localhost:8000` by default.

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
