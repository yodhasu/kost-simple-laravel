# Laravel API Reference

This document lists the Laravel-side app endpoints that currently power `kost-simple-laravel`, what they do, and where they are used.

## Design Notes

- Authentication uses Laravel session auth on the `users` table.
- Page rendering still uses Inertia server props for the main screens.
- Mutations use small REST-style endpoints under `/api/*`.
- Region access is enforced server-side through `App\Services\RegionScopeService`.
- Owner and IT can access all regions. Admin is limited to assigned regions.
- Query strategy favors narrow reloads after mutation instead of full page refetches.

## Page Endpoints

### `GET /dashboard`

- Purpose: render the dashboard page.
- Controller: `App\Http\Controllers\KostAppController@dashboard`
- Data source: `App\Services\DashboardPayloadService`
- Used by:
  - `resources/js/pages/KostDashboard.vue`

### `GET /tenants`

- Purpose: render tenant management page with tenant list, filters, and kost options.
- Controller: `App\Http\Controllers\KostAppController@tenants`
- Main queries:
  - paginated tenants via `App\Services\TenantsService::getAll()`
  - kost dropdown options via `KostAppController::kostOptions()`
- Used by:
  - `resources/js/pages/Tenants/Index.vue`
  - `resources/js/components/tenants/TenantFormModal.vue`
  - `resources/js/components/tenants/TenantDetailModal.vue`

### `GET /payments`

- Purpose: render quick actions page for tenant creation, payment input, expense input, and kost CRUD.
- Controller: `App\Http\Controllers\KostAppController@payments`
- Main queries:
  - kost dropdown options
  - payment tenant options
- Used by:
  - `resources/js/pages/Payments/Index.vue`
  - `resources/js/components/payments/PaymentUpdateModal.vue`
  - `resources/js/components/payments/ExpenseFormModal.vue`
  - `resources/js/components/kosts/KostFormModal.vue`
  - `resources/js/components/tenants/TenantFormModal.vue`

### `GET /export`

- Purpose: render export page and available export type options.
- Controller: `App\Http\Controllers\KostAppController@export`
- Used by:
  - `resources/js/pages/Exports/Index.vue`

### `GET /settings`

- Purpose: render region/admin settings page.
- Controller: `App\Http\Controllers\KostAppController@settings`
- Used by:
  - `resources/js/pages/KostSettings.vue`

## API Endpoints

All `/api/*` endpoints are inside `web + auth + verified` middleware.

### `GET /api/dashboard`

- Purpose: return the complete dashboard payload from one call.
- Controller: `App\Http\Controllers\Api\DashboardController`
- Service: `App\Services\DashboardPayloadService`
- Query notes:
  - supports `region_id`
  - uses server-side region scoping
- Used by:
  - available for dashboard FE/API parity

### `POST /api/tenants`

- Purpose: create a new tenant.
- Controller: `App\Http\Controllers\Api\TenantController@store`
- Service: `App\Services\TenantsService::create()`
- Business behavior:
  - validates kost access by region
  - blocks create if kost capacity is full
  - creates DP liability transaction when status = `dp`
  - otherwise creates initial rent transaction and linked extra-fee transaction
- Used by:
  - `resources/js/pages/Tenants/Index.vue`
  - `resources/js/pages/Payments/Index.vue`
  - `resources/js/components/tenants/TenantFormModal.vue`

### `PATCH /api/tenants/{tenant}`

- Purpose: update tenant data.
- Controller: `App\Http\Controllers\Api\TenantController@update`
- Service: `App\Services\TenantsService::update()`
- Business behavior:
  - validates current tenant access and target kost access
  - rechecks kost capacity
  - updates or creates DP transaction metadata when tenant stays in DP flow
- Used by:
  - `resources/js/pages/Tenants/Index.vue`
  - `resources/js/components/tenants/TenantFormModal.vue`

### `DELETE /api/tenants/{tenant}`

- Purpose: deactivate a tenant instead of hard-deleting.
- Controller: `App\Http\Controllers\Api\TenantController@destroy`
- Service: `App\Services\TenantsService::delete()`
- Business behavior:
  - releases frozen DP transaction into revenue when applicable
  - marks tenant `is_active = false`
  - sets tenant status to `inaktif`
  - sets `end_date` to current date
- Used by:
  - `resources/js/pages/Tenants/Index.vue`
  - `resources/js/components/tenants/TenantDetailModal.vue`

### `POST /api/payments`

- Purpose: record a tenant payment.
- Controller: `App\Http\Controllers\Api\PaymentController@store`
- Service: `App\Services\TransactionsService::createPayment()`
- Business behavior:
  - validates kost/tenant pairing
  - creates revenue transaction
  - unfreezes linked DP transaction when paying off DP
  - creates linked `extra_fee` expense transaction when tenant has fee components
  - promotes tenant status from `dp` or `telat` to `aktif`
- Used by:
  - `resources/js/pages/Payments/Index.vue`
  - `resources/js/pages/Tenants/Index.vue`
  - `resources/js/components/payments/PaymentUpdateModal.vue`
  - `resources/js/components/tenants/TenantDetailModal.vue`

### `POST /api/expenses`

- Purpose: create an expense transaction at region or kost level.
- Controller: `App\Http\Controllers\Api\ExpenseController@store`
- Service: `App\Services\TransactionsService::createExpense()`
- Business behavior:
  - accepts either region-level or kost-level expense
  - derives `region_id` from `kost_id` when a kost is selected
  - enforces region access
- Used by:
  - `resources/js/pages/Payments/Index.vue`
  - `resources/js/components/payments/ExpenseFormModal.vue`

### `POST /api/kosts`

- Purpose: create a kost/property record.
- Controller: `App\Http\Controllers\Api\KostController@store`
- Service: `App\Services\KostsService::create()`
- Business behavior:
  - requires region
  - enforces region access
- Used by:
  - `resources/js/pages/Payments/Index.vue`
  - `resources/js/components/kosts/KostFormModal.vue`

### `PATCH /api/kosts/{kost}`

- Purpose: update kost data.
- Controller: `App\Http\Controllers\Api\KostController@update`
- Service: `App\Services\KostsService::update()`
- Business behavior:
  - enforces region access
  - blocks reducing `total_units` below active tenant count
- Used by:
  - `resources/js/pages/Payments/Index.vue`
  - `resources/js/components/kosts/KostFormModal.vue`

### `DELETE /api/kosts/{kost}`

- Purpose: delete a kost record.
- Controller: `App\Http\Controllers\Api\KostController@destroy`
- Service: `App\Services\KostsService::delete()`
- Business behavior:
  - enforces region access
  - blocks delete while active tenants still exist
- Used by:
  - `resources/js/pages/Payments/Index.vue`
  - `resources/js/components/kosts/KostFormModal.vue`

### `GET /api/exports/download`

- Purpose: download one Excel `.xlsx` workbook with multiple sheets from the export page.
- Controller: `App\Http\Controllers\Api\ExportController`
- Service: `App\Services\ExportsService::download()`
- Query params:
  - `start_date`
  - `end_date`
  - `region_id` optional
  - `data_types[]` one or more of `tenants`, `payments`, `expenses`, `control_map`
- Behavior:
  - always returns one `.xlsx` file
  - each selected type becomes its own sheet
  - `control_map` adds a “who controls what” operational sheet for region, kost, admin, role, and assignment info
- Query notes:
  - uses PhpSpreadsheet for workbook generation
  - uses direct query-builder joins for export speed
  - applies server-side region scoping
- Used by:
  - `resources/js/pages/Exports/Index.vue`

## Existing Settings Endpoints

These are still Inertia/web mutations rather than JSON API endpoints, and they are already connected.

### Region CRUD

- `POST /settings/regions`
- `PATCH /settings/regions/{region}`
- `DELETE /settings/regions/{region}`
- Controller: `App\Http\Controllers\Settings\RegionController`
- Service: `App\Services\RegionsService`
- Used by:
  - `resources/js/pages/KostSettings.vue`

### Admin CRUD

- `POST /settings/admins`
- `PATCH /settings/admins/{user}`
- `DELETE /settings/admins/{user}`
- Controller: `App\Http\Controllers\Settings\AdminAccountController`
- Service: `App\Services\UserProfileService`
- Used by:
  - `resources/js/pages/KostSettings.vue`

## Main Service Layer

- `App\Services\DashboardPayloadService`
  - assembles dashboard payload for both Inertia and API use
- `App\Services\DashboardService`
  - heavy dashboard summary/trend calculations
- `App\Services\TenantsService`
  - tenant CRUD rules and tenant-linked transaction side effects
- `App\Services\TransactionsService`
  - payment and expense transaction creation logic
- `App\Services\KostsService`
  - kost CRUD rules and capacity protection
- `App\Services\RegionsService`
  - region CRUD rules and dependency checks
- `App\Services\UserProfileService`
  - admin/profile/account region assignment logic
- `App\Services\RegionScopeService`
  - central region visibility and authorization rules
- `App\Services\ExportsService`
  - efficient CSV/ZIP export generation

## Recommended Next Backend Steps

- Move tenant list filtering and pagination fully server-side via query param reloads.
- Add API field-level validation display in modals instead of only top-level error messages.
- Add transaction list/read endpoints if you want a dedicated finance ledger screen.
- Add DB indexes for:
  - `transactions(region_id, transaction_date, financial_class)`
  - `tenants(kost_id, is_active, status)`
  - `kosts(region_id)`
