# ENVIRONMENT & DEPLOYMENT RULES

---

# 1. APPLICATION TYPE

This is a **FULLY STANDALONE application**.

* Domain: **statisztika.asvanyvizek.hu**
* Completely separate from the email system (email.flowone.pro)
* Own database, own auth, own frontend, own backend
* Copies auth/security patterns from the email app -- does NOT share them at runtime

---

# 2. SERVER ROOT PATH

Production/test deployment:

/home/statisztika.asvanyvizek.hu/public_html/

---

# 3. PROJECT STRUCTURE ON SERVER

```
/home/statisztika.asvanyvizek.hu/public_html/
  .env                    <-- DB credentials, JWT secret, app config (user fills manually)
  index.php               <-- PHP entry point (API router)
  backend/
    controllers/
    services/
    repositories/
    validators/
    middleware/
    core/                 <-- Router, Request, Response, DB classes (adapted from email app)
    routes.php
  frontend/
    dist/                 <-- built Vue app (vite build output served by web server)
  migrations/
    SCHEMA.sql
    SEED.sql
  logs/
  storage/
```

---

# 4. LOCAL DEVELOPMENT STRUCTURE

In this repo, all code lives under:

```
EXCEL/
  docs/                   <-- project documentation (this file, RULES.md, etc.)
  backend/                <-- PHP backend source
  frontend/               <-- Vue 3 source (npm project with Vite)
  migrations/             <-- SQL schema + seed files
```

---

# 5. SERVER ENVIRONMENTS

## 5.1 TEST SERVER

* Web server: OpenLiteSpeed
* Domain: statisztika.asvanyvizek.hu
* Purpose: development + testing

## 5.2 PRODUCTION SERVER

* Web server: Apache
* Must be fully compatible

---

# 6. COMPATIBILITY RULES (CRITICAL)

Code MUST work on BOTH:

* OpenLiteSpeed
* Apache

## 6.1 URL HANDLING

* Do NOT rely on server-specific rewrites
* Use standard PHP-based routing

## 6.2 .HTACCESS

* Allowed (for Apache)
* BUT system must not break if ignored (LiteSpeed)

## 6.3 FILE PATHS

* ALWAYS use __DIR__ or config-based root
* NEVER hardcode server-specific paths

## 6.4 PERMISSIONS

* writable folders: logs/, storage/
* no chmod assumptions

---

# 7. DATABASE ENVIRONMENT

## RULES

* Separate dedicated database for this app
* User creates the DB on the server manually
* Agent produces SCHEMA.sql -- user runs it on server
* Agent produces SEED.sql -- user runs it optionally

## CONFIG

Database credentials are stored in:

```
.env
```

The .env file contains:

* DB_HOST
* DB_NAME
* DB_USER
* DB_PASS
* JWT_SECRET
* APP_DEBUG (true/false)
* APP_URL (https://statisztika.asvanyvizek.hu)
* SMTP_HOST
* SMTP_PORT
* SMTP_USER
* SMTP_PASS
* MAIL_FROM (e.g. noreply@asvanyvizek.hu)

Agent must NEVER hardcode credentials. Agent provides .env.example with placeholder values.
User fills in real credentials manually.

## CHARSET

* Database charset: utf8mb4
* Collation: utf8mb4_hungarian_ci

---

# 8. TECH STACK

* **Backend**: PHP 8.3 (no framework, custom Router/Request/Response adapted from email app)
* **Frontend**: Vue 3 + Vite + Pinia + Tailwind CSS + Google Material Symbols
* **Database**: MariaDB (separate DB)
* **Auth**: JWT (Bearer tokens) -- pattern copied from email app's SessionService
* **Icons**: Google Material Symbols (https://fonts.google.com/icons)

---

# 9. AUTH SYSTEM (STANDALONE)

* This app has its OWN auth system
* Pattern copied/adapted from email app (JWT + SessionService)
* Own users table, own login, own 2FA
* Roles: admin, reviewer, client (company)
* Auth is NOT shared with email system at runtime

---

# 10. API BASE PATH

All backend endpoints:

/api/...

---

# 11. FRONTEND

* Vue 3 SPA built with Vite
* Served as static files from frontend/dist/
* Uses Tailwind CSS for styling
* Uses Pinia for state management
* Toggle buttons instead of checkboxes
* Rounded pill buttons
* Google Material Symbols for icons

---

# 12. LOGGING

Logs stored in:

logs/

---

# 13. TEMP / CACHE

Use:

storage/

---

# 14. DEPLOYMENT RULES

* All development happens locally
* Code uploaded manually or via git pull
* NO live editing on server

## DEPLOY STEPS

1. Upload code to /home/statisztika.asvanyvizek.hu/public_html/
2. User fills in .env with real credentials
3. User creates DB on server
4. User runs: mysql -u USER -p'PASS' DB_NAME < migrations/SCHEMA.sql
5. User optionally runs: mysql -u USER -p'PASS' DB_NAME < migrations/SEED.sql
6. User builds frontend: cd frontend && npm install && npm run build
7. Verify endpoints work

## BEFORE DEPLOY

Agent must ensure:

* no hardcoded paths or credentials
* no debug code
* no dev-only configs
* .env.example provided with all required keys

---

# 15. ERROR HANDLING

* Must not expose server paths in responses
* Must return structured JSON errors
* Error messages in Hungarian

---

# 16. FINAL PRINCIPLE

System must be:

* environment-agnostic
* portable between LiteSpeed and Apache
* fully standalone (zero dependency on email system at runtime)

---

END
