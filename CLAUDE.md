# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Table4All: Laravel 13 (PHP 8.3+, PostgreSQL) + React 19/TypeScript served through Inertia.js (there is no separate API layer). It handles restaurant table management, QR self-ordering, kitchen/bar prep queues and sales analytics. The primary market is Brazil, so URL segments are Portuguese (`mesas`, `garcons`, `cardapio`, `produtos`, `filas`, `promocoes`, `entregas`, `painel`), and route **names** are English (`restaurant.tables.*`, `restaurant.waiters.*`, …).

## Commands

Development (pick one):
- Docker: `docker compose up --build` serves the app at http://localhost:8080 and Vite at :5173. Config comes from `.env.docker.dev`. Run artisan/composer/npm **inside** the container (`docker compose exec app php artisan …`), because host `vendor`/`node_modules` aren't shared. Postgres is published on host port 5433.
- Native: `composer run setup` once, then `composer run dev` (artisan serve on :8000 + `queue:listen` + Vite).

Checks (CI runs these on push/PR to `main`/`develop`, PHP 8.3–8.5, Node 22):
```bash
php artisan test                                  # PHPUnit (sqlite :memory:, see phpunit.xml)
php artisan test --filter=ProfileUpdateTest       # single test class / method
php artisan test tests/Feature/Auth/AuthenticationTest.php
composer types:check    # PHPStan/Larastan, level 7
composer lint           # Pint fix   (lint:check to verify)
npm run lint            # ESLint fix (lint:check to verify)
npm run format          # Prettier on resources/ (format:check to verify)
npm run types:check     # tsc --noEmit
composer test           # config:clear + pint check + phpstan + tests
composer ci:check       # everything above, including the frontend checks
```

## Architecture

### Roles and tenancy
`App\Enums\AccountType`: `admin`, `restaurant`, `waiter`, `customer`. A restaurant is a `User` with `account_type = restaurant`. Waiters are `User`s whose `restaurant_id` points at the owner user. Restaurant-owned models (`Product`, `RestaurantTable`, `Menu`, `RestaurantQueue`, `Sale`, …) carry a `user_id` that is the **restaurant owner's** id.

- Always scope queries and authorize with `$request->user()->effectiveRestaurantId()`. It returns the owner's id for waiters and the user's own id for restaurants. Controllers check ownership manually: `abort_unless($model->user_id === $request->user()->effectiveRestaurantId(), 403)`. There are no policies.
- Route access is set by middleware groups in `routes/web.php`: `EnsureRestaurantOrWaiter` covers tables, deliveries, product listing and queue-item status; `EnsureRestaurant` covers products CRUD, menus, queues, promotions, waiters and the dashboard; `EnsureAdmin` covers global categories. When adding a route, put it in the group that matches who should reach it. In `/restaurant/garcons`, `cancelInvitation` must stay declared before the resource route.
- Customer ordering lives under `/customer/mesa/{code}` (auth required). The public `/mesa/{code}` routes are the join/landing flow.

### Order flow (spans several controllers)
- Customer orders (`Customer\JoinController::order`) and staff-added items (`Restaurant\TableController`) create `QueueItem`s. `queue_id` is null for products with no prep queue, so they go straight to the delivery board. Status flows `QueueItemStatus` pending → done → delivered via `QueueItemController`.
- The **price is snapshotted** at order time onto `queue_items.price` and the `restaurant_table_product.price` pivot. Active promotions (`Sale`, either a weekly window or a dated range) override `Product.price` when the order is placed. Table totals use the stored `price * quantity` and never read the current product price.
- `TablePayment`s accumulate against a table. A table can only be closed (`closed_at`) once payments cover the total. Closed tables feed `DashboardController`.

### Frontend
- Pages: `resources/js/pages/**`, rendered with `Inertia::render('restaurant/tables/show', …)`. The layout is chosen **by page-name prefix** in `resources/js/app.tsx` (`table/*` → CustomerLayout, `auth/*` → AuthLayout, `settings/*` → App+Settings, `welcome`/`menu/*`/`waiter/*`/print → none, everything else → AppLayout). When adding a page under a new prefix, update that switch.
- Routing on the client uses **Laravel Wayfinder**: import typed route helpers from `@/routes` or controller actions from `@/actions/App/Http/Controllers/...`. Don't hardcode URLs. These directories (`resources/js/routes`, `actions`, `wayfinder`) are **generated** by the Vite plugin and gitignored, so they regenerate when Vite runs (or via `php artisan wayfinder:generate`) after route/controller changes.
- Shared props come from `HandleInertiaRequests::share` (`auth.user`, `name`, `sidebarOpen`). Shared TS types are in `resources/js/types/` (domain types in `restaurant.ts`).
- UI is shadcn-style Radix components in `resources/js/components/ui/` with Tailwind v4. React Compiler is enabled via Babel.

### i18n
- Frontend uses `react-i18next`, default `pt_BR`. Every new UI string needs a dot-separated key (e.g. `restaurant.tables.title`) in **both** `resources/js/locales/pt_BR.json` and `en.json`. Page `layout` titles and breadcrumb titles are translation keys, resolved with `t()` at render time.
- On the backend, the default locale is `pt_BR` (`APP_LOCALE`). Flash/validation strings go in `lang/pt_BR.json` and `lang/pt_BR/*.php`.

### Auth
Laravel Fortify (`FortifyServiceProvider`, `app/Actions/Fortify`) handles login/registration, email verification, 2FA and passkeys (WebAuthn). Settings routes are in `routes/settings.php`.
