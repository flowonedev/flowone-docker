# ARCHITECTURE RULES

---

# 1. APPLICATION TYPE

This is a **fully standalone web application**.

* NOT a module/addon inside another system
* Has its own backend, frontend, database, and auth
* Auth/security patterns adapted from the email app (email.flowone.pro) for speed
* Domain: statisztika.asvanyvizek.hu

---

# 2. FULL PROJECT STRUCTURE

```
EXCEL/
  docs/                              <-- documentation
  backend/
    index.php                        <-- entry point, loads .env, boots router
    .env.example                     <-- environment template
    core/
      Router.php                     <-- HTTP router (adapted from email app)
      Request.php                    <-- request wrapper
      Response.php                   <-- JSON response helper
      Database.php                   <-- PDO connection (reads .env)
    middleware/
      AuthMiddleware.php             <-- JWT validation (adapted from email app)
      RoleMiddleware.php             <-- role-based access control
      RateLimitMiddleware.php        <-- API request throttling
    controllers/
      AuthController.php             <-- login, logout, refresh, me
      TwoFactorController.php        <-- 2FA setup, verify
      ConfigController.php           <-- dimension config CRUD
      EntriesController.php          <-- data entry save/load
      WorkflowController.php         <-- submit, approve, reject
      AggregationController.php      <-- calculated outputs, chart data
      UserController.php             <-- user management (admin)
      PeriodController.php           <-- period management (admin)
      CompanyController.php          <-- company management (admin)
      ImportController.php           <-- XML/CSV upload + automated API push
      ApiKeyController.php           <-- API key CRUD (admin)
      NotificationController.php     <-- email notification management
      PublicController.php           <-- public stats endpoints (no auth)
    services/
      SessionService.php             <-- JWT create/validate (adapted from email app)
      SessionTrackingService.php     <-- active session tracking, revocation
      PasswordService.php            <-- bcrypt hash/verify
      RateLimitService.php           <-- brute-force protection
      EntryService.php               <-- entry business logic
      ConfigService.php              <-- config/dimension logic
      ConfigSnapshotService.php      <-- period config snapshots
      WorkflowService.php            <-- state transitions
      AggregationService.php         <-- calculation engine
      AuditService.php               <-- audit logging
      ValidationService.php          <-- business rule validation
      ImportService.php              <-- XML/CSV parsing, validation, import
      ApiKeyService.php              <-- API key generation, validation
      NotificationService.php        <-- email sending (SMTP)
      ExportService.php              <-- CSV/PDF export generation
    repositories/
      EntryRepository.php            <-- entries_draft / entries_final queries
      ConfigRepository.php           <-- dimension table queries
      UserRepository.php             <-- users table queries
      SessionRepository.php          <-- user_sessions queries
      AuditRepository.php            <-- audit_logs queries
      PeriodRepository.php           <-- periods queries
      CompanyRepository.php          <-- companies queries
      ImportDefaultRepository.php    <-- import_defaults queries
      ApiKeyRepository.php           <-- api_keys queries
      NotificationRepository.php     <-- email_notifications queries
      FlavorRepository.php           <-- flavor entries queries
      SugarRepository.php            <-- sugar entries queries
      CalorieRepository.php          <-- calorie entries queries
    validators/
      EntryValidator.php             <-- input validation for entries
      ConfigValidator.php            <-- input validation for config
      ImportValidator.php            <-- XML/CSV format validation
    helpers/
      LogRedactor.php                <-- strip sensitive data from logs
    routes.php                       <-- route definitions
  frontend/
    package.json
    vite.config.js
    tailwind.config.js
    postcss.config.js
    index.html
    src/
      main.js                        <-- Vue app bootstrap
      App.vue                        <-- root component
      router/
        index.js                     <-- Vue Router setup
      store/
        authStore.js                 <-- login state, JWT, user info
        configStore.js               <-- dimension data from API
        entryStore.js                <-- grid data, autosave state
        workflowStore.js             <-- submission/approval state
        chartStore.js                <-- chart data and filters
      api/
        client.js                    <-- native fetch wrapper with JWT interceptor
        authApi.js                   <-- login/logout/refresh calls
        configApi.js                 <-- config endpoints
        entriesApi.js                <-- entry save/load endpoints
        workflowApi.js               <-- submit/approve endpoints
        aggregationApi.js            <-- aggregation endpoints
        importApi.js                 <-- XML/CSV upload endpoints
        chartApi.js                  <-- chart data endpoints
      pages/
        LoginPage.vue
        DashboardPage.vue
        DataEntryPage.vue
        FlavorEntryPage.vue
        SugarEntryPage.vue
        CalorieEntryPage.vue
        ReviewPage.vue
        ChartsPage.vue
        PublicStatsPage.vue
        AdminConfigPage.vue
        AdminUsersPage.vue
        AdminPeriodsPage.vue
        AdminCompaniesPage.vue
        AdminApiKeysPage.vue
        ImportPage.vue
      components/
        layout/
          AppShell.vue               <-- sidebar + topbar layout
          SideNav.vue
          TopBar.vue
        grid/
          DataGrid.vue               <-- main Excel-like grid
          GridCell.vue               <-- editable cell
          GridRow.vue
          GridHeader.vue
        charts/
          ChartContainer.vue         <-- wrapper for ApexCharts
          PieChart.vue
          BarChart.vue
          LineChart.vue
          TreemapChart.vue
          ChartFilters.vue           <-- time/product/flow filters
        common/
          ToggleSwitch.vue           <-- toggle (never checkboxes)
          PillButton.vue             <-- rounded pill button
          StatusBadge.vue
          SaveIndicator.vue
          AlertMessage.vue
          FileUpload.vue             <-- XML/CSV upload component
  migrations/
    SCHEMA.sql                       <-- full database schema
    SEED.sql                         <-- initial/test data
```

---

# 3. ROLES & PERMISSIONS

## 3.1 ROLE DEFINITIONS

| Role | Hungarian Label | Description |
|------|----------------|-------------|
| admin | Szövetség (Adminisztrátor) | Full access to everything: users, companies, config, dimensions, periods, all data, all settings, publish to public page |
| reviewer | Ellenőrző | Can see ALL companies' data, directly edit company data (fix errors), create statistics/reports, approve/reject. CANNOT manage config, users, periods, or system structure |
| client | Tagvállalat (Ügyfél) | Enters data for their own company, uploads XML/CSV to import data, uses automated API, submits for review. Can see own data + anonymous industry aggregates |

## 3.2 PERMISSION MATRIX

| Action | admin | reviewer | client |
|--------|-------|----------|--------|
| Login / view dashboard | yes | yes | yes |
| Enter data (own company) | yes | no | yes |
| Enter data (any company) | yes | no | no |
| Upload XML/CSV to import data | yes | no | yes |
| Use automated API (API key) | yes | no | yes |
| Submit data for review | yes | no | yes |
| View all companies' data | yes | yes | no |
| Directly edit company data (fix errors) | yes | yes | no |
| Approve / reject submissions | yes | yes | no |
| Create statistics / reports | yes | yes | no |
| View charts (all companies) | yes | yes | no |
| View charts (own company + anonymous aggregate) | yes | yes | yes |
| Manage users | yes | no | no |
| Manage companies | yes | no | no |
| Manage config (dimensions) | yes | no | no |
| Manage periods (open/close/deadline) | yes | no | no |
| Manage API keys | yes | no | no |
| Set import defaults | yes | no | no |
| Publish to public page | yes | no | no |
| View public stats (no login) | -- | -- | -- |

## 3.3 COMPANY BINDING

* Each user with role "client" is bound to exactly ONE company
* Admin and reviewer see ALL companies
* A client can only see/edit their own company's data
* Reviewers can directly edit any company's draft data (to fix errors before approval)

## 3.4 THREE-TIER VISIBILITY

| Tier | Access | Data |
|------|--------|------|
| Szövetség (admin/reviewer) | Full login | All data, per-company breakdown |
| Tagok (client, logged in) | Login required | Own company detail + anonymous industry aggregate |
| Nyilvános (public) | No login | Selected published aggregates only |

---

# 4. AUTH SYSTEM

* Adapted from email app's JWT implementation
* Login: email + password -> returns access_token + refresh_token
* Access token: short-lived (e.g. 15 min)
* Refresh token: longer-lived (e.g. 7 days), rotated on use
* All API calls require Bearer token in Authorization header
* 2FA support (adapted from email app) -- Phase 2
* Rate limiting on login attempts (IP + email based)
* Session tracking with revocation capability
* Log redaction for sensitive data

---

# 5. NAMING RULES

## Backend (PHP)

* Controllers: PascalCase + Controller (e.g. EntriesController.php)
* Services: PascalCase + Service (e.g. EntryService.php)
* Repositories: PascalCase + Repository (e.g. EntryRepository.php)
* Validators: PascalCase + Validator (e.g. EntryValidator.php)
* Variables: snake_case
* Methods: camelCase
* Classes: PascalCase

## Frontend (Vue)

* Components: PascalCase.vue (e.g. DataGrid.vue)
* Stores: camelCase + Store (e.g. entryStore.js)
* API files: camelCase + Api (e.g. entriesApi.js)
* Files must match component/module name

---

# 6. FILE SIZE LIMIT

* MAX: 1200 lines per file
* Recommended: < 500 lines

IF exceeded:
-> MUST split into smaller modules

---

# 7. SEPARATION OF CONCERNS

* Controller -> HTTP request/response only
* Service -> business logic
* Repository -> database queries only
* Validator -> input validation only
* Store (Pinia) -> state + orchestration only (no heavy logic)
* Component (Vue) -> rendering only (no business logic)

---

# 8. NO MIXING RULES

* NO SQL in controllers
* NO business logic in Vue components
* NO direct DB access outside repositories
* NO calculations in frontend
* NO hardcoded credentials anywhere

---

# 9. API STRUCTURE

All routes:

```
/api/auth/...           <-- login, logout, refresh, me, 2fa
/api/config/...         <-- dimension management
/api/entries/...        <-- data entry CRUD
/api/workflow/...       <-- submit, approve, reject
/api/aggregations/...   <-- calculated outputs, chart data
/api/users/...          <-- user management (admin)
/api/periods/...        <-- period management (admin)
/api/companies/...      <-- company management (admin)
/api/import/...         <-- XML/CSV upload, automated API push
/api/api-keys/...       <-- API key management (admin)
/api/notifications/...  <-- notification management
/api/export/...         <-- CSV/PDF export
/api/public/...         <-- public stats (no auth required)
```

---

# 10. SINGLE RESPONSIBILITY

Each file must do ONE thing only.

---

# 11. REUSABILITY

Shared logic must be extracted into services.
Never duplicate code between controllers.

---

# 12. REFERENCE SOURCE

When implementing auth, security, routing, or core infrastructure:

* READ patterns from: email/backend/src/ (read-only reference)
* Key files to reference:
  - email/backend/src/Services/SessionService.php (JWT logic)
  - email/backend/src/Controllers/AuthController.php (login flow)
  - email/backend/src/Core/Router.php (routing)
  - email/backend/routes.php (route definitions)
  - email/backend/src/Controllers/TwoFactorController.php (2FA)
  - email/backend/src/Services/SessionTrackingService.php (session tracking)
  - email/backend/src/Services/RateLimitService.php (brute-force protection)
  - email/backend/src/Middleware/RateLimitMiddleware.php (API rate limiting)
  - email/backend/src/Helpers/LogRedactor.php (log redaction)
* ADAPT and create NEW files inside EXCEL/ -- never copy-paste blindly

---

END
