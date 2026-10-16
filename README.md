<div align="center">

# SportsF API

**REST API for multi-tenant sports court booking** — venues, courts, schedules, dynamic pricing and concurrency-safe reservations.

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-4169E1?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![Sanctum](https://img.shields.io/badge/Auth-Laravel%20Sanctum-FF2D20)](https://laravel.com/docs/sanctum)
[![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)](https://www.docker.com/)
[![Tests](https://img.shields.io/badge/tests-56%20passing-brightgreen)](#-testing)

[Overview](#-overview) · [Features](#-features) · [Architecture](#-architecture) · [API](#-api-reference) · [Getting started](#-getting-started) · [Testing](#-testing) · [Deployment](#-deployment)

</div>

---

## 📖 Overview

Backend of a **white-label platform for booking sports courts** (football, beach tennis, tennis, volleyball and more). It powers the customer app, the venue-manager dashboard and the internal admin panel through a single versioned REST API.

The hard part of this domain is not CRUD — it's **time**. A court slot must never be sold twice, business hours can be split into multiple shifts, prices change by hour and by holiday, and managers need to reserve slots manually (phone/WhatsApp) while keeping the same agenda consistent. This API encodes all of that as explicit, tested business rules.

> **Product:** FACCO · **Codebase name:** SportsF · **Domain:** `sfacco.com.br`

---

## ✨ Features

### Booking engine
- **Concurrency-safe reservations** — pessimistic row lock (`lockForUpdate`) inside a database transaction prevents double-booking even under simultaneous requests.
- **Strict slot alignment** for customers (bookings must match the court grid); **free duration** for managers, who see the grid as a reference only.
- **Configurable notice window** — minimum advance (default 1h) and maximum advance (default 30 days) are per-venue settings, not hardcoded.
- **Atomic batch booking** — booking multiple slots at once either creates all of them or creates none.
- **Manual / walk-in bookings** — staff can register phone or counter reservations without bypassing any conflict rule.
- **Cancellation window** — per-venue `cancel_limit_hours`, enforced server-side.

### Venue & court management
- Venues (complexes) with location, media, amenities, cover upload and public listing.
- Courts with sport, structure, details, image and per-court settings.
- **Operating hours with multiple shifts per day** (e.g. 08–12 and 14–22), validated against inverted or malformed ranges.
- **Dynamic pricing** — peak-hour rules by day/time plus **special prices for holidays**, resolved and merged when computing a slot price.
- **Agenda blockouts** — timed or **indefinite** blocks (rain, maintenance, events) with reason.
- **Fixed weekly schedules** (recurring classes / monthly clients) with **single-day exceptions** (skip one occurrence without dropping the series).

### Users, roles & trust
- **4 roles:** `ADMIN`, `GESTOR_ESPACO`, `FUNCIONARIO`, `CLIENTE`, enforced by a `CheckRole` middleware and per-record ownership checks.
- **Employee binding per venue** (`venue_staff`) — staff permissions are scoped to the complexes they belong to.
- **Role escalation is blocked** — `register` always forces `CLIENTE`; only an `ADMIN` can promote.
- **Token auth** via Laravel Sanctum, with `logout-all` and per-login cleanup of expired tokens.
- **Password recovery by e-mail** (Resend) with a dedicated anti-spam throttle and generic responses (no account enumeration).
- **Login lockout** after repeated failures.

### Reviews & analytics
- **Verified reviews** — only customers with a **played booking** (confirmed and already past) can rate a venue (1–5 stars + comment).
- **Access analytics** — anonymous `visitor_id` + `session_id` telemetry, with admin (global) and manager (own venues only) overviews.

### Engineering quality
- **56 automated tests** covering concurrency, authorization, pricing, flexible duration, fixed schedules, payments and analytics.
- Clean JSON error contract: `401` / `403` / `404` in the API's language, no stack traces leaked.
- Reverse-proxy aware (`trustProxies`), so rate limiting works correctly behind Railway/Render.
- Healthcheck endpoint for uptime probes.

---

## 🧱 Tech stack

| Layer | Choice |
| --- | --- |
| Language | PHP 8.3+ (typed, strict) |
| Framework | Laravel 12 |
| Database | PostgreSQL 16 |
| Auth | Laravel Sanctum (personal access tokens) |
| Media | Cloudinary (`cloudinary-labs/cloudinary-laravel`) |
| E-mail | Resend (`resend/resend-php`) |
| Tests | PHPUnit 11 |
| Local env | Docker Compose (PHP-FPM + Nginx + PostgreSQL + Mailpit) |
| Production | Docker (`php:8.4-apache`) on Railway |

---

## 🏗️ Architecture

A pragmatic layered architecture — **Controller → Service → Model** — with business rules concentrated in services so they stay testable and independent of HTTP.

```
app/
├── Http/
│   ├── Controllers/Api/     # 16 thin controllers (one per resource)
│   └── Middleware/CheckRole.php
├── Models/                  # 14 Eloquent models
└── Services/                # BookingService, BookingSlotService, FixedScheduleService
```

- `BookingService` — creates/cancels bookings, enforces every conflict rule inside a transaction.
- `BookingSlotService` — expands operating hours into bookable slots and computes prices (peak + special dates).
- `FixedScheduleService` — materializes recurring series, handles occurrences and exceptions.

### Domain model

```
Venue ──┬── Court ──┬── OperatingHour        (shifts per weekday)
        │           ├── CourtPricingRule     (peak hours)
        │           ├── CourtSpecialPrice    (holidays)
        │           ├── CourtBlockout        (timed / indefinite)
        │           └── CourtFixedSchedule ── CourtFixedScheduleException
        ├── VenueReview
        └── VenueStaff (user ↔ venue)
Booking ── Court · User
AccessEvent                                (anonymous/identified traffic)
Sport · Amenity                            (catalog)
```

### Business rules (highlights)

| Rule | Behavior |
| --- | --- |
| No double-booking | Row lock + transaction; conflicting insert fails atomically |
| Customer slot alignment | Must match the court grid exactly |
| Manager free duration | May book any length, still cannot overlap |
| Min / max advance | Per-venue configurable |
| Cancellation | Blocked inside the venue's cancel window |
| Fixed schedule vs. bookings | Overlap rejected in both directions |
| Payment | Partial or full, `payment_status` synced with legacy `is_paid` |

Full ruleset: [`regras_do_sistema.md`](regras_do_sistema.md).

---

## 🔌 API reference

Base URL: `/api/v1` · Full endpoint-by-endpoint documentation with payloads and examples: [`rotas.md`](rotas.md).

| Group | Endpoints |
| --- | --- |
| Health | `GET /health` |
| Auth | `register`, `login`, `forgot-password`, `reset-password`, `me`, `password`, `logout`, `logout-all` |
| Catalog | `GET /sports`, `GET /amenities`, `GET /cities` |
| Venues | list / show / reviews, `POST /venues/{venue}/reviews` |
| Courts | list / show, `operating-hours`, `available-slots` |
| Pricing | `pricing-rules`, `special-prices` |
| Agenda | `blockouts`, `fixed-schedules` (+ `occurrences`, `exceptions`) |
| Bookings | list, `store`, `storeBatch`, `cancel`, `payment` |
| Analytics | `POST /analytics/track`, `GET /analytics/overview`, `GET /venues/{venue}/analytics` |
| Admin | `GET /users`, venue & staff management |

Access is layered: public → authenticated → role-restricted (`ADMIN` / `GESTOR` / `FUNCIONARIO`), with ownership checks inside the services.

### Healthcheck

```json
{
  "status": "ok",
  "timestamp": "2026-08-08T21:02:59+00:00",
  "services": { "database": "connected", "php_version": "8.3.33" }
}
```

---

## 🚀 Getting started

### Option A — Docker Compose (recommended)

Spins up PHP-FPM, Nginx, PostgreSQL and Mailpit.

```bash
git clone https://github.com/DiovaniFacco/SportsF-back.git
cd back

cp .env.example .env
docker compose up -d --build

docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

| Service | URL |
| --- | --- |
| API | http://localhost:8000 |
| Mailpit (captured e-mails) | http://localhost:8025 |
| PostgreSQL | `localhost:5432` |

### Option B — Local PHP

Requires PHP 8.3+, Composer and a running PostgreSQL.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

> Seeding creates the essential data only (admin user, sports, amenities). The production container deliberately **does not** auto-seed, so deployments never overwrite real venue data.

### Environment

Key variables (see [`.env.example`](.env.example) for the full list):

| Variable | Purpose |
| --- | --- |
| `DB_CONNECTION` / `DB_*` | PostgreSQL connection |
| `FRONTEND_URL` | Base URL used in password-recovery e-mails |
| `CORS_ALLOWED_ORIGINS` | Allowed origins (`*.sfacco.com.br` allowed via regex) |
| `RESEND_API_KEY` | Transactional e-mail |
| `CLOUDINARY_URL` | Cover/image uploads |
| `SANCTUM_TOKEN_EXPIRATION_MINUTES` | Token lifetime |

---

## 🧪 Testing

```bash
php artisan test        # or: composer test
```

**56 tests** across 12 feature suites, including the ones that matter most for this domain:

| Suite | Guards against |
| --- | --- |
| `BookingConcurrencyTest` | Double-booking under concurrent requests |
| `FlexibleDurationTest` | Manager free duration vs. customer strict grid |
| `FixedScheduleTest` | Recurring-series overlaps and single-day exceptions |
| `NewBusinessRulesTest` | Notice windows, peaks and holiday pricing |
| `BookingPaymentTest` | Partial/full payment and overpayment rejection |
| `AuthTest` | Role locking, lockout, token revocation on reset |
| `AccessAnalyticsTest` | Anonymous vs. identified traffic, manager scoping |
| `IndefiniteBlockoutTest` | Permanent agenda blocks |
| `VenueCourtTest`, `QaFixesTest`, `HealthCheckTest` | Management flows and regressions |

---

## 📦 Deployment

Production runs on **Railway** using the root [`Dockerfile`](Dockerfile) (`php:8.4-apache`):

1. `composer install --no-dev --optimize-autoloader`
2. [`start.sh`](start.sh) caches config/routes/views, creates the storage link and runs `php artisan migrate --force`
3. Apache listens on the platform-injected `$PORT`

Set `APP_DEBUG=false`, a strong `APP_KEY`, the database credentials and the mail/media keys as environment variables. The API healthcheck lives at `GET /api/v1/health`.

---

## 📁 Project structure

```
├── app/
│   ├── Http/Controllers/Api/   # REST controllers
│   ├── Http/Middleware/        # CheckRole (RBAC)
│   ├── Models/                 # Eloquent models
│   └── Services/               # Booking / slots / fixed schedules
├── database/migrations/        # 34 migrations
├── docker/                     # nginx + php-fpm (local)
├── routes/api.php              # versioned /v1 routes
├── tests/                      # 56 PHPUnit tests
├── rotas.md                    # full API documentation (PT-BR)
├── regras_do_sistema.md        # business rules (PT-BR)
└── plano.md                    # architecture plan (PT-BR)
```

---

## 📚 Documentation

| Document | Content |
| --- | --- |
| [`rotas.md`](rotas.md) | Every endpoint with payloads and response examples |
| [`regras_do_sistema.md`](regras_do_sistema.md) | Roles, permissions and business rules |
| [`plano.md`](plano.md) | Domain overview and architecture decisions |

---

## 👤 Author

**Diovani Facco**

[![GitHub](https://img.shields.io/badge/GitHub-DiovaniFacco-181717?logo=github)](https://github.com/DiovaniFacco)
[![LinkedIn](https://img.shields.io/badge/LinkedIn-diovani--facco-0A66C2?logo=linkedin&logoColor=white)](https://www.linkedin.com/in/diovani-facco-3a247b11a/)
[![Email](https://img.shields.io/badge/Email-diovanifacco@gmail.com-EA4335?logo=gmail&logoColor=white)](mailto:diovanifacco@gmail.com)

> Frontend repository: **[DiovaniFacco/SportsF-front](https://github.com/DiovaniFacco/SportsF-front)** — Expo / React Native client for this API.
