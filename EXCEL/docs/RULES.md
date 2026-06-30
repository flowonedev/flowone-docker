STATISTICS SYSTEM — MASTER RULES & BRIEF (FOR AI AGENT)

0. NON-NEGOTIABLE RULES

This is a **FULLY STANDALONE application** on statisztika.asvanyvizek.hu.

Use the SAME tech stack and patterns as the email app:
* PHP backend (custom Router, no framework)
* Vue 3 frontend (Vite + Pinia + Tailwind)
* JWT auth (adapted from email app's SessionService)
* Google Material Symbols for icons

Auth, security, routing patterns are COPIED/ADAPTED from the email app.
They are NOT shared at runtime. This app runs independently.

DO NOT:
* introduce new frameworks
* use the email system's database
* create runtime dependencies on the email system

ALL code for this project lives in:
→ EXCEL/ folder in this repository

---

0.A LANGUAGE RULE (NON-NEGOTIABLE)

The ENTIRE application must be in HUNGARIAN.

* ALL UI text, labels, buttons, messages, tooltips, placeholders → Hungarian
* ALL error messages → Hungarian
* ALL status labels → Hungarian
* ALL table headers, column names in UI → Hungarian
* Must correctly handle Hungarian special characters: á, é, í, ó, ö, ő, ú, ü, ű (and uppercase: Á, É, Í, Ó, Ö, Ő, Ú, Ü, Ű)
* Database charset MUST be utf8mb4 with utf8mb4_hungarian_ci collation
* All HTML pages must declare: <meta charset="UTF-8">
* All API responses must use UTF-8 encoding
* NO English text in the user-facing interface

---

0.B FILE SCOPE RULE (NON-NEGOTIABLE)

The AI agent must NEVER edit, create, or delete any file outside the EXCEL/ folder.

* ALL code, docs, configs for this project → inside EXCEL/ only
* Existing system files outside EXCEL/ → READ-ONLY (for reference)
* This rule has NO exceptions

---

0.C ROLES (NON-NEGOTIABLE)

Three roles exist in this system:

* **admin** (Adminisztrátor) — full access to everything: users, companies, config, dimensions, periods, all data, all settings
* **reviewer** (Ellenőrző) — can see ALL companies' data, directly edit company data (fix errors), create statistics/reports, approve/reject. CANNOT manage config, users, periods, or system structure
* **client** (Ügyfél) — enters data for their own company only, uploads XML or CSV to import data, can use automated API (API key) for machine-to-machine upload, submits for review. Cannot see other companies

Each "client" is bound to exactly one company.
Admin and reviewer can see all companies.
Reviewers CAN directly edit any company's draft data (to fix errors before approving).

---

0.D DATABASE RULE (NON-NEGOTIABLE)

* This app uses its OWN separate database
* Credentials stored in .env (user fills manually)
* Agent produces SCHEMA.sql, user runs it on server
* Agent NEVER hardcodes credentials
1. SYSTEM PURPOSE

Replace Excel-based statistical system with:

structured data input
validation
aggregation
approval workflow
public output

System must replicate Excel logic using:
→ database + rules (NOT formulas in UI)

2. CORE CONCEPT

System is a:

→ DATA ENGINE (not a form app, not Excel clone)

All outputs must be derived from:
→ atomic stored data

3. DATA MODEL RULES
3.1 Atomic Entry Definition

Each row MUST represent:

company_id
period_id (year + quarter)
dataset_type
product_group_id
subtype (A, B, C…)
packaging_type_id
returnable_type_id
size_id
flow_type (domestic/export/import)
metric_type (db)
value
3.2 STRICT UNIQUE CONSTRAINT

Combination MUST be unique:

(company_id + period_id + dataset_type + product_group_id + subtype + packaging + returnable + size + flow_type + metric)

3.3 FORBIDDEN DATA

NEVER STORE:

totals
“összesen”
calculated liters
summary values
3.4 DERIVED VALUES

Must be calculated ONLY:

liter = db * size / 1000

All totals = SUM()

4. CONFIG SYSTEM (DYNAMIC)

All dimensions must be configurable:

product_groups
subtypes
packaging_types
returnable_types
sizes
flow_types
dataset_types
4.1 CONFIG STATES

Each config item:

pending
approved
disabled
4.2 AUTO-CREATION RULE

If unknown value appears (e.g. new size):

auto-create as:
status = pending
MUST NOT be used in final data until approved
5. DATA LAYERS
5.1 DRAFT TABLE

entries_draft:

editable
autosaved
not final
5.2 FINAL TABLE

entries_final:

approved only
read-only
source of truth
6. WORKFLOW
STATES:

draft → submitted → approved → locked → published

RULES:
draft
editable by user
submitted
locked for user
visible to reviewer
approved
moved to final table
immutable
locked
no changes allowed
published
public visible
7. AUTOSAVE (CRITICAL)
MUST HAVE:
debounce save (300–500ms)
UPSERT behavior
local backup (browser)
FLOW:

input → local state → debounce → API → DB → confirm

FAILURE HANDLING:
retry on fail
keep local copy
restore on reload
8. VALIDATION RULES
INPUT VALIDATION
value ≥ 0
integer only
no nulls
valid config references
BUSINESS VALIDATION
no duplicate entries
all required dimensions present
ANOMALY CHECK

Compare with previous period:

IF difference > 30%:
→ flag warning

9. CALCULATION ENGINE
RESPONSIBILITIES:
convert db → liter
aggregate:
by size
by subtype
by product group
by flow
RULE:

ALL calculations happen in backend ONLY

10. FRONTEND RULES (VUE)
10.1 ROLE OF VUE

Vue is responsible for:

rendering grid
managing state
autosave handling
UI feedback
10.2 NOT ALLOWED IN FRONTEND
no business logic
no calculations
no totals stored
10.3 GRID RULES
Excel-like layout
dynamic from config
editable cells = leaf nodes only
totals = read-only
10.4 STATE MODEL

Must track:

current values
unsaved changes
saving state
validation errors
11. API RULES
REQUIRED ENDPOINTS:

GET /config
GET /entries (draft + final)
POST /entries/save (bulk UPSERT)
POST /entries/submit
POST /entries/approve
GET /aggregations

RULES:
all endpoints secured
role-based access
idempotent operations
12. APPROVAL SYSTEM
REVIEW INTERFACE MUST:
show full dataset
show differences vs previous period
allow:
approve
reject
edit draft
13. AUDIT LOG

Every change must log:

user_id
timestamp
old value
new value
14. PERFORMANCE RULES
use indexes on all dimension keys
batch operations (no per-cell API spam)
cache aggregations per period
15. SECURITY RULES
reuse existing middleware
sanitize all input
validate server-side
prevent SQL injection
16. UI REQUIREMENTS
fast input (Excel-like)
visible save status
error feedback
restore drafts
tab-based navigation per product group
17. EXTENSIBILITY

System must support WITHOUT CODE CHANGE:

new sizes
new product groups
new categories
18. FINAL PRINCIPLE

System must behave as:

→ deterministic data system
→ fully auditable
→ no hidden logic

Excel behavior must be replicated using:
→ structured data + rules ONLY


# REFERENCE SOURCE RULES

---

# R.1 EMAIL APP AS REFERENCE

The email app (email/) is used as a READ-ONLY reference for:

* JWT auth pattern (SessionService.php)
* Login flow (AuthController.php)
* Router pattern (Router.php, Request.php)
* 2FA implementation (TwoFactorController.php)
* Route definitions (routes.php)

Agent may READ these files, ADAPT the patterns, and create NEW files inside EXCEL/.
Agent must NEVER modify email app files.

---

# R.2 WHAT TO ADAPT

From the email app, adapt:

* Router + Request + Response classes
* SessionService (JWT create/validate)
* AuthController (login, logout, refresh, me)
* Password hashing (bcrypt)
* Middleware pattern (auth check on protected routes)
* 2FA flow (optional, Phase 2)

---

# R.3 WHAT NOT TO COPY

Do NOT bring over:

* IMAP/email-specific code
* Addon system
* Existing user database
* Any email app business logic

---

END OF SPEC