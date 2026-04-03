# Kost Simple — Boarding House Management System

A full-stack web application for managing boarding houses (kost) built with **Laravel 13**, **Vue 3**, **Inertia.js**, and **TailwindCSS v4**. Supports multi-region property management with role-based access control.

---

## Table of Contents

- [Features](#features)
- [Tech Stack](#tech-stack)
- [Architecture](#architecture)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Running the App](#running-the-app)
- [User Roles & Access Control](#user-roles--access-control)
- [Scheduled Tasks (Cron Jobs)](#scheduled-tasks-cron-jobs)
- [Database Schema](#database-schema)
- [API Reference](#api-reference)
- [Project Structure](#project-structure)
- [Development](#development)

---

## Features

- **Dashboard** — Real-time financial overview with net revenue, DP tracking, occupancy stats, weekly cashflow chart, and income/expense breakdown by kost with pie charts
- **Tenant Management** — Full CRUD for tenants across all kosts, with status tracking (aktif, telat, dp)
- **Payment Processing** — Record rent payments with automatic DP settlement, extra fee deductions (trash, security, admin), and auto-transition from `telat`/`dp` → `aktif`
- **Expense Tracking** — Categorized expense recording per kost or per region
- **Data Export** — Export tenant and transaction data to Excel spreadsheets
- **Multi-Region Support** — Organize kosts by geographic region with cross-region reporting
- **Role-Based Access Control (RBAC)** — Three roles: Owner, IT, and Admin with granular permission enforcement
- **Overdue Detection** — Automated daily cron job that flags tenants with late rent payments
- **Responsive Design** — Fully optimized for desktop and mobile with separate layouts

---

## Tech Stack

| Layer      | Technology                            |
|------------|---------------------------------------|
| Backend    | PHP 8.3+, Laravel 13                  |
| Frontend   | Vue 3 (Composition API), TypeScript   |
| Routing    | Inertia.js v3                         |
| Styling    | TailwindCSS v4                        |
| Auth       | Laravel Fortify                       |
| Build      | Vite 8                                |
| Database   | SQLite (default) / MySQL / PostgreSQL |
| Icons      | Lucide Vue                            |
| Excel      | PhpSpreadsheet                        |

---

## Architecture

```
┌──────────────────────────────────────────────────┐
│                   Browser (SPA)                  │
│         Vue 3 + Inertia.js + TailwindCSS         │
└────────────────────┬─────────────────────────────┘
                     │ Inertia Requests
                     ▼
┌──────────────────────────────────────────────────┐
│              Laravel 13 Backend                  │
│  ┌────────────┐  ┌──────────┐  ┌──────────────┐ │
│  │ Controllers│  │Middleware │  │   Services   │ │
│  │  (Web+API) │→ │(Auth,RBAC)│→ │ (Business    │ │
│  │            │  │           │  │  Logic)      │ │
│  └────────────┘  └──────────┘  └──────┬───────┘ │
│                                       │         │
│  ┌───────────────────────────────────┐│         │
│  │        Eloquent Models            ││         │
│  │  Region → Kost → Tenant           │←────────┘ │
│  │                → Transaction       │         │
│  │  User → UserProfile → UserRegion   │         │
│  └───────────────┬───────────────────┘         │
└──────────────────┼──────────────────────────────┘
                   ▼
          ┌─────────────┐
          │   Database   │
          │   (SQLite)   │
          └─────────────┘
```

---

## Prerequisites

- **PHP** ≥ 8.3 with extensions: `mbstring`, `xml`, `sqlite3` (or `pdo_mysql`)
- **Composer** ≥ 2.x
- **Node.js** ≥ 20.x
- **npm** ≥ 10.x

---

## Installation

### 1. Clone the repository

```bash
git clone <repository-url>
cd kost-simple-laravel
```

### 2. Quick setup (recommended)

```bash
composer setup
```

This single command will:
- Install PHP dependencies (`composer install`)
- Copy `.env.example` → `.env`
- Generate the application key
- Run database migrations
- Install Node.js dependencies (`npm install`)
- Build the frontend (`npm run build`)

### 3. Manual setup (alternative)

```bash
# PHP dependencies
composer install

# Environment configuration
cp .env.example .env
php artisan key:generate

# Database
php artisan migrate

# Frontend
npm install
npm run build
```

### 4. Create the first user

```bash
php artisan tinker
```

```php
use App\Models\User;
use App\Models\UserProfile;

$user = User::create([
    'username' => 'owner',
    'email' => 'owner@kost.local',
    'password' => bcrypt('password'),
    'email_verified_at' => now(),
]);

UserProfile::create([
    'user_id' => $user->id,
    'name' => 'Owner Name',
    'role' => 'owner',
]);
```

---

## Running the App

### Development (all services in one command)

```bash
composer dev
```

This runs concurrently:
- **Laravel server** — `php artisan serve` (http://localhost:8000)
- **Queue worker** — `php artisan queue:listen`
- **Vite dev server** — `npm run dev` (HMR)

### Production

```bash
npm run build
php artisan serve
```

---

## User Roles & Access Control

The application supports three user roles with different permission levels:

| Feature                     | Owner | IT    | Admin |
|-----------------------------|-------|-------|-------|
| View Dashboard              | ✅    | ✅    | ✅    |
| View Tenants                | ✅    | ✅    | ✅    |
| Add/Edit/Delete Tenants     | ✅    | ✅    | ✅    |
| Record Payments             | ✅    | ✅    | ✅    |
| Record Expenses             | ✅    | ✅    | ✅    |
| Export Data                 | ✅    | ✅    | ✅    |
| Add/Edit/Delete Kost        | ✅    | ✅    | ❌    |
| Access Settings             | ✅    | ✅    | ❌    |
| Manage Regions              | ✅    | ✅    | ❌    |
| Manage Admin Accounts       | ✅    | ✅    | ❌    |
| View Kost Details (read-only) | ✅ | ✅    | ✅    |

**Admin restrictions:**
- Cannot access the Settings page (redirect to dashboard)
- Cannot add, edit, or delete kosts (API returns 403)
- Region selectors only show assigned regions
- "Tambah Kost" shows a forbidden overlay
- "Daftar Kost" opens in view-only mode
- Navigation hides the "Pengaturan" link

### Middleware

- `role.owner_or_it` — Registered in `bootstrap/app.php`, enforced on:
  - `GET /settings` (web)
  - All region and admin CRUD routes (`settings.php`)
  - Kost store/update/destroy API routes (`api.php`)

---

## Scheduled Tasks (Cron Jobs)

### Overdue Tenant Detection

The application includes an automated system to detect tenants with overdue rent payments.

#### How It Works

1. The command `tenants:check-overdue` runs **daily at midnight**
2. It queries all tenants with `status = 'aktif'` and `is_active = true`
3. For each tenant, it checks the **last rent payment date** from the `transactions` table
4. If no payment exists, it falls back to the tenant's `start_date`
5. The **next due date** is calculated as: `last_payment_date + 1 month`
6. If `today > next_due_date`, the tenant's status is updated to `telat`
7. When a payment is recorded later (via the Payments page), the status automatically transitions back to `aktif`

#### Month Overflow Handling

For edge dates like January 31:
- Jan 31 → Feb 28 (uses `addMonthNoOverflow`)
- If the original day exceeds the target month's days (e.g., 31 > 28), the due date pushes to the **1st of the following month** (Mar 1)

#### Running Manually

```bash
php artisan tenants:check-overdue
```

Output example:
```
Checked 3 tenants. Marked 1 as telat.
```

#### Setting Up the Cron Job (Production)

Add this entry to your server's crontab (`crontab -e`):

```cron
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

This runs Laravel's scheduler every minute. The scheduler itself determines when each command should execute (in this case, `tenants:check-overdue` runs once daily at 00:00).

#### On Windows (Task Scheduler)

1. Open **Task Scheduler**
2. Create a new task that runs every minute
3. Action: `php artisan schedule:run`
4. Working directory: your project path

Or use a batch script:

```bat
cd C:\path\to\kost-simple-laravel
php artisan schedule:run
```

#### Dashboard Integration

When overdue tenants are detected, the dashboard displays:
- A **red `!` badge** on the "Penyewa Aktif" card
- On hover, a **tooltip** showing per-kost breakdown: *"X penyewa telat di [Kost Name]"*

The overdue data automatically respects region filtering — admin users only see overdue tenants from their assigned regions.

#### Source Files

| File | Purpose |
|------|---------|
| `app/Console/Commands/CheckOverdueTenants.php` | The artisan command that checks and marks overdue tenants |
| `routes/console.php` | Registers the daily schedule |
| `app/Services/DashboardService.php` | Provides `overdue_tenants` count and `overdue_by_kost` breakdown |
| `app/Services/DashboardPayloadService.php` | Passes overdue data to the frontend |
| `resources/js/pages/KostDashboard.vue` | Renders the red badge and hover tooltip |

---

## Database Schema

```
regions
├── id (UUID, PK)
├── name
└── created_at

users
├── id (auto-increment, PK)
├── username
├── email
├── password
└── email_verified_at

user_profiles
├── id (UUID, PK)
├── user_id (FK → users)
├── name
└── role (owner | it | admin)

user_regions
├── id (UUID, PK)
├── user_id (FK → users)
├── region_id (FK → regions)
└── assigned_at

kosts
├── id (UUID, PK)
├── region_id (FK → regions)
├── name
├── address
├── total_units
├── notes
└── created_at

tenants
├── id (UUID, PK)
├── kost_id (FK → kosts)
├── name
├── phone
├── start_date
├── end_date
├── rent_price
├── status (aktif | telat | dp)
├── is_active (boolean)
├── trash_fee
├── security_fee
├── admin_fee
└── created_at

transactions
├── id (UUID, PK)
├── kost_id (FK → kosts)
├── tenant_id (FK → tenants, nullable)
├── region_id (FK → regions)
├── financial_class (REVENUE | EXPENSE | LIABILITY)
├── category (rent | dp | extra_fee | ...)
├── amount
├── transaction_date
├── description
├── is_frozen (boolean)
├── reference_id (UUID, nullable)
└── created_at
```

---

## API Reference

All API routes are prefixed with `/api` and require authentication.

### Dashboard
| Method | Endpoint     | Description            |
|--------|-------------|------------------------|
| GET    | `/api/dashboard` | Fetch dashboard data |

### Tenants
| Method  | Endpoint               | Description       |
|---------|------------------------|--------------------|
| POST    | `/api/tenants`         | Create tenant      |
| PATCH   | `/api/tenants/{id}`    | Update tenant      |
| DELETE  | `/api/tenants/{id}`    | Delete tenant      |

### Payments & Expenses
| Method | Endpoint         | Description       |
|--------|------------------|--------------------|
| POST   | `/api/payments`  | Record payment     |
| POST   | `/api/expenses`  | Record expense     |

### Kosts (Owner/IT only)
| Method  | Endpoint             | Description     |
|---------|----------------------|------------------|
| POST    | `/api/kosts`         | Create kost      |
| PATCH   | `/api/kosts/{id}`    | Update kost      |
| DELETE  | `/api/kosts/{id}`    | Delete kost      |

### Exports
| Method | Endpoint               | Description          |
|--------|------------------------|----------------------|
| GET    | `/api/exports/download`| Download Excel file  |

---

## Project Structure

```
kost-simple-laravel/
├── app/
│   ├── Console/Commands/
│   │   └── CheckOverdueTenants.php    # Cron: overdue tenant detection
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/                   # REST API controllers
│   │   │   ├── Settings/              # Settings page controllers
│   │   │   └── KostAppController.php  # Main page controller (Inertia)
│   │   └── Middleware/
│   │       └── EnsureOwnerOrIt.php    # RBAC middleware
│   ├── Models/
│   │   ├── Kost.php
│   │   ├── Region.php
│   │   ├── Tenant.php
│   │   ├── Transaction.php
│   │   ├── User.php
│   │   ├── UserProfile.php
│   │   └── UserRegion.php
│   └── Services/
│       ├── DashboardService.php       # Dashboard stats & charts
│       ├── DashboardPayloadService.php# Dashboard data assembly
│       ├── ExportsService.php         # Excel export generation
│       ├── KostsService.php           # Kost CRUD
│       ├── RegionScopeService.php     # Region-based access scoping
│       ├── RegionsService.php         # Region CRUD
│       ├── TenantsService.php         # Tenant CRUD + status logic
│       ├── TransactionsService.php    # Payment/expense processing
│       └── UserProfileService.php     # User & admin management
├── resources/js/
│   ├── components/                    # Reusable Vue components
│   ├── layouts/
│   │   └── KostLayout.vue             # Main app layout with RBAC nav
│   ├── pages/
│   │   ├── KostDashboard.vue          # Dashboard with charts & stats
│   │   ├── KostSettings.vue           # Settings (regions, admins)
│   │   ├── Tenants/Index.vue          # Tenant management
│   │   ├── Payments/Index.vue         # Payment & kost management
│   │   └── Exports/Index.vue          # Data export page
│   └── types/                         # TypeScript type definitions
├── routes/
│   ├── web.php                        # Page routes
│   ├── api.php                        # API routes
│   ├── settings.php                   # Settings CRUD routes
│   └── console.php                    # Scheduled tasks
└── database/migrations/               # Database schema
```

---

## Development

### Available Commands

```bash
# Development server (Laravel + Vite + Queue)
composer dev

# Build frontend for production
npm run build

# Code quality
composer lint          # Run PHP CS Fixer (Laravel Pint)
npm run lint           # Run ESLint + fix
npm run format         # Run Prettier
npm run types:check    # TypeScript type checking

# Tests
composer test          # Run PHPUnit tests

# Cron job
php artisan tenants:check-overdue   # Manually check overdue tenants
php artisan schedule:run            # Run all scheduled tasks
php artisan schedule:list           # List all scheduled tasks
```

### Environment Variables

Copy `.env.example` to `.env` and configure:

```env
APP_NAME="Kost Simple"
APP_URL=http://localhost:8000

DB_CONNECTION=sqlite    # or mysql, pgsql
DB_DATABASE=/absolute/path/to/database.sqlite

# For MySQL/PostgreSQL:
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=kost_simple
# DB_USERNAME=root
# DB_PASSWORD=
```

---

## License

MIT
