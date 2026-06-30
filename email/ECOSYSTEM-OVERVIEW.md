# MailFlow + DEVCON Panel - Complete Ecosystem

A fully integrated, self-hosted business communication and server management platform.

---

## Overview

This ecosystem consists of three major interconnected applications:

1. **MailFlow** (Email App) - A full-featured business webmail and productivity platform
2. **DEVCON Panel** (VPS Admin) - A secure, self-hosted server administration panel
3. **Fleet Manager** - A centralized server provisioning and fleet management platform

Together, these form a complete infrastructure solution from fleet-wide server provisioning, through individual server management, down to end-user communication.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          Fleet Manager                                       │
│                   (Centralized Fleet Management)                             │
│                                                                              │
│  - Server Provisioning        - Blueprint Templates                          │
│  - Package Deployment         - Config Management                            │
│  - Fleet Monitoring           - Agent Task Queue                             │
│  - Server Reports             - AI Diagnostics                               │
└───────────────┬──────────────────────────────────┬──────────────────────────┘
                │ SSH / Agent API                   │ Deploys packages
                │ (Provisioning, Health)            │ (Panel, Email, Agent)
                ▼                                   ▼
┌────────────────────────────────────────────────────────────────────┐
│                        Linux VPS Server(s)                         │
│                                                                    │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │              DEVCON Panel (VPS Admin Dashboard)              │  │
│  │              panel.devcon1.hu                                │  │
│  │                                                              │  │
│  │  - Server Management          - User Management              │  │
│  │  - Email Server Config        - VPN Management               │  │
│  │  - NAS Storage Config         - Cache Management             │  │
│  │  - Security (Firewall/Fail2ban/ModSec)                       │  │
│  │  - Backups                    - phpMyAdmin Access            │  │
│  │  - AI Helper                  - Agent Diagnostics            │  │
│  │  - DNS & Mail Migration       - Security Scanning            │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                    │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐             │
│  │ OpenLiteSpeed │  │   MariaDB    │  │   Postfix    │             │
│  │  Web Server   │  │   Database   │  │    SMTP      │             │
│  └──────────────┘  └──────────────┘  └──────────────┘             │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐             │
│  │   Dovecot    │  │   PowerDNS   │  │  Fail2ban    │             │
│  │    IMAP      │  │    DNS       │  │  Security    │             │
│  └──────────────┘  └──────────────┘  └──────────────┘             │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐             │
│  │  ModSecurity │  │  FirewallD   │  │   CPGuard    │             │
│  │     WAF      │  │   Firewall   │  │   Scanner    │             │
│  └──────────────┘  └──────────────┘  └──────────────┘             │
│  ┌──────────────┐  ┌──────────────┐                               │
│  │ Meilisearch  │  │    Redis     │                               │
│  │ Search (LMDB)│  │    Cache     │                               │
│  └──────────────┘  └──────────────┘                               │
│  ┌──────────────┐                                                  │
│  │ Fleet Agent  │  Heartbeat, health, task execution               │
│  └──────────────┘                                                  │
└────────────────────────────────────────────────────────────────────┘
                                           │
                            VPN Tunnel (OpenVPN)
                                           │
                                           ▼
                           ┌─────────────────────────────────────┐
                           │          Synology NAS               │
                           │     (Home/Office Storage)           │
                           │                                     │
                           │  - NFS Mount: /mnt/nas-drive        │
                           │  - FlowOne Drive Storage           │
                           │  - Encrypted Tunnel                 │
                           └─────────────────────────────────────┘

                                           │
                           ┌───────────────┴───────────────┐
                           ▼                               ▼
            ┌─────────────────────────┐     ┌─────────────────────────┐
            │      MailFlow Web       │     │    FlowOneDrive        │
            │   email.devcon1.hu      │     │  Desktop Sync Client    │
            │                         │     │                         │
            │  - Webmail              │     │  - Two-way sync         │
            │  - Team Chat & Calls    │     │  - System tray app      │
            │  - Drive Storage        │     │  - Conflict resolution  │
            │  - Calendar             │     │  - Shared notifications │
            │  - Boards + Board Pro   │     │                         │
            │  - Mood Boards          │     │                         │
            │  - Clients CRM          │     │                         │
            │  - Email Campaigns      │     │                         │
            │  - Universal Search     │     │                         │
            │  - Colleagues           │     │                         │
            │  - Onboarding           │     │                         │
            └─────────────────────────┘     └─────────────────────────┘
```

---

## DEVCON Panel (VPS Admin)

### Core Philosophy

- **Security First**: No shell exposure, no arbitrary command execution
- **Agent + UI Separation**: Privileged agent runs locally, UI communicates via secure API
- **Action-Based API**: Named, allowlisted tasks - never raw commands
- **Full Auditability**: Every action creates backups, diffs, and logs

### Server Stack Management

| Service | Features |
|---------|----------|
| **OpenLiteSpeed** | Virtual hosts, SSL, listeners, rewrites, caching |
| **MariaDB/MySQL** | Databases, users, permissions, backups |
| **Postfix** | SMTP, mail queue, domain management, DKIM/SPF/DMARC |
| **Dovecot** | IMAP, mailboxes, connections, quotas |
| **PowerDNS** | DNS zones, records, DNSSEC |
| **Fail2ban** | Jails, bans, custom rules |
| **FirewallD** | Zones, ports, services, rich rules |
| **ModSecurity** | WAF modes, rule management, audit logs |
| **CPGuard** | Malware scanning, WAF integration |

### Site Management

- **Virtual Hosts**: Full CRUD with config editor
- **SSL Certificates**: Auto-issue via Let's Encrypt, preflight checks, expiry monitoring
- **Databases**: Per-site linking, size tracking, auto-link detection
- **SSH Keys**: Per-site key management
- **Site Validation**: Detect and fix common issues

### Backup System

| Backup Type | Features |
|-------------|----------|
| **Site Backups** | Files + Database combined |
| **Config Backups** | All server configs with selective restore |
| **Email Backups** | Per-domain mail archiving |
| **Database Backups** | Individual DB dumps |
| **NAS Remote** | Push backups to NAS via VPN |
| **Scheduled** | Cron-based automated backups |
| **Inspection** | Preview backup contents before restore |

### Security Features

- **Two-Factor Authentication** (TOTP)
- **Session Management** (revoke sessions)
- **Role-Based Access** (super_admin, admin, user)
- **IP Banning** via Fail2ban integration
- **Firewall Management** with rich rules
- **ModSecurity WAF** with rule customization

### AI Helper

- GPT-5 powered server diagnostics
- Conversation-based troubleshooting
- Config file analysis
- Log analysis
- Issue caching and tracking
- Dry-run command preview

### NAS Storage Management

- NFS connections via VPN tunnel
- Per-domain storage overrides
- Centralized storage config API for Email App
- Connection health monitoring
- Storage statistics

### VPN Connection Management

| Feature | Description |
|---------|-------------|
| **OpenVPN Clients** | Create and manage OpenVPN client connections |
| **Start/Stop/Restart** | Control VPN tunnel state |
| **Connection Status** | Real-time status tracking (connected/disconnected/error) |
| **Config Files** | View and manage VPN configuration files |
| **Connection Logs** | View VPN connection logs |
| **NAS Connectivity** | Used for NAS storage connectivity over VPN |

### User Management

| Feature | Description |
|---------|-------------|
| **User CRUD** | Create, edit, delete admin users |
| **Role Management** | Roles: super_admin, admin, user |
| **Status Management** | Active/suspended user status |
| **Site Assignment** | Assign specific sites to regular users |
| **User Statistics** | User count, filtering, last login tracking |

### Cache Management

| Feature | Description |
|---------|-------------|
| **Redis Statistics** | Cache hit/miss ratios, memory usage |
| **Flush All** | Clear the entire Redis cache |
| **Selective Invalidation** | Invalidate by domain or type (sites, dns, mail, files, backups, db) |
| **Frontend Caching** | Client-side caching service with TTL management |

### phpMyAdmin Secure Access

| Feature | Description |
|---------|-------------|
| **Token-Based Access** | Generate time-limited (5-minute) HMAC-signed tokens |
| **Role-Based Access** | Database access scoped to user role |
| **One-Click Launch** | Open phpMyAdmin directly from dashboard or site detail |
| **Gate Validation** | Secure token validation middleware |

### Agent Health Monitor

| Feature | Description |
|---------|-------------|
| **Service Diagnostics** | Agent running status, PID, uptime, memory usage |
| **Socket & Token Checks** | Verify agent communication files |
| **PHP Extension Checks** | Validate required PHP extensions |
| **MySQL Connection Status** | Database connectivity check |
| **File Permissions** | Validate critical file permissions |
| **Security Status** | CPGuard, ModSecurity, Fail2ban status |
| **Handler Status** | Agent subsystem/handler health |
| **Recent Logs** | View agent logs and errors |
| **Auto-Refresh** | Automatic status polling |
| **Agent Restart** | Restart the agent service from UI |

### Security Dependency Scanning

| Feature | Description |
|---------|-------------|
| **Vulnerability Scanning** | Automated dependency vulnerability scanning |
| **Severity Tracking** | Critical, high, medium, low vulnerability classification |
| **Scan History** | Per-application scan history |
| **Cron Integration** | Automated scans via security-scan.sh |
| **Vulnerability Details** | Detailed vulnerability information and counts |

### DNS Migration System

| Feature | Description |
|---------|-------------|
| **Multi-Phase Migration** | Phases: not_started, syncing, dual_write, switched, completed |
| **CyberPanel Migration** | Migrate from CyberPanel PowerDNS tables to own tables |
| **Data Verification** | Integrity checks at each phase |
| **Instant Rollback** | Revert to previous phase at any time |
| **Config Generation** | Generate PowerDNS configuration templates |

### Mail Migration System

| Feature | Description |
|---------|-------------|
| **Multi-Phase Migration** | Same phased approach as DNS migration |
| **Domain/Account/Forward Migration** | Migrate all mail entities |
| **Dual-Write Mode** | Safe testing with writes to both old and new tables |
| **Instant Rollback** | Revert to previous phase at any time |
| **Config Generation** | Generate Postfix/Dovecot configuration templates |

### Additional Features

- **File Manager**: Web-based file browser with permissions
- **Docker Management**: Containers, images, compose
- **Cron Jobs**: Visual cron editor
- **WordPress Management**: Plugins, themes, users, security
- **IMAP Migration**: Transfer emails from external servers
- **Application Installer**: One-click WordPress install
- **Billing Management**: Subscriptions, payments, invoices
- **Client Management**: Multi-tenant client tracking

---

## Fleet Manager

A centralized server provisioning and fleet management platform for deploying, configuring, and monitoring multiple VPS servers running the DEVCON Panel and MailFlow stack.

### Core Philosophy

- **Centralized Control**: Manage all servers from a single dashboard
- **Blueprint-Based Provisioning**: Create server templates from existing servers
- **Idempotent Deployments**: Config deployments track file hashes, only deploying changes
- **Agent Architecture**: Lightweight agent on each server for health reporting and task execution
- **Package Versioning**: Versioned deployment packages for Panel, Email App, and Agent

### Server Provisioning

| Feature | Description |
|---------|-------------|
| **Automated Setup** | Full server provisioning via SSH (install packages, deploy configs, deploy apps, SSL) |
| **SSH/SFTP Connectivity** | Secure server access with key management |
| **Connection Testing** | Verify SSH connectivity before operations |
| **Agent Installation** | Deploy fleet agent to managed servers |
| **SSL Setup** | Configure SSL certificates during provisioning |
| **Multi-Step Progress** | Track provisioning progress through each step |

### Blueprint System

| Feature | Description |
|---------|-------------|
| **Server Snapshots** | Capture full server state as a snapshot |
| **Config Extraction** | Extract all configuration files from a running server |
| **Template Creation** | Convert extracted configs into reusable templates |
| **Variable Detection** | Auto-detect variables in config files (IPs, domains, paths) |
| **Package Definitions** | Define which packages each blueprint requires |
| **Blueprint CRUD** | Create, edit, delete, and manage blueprint templates |
| **Create from Snapshot** | Convert snapshots into reusable blueprints |

### Deployment System

| Feature | Description |
|---------|-------------|
| **Config Deployment** | Push configuration files to servers |
| **App Deployment** | Deploy Panel, Email, or Agent packages |
| **Deployment Preview** | Preview changes before deploying |
| **Config Diff** | Compare current vs. new configuration |
| **Batch Deployment** | Deploy to multiple servers at once |
| **Rollback** | Revert deployments to previous state |
| **Deployment Logs** | Full logs for every deployment |
| **Cancellation** | Cancel in-progress deployments |
| **Idempotency** | Config file hash tracking prevents unnecessary deploys |

### Package Management

| Feature | Description |
|---------|-------------|
| **Package Types** | Panel, Email App, and Agent packages |
| **Version Management** | Upload and track package versions |
| **Build from Source** | Build packages directly from source |
| **Set Latest** | Mark a version as the latest release |
| **Package Download** | Download packages for manual deployment |
| **Version History** | Full version history per package type |

### Fleet Agent

| Feature | Description |
|---------|-------------|
| **Heartbeat Reporting** | Regular health check-ins from each server |
| **Error Reporting** | Agents report errors and issues to Fleet Manager |
| **Task Execution** | Execute queued tasks on the server |
| **Task Progress** | Report task progress (start, progress, complete, fail) |
| **Config Retrieval** | Agents pull their configuration from Fleet Manager |
| **Token Authentication** | Each agent authenticates with a unique X-Agent-Token |
| **Unix Socket Communication** | Local agent communicates via `/run/fleet-manager/agent.sock` |

### Server Monitoring

| Feature | Description |
|---------|-------------|
| **Health Snapshots** | Periodic health data collection per server |
| **Error Aggregation** | Centralized error tracking across all servers |
| **Issue Tracking** | Per-server issue detection and listing |
| **Server Reports** | Generate detailed server health reports |
| **Service Health** | Track individual service status (web, db, mail, etc.) |
| **Redis/Meilisearch Monitoring** | Optional service health tracking |
| **LiveKit Integration** | Video/audio service status tracking |

### AI Helper

| Feature | Description |
|---------|-------------|
| **Config Analysis** | AI-powered analysis of server configuration files |
| **Log Analysis** | AI-powered analysis of server logs |
| **Conversation-Based** | Multi-turn troubleshooting conversations |
| **Issue Caching** | Cache identified issues for quick reference |
| **OpenAI Integration** | GPT-powered diagnostics |

### Security

| Feature | Description |
|---------|-------------|
| **JWT Authentication** | Token-based auth with refresh tokens |
| **Two-Factor Authentication** | TOTP-based 2FA |
| **Trusted Devices** | Skip 2FA for trusted devices |
| **Session Management** | View and revoke active sessions |
| **Encrypted Credentials** | AES-256-GCM encryption for stored credentials |
| **SSH Key Management** | Secure SSH key storage |
| **Rate Limiting** | Login attempt throttling |
| **Audit Logging** | Full audit trail of all actions |

### Dashboard

| Feature | Description |
|---------|-------------|
| **Server Overview** | Total servers, health status, recent deployments |
| **Server Statistics** | Aggregate stats across the fleet |
| **Recent Activity** | Latest deployments and events |
| **Quick Actions** | One-click access to common operations |

### System Administration

| Feature | Description |
|---------|-------------|
| **Database Migrations** | Managed schema migrations with up/down support |
| **Self-Check** | System health verification |
| **Bootstrap** | Initial system setup wizard |
| **Snapshot Management** | Create, view, delete system snapshots |

### Fleet Manager API Endpoints

**Authentication**
- `POST /api/auth/login` - Login
- `POST /api/auth/refresh` - Refresh token
- `POST /api/auth/2fa/verify` - Verify 2FA
- `GET /api/auth/me` - Current user
- `POST /api/auth/logout` - Logout
- `POST /api/auth/password` - Change password

**Servers**
- `GET /api/servers` - List servers
- `GET /api/servers/stats` - Server statistics
- `GET /api/servers/{id}` - Server details
- `POST /api/servers` - Create server
- `PUT /api/servers/{id}` - Update server
- `DELETE /api/servers/{id}` - Delete server
- `POST /api/servers/{id}/test-connection` - Test SSH
- `POST /api/servers/{id}/regenerate-token` - Regenerate agent token
- `GET /api/servers/{id}/tasks` - List tasks
- `POST /api/servers/{id}/tasks` - Create task
- `GET /api/servers/{id}/credentials` - Get credentials
- `GET /api/servers/{id}/audit` - Audit log
- `GET /api/servers/{id}/reports` - List reports
- `POST /api/servers/{id}/reports/generate` - Generate report
- `GET /api/servers/{id}/issues` - List issues

**Blueprints**
- `GET /api/blueprints` - List blueprints
- `GET /api/blueprints/{id}` - Blueprint details
- `POST /api/blueprints` - Create blueprint
- `PUT /api/blueprints/{id}` - Update blueprint
- `DELETE /api/blueprints/{id}` - Delete blueprint
- `POST /api/blueprints/extract` - Extract from server
- `POST /api/blueprints/detect-variables` - Detect variables
- `GET /api/blueprints/{id}/templates/{templateId}` - Get template
- `POST /api/blueprints/{id}/templates` - Save template
- `GET /api/blueprints/{id}/packages` - Get packages
- `POST /api/blueprints/{id}/packages` - Save packages

**Deployments**
- `GET /api/deployments` - List deployments
- `GET /api/deployments/{id}` - Deployment details
- `GET /api/deployments/{id}/logs` - Deployment logs
- `POST /api/deployments` - Create deployment
- `POST /api/deployments/batch` - Batch deployment
- `POST /api/deployments/preview` - Preview deployment
- `POST /api/deployments/diff` - Compare configs
- `POST /api/deployments/{id}/cancel` - Cancel deployment
- `POST /api/deployments/{id}/rollback` - Rollback

**Packages**
- `GET /api/packages` - List all packages
- `GET /api/packages/{type}` - List versions
- `POST /api/packages/{type}/upload` - Upload package
- `POST /api/packages/{type}/build` - Build package
- `GET /api/packages/{type}/{version}` - Package details
- `POST /api/packages/{type}/{version}/set-latest` - Set as latest
- `DELETE /api/packages/{type}/{version}` - Delete package
- `GET /api/packages/{type}/{version}/download` - Download

**Agent (X-Agent-Token auth)**
- `POST /api/agent/heartbeat` - Heartbeat
- `POST /api/agent/errors` - Report errors
- `POST /api/agent/progress` - Report progress
- `GET /api/agent/config` - Get config
- `POST /api/agent/task/{id}/start` - Task started
- `POST /api/agent/task/{id}/progress` - Task progress
- `POST /api/agent/task/{id}/complete` - Task complete
- `POST /api/agent/task/{id}/fail` - Task failed

**AI Helper**
- `GET /api/ai-helper/settings` - Get settings
- `PUT /api/ai-helper/settings` - Update settings
- `GET /api/ai-helper/conversations` - List conversations
- `POST /api/ai-helper/conversations` - Create conversation
- `POST /api/ai-helper/conversations/{id}/messages` - Send message
- `POST /api/ai-helper/analyze-logs` - Analyze logs
- `POST /api/ai-helper/analyze-config` - Analyze config

**System**
- `GET /api/system/health` - Health check
- `GET /api/system/migrations` - List migrations
- `POST /api/system/migrations/run` - Run migrations
- `GET /api/system/self-check` - Self-check
- `POST /api/system/bootstrap` - Bootstrap system
- `GET /api/system/snapshots` - List snapshots
- `POST /api/system/snapshots` - Take snapshot
- `GET /api/system/snapshots/{id}` - Get snapshot
- `DELETE /api/system/snapshots/{id}` - Delete snapshot
- `POST /api/system/snapshots/{id}/create-blueprint` - Create blueprint from snapshot

### Fleet Manager Database Schema

- `admin_users` - Admin users (username, password_hash, totp_secret, role)
- `sessions` - User sessions
- `login_attempts` - Rate limiting
- `servers` - Managed servers (IP, SSH credentials, domains, blueprint, agent_token, status)
- `server_credentials` - Encrypted server credentials (AES-256-GCM)
- `blueprints` - Server blueprint templates
- `blueprint_templates` - Config file templates within blueprints
- `blueprint_packages` - Package definitions within blueprints
- `deployments` - Deployment history and status
- `server_health` - Health check snapshots
- `server_errors` - Aggregated error records
- `server_packages` - Installed package version tracking
- `server_configs` - Config file hashes (idempotency tracking)
- `config_backups` - Configuration backup storage
- `agent_tasks` - Task queue for agents
- `agent_task_logs` - Task execution logs
- `packages` - Deployment packages (panel, email, agent)
- `audit_logs` - Full audit trail
- `settings` - System settings
- `ai_conversations` - AI helper conversations
- `ai_messages` - AI helper messages
- `ai_helper_settings` - AI configuration
- `ai_cached_issues` - Cached AI-identified issues

---

## MailFlow (Email App)

### Email Core

| Feature | Description |
|---------|-------------|
| **Full IMAP Support** | Connect to Postfix/Dovecot or any IMAP server |
| **Conversation Threading** | Smart thread grouping with DB-backed indexing |
| **Multi-Account** | Multiple email accounts in one interface |
| **Email Tracking** | Open/read notifications with tracking pixels |
| **Reactions** | Outlook-style emoji reactions on emails |
| **Server-Side Filters** | Sieve filter support with visual editor |
| **Spam Management** | Block/safe senders, spam reporting, SpamAssassin integration |
| **Labels & Folders** | Custom labels with colors, folder management |
| **Bulk Actions** | Mass move, delete, label operations |
| **Rich Text Compose** | Full HTML editor with inline images |
| **Unsubscribe Detection** | One-click unsubscribe from newsletters |

### Email Architecture (IMAP Single Source of Truth)

The mail engine follows a strict **IMAP-single-source-of-truth** model: live IMAP is authoritative for everything volatile — which messages exist, `\Seen`/read-state, and unread counts. The MariaDB mirror and Redis are **caches only** and are never served as truth, so a read message can never flip back to unread and a folder badge can never "jump". There is no mirror-as-truth rollback path. (Reference: `email/backend/docs/sync-architecture.md`.)

#### Read Path (live IMAP, cache-warming on the side)

| Endpoint / Method | Behavior |
|-------------------|----------|
| `MailboxController::messages()` | Always lists from live IMAP (`ImapService::getMessages`). Best-effort warms the DB cache (`ConversationService::assignMessagesToConversations`) inside a try/catch, so a DB failure never breaks a read. Returns live `folderStatus` (uidvalidity / uidnext / total / unread) on every response. |
| `MailboxController::messagesSince()` | Incremental list from live IMAP (`getMessagesSince`), warms the cache, applies the in-flight read-state overlay. |
| `MailboxController::delta()` | Live `getFolderStatus` + new UIDs + CONDSTORE `CHANGEDSINCE` flag changes (`fetchFlagChangesSince`), filtered against in-flight outbox ops. **No DB delta path exists** — serving counts/flags from the mirror is exactly what previously caused spurious unread jumps. |
| `AccountController::getUnreadCounts()` | Primary INBOX unread comes from a live IMAP `STATUS UNSEEN`; the Redis `UnreadCountCache` is used only while fresh; the DB count is a degraded fallback (OAuth-only primary or IMAP unreachable). |

**In-flight read-state overlay.** Because user writes (e.g. mark-read) reach IMAP asynchronously (see the write path below), every listed message's seen flag is reconciled against the local cache and any pending writes, so a just-read message cannot momentarily un-read itself. The merge rule is a single, side-effect-free pure function (`MailboxController::reconcileSeen`, unit-testable in isolation):

```php
public static function reconcileSeen(bool $imapSeen, bool $dbSeen, bool $pending): bool
{
    return $pending ? $dbSeen : ($imapSeen || $dbSeen);
}
```

- A **pending** local write wins outright (`$dbSeen`) — it is the user's latest, not-yet-drained intent.
- Otherwise a message is read if **either** source says so (`$imapSeen || $dbSeen`) — covering "read on another device" (IMAP only) and "read in-app, not yet drained" (DB only) without resurrecting genuinely-unread mail.

#### The Mirror (Cache) Layer

| Table | Role |
|-------|------|
| `webmail_conversation_members` | Per-message envelope cache (subject / from / date / snippet, `conversation_id`, stable `folder_id`, `uid`). `is_seen` is a **cache column only — never served as read-state.** |
| `webmail_conversations` | Cached conversation/thread rollups for the conversation-list panel (never the source for badge counts). |
| `webmail_folder_sync_state` | Sync bookkeeping: `status`, `uidvalidity`, watermarks (`highest_uid`, `highest_modseq`), `message_count`, backoff (`attempts`, `next_attempt_at`). |
| `webmail_folder_tombstones` | Expunge tombstones that feed `/delta`'s `deletedUids` between IDLE and polling passes. |
| `webmail_folder_identity` | Stable UUIDv7 `folder_id` per (user, folder path) so IMAP renames don't orphan cached rows. |
| `universal_search_index` | Dual-written content index (MySQL FULLTEXT fallback + Meilisearch). |
| `imap_outbox` | **The one piece of operational (non-cache) state** — the durable write queue (below). |

Because the mirror is pure cache, it can be wiped and rebuilt at any time with no data loss.

#### Background Sync Engine (Cache Warmer) — `cron/sync-mailbox.php` + `MailboxSyncService`

Runs every 5 minutes (self-loops ~240 s, `flock`-guarded). It is purely a **cache warmer + new-mail notifier** — it does **not** reconcile read-state or counts. The reconcilers that used to compete for truth were deleted in the IMAP-truth cutover (`reconcileReadState`, `reconcileFolderHealth`, and the entire `MailboxMirrorReadService` read path).

Per-folder state machine (`webmail_folder_sync_state.status`), claimed in priority order `uidvalidity_reset > pending > initial_syncing > failed > synced`:

| Phase | Purpose |
|-------|---------|
| **Initial sync** | Pages envelopes (`INITIAL_BATCH_SIZE = 200`) above `highest_uid`; marks `synced` only when `highest_uid + 1 >= UIDNEXT` (UIDNEXT is the authoritative end-of-folder signal — the old short-batch heuristic caused partial mirrors). |
| **Incremental sync** | CONDSTORE flag-delta (`fetchFlagChangesSince`) + new-mail paging; advances `highest_modseq` / `highest_uid`; fans out real-time `MESSAGE_NEW` + `FOLDER_COUNTS` for new mail only. |
| **Expunge reconcile** | Throttled (`EXPUNGE_INTERVAL_SECONDS = 1800`, 30 min/folder); diffs the live UID set vs. cache, bulk-deletes stale rows, records tombstones. |
| **UIDVALIDITY reset** | On UIDVALIDITY change, tombstones + wipes the folder's mirror rows and restarts it at `pending`. |

It also drains the IDLE expunge queue (`webmail:flowone:idle:tombstones`) that the Node worker fills (see Real-time Layer).

#### Durable Write Path (DB-first Outbox) — `cron/drain-outbox.php` + `OutboxService`

User actions that mutate IMAP state (mark-read, move, delete, rename) are committed to MariaDB **and** an `imap_outbox` row in a **single transaction** at request time — so the UI is instant and the intent is durable — then pushed to IMAP asynchronously.

| Aspect | Detail |
|--------|--------|
| Ops | `set_flag`, `clear_flag`, `move`, `delete`, `rename_folder` |
| Idempotency | SHA-256 `idempotency_key` unique index; safe to retry |
| Drainer | `drain-outbox.php` (every minute, self-loops ~55 s) groups rows by account → one IMAP connection per account, executes each op, then publishes the **confirmed** real-time event |
| Reliability | Exponential backoff (`1, 5, 30, 300, 1800, 3600…` s), `MAX_ATTEMPTS = 8` → `dead` (surfaced in the UI "sync issues" banner), stuck-row reaper (300 s) |
| Post-move | Backfills the new UID into `webmail_conversation_members` |

Writes run in PHP (not the Node worker) because only PHP holds the OAuth-refresh and session-password decryption keys.

#### Real-time Layer (Node WebSocket Mailsync Worker)

A Node.js worker (`email/mailsync/server/`, port 1235; `ws` + `ioredis` + `imapflow`) delivers sub-second UI updates:

- **IMAP IDLE** (`ImapIdleManager`, QRESYNC): emits `MESSAGE_NEW`, `MESSAGE_DELETED`, `FLAGS_CHANGED`, `FOLDER_COUNTS` per active user/folder. Expunges are also `LPUSH`ed to the Redis list `webmail:flowone:idle:tombstones`, drained by the PHP sync cron — the only cross-process bridge besides pub/sub.
- **Redis pub/sub** (`RedisPubSub` ← PHP `RedisCacheService::publish*`): backend state changes are published on `webmail:mailbox:{userEmail}` and fanned out over WebSocket (and as push notifications to offline users).

Mail event constants: `MESSAGE_NEW`, `MESSAGE_DELETED`, `MESSAGE_MOVED`, `FLAGS_CHANGED`, `FOLDER_COUNTS`, `FOLDER_CHANGED`, `CONVERSATION_UPDATED`, `PIN_CHANGED`, `LABELS_CHANGED`, `SETTINGS_CHANGED`. The frontend treats `FOLDER_COUNTS` as a "go re-read IMAP" trigger rather than a source of truth.

#### Credentials & Connection Resilience

- **`ImapCredentialResolver`** is the single credential authority for all background workers: OAuth bearer tokens (minted by `GoogleOAuthService` / `MicrosoftOAuthService` from AES-256-GCM-encrypted refresh tokens in `webmail_oauth_tokens`) or AES-decrypted session passwords (`webmail_sessions`) for local Dovecot accounts. Token crypto uses a versioned `OAuthCryptor` envelope (`v{N}:…`) with legacy AES-256-CBC read support.
- **`Database`** is a per-process PDO singleton. Long-running crons call **`Database::pingOrReconnect()`** at the top of every loop pass (and rebuild any services that cached the old handle) so a connection dropped mid-pass cannot be mistaken for "no credentials". Both mail crons also raise their **session** `wait_timeout` / `interactive_timeout` to 900 s to survive long IMAP gaps under the server's aggressive 120 s global idle timeout.

### Drive (Cloud Storage)

| Feature | Description |
|---------|-------------|
| **File Storage** | Upload, download, organize files |
| **Folder Sharing** | Share with collaborators (view/edit) |
| **Public Links** | Password-protected share links |
| **File Versioning** | Track and restore previous versions |
| **NAS Integration** | Storage on home NAS via VPN |
| **Trash & Restore** | Soft delete with recovery |
| **ZIP Archives** | Create/download archives, large file splitting |
| **Thumbnails** | Auto-generated previews |

#### Storage Tiering & NAS Lifecycle

Drive files move between **VPS local disk (hot)** and **NAS-over-VPN (cold)** under a phased lifecycle managed by the shared `FlowOne\Storage` library and an external `flowone-storage-monitord` daemon. The destructive step (deleting the VPS copy) is always separated from the move by a grace window, with checksum re-verification before any unlink.

| Tier state (`drive_files.tier_state`) | Meaning |
|---------------------------------------|---------|
| `hot` | Bytes live on VPS local disk |
| `tiering` | In-flight VPS → NAS transfer (rolls back to `hot` on error) |
| `cold` | Canonical bytes on NAS; the VPS shadow may persist until the sweep |
| `recalling` | In-flight NAS → VPS recall (triggered when a user downloads a cold file) |

| Component (`FlowOne\Storage`) | Role |
|-------------------------------|------|
| `TierStateService` | DB state machine over `drive_files` + `drive_tier_transitions`; LRU candidate selection by `last_read_at` |
| `TierBytesMover` | Streams hot → NAS with checksum verification |
| `TierRecallService` | Streams NAS → VPS on demand (idempotent, `MountLock`-guarded) |
| `TierDestructiveSweeper` | Unlinks VPS shadows of cold files past the grace window, re-verifying the NAS checksum first |
| `StorageHealth` / `HealthState` | Reads the daemon's HMAC-signed health JSON (`HEALTHY` / `DEGRADED` / `UNAVAILABLE`) |
| `OperationJournal` / `HmacSigner` | Append-only, HMAC-signed audit log of storage operations |
| `MountLock` | Per-file lock shared by tier-down, recall, and sweep to prevent races |
| `NasHealthCheck` | Health facade with a Redis kill-switch (`flowone:storage:nas:force_offline`) and a `storage-ctl freeze` ops command |

Lifecycle crons: `drive-tier-backfill.php` (reconcile `tier_state`), `drive-tier-down.php` (copy hot→NAS, then sweep VPS shadows past grace), `drive-recall-warm.php` (on-demand recall, spawned by `DriveService::triggerBackgroundRecall()`), `drive-pending-nas-migrate.php` (replays uploads that fell back to VPS during a NAS outage, via `drive_pending_nas_migration`), and `cleanup-drive.php` (purge expired email attachments). Storage config (driver, NFS mount point, per-domain overrides) is fetched from the Panel API and cached in Redis (~5 min TTL). Every `StorageService` operation checks NAS health first and degrades gracefully when the NAS is offline.

### FlowOneDrive (Desktop Client)

- **Electron-based** desktop sync application
- **Two-way sync** with cloud
- **System tray** background operation
- **Conflict resolution** for simultaneous edits
- **Shared folder notifications**
- **Selective sync** (coming soon)

### Collaborative Editing

| Feature | Description |
|---------|-------------|
| **Documents** | Google Docs-style real-time editing |
| **Presentations** | Slide editor with themes |
| **Y.js CRDT** | Conflict-free real-time collaboration |
| **WebSocket Server** | Node.js presence/awareness server |
| **Version History** | Named snapshots with compare |
| **Comments** | Inline commenting with resolution |
| **Sharing** | Owner/Editor/Viewer permissions |

### Calendar

| Feature | Description |
|---------|-------------|
| **Multiple Calendars** | Color-coded calendars |
| **Event Management** | Create, edit, recurring events |
| **Google Calendar Sync** | Two-way sync with Google |
| **Microsoft Calendar Sync** | Outlook/Office 365 sync |
| **ICS Subscriptions** | Public calendar feeds |
| **Event Invitations** | Send/accept/decline invites |
| **Quick Add** | Natural language event creation |

### Boards (Project Management)

| Feature | Description |
|---------|-------------|
| **Trello-Style Boards** | Kanban boards with lists and cards |
| **Cards** | Title, description, due dates, checklists |
| **Labels** | Color-coded labels per board |
| **Attachments** | Files, URLs, Drive integration |
| **Comments** | Card discussions |
| **Member Assignment** | Assign cards to team members |
| **Email Linking** | Link emails to boards/cards |
| **Progress Reports** | Auto-generated project updates |
| **Milestones** | Financial tracking per milestone |
| **Activity Log** | Full audit trail |

### Board Pro (Addon - Enhanced Board Features)

An advanced project management addon that extends Boards with financial tracking, automation, AI features, and multi-lens analytics. Gated by the Board Pro addon toggle.

| Feature | Description |
|---------|-------------|
| **Email Auto-Link Rules** | Automatically link emails to boards by subject, sender, or domain matching |
| **Card Financials** | Track revenue, cost, margin, and time budget per card |
| **Card Timeline View** | Chronological timeline view of card activity |
| **Multi-Lens Views** | View boards through revenue, time, or client lenses |
| **MoodBoard-Card Linking** | Two-way linking between mood board items and board cards |
| **Advanced Card Permissions** | Visibility controls, stage locks, portal visibility settings |
| **Member Stage Permissions** | Stage-level permission control per board member |
| **AI Summarize** | AI-generated board and card summaries |
| **AI Risk Report** | AI-powered project risk analysis |
| **AI Estimation** | AI-assisted time and effort estimates |
| **AI Draft Updates** | AI-generated project update drafts |
| **Executive Reports** | Auto-generated executive-level project reports |
| **Board Analytics** | Advanced analytics and reporting per board |

#### Board Pro Automations

A per-board automation engine with event-based and time-based triggers, cross-module actions (CRM, Calendar, Chat), circuit breaker protection, and full execution logging.

**Trigger Types**

| Trigger | Description |
|---------|-------------|
| **card_moved_to_list** | Card is moved to a specific list |
| **card_completed** | Card is marked as completed |
| **card_overdue** | Card passes its due date |
| **card_idle_days** | Card has no activity for N days |
| **card_created** | New card is created (optionally in specific list) |
| **list_all_completed** | All cards in a list are completed |
| **email_received_on_card** | An email is linked to a card |
| **checklist_completed** | A card's checklist is fully completed |
| **label_added** | A specific label is added to a card |

**Action Types**

| Action | Description |
|--------|-------------|
| **move_card** | Move card to another list |
| **assign_member** | Assign a team member to the card |
| **add_label** | Add a label to the card |
| **create_invoice_draft** | Create a CRM invoice draft from card financials |
| **send_notification** | Send real-time notification via Redis pub/sub |
| **send_email** | Send email to specified recipients |
| **update_deal_stage** | Update a linked CRM deal's pipeline stage |
| **start_crm_sequence** | Enroll client in a CRM email drip sequence |
| **create_calendar_event** | Create a calendar event |
| **post_chat_message** | Post a message to a chat channel |

**Engine Features**

| Feature | Description |
|---------|-------------|
| **Circuit Breaker** | Max chain depth of 3 prevents automation loops |
| **Time-Based Evaluation** | Cron job evaluates overdue and idle card triggers |
| **24-Hour Deduplication** | Prevents re-firing the same rule on the same card within 24 hours |
| **Rule Templates** | Pre-built board automation templates organized by category (see template categories below) |
| **Automation Guide** | Interactive 7-step guide explaining board automation setup with triggers, actions, and examples |
| **Per-Rule Log** | Execution log per individual rule |
| **Board-Level Log** | Aggregated execution log across all rules on a board |
| **Run Statistics** | Track last run time and total run count per rule |
| **Message Interpolation** | Variables in messages: {{card_title}}, {{assigned_to}}, {{list_name}}, etc. |
| **Cron-Based Processing** | Background processing for time-based triggers |

**Board Automation Template Categories**

Pre-built one-click templates to help users get started with board automations:

| Category | Example Templates |
|----------|-------------------|
| **Notifications** | Alert on Overdue Cards, Idle Card Reminder, New Email on Card |
| **Workflow** | Archive Completed Cards, Auto-Assign New Cards, Move on Label Change |
| **Billing** | Invoice on Milestone Done, Invoice on Card Completion, Update Deal on Card Move |
| **Communication** | Chat Alert on Overdue, Celebrate Completions |

### Mood Boards (Visual Canvas)

A full-featured infinite-canvas design tool for visual planning, presentations, and creative collaboration.

#### Canvas Item Types

| Type | Description |
|------|-------------|
| **Sticky Notes** | Color-coded notes with rich text, pin effect |
| **Text Blocks** | Headings, body text with font/size/color |
| **Images** | From upload, Drive, or URL with crop/resize |
| **Image Sets** | Multi-image galleries with carousel |
| **Color Swatches** | Color palette cards with HEX/RGB values |
| **Drawings** | Freehand drawing and pen tool shapes |
| **Shapes** | Rectangles, circles, arrows, custom pen shapes |
| **Todo Lists** | Interactive checklists on canvas |
| **Links** | URL bookmarks with preview |
| **Files** | Attached files from uploads or Drive |
| **Tables** | Data tables with rows and columns |
| **Folders** | Organizational grouping for canvas items |
| **Frames** | Camera viewports for presentation slides |
| **Board Links** | Embedded Kanban board references |
| **Calendar Events** | Embedded calendar event cards |
| **Videos** | Video files and YouTube embeds |

#### Core Features

| Feature | Description |
|---------|-------------|
| **Infinite Canvas** | Pan, zoom, scroll with no boundaries |
| **Connections** | Lines between items (solid/dashed/dotted, arrows, labels) |
| **Layers Panel** | Z-index management, lock/unlock items |
| **Alignment Tools** | Snap-to-grid, align left/center/right, distribute |
| **Color Palette** | Board-level saved color palette |
| **Background Effects** | Gradient overlay, vignette, film grain, blur |
| **Batch Operations** | Multi-select, group move/resize/delete |
| **Drag & Drop** | Drop images, files, Drive content onto canvas |
| **Component Library** | Save and reuse item groups as reusable components |
| **Board Templates** | Create boards from templates |
| **Duplicate Board** | Clone entire board with all items |
| **Archive** | Archive boards without deleting |

#### Presentation Mode

| Feature | Description |
|---------|-------------|
| **Frame-Based Slides** | Frames define camera viewports as slides |
| **Filmstrip Navigation** | Slide thumbnail strip for quick jumping |
| **Slide Transitions** | Fly, fade, zoom transitions between frames |
| **Presenter Notes** | Per-frame speaker notes |
| **Full-Screen Presenter** | Dedicated presentation overlay with HUD auto-hide |
| **Scroll-Driven Storytelling** | Items reveal as user scrolls through the board |
| **Background Toggle** | Show/hide grid and background during presentation |

#### Ambient Motion System

| Feature | Description |
|---------|-------------|
| **Card Wobble/Float** | Subtle animation on sticky notes and cards |
| **Element Float** | Gentle motion on images, swatches, shapes |
| **Line Wave** | Ripple/wave animation on connection lines |
| **Draw-On Reveal** | Animated drawing reveal for drawings, text, lines |
| **Intensity Controls** | Per-category intensity and speed sliders |
| **Viewport Trigger** | Animate when scrolled into view or replay on load |

#### Collaboration

| Feature | Description |
|---------|-------------|
| **Members** | Invite colleagues as Viewer, Editor, or Admin |
| **Group Access** | Grant access to entire colleague groups |
| **Real-Time Updates** | Item changes broadcast via WebSocket/Redis |
| **Follow Collaborator** | Follow another user's cursor or viewport |
| **Focus Mode** | Highlight one item, dim everything else |

#### Public Sharing

| Feature | Description |
|---------|-------------|
| **Share Links** | Generate public view or edit links |
| **Password Protection** | Optional password on share links |
| **Link Expiry** | Time-limited share links |
| **View Analytics** | Track views, duration, slides viewed, referrers |
| **Public File Serving** | Uploaded files served via unguessable URLs |

#### Integrations

| Feature | Description |
|---------|-------------|
| **Drive Picker** | Import files/images from MailFlow Drive |
| **Calendar Picker** | Embed calendar events on canvas |
| **Board Picker** | Link to Kanban boards and cards |
| **Client Linking** | Associate mood boards with CRM clients |
| **Kanban Board Linking** | Two-way linking between mood boards and project boards |

### Clients (CRM Base)

| Feature | Description |
|---------|-------------|
| **Contact Management** | Auto-discovered from emails, manual creation |
| **Multi-Contact Clients** | Multiple contacts per client (email, phone, position) |
| **Email History** | All email conversations with client |
| **Time Tracking** | Track time spent per client with breakdown by activity |
| **Linked Boards** | Associate Kanban project boards with clients |
| **Linked Mood Boards** | Associate visual mood boards with clients |
| **Drive Folders** | Client-specific file storage, auto-sync from boards |
| **Mind Maps** | Visual relationship mapping across email contacts |
| **Financial Tracking** | Client revenue and expense tracking |
| **Activity Log** | Complete client activity timeline |
| **Team Members** | Multi-user client sharing with role-based access (owner/admin/member) |
| **Signature Extraction** | AI-powered auto-extract of contact details from email signatures |
| **Client Health Score** | 0-100 score based on last interaction recency |
| **Status Auto-Calculation** | Auto-classify clients as active/idle/at-risk/lost |
| **Associated Accounts** | Link subsidiary companies to parent client |
| **Client Merging** | Merge duplicate clients with data consolidation |
| **Domain Aliases** | Multiple domain aliases per client |
| **Import/Export** | CSV/Excel import and export of client data |
| **Client Overview Dashboard** | Comprehensive snapshot with all linked data |

### CRM Pro (Addon - Pipeline & Deal Management)

A full sales pipeline and deal management system, gated by the CRM Pro addon toggle. Includes deals, invoices, automation, reporting, and client portal.

#### Deal Pipeline

| Feature | Description |
|---------|-------------|
| **Kanban Pipeline** | Visual drag-and-drop pipeline board |
| **Pipeline Stages** | Lead, Qualified, Proposal, Negotiation, Won, Lost |
| **Deal Management** | Create, edit, delete deals with full lifecycle tracking |
| **Deal Cards** | Rich deal cards showing value, client, probability, expected close |
| **Stage Transitions** | Drag deals between stages with automatic history recording |
| **Deal Activity Feed** | Per-deal activity log showing all changes and interactions |
| **Stage History** | Full audit trail of every stage transition with timestamps |
| **Lost Reason Tracking** | Record why deals were lost for analysis |
| **Deal Velocity Metrics** | Average time in each stage, conversion rates, win/loss ratios |
| **Pipeline Summary** | Total pipeline value, weighted forecast, stage breakdown |
| **Deal Filtering** | Filter by client, stage, assigned user, date range, search |
| **Monthly Forecast** | Revenue forecast by month based on deal probability |

#### CRM Invoicing

| Feature | Description |
|---------|-------------|
| **Invoice CRUD** | Create, edit, delete invoices linked to clients and deals |
| **Line Items** | Multiple line items with quantity, unit price, tax, discount |
| **Invoice Status** | Draft, Sent, Paid, Partially Paid, Overdue, Cancelled |
| **Payment Recording** | Record partial and full payments with date tracking |
| **PDF Generation** | Auto-generate professional PDF invoices |
| **Email Sending** | Send invoices to clients via email directly |
| **Billing Provider Integration** | Push invoices to Billingo or Szamlazz.hu |
| **External PDF Download** | Download official invoice PDFs from billing provider |
| **Status Sync** | Sync payment status from external billing platform |
| **Invoice Blocks** | Pre-configured invoice block templates |
| **Multi-Currency** | Support for HUF, EUR, USD, RON |

#### CRM Expenses

| Feature | Description |
|---------|-------------|
| **Expense Tracking** | Record business expenses with categorization |
| **Client Linking** | Associate expenses with specific clients |
| **Category Management** | Organize expenses by category |
| **Date Filtering** | Filter expenses by date range |
| **Profitability Calculation** | Revenue minus expenses per client |

#### CRM Tags & Custom Fields

| Feature | Description |
|---------|-------------|
| **Tag System** | Create color-coded tags, assign to clients |
| **Client Tagging** | Multiple tags per client for segmentation |
| **Custom Field Definitions** | Define custom data fields (text, number, date, select, etc.) |
| **Per-Client Field Values** | Store custom data against each client |
| **Field Management** | Full CRUD for field definitions |

#### CRM Reminders

| Feature | Description |
|---------|-------------|
| **Reminder Creation** | Set reminders for follow-ups, calls, meetings |
| **Client & Deal Linking** | Associate reminders with clients and/or deals |
| **Completion Tracking** | Mark reminders as complete |
| **Date-Based** | Set specific reminder dates and times |
| **Reminder Management** | Full CRUD with filtering |

#### CRM Call Log & Meeting Notes

| Feature | Description |
|---------|-------------|
| **Call Logging** | Record calls with client, duration, outcome, notes |
| **Meeting Notes** | Rich meeting notes with attendees, agenda, action items |
| **Meeting Note Updates** | Edit and update existing meeting notes |
| **Client Linking** | All calls and meetings linked to client record |
| **Timeline Integration** | Call logs and meetings appear in client timeline |

#### CRM Unified Timeline

| Feature | Description |
|---------|-------------|
| **Aggregated Timeline** | Single chronological view of all client activity |
| **Event Types** | Emails, calls, meetings, invoices, deal changes, portal updates, automation actions |
| **Deal Stage History** | Stage transitions shown in timeline |
| **Automation Events** | Automated actions logged in timeline |
| **Paginated** | Efficient loading with limit/offset pagination |

#### CRM Dashboard & Reports

| Feature | Description |
|---------|-------------|
| **CRM Dashboard** | Overview with key metrics, pipeline summary, recent activity |
| **Revenue Report** | Revenue analysis by period (monthly/quarterly/yearly) |
| **Pipeline Report** | Deal distribution across stages, conversion funnel |
| **Client Health Report** | Health score distribution, at-risk client alerts |
| **Deal Activity Report** | Activity tracking across all deals |
| **Invoice Aging Report** | Outstanding invoice analysis by age brackets |
| **Client Ranking** | Top clients by revenue with configurable time periods |
| **Profitability Report** | Revenue vs expenses analysis per client |
| **Forecast Report** | Future revenue projection based on deal pipeline |
| **Conversion Funnel** | Visual funnel showing stage-to-stage conversion rates |

#### CRM Automation Rules

A comprehensive if-this-then-that automation engine with event-based and time-based triggers, cross-module actions, rule sharing, templates, and full audit logging.

**Trigger Types**

| Trigger | Description |
|---------|-------------|
| **deal_stage_changed** | Deal moves to a different pipeline stage |
| **deal_stage_idle** | Deal sits in a stage for X days without movement |
| **deal_won** | Deal is marked as won |
| **deal_lost** | Deal is marked as lost |
| **client_health_low** | Client health score drops below threshold |
| **invoice_overdue** | Invoice becomes overdue by X days |
| **no_contact_days** | No communication with client for X days |
| **task_changed** | Board task state changes |
| **board_closed** | Kanban board is closed |
| **moodboard_ready** | Mood board is marked as ready |
| **time_spent_reached** | Time tracking threshold reached for a client |
| **colleague_sick_status** | Colleague status changes (sick/unavailable) |
| **drive_folder_permission_changed** | Drive folder permissions are modified |
| **email_opened** | Tracked email is opened by recipient |
| **email_link_clicked** | Tracked link in email is clicked |

**Action Types**

| Action | Description |
|--------|-------------|
| **send_email** | Auto-send emails with HTML editor and merge tags ({client_name}, {deal_title}, etc.) |
| **create_reminder** | Auto-create follow-up reminders |
| **create_invoice_draft** | Auto-create draft invoices from deal/card data |
| **move_deal_stage** | Auto-move deals to different pipeline stages |
| **notify_user** | Send internal notifications to team members |
| **start_sequence** | Auto-enroll clients in email drip sequences |
| **assign_task** | Assign tasks to team members |
| **send_chat_message** | Post messages to chat conversations/channels |
| **reassign_deals** | Reassign deals to different team members |

**Rule Management**

| Feature | Description |
|---------|-------------|
| **Rule CRUD** | Create, edit, delete automation rules with name and description |
| **Rule Toggle** | Enable/disable individual rules |
| **Test Fire** | Test-fire a rule on demand (ignores debounce) to verify behavior |
| **Rule Templates** | Pre-built automation templates organized by category for quick setup (see template categories below) |
| **Automation Guide** | Interactive 9-step guide explaining CRM automation triggers, actions, and best practices with examples |
| **Execution Log** | Full audit trail of every automation action with target details |
| **Run Statistics** | Track last run time and total run count per rule |
| **Cron-Based Evaluation** | Background processing every 5 minutes for time-based triggers |
| **Rich Text Email Body** | HTML email editor with merge tag support |

**CRM Automation Template Categories**

Pre-built one-click templates to help users get started with CRM automations:

| Category | Example Templates |
|----------|-------------------|
| **Deal Management** | Follow Up Stale Deals, Auto-Invoice on Deal Won, Auto-Advance Deal Stage, Alert on Lost Deal |
| **Client Management** | Chase Overdue Invoices, Re-Engage Dormant Clients, Alert on Low Client Health |
| **Cross-Feature** | Invoice When Board Closes, Onboarding Sequence on Won, Assign Task on Stage Change |
| **Team** | Reassign Deals on Sick Leave, Alert on Hours Threshold |
| **Email Tracking** | Follow Up on Email Open, Re-engage Link Clickers, Task on Link Click |

**Rule Sharing**

| Feature | Description |
|---------|-------------|
| **Share with Colleagues** | Share rules with individual colleagues as viewer or editor |
| **Share with Groups** | Share rules with colleague groups (viewer/editor permissions) |
| **Duplicate Shared Rules** | Copy a shared rule to your own rules |
| **Visibility Control** | Rules are private by default, shared when explicitly published |

#### CRM Email Sequences (Drip Campaigns)

| Feature | Description |
|---------|-------------|
| **Multi-Step Sequences** | Define multi-email drip campaigns with delays |
| **Step Configuration** | Subject, body, delay between steps |
| **Client Enrollment** | Manually or automatically enroll clients |
| **Deal Linking** | Associate sequence enrollment with specific deals |
| **Enrollment Management** | Pause, resume, cancel individual enrollments |
| **Stage-Based Triggers** | Auto-enroll when deal enters specific stage |
| **Template Variables** | Dynamic content with {client_name}, {deal_title}, {step_number}, etc. |
| **Progress Tracking** | Track which step each client is on |
| **Cron-Based Processing** | Background step processing every 5 minutes |

### Client Portal

An external-facing portal where clients can view project updates, sign documents, and join calls. Fully independent authentication system.

#### Portal Authentication

| Feature | Description |
|---------|-------------|
| **Magic Link Auth** | Passwordless login via single-use email links |
| **24-Hour Link Expiry** | Magic links expire after 24 hours |
| **30-Day Sessions** | Portal sessions last 30 days |
| **Rate Limiting** | 5 magic link requests per hour per email |
| **Access Management** | Grant/revoke portal access per client contact |
| **Data Isolation** | Client A never sees client B's data |
| **Independent Auth** | Completely separate from internal JWT system |

#### Portal Updates

| Feature | Description |
|---------|-------------|
| **Project Updates** | Push updates to clients (design reviews, milestones, announcements) |
| **Update Types** | Design review, milestone, general announcement |
| **Rich Content** | HTML content with attached files |
| **Comments** | Threaded comment system (internal + portal users) |
| **Read Tracking** | Know when clients read updates |
| **File Attachments** | Attach files from Drive or direct upload |
| **Pinned Updates** | Pin important updates to top |
| **Board/Card Links** | Link updates to Kanban boards and cards |
| **Mood Board Links** | Link updates to shared mood boards |

#### Portal Documents & E-Signing

| Feature | Description |
|---------|-------------|
| **Document Types** | Contract, invoice, proposal, quote, NDA, agreement, receipt |
| **Status Workflow** | Draft, Sent, Viewed, Signing, Signed, Rejected, Expired, Archived |
| **Multi-Signer Support** | Multiple signers per document |
| **Signing Methods** | Upload signed copy, signature pad, or both |
| **Sequential/Parallel Signing** | Control signing order |
| **Full Audit Trail** | Every document action logged with timestamp |
| **Reminder System** | Send reminders to pending signers |
| **Document Download** | Clients can download documents for review |
| **Signed File Storage** | Signed copies stored per signer |

#### Portal Video Calls

| Feature | Description |
|---------|-------------|
| **Client Calls** | Schedule and initiate video calls with clients |
| **Call Room** | Dedicated portal call room interface |
| **Join by Link** | Clients join calls from their portal |
| **Call History** | Track all client calls |

### Time Tracking

| Feature | Description |
|---------|-------------|
| **Per-Client Tracking** | Track time spent on each client |
| **Website Time Tracking** | Track time on client websites (via FlowOneDrive) |
| **Time Breakdown** | Breakdown by activity type, date range |
| **Time Statistics** | Totals, averages, trends per client |
| **Board Integration** | Time tracking linked to project boards |
| **Detailed Reports** | Export and analyze time data |

### Scheduled Emails

| Feature | Description |
|---------|-------------|
| **Schedule Send** | Compose emails and schedule for later delivery |
| **View Scheduled** | List all pending scheduled emails |
| **Cancel Scheduled** | Cancel emails before they send |
| **Cron Processing** | Background processing every minute |
| **UTC Handling** | Timezone-safe scheduling |

### Email Campaigns

| Feature | Description |
|---------|-------------|
| **Bulk Sending** | Queue and send emails to large recipient lists |
| **Campaign Status** | Track pending, processing, completed, paused, cancelled |
| **Progress Monitoring** | Real-time delivery progress with auto-refresh |
| **Pause/Resume** | Pause and resume active campaigns |
| **Failed Tracking** | View and retry failed recipients |
| **Multi-Account** | Send from any configured email account |
| **Status Filtering** | Filter campaigns by status |
| **Rate Limiting** | Built-in rate limit awareness per account |

### Email Templates

| Feature | Description |
|---------|-------------|
| **Template Library** | Create and manage reusable email templates |
| **Rich HTML Editor** | Full HTML template editing |
| **Quick Insert** | Use templates when composing emails |
| **Shared Templates** | Organization-wide template sharing |
| **Template Variables** | Dynamic merge tags for personalized content |

### Automation Hub (Addon - Visual Workflow Engine)

A visual, node-based workflow automation engine with 89+ node types spanning triggers, actions, conditions, and integrations. Users build workflows by dragging nodes onto an infinite canvas and connecting them with edges. Gated by the `automation_hub` addon toggle.

#### Workflow Builder

| Feature | Description |
|---------|-------------|
| **Visual Canvas** | Infinite pan/zoom canvas for node-based workflow design |
| **Drag & Drop Nodes** | Drag nodes from categorized sidebar palette onto canvas |
| **Edge Connections** | Draw edges between node ports to define data flow |
| **Node Config Panel** | Right-side panel for configuring each node's settings |
| **Test / Run** | Test-execute a workflow from the toolbar or run in production |
| **Execution Trace** | Per-node execution log showing input, output, duration, and errors |
| **Workflow Toggle** | Enable/disable workflows without deleting them |
| **Workflow Duplication** | Clone workflows for rapid iteration |
| **Cron Scheduling** | Time-based workflows execute on schedule via cron |
| **Webhook Triggers** | Workflows triggered by external HTTP requests |

#### Trigger Nodes (23 types)

| Category | Node | Description |
|----------|------|-------------|
| **Core** | `trigger.manual` | Run workflow manually via Test button |
| **Core** | `trigger.schedule.cron` | Run on a time schedule (cron expression or interval) |
| **Core** | `trigger.webhook.incoming` | Triggered by external HTTP request to unique URL |
| **Board** | `trigger.board.card_moved` | When a card moves to a specific list |
| **Board** | `trigger.board.card_created` | When a new card is created |
| **Board** | `trigger.board.card_completed` | When a card is marked complete |
| **Board** | `trigger.board.card_overdue` | When a card passes its due date |
| **Board** | `trigger.board.shared` | When a board is shared with someone |
| **CRM** | `trigger.crm.deal_stage_changed` | When a deal moves to a new pipeline stage |
| **CRM** | `trigger.crm.deal_won` | When a deal is marked as won |
| **CRM** | `trigger.crm.deal_lost` | When a deal is marked as lost |
| **CRM** | `trigger.crm.invoice_overdue` | When an invoice is past due |
| **Clients** | `trigger.client.health_low` | When client health score drops below threshold |
| **Clients** | `trigger.client.inactive` | When a client has no activity for N days |
| **Financial** | `trigger.invoice.paid` | When an invoice is marked as paid |
| **Financial** | `trigger.invoice.created` | When a new invoice is created |
| **Financial** | `trigger.financial.threshold` | When revenue/expenses cross a configured limit |
| **Server** | `trigger.server.health` | Monitor CPU, RAM, disk usage, service status |
| **Calendar** | `trigger.calendar.event_created` | When a calendar event is created |
| **Calendar** | `trigger.calendar.event_upcoming` | Before an event starts (configurable lead time) |
| **Drive** | `trigger.drive.file_uploaded` | When a new file is uploaded to Drive |
| **Drive** | `trigger.drive.file_updated` | When a file is modified |
| **Telegram** | `trigger.telegram.message` | When a Telegram message is received by a bot |

#### Action Nodes (62+ types)

**Communication Actions**

| Node | Description |
|------|-------------|
| `action.email.send` | Send an email message with merge variables |
| `action.chat.send` | Post a message to a chat conversation or channel |
| `action.notification.send` | Push notification to a user |
| `action.telegram.send` | Send a Telegram message via bot |

**Board & Task Actions**

| Node | Description |
|------|-------------|
| `action.board.move_card` | Move a card to another list |
| `action.task.create` | Create a new task or card |

**CRM & Client Actions**

| Node | Description |
|------|-------------|
| `action.crm.move_deal` | Move a deal to a different pipeline stage |
| `action.client.get_data` | Fetch client details and contacts |
| `action.client.get_financials` | Get client revenue and invoice data |
| `action.client.get_health` | Calculate client health score |

**Invoice & Financial Actions**

| Node | Description |
|------|-------------|
| `action.invoice.create` | Create a new invoice for a client |
| `action.invoice.send` | Mark invoice as sent |
| `action.invoice.record_payment` | Record a payment on an invoice |
| `action.invoice.push_billingo` | Push invoice to billing provider (Billingo/Szamlazz.hu) |
| `action.invoice.download_pdf` | Download invoice PDF and save to Drive |
| `action.invoice.send_to_client` | Email invoice directly to client |
| `action.invoice.get_status` | Check invoice payment status |

**Statistics & Reporting Actions**

| Node | Description |
|------|-------------|
| `action.stats.email` | Get email send/receive stats for a period |
| `action.stats.response_time` | Get average reply/response times |
| `action.stats.revenue_report` | Get revenue and profitability data |
| `action.stats.client_ranking` | Rank clients by value |
| `action.stats.aging_report` | Get overdue invoice age breakdown |

**Calendar Actions**

| Node | Description |
|------|-------------|
| `action.calendar.create_event` | Create a calendar event |
| `action.calendar.get_events` | Get events for a date range |
| `action.calendar.update_event` | Update an existing event |
| `action.calendar.delete_event` | Delete a calendar event |
| `action.calendar.get_upcoming` | Get next N upcoming events |

**Drive Actions**

| Node | Description |
|------|-------------|
| `action.drive.list_files` | List files in a folder |
| `action.drive.get_file_info` | Get file metadata |
| `action.drive.create_folder` | Create a new Drive folder |

**Email Marketing & List Actions**

| Node | Description |
|------|-------------|
| `action.campaign.get_stats` | Get email campaign statistics |
| `action.campaign.send` | Send an email campaign |
| `action.list.get_mailing_list` | Fetch contacts from a mailing list |
| `action.list.add_contact` | Add a contact to a mailing list |
| `action.list.remove_contact` | Remove a contact from a mailing list |
| `action.list.get_team` | Fetch team or colleague group members |

**Sequence Actions**

| Node | Description |
|------|-------------|
| `action.sequence.start` | Enroll a contact in an email drip sequence |
| `action.sequence.stop` | Cancel an active sequence enrollment |
| `action.sequence.get_status` | Check sequence enrollment status |

**Mood Board Actions**

| Node | Description |
|------|-------------|
| `action.moodboard.get_info` | Get mood board details |
| `action.moodboard.list` | List mood boards with optional filter |
| `action.moodboard.share` | Generate share link and send via email |

**AI Actions** (requires AI API key)

| Node | Description |
|------|-------------|
| `action.ai.prompt` | Send a prompt to AI and get a response |
| `action.ai.summarize` | Summarize text content with AI |
| `action.ai.rewrite` | Rewrite text in a chosen style |

**Data & Integration Actions**

| Node | Description |
|------|-------------|
| `action.http.request` | Make an external HTTP/API call |
| `action.sql.query` | Run a read-only database query |
| `action.export.csv` | Export data to CSV and save to Drive |

**Google Actions** (requires Google OAuth)

| Node | Description |
|------|-------------|
| `action.google.get_contacts` | Fetch Google contacts |
| `action.google.get_contact` | Find a Google contact by name/email |
| `action.google.sync_calendar` | Force Google Calendar sync |

**Trello Actions** (requires Trello connection)

| Node | Description |
|------|-------------|
| `action.trello.sync_boards` | Import Trello boards |
| `action.trello.get_boards` | Fetch Trello boards and cards |

**Mailchimp Actions** (requires Mailchimp API key)

| Node | Description |
|------|-------------|
| `action.mailchimp.get_lists` | Fetch Mailchimp audiences |
| `action.mailchimp.get_members` | Fetch subscribers from an audience |
| `action.mailchimp.add_member` | Add or update a subscriber |
| `action.mailchimp.remove_member` | Unsubscribe a contact |
| `action.mailchimp.get_campaigns` | Fetch Mailchimp campaigns |
| `action.mailchimp.send_campaign` | Send a Mailchimp campaign |

**Printer Actions** (requires Desktop app)

| Node | Description |
|------|-------------|
| `action.printer.list` | Get available printers from local machine |
| `action.printer.print` | Send a document to a printer |

**Weather Actions** (requires OpenWeatherMap API key)

| Node | Description |
|------|-------------|
| `action.weather.get_current` | Get current weather for a location |

#### Logic Nodes (4 types)

| Node | Description | Ports |
|------|-------------|-------|
| `logic.condition` | If/else branch based on data values | Outputs: `true`, `false` |
| `logic.delay` | Wait for a configured duration before continuing | Input/Output |
| `logic.filter` | Pass or block data based on criteria | Input/Output |
| `logic.merge` | Combine multiple input paths into one | Inputs: `input_a`, `input_b` |

#### Conditional Node Availability

Some node groups only appear when the corresponding integration is configured:

| Integration | Requires | Nodes Unlocked |
|-------------|----------|----------------|
| **AI** | AI API key configured | `action.ai.prompt`, `action.ai.summarize`, `action.ai.rewrite` |
| **Weather** | OpenWeatherMap API key | `action.weather.get_current` |
| **Google** | Google OAuth connected | `action.google.*` (3 nodes) |
| **Trello** | Trello token saved | `action.trello.*` (2 nodes) |
| **Mailchimp** | Mailchimp API key | `action.mailchimp.*` (6 nodes) |
| **Printer** | Desktop app connected | `action.printer.*` (2 nodes) |
| **Telegram** | Telegram bot configured | `trigger.telegram.message`, `action.telegram.send` |

#### Automation Hub API Endpoints

**Workflow CRUD**
- `GET /automation-hub/workflows` - List workflows
- `POST /automation-hub/workflows` - Create workflow
- `GET /automation-hub/workflows/{id}` - Get workflow with nodes and edges
- `PUT /automation-hub/workflows/{id}` - Update workflow
- `DELETE /automation-hub/workflows/{id}` - Delete workflow
- `POST /automation-hub/workflows/{id}/toggle` - Toggle active status
- `POST /automation-hub/workflows/{id}/duplicate` - Duplicate workflow

**Execution**
- `POST /automation-hub/workflows/{id}/execute` - Execute workflow
- `POST /automation-hub/workflows/{id}/test` - Test-execute workflow
- `GET /automation-hub/workflows/{id}/executions` - List execution history
- `GET /automation-hub/executions/{id}` - Execution details
- `GET /automation-hub/executions/{id}/nodes` - Per-node execution trace

**Registry & Webhooks**
- `GET /automation-hub/node-registry` - Get available node types
- `POST /automation-hub/webhook/{token}` - Webhook trigger endpoint
- `POST /automation-hub/telegram/webhook/{token}` - Telegram bot webhook

**Connections**
- `GET /automation-hub/connections` - List external connections
- `POST /automation-hub/connections` - Save connection (API key, OAuth token)
- `POST /automation-hub/connections/disconnect` - Disconnect a service

**Trello**
- `GET /automation-hub/trello/auth-url` - Get Trello OAuth URL
- `POST /automation-hub/trello/save-token` - Save Trello token

**Desktop Tasks**
- `GET /automation-hub/desktop-tasks/pending` - Get pending desktop tasks (print jobs)
- `POST /automation-hub/desktop-tasks/{id}/result` - Report task result

**Exports**
- `GET /automation-hub/exports/{filename}` - Download CSV export file

#### Automation Hub Database Schema

- `automation_hub_workflows` - Workflow definitions (name, description, active, cron_schedule, trigger_type)
- `automation_hub_nodes` - Nodes within workflows (type, position_x/y, config JSON, label)
- `automation_hub_edges` - Connections between nodes (source_node_id, source_port, target_node_id, target_port)
- `automation_hub_executions` - Workflow execution history (status, started_at, finished_at, trigger_data)
- `automation_hub_node_executions` - Per-node execution trace (input, output, duration_ms, error)
- `automation_hub_telegram_bots` - Telegram bot configurations (bot_token, webhook_token, chat_id)
- `automation_hub_delayed_executions` - Queue for delay nodes (execute_at, node_id, context)
- `automation_hub_connections` - External service connections (provider, api_key, oauth_token, status)

### Todo System

- Quick tasks with due dates
- Create from emails
- Priority ordering
- Checklist support

### Chat (Team Messaging)

A comprehensive real-time messaging system built for team collaboration with enterprise-grade features.

#### Architecture & Real-Time Delivery

**WebSocket Server (Node.js)**
- Dedicated WebSocket server (`mailsync/server`) running on port 1235
- JWT-based authentication with token validation
- Connection pooling and client management
- Heartbeat/ping-pong for connection health monitoring
- Graceful reconnection handling with event replay

**Redis Pub/Sub Integration**
- PHP backend publishes chat events to Redis channels
- WebSocket server subscribes to Redis pattern: `webmail:mailbox:{userEmail}`
- Instant message delivery across all connected clients
- Multi-server support (events shared across instances)
- Event versioning and idempotency for reliable delivery

**Message Flow**
1. User sends message via REST API (`POST /chat/conversations/{id}/messages`)
2. PHP backend stores message in MariaDB (`chat_messages` table)
3. PHP publishes `CHAT_MESSAGE_NEW` event to Redis
4. WebSocket server receives event from Redis pub/sub
5. WebSocket broadcasts to all subscribed clients for that conversation
6. Clients receive instant notification and update UI

#### Core Features

| Feature | Description | Technical Details |
|---------|-------------|-------------------|
| **Direct Messages** | 1:1 private messaging between colleagues | Auto-creates conversation on first message, domain-scoped (colleagues only) |
| **Group Chat** | Multi-person conversations with named groups | Named groups with descriptions, member management, admin roles |
| **Real-Time Delivery** | Sub-second message delivery via WebSocket | Redis pub/sub → WebSocket broadcast, typically <100ms latency |
| **Typing Indicators** | See when colleague is typing | `CHAT_TYPING_START`/`STOP` events, 3-second timeout, per-conversation tracking |
| **Read Receipts** | Know when messages are seen | `CHAT_READ_RECEIPT` events, tracks last read message ID per user |
| **Reactions** | Emoji reactions on messages | Multiple reactions per message, per-user tracking, real-time updates |
| **Threaded Replies** | Reply to specific messages | `reply_to_id` links messages, maintains conversation context |
| **Message Editing** | Edit sent messages with history | Soft edit (updates content), preserves original timestamp, edit indicator |
| **Message Deletion** | Soft delete with recovery | Marks as deleted, hides from UI, preserves in database for audit |
| **Message Pinning** | Pin important messages | Pinned messages shown at top of conversation, per-conversation limit |
| **Unread Counts** | Badge notifications per conversation | Real-time unread tracking, total unread across all conversations |
| **Conversation Search** | Full-text search across messages | MySQL full-text search, filters by conversation, date range |
| **Conversation Settings** | Customize chat appearance | Background images, notification preferences, mute/archive options |

#### Advanced Features

**Attachments & Media**
- File uploads with progress tracking
- Image gallery view with lightbox
- Voice messages with waveform visualization
- Video and document previews
- Attachment categories (image, video, document, audio)
- Save attachments to Drive with one click
- Attachment size limits and type validation

**Rich Embeds**
- Embed Drive files and folders (live preview)
- Embed calendar events (RSVP from chat)
- Embed board cards (link project discussions)
- Embed boards (full project context)
- Embed todos (task references)
- Auto-resolve embeds when content changes
- Click-through to full content

**View Together (Synchronized Viewing)**
- Start shared viewing session for any content
- Real-time scroll position sync across participants
- Works with Drive files, documents, presentations
- Session management (start/end/sync position)
- Multiple participants can view simultaneously
- Broadcasts `CHAT_VIEW_SESSION_START/END/SYNC` events

**External Invitations**
- Invite users outside your organization via email
- Secure token-based invitation system (7-day expiry)
- Email invitations sent from inviter's account (OAuth or SMTP)
- Accept/decline invitation flow
- Creates conversation upon acceptance
- Cross-domain chat support

**Group Management**
- Create groups from colleague groups (one-click)
- Add/remove members dynamically
- Admin roles (promote/demote members)
- Group name and description editing
- Member list with presence status
- Leave group option
- Group invitation system

**Conversation Management**
- Pin/unpin conversations
- Mute/unmute notifications
- Archive/unarchive conversations
- Conversation settings (background, notifications)
- Conversation search and filtering
- Sort by recent activity or unread count

**Voice & Video Calls (WebRTC)**
- Peer-to-peer voice and video calls via WebRTC
- ICE/TURN server credential provisioning
- Call history with duration tracking
- Call initiation from chat conversations
- Meeting links with token-based join flow
- Calendar integration for scheduled meetings

**Huddles**
- Quick audio huddles within chat conversations
- Start/join/leave huddle
- Lightweight alternative to full video calls
- Conversation-scoped (one huddle per conversation)

**Channels**
- Slack-style public and private channels
- Browse and search available channels
- Create, join, leave channels
- Channel-scoped messaging and notifications

**Scheduled Messages**
- Schedule chat messages for future delivery
- Edit scheduled messages before they send
- Cancel/delete scheduled messages
- View all pending scheduled messages
- Cron-based processing

**Incoming Webhooks**
- Create webhook URLs for external integrations
- Post messages to conversations from external services
- Token-based authentication (no user auth needed)
- Manage and delete webhooks per conversation

#### Database Schema

**Core Tables**
- `chat_conversations` - DM and group conversations
- `chat_participants` - Conversation membership (many-to-many)
- `chat_messages` - All messages with content, type, metadata
- `chat_reactions` - Message reactions (emoji + user)
- `chat_read_receipts` - Read status per user per conversation
- `chat_typing_status` - Current typing state
- `chat_attachments` - File attachments linked to messages
- `chat_pinned_messages` - Pinned messages per conversation
- `chat_invitations` - External user invitations
- `chat_voice_messages` - Voice message metadata
- `chat_embed_messages` - Embedded content references

**Message Types**
- `text` - Standard text messages
- `file` - File attachments
- `image` - Image attachments
- `voice` - Voice messages
- `call` - Call initiation/end events
- `embed` - Embedded content (Drive, Calendar, Board, etc.)
- `system` - System notifications

#### API Endpoints

**Conversations**
- `GET /chat/conversations` - List all conversations
- `GET /chat/conversations/{id}` - Get conversation details
- `POST /chat/dm/{colleagueId}` - Get or create DM
- `GET /chat/unread` - Get unread counts

**Messages**
- `GET /chat/conversations/{id}/messages` - Get messages (paginated)
- `POST /chat/conversations/{id}/messages` - Send message
- `PATCH /chat/messages/{id}` - Edit message
- `DELETE /chat/messages/{id}` - Delete message
- `GET /chat/search` - Search messages

**Reactions**
- `POST /chat/messages/{id}/reactions` - Add reaction
- `DELETE /chat/messages/{id}/reactions/{emoji}` - Remove reaction

**Read Receipts & Typing**
- `POST /chat/conversations/{id}/read` - Mark as read
- `POST /chat/conversations/{id}/typing` - Update typing status

**Attachments**
- `POST /chat/conversations/{id}/attachments` - Upload files
- `GET /chat/conversations/{id}/attachments` - List attachments
- `GET /chat/attachments/{conversationId}/{filename}` - Serve file
- `POST /chat/conversations/{id}/attachments/save-to-drive` - Save to Drive

**Pinning**
- `POST /chat/messages/{id}/pin` - Toggle pin
- `GET /chat/conversations/{id}/pinned` - Get pinned messages

**Groups**
- `POST /chat/groups` - Create group
- `POST /chat/groups/from-colleague-group` - Create from colleague group
- `GET /chat/groups/{id}/members` - Get members
- `POST /chat/groups/{id}/members` - Add members
- `DELETE /chat/groups/{id}/members/{memberId}` - Remove member
- `PATCH /chat/groups/{id}` - Update group info
- `POST /chat/groups/{id}/admins` - Set admin role
- `POST /chat/groups/{id}/invite` - Invite external user

**Invitations**
- `POST /chat/invite` - Invite external user (DM)
- `GET /chat/invitations` - Get pending invitations
- `POST /chat/invitations/{id}/accept` - Accept invitation
- `POST /chat/invitations/{id}/decline` - Decline invitation
- `GET /chat/invitations/token/{token}` - Look up by token

**View Together**
- `POST /chat/conversations/{id}/view-session` - Start session
- `DELETE /chat/conversations/{id}/view-session` - End session
- `PUT /chat/conversations/{id}/view-session/sync` - Sync position

**Settings**
- `GET /chat/conversations/{id}/settings` - Get settings
- `PUT /chat/conversations/{id}/settings` - Update settings
- `POST /chat/conversations/{id}/pin` - Pin conversation
- `POST /chat/conversations/{id}/mute` - Mute conversation
- `POST /chat/conversations/{id}/archive` - Archive conversation

**Embeds**
- `GET /chat/embed/resolve` - Resolve embed reference

**Calls**
- `GET /call/ice-servers` - Get ICE/TURN credentials
- `GET /call/history/{id}` - Get call history for conversation
- `POST /call/history` - Save call record

**Huddles**
- `POST /chat/huddles/start` - Start huddle in conversation
- `POST /chat/huddles/{id}/join` - Join existing huddle
- `POST /chat/huddles/{id}/leave` - Leave huddle

**Channels**
- `GET /chat/channels` - Browse all channels
- `POST /chat/channels` - Create channel
- `POST /chat/channels/{id}/join` - Join channel
- `POST /chat/channels/{id}/leave` - Leave channel

**Scheduled Messages**
- `GET /chat/scheduled` - List scheduled messages
- `POST /chat/conversations/{id}/schedule` - Schedule a message
- `PATCH /chat/scheduled/{id}` - Edit scheduled message
- `DELETE /chat/scheduled/{id}` - Cancel scheduled message

**Webhooks**
- `POST /chat/webhooks` - Create incoming webhook
- `GET /chat/webhooks` - List webhooks
- `DELETE /chat/webhooks/{id}` - Delete webhook
- `POST /webhook/{token}` - Receive webhook message (public, no auth)

#### Mood Board API Endpoints

**Boards**
- `GET /mood-boards` - List all boards
- `GET /mood-boards/{id}` - Get board with items
- `POST /mood-boards` - Create board
- `PUT /mood-boards/{id}` - Update board
- `DELETE /mood-boards/{id}` - Delete board
- `POST /mood-boards/{id}/duplicate` - Duplicate board

**Items**
- `POST /mood-boards/{id}/items` - Add item to canvas
- `PUT /mood-boards/{id}/items/{itemId}` - Update item
- `PUT /mood-boards/{id}/items/batch` - Batch update positions
- `DELETE /mood-boards/{id}/items/{itemId}` - Delete item

**Uploads**
- `POST /mood-boards/{id}/upload` - Upload files
- `GET /mood-boards/{id}/uploads/{filename}` - Serve uploaded file
- `POST /mood-boards/{id}/import-drive-file` - Import from Drive

**Image Sets**
- `POST /mood-boards/{id}/items/{itemId}/images` - Add image to set
- `DELETE /mood-boards/{id}/images/{imageId}` - Remove image from set

**Todos (within canvas)**
- `POST /mood-boards/{id}/items/{itemId}/todos` - Add todo
- `PUT /mood-boards/{id}/todos/{todoId}` - Update todo
- `DELETE /mood-boards/{id}/todos/{todoId}` - Delete todo

**Connections**
- `POST /mood-boards/{id}/connections` - Create connection
- `PUT /mood-boards/{id}/connections/{connId}` - Update connection
- `DELETE /mood-boards/{id}/connections/{connId}` - Delete connection

**Members**
- `GET /mood-boards/{id}/members` - Get members
- `POST /mood-boards/{id}/members` - Add member
- `PUT /mood-boards/{id}/members/{email}` - Update role
- `DELETE /mood-boards/{id}/members/{email}` - Remove member

**Groups**
- `GET /mood-boards/{id}/groups` - Get group access
- `POST /mood-boards/{id}/groups` - Grant group access
- `DELETE /mood-boards/{id}/groups/{groupId}` - Remove group access

**Board Linking (Mood <-> Kanban)**
- `GET /mood-boards/{id}/board-links` - Get linked kanban boards
- `POST /mood-boards/{id}/board-links` - Link to kanban board
- `DELETE /mood-boards/{id}/board-links/{kanbanBoardId}` - Unlink
- `GET /kanban-boards/{kanbanBoardId}/mood-boards` - Reverse lookup

**Client Linking**
- `GET /clients/{clientId}/mood-boards` - Get client's mood boards
- `POST /clients/{clientId}/mood-boards` - Link board to client
- `DELETE /clients/{clientId}/mood-boards/{boardId}` - Unlink

**Public Sharing**
- `POST /mood-boards/{id}/share` - Create share link
- `PUT /mood-boards/{id}/share` - Update share settings
- `DELETE /mood-boards/{id}/share` - Remove share link
- `GET /mood-boards/{id}/share/stats` - Share analytics
- `GET /mood-boards/shared` - List all shared boards
- `GET /mood-boards/share/{token}` - Public board view (no auth)
- `GET /mood-boards/share/{token}/uploads/{filename}` - Public file serve
- `POST /mood-boards/share/{token}/track` - Track view analytics
- `PUT /mood-boards/share/{token}/heartbeat` - Update view duration

**Components**
- `GET /mood-boards/components` - List saved components
- `POST /mood-boards/components` - Save component
- `PUT /mood-boards/components/{id}` - Update component
- `DELETE /mood-boards/components/{id}` - Delete component

#### Mood Board Database Schema

- `mood_boards` - Board metadata (name, background, canvas size, viewport, motion settings)
- `mood_board_items` - Canvas items (type, position, size, rotation, z-index, content, style)
- `mood_board_todos` - Todo items within todo_list canvas items
- `mood_board_connections` - Lines between items (style, color, arrows, labels)
- `mood_board_members` - Board members (email, role: viewer/editor/admin)
- `mood_board_group_access` - Colleague group access (board, group, role)
- `mood_board_client_links` - Client-to-board associations
- `mood_board_board_links` - Mood-to-Kanban board links
- `mood_board_image_set_items` - Images within image_set items
- `mood_board_uploads` - Uploaded file metadata
- `mood_board_components` - Saved reusable component blocks
- `mood_board_shares` - Public share link settings
- `mood_board_share_views` - Share view analytics/tracking

#### CRM Pro API Endpoints

**Deals**
- `GET /crm/deals` - List deals (filter by client, stage, assigned, search)
- `GET /crm/deals/pipeline` - Get pipeline view (deals grouped by stage)
- `GET /crm/deals/{id}` - Get deal details
- `POST /crm/deals` - Create deal
- `PUT /crm/deals/{id}` - Update deal
- `DELETE /crm/deals/{id}` - Delete deal
- `PUT /crm/deals/{id}/stage` - Move deal to stage (triggers automation)
- `GET /crm/deals/{id}/activity` - Get deal activity feed

**Invoices**
- `GET /crm/invoices` - List invoices (filter by client, status, date, search)
- `GET /crm/invoices/{id}` - Get invoice details
- `POST /crm/invoices` - Create invoice
- `PUT /crm/invoices/{id}` - Update invoice
- `DELETE /crm/invoices/{id}` - Delete invoice
- `POST /crm/invoices/{id}/send` - Send invoice to client
- `POST /crm/invoices/{id}/payment` - Record payment
- `GET /crm/invoices/{id}/pdf` - Generate PDF
- `POST /crm/invoices/{id}/push` - Push to billing provider
- `GET /crm/invoices/{id}/download-pdf` - Download from billing provider
- `POST /crm/invoices/{id}/sync-status` - Sync status from provider
- `POST /crm/invoices/{id}/cancel-external` - Cancel on provider
- `POST /crm/invoices/{id}/send-email` - Send via email

**Expenses**
- `GET /crm/expenses` - List expenses
- `POST /crm/expenses` - Create expense
- `PUT /crm/expenses/{id}` - Update expense
- `DELETE /crm/expenses/{id}` - Delete expense

**Tags**
- `GET /crm/tags` - List tags
- `POST /crm/tags` - Create tag
- `PUT /crm/tags/{id}` - Update tag
- `DELETE /crm/tags/{id}` - Delete tag
- `POST /clients/{id}/tags` - Assign tag to client
- `DELETE /clients/{id}/tags/{tagId}` - Remove tag from client
- `GET /clients/{id}/tags` - Get client's tags

**Custom Fields**
- `GET /crm/custom-fields` - List field definitions
- `POST /crm/custom-fields` - Create field definition
- `PUT /crm/custom-fields/{id}` - Update field definition
- `DELETE /crm/custom-fields/{id}` - Delete field definition
- `PUT /clients/{id}/fields` - Save client field values

**Reminders**
- `GET /crm/reminders` - List reminders
- `POST /crm/reminders` - Create reminder
- `PUT /crm/reminders/{id}` - Update reminder
- `DELETE /crm/reminders/{id}` - Delete reminder
- `POST /crm/reminders/{id}/complete` - Mark complete

**Call Log & Meeting Notes**
- `GET /clients/{id}/call-log` - Get call log
- `POST /clients/{id}/call-log` - Log a call
- `GET /clients/{id}/meeting-notes` - Get meeting notes
- `POST /clients/{id}/meeting-notes` - Create meeting note
- `PUT /clients/{id}/meeting-notes/{noteId}` - Update meeting note

**Timeline**
- `GET /clients/{id}/timeline` - Get unified client timeline

**Dashboard & Reports**
- `GET /crm/dashboard` - CRM dashboard metrics
- `GET /crm/reports/revenue` - Revenue report
- `GET /crm/reports/pipeline` - Pipeline report
- `GET /crm/reports/health` - Client health report
- `GET /crm/reports/aging` - Invoice aging report
- `GET /crm/reports/client-ranking` - Client ranking
- `GET /crm/reports/profitability` - Profitability report
- `GET /crm/reports/forecast` - Revenue forecast
- `GET /crm/reports/funnel` - Conversion funnel

**Automation**
- `GET /crm/automation/rules` - List automation rules
- `POST /crm/automation/rules` - Create rule
- `PUT /crm/automation/rules/{id}` - Update rule
- `DELETE /crm/automation/rules/{id}` - Delete rule
- `POST /crm/automation/rules/{id}/toggle` - Enable/disable rule
- `POST /crm/automation/rules/{id}/test` - Test-fire a rule on demand
- `GET /crm/automation/rules/{id}/shares` - Get rule sharing details
- `POST /crm/automation/rules/{id}/duplicate` - Duplicate a shared rule
- `GET /crm/automation/log` - Get execution log

**Sequences**
- `GET /crm/sequences` - List sequences
- `POST /crm/sequences` - Create sequence
- `PUT /crm/sequences/{id}` - Update sequence
- `DELETE /crm/sequences/{id}` - Delete sequence
- `POST /crm/sequences/{id}/enroll` - Enroll client
- `GET /crm/sequences/{id}/enrollments` - Get enrollments
- `POST /crm/sequences/enrollments/{id}/cancel` - Cancel enrollment

**Billing Provider**
- `GET /billing/settings` - Get billing settings
- `PUT /billing/settings` - Save billing settings
- `POST /billing/test-connection` - Test provider connection
- `GET /billing/invoice-blocks` - Get invoice block templates

#### CRM Pro Database Schema

- `crm_deals` - Deal records (title, client_id, value, probability, pipeline_stage, expected_close)
- `crm_deal_stage_history` - Stage transition audit trail
- `crm_invoices` - Invoice records (client_id, deal_id, items, status, totals)
- `crm_invoice_items` - Invoice line items
- `crm_payments` - Payment records
- `crm_expenses` - Business expenses
- `crm_tags` - Tag definitions (name, color)
- `crm_client_tags` - Client-tag associations
- `crm_custom_field_definitions` - Custom field schemas
- `crm_custom_field_values` - Per-client field data
- `crm_reminders` - Reminder records
- `crm_call_log` - Call logging records
- `crm_meeting_notes` - Meeting note records
- `crm_automation_rules` - Automation rule definitions (triggers, actions, config, run stats)
- `crm_automation_log` - Automation execution log (rule, target, action, result)
- `crm_automation_rule_shares` - Individual colleague rule shares (viewer/editor)
- `crm_automation_rule_group_shares` - Colleague group rule shares (viewer/editor)
- `crm_sequences` - Email sequence definitions
- `crm_sequence_steps` - Steps within sequences
- `crm_sequence_enrollments` - Client enrollments in sequences
- `crm_billing_settings` - Billing provider configuration
- `boardpro_automation_rules` - Board-level automation rules (triggers, actions, per-board)
- `boardpro_automation_log` - Board automation execution log

#### Client Portal API Endpoints

**Portal Auth (Public)**
- `GET /portal/auth/{token}` - Consume magic link, create session
- `POST /portal/request-link` - Request new magic link

**Portal Endpoints (Portal-Authenticated)**
- `GET /portal/me` - Get current portal user info
- `POST /portal/logout` - End portal session
- `GET /portal/updates` - List updates for client
- `GET /portal/updates/{id}` - Get single update
- `POST /portal/updates/{id}/read` - Mark update as read
- `POST /portal/updates/{id}/comments` - Add comment
- `GET /portal/updates/{id}/files/{fileId}` - Download update file
- `GET /portal/documents` - List documents
- `GET /portal/documents/{docId}` - Get document details
- `GET /portal/documents/{docId}/download` - Download document
- `POST /portal/documents/{docId}/sign/upload` - Upload signed copy
- `POST /portal/documents/{docId}/sign/pad` - Sign with pad
- `POST /portal/documents/{docId}/reject` - Reject document
- `GET /portal/calls` - List calls
- `POST /portal/calls/{callId}/join` - Join call
- `POST /portal/calls/{callId}/end` - End call

**Internal Portal Management (JWT-Authenticated)**
- `POST /clients/{id}/portal/grant` - Grant portal access
- `DELETE /clients/{id}/portal/revoke/{accessId}` - Revoke access
- `GET /clients/{id}/portal/access` - List portal access
- `POST /clients/{id}/portal/send-link` - Send magic link email
- `POST /clients/{id}/portal/generate-link` - Generate magic link URL
- `POST /clients/{id}/portal/updates` - Create update
- `GET /clients/{id}/portal/updates` - List client updates
- `POST /clients/{id}/portal/updates/{updateId}/comments` - Add internal comment
- `POST /clients/{id}/portal/updates/{updateId}/files` - Attach file
- `POST /clients/{id}/portal/documents` - Create document
- `PUT /clients/{id}/portal/documents/{docId}` - Update document
- `POST /clients/{id}/portal/documents/{docId}/send` - Send document to signers
- `POST /clients/{id}/portal/documents/{docId}/remind` - Send reminder
- `GET /clients/{id}/portal/documents` - List client documents
- `GET /clients/{id}/portal/documents/{docId}/audit` - Get document audit trail
- `GET /clients/{id}/portal/documents/{docId}/signed-file/{signerId}` - Download signed file
- `GET /clients/{id}/portal/calls` - List client calls
- `POST /clients/{id}/portal/calls` - Create call
- `POST /clients/{id}/portal/calls/{callId}/end` - End call

#### Client Portal Database Schema

- `portal_access` - Portal access grants (client_id, email, is_active)
- `portal_magic_links` - Magic link tokens (token, email, expiry, used_at)
- `portal_sessions` - Active portal sessions (token, email, client_id, device info)
- `portal_updates` - Project updates (client_id, title, content, type, pinned)
- `portal_update_reads` - Read tracking per update per user
- `portal_update_comments` - Threaded comments (update_id, author_type, content)
- `portal_update_files` - Attached files per update
- `portal_documents` - Documents (client_id, title, type, status, file info)
- `portal_document_signers` - Signers per document (email, status, signed_at)
- `portal_document_audit` - Full audit trail per document action
- `portal_calls` - Call records (client_id, status, participants)

### Out of Office (Auto-Reply)

| Feature | Description |
|---------|-------------|
| **Schedule-Based** | Set start and end dates for OOO period |
| **Custom Messages** | Personalized subject and body templates |
| **Smart Filtering** | Excludes mailing lists, no-reply addresses |
| **Duplicate Prevention** | One reply per sender per OOO period |
| **Header Detection** | Skips bulk/automated mail (List-Unsubscribe, etc.) |
| **Loop Prevention** | Auto-Submitted headers prevent reply chains |
| **Multi-Account** | Works across all configured email accounts |
| **Background Processing** | Cron-based every 5 minutes |

### Mailing Lists

| Feature | Description |
|---------|-------------|
| **Contact Lists** | Organize external contacts into lists |
| **Rich Contact Info** | Name, email, phone, position, company, notes |
| **Excel/CSV Import** | Bulk import contacts from spreadsheets |
| **Color & Icon** | Customizable list appearance |
| **Send to List** | Select entire list as email recipients |
| **Private or Shared** | Personal lists or organization-wide |
| **Drag & Drop** | Reorder contacts within lists |

### Colleagues (Team Management)

| Feature | Description |
|---------|-------------|
| **Auto-Sync** | Import colleagues from Dovecot/Postfix |
| **Groups** | Organize into teams, departments |
| **Profiles** | Display name, job title, department, phone, avatar |
| **Admin Roles** | Admins can manage colleagues and groups |
| **Presence Status** | Active, away, offline, do not disturb |
| **Real-Time Updates** | Profile changes broadcast via WebSocket |
| **Group Permissions** | Share Drive folders, Boards with groups |
| **Directory** | Searchable organization directory |
| **Manual Add** | Admins can add colleagues manually |

### AI Features

- **Email Summarization**: Condense long threads
- **Reply Drafting**: AI-suggested responses
- **Text Rewriting**: Improve/reformat text
- OpenAI GPT integration

### Statistics Dashboard

| Metric | Tracked |
|--------|---------|
| Emails | Sent, received, by time |
| Conversations | Active, response times |
| Contacts | Top contacts, growth |
| Folders | Size, message counts |
| Tasks | Completion rates |
| Calendar | Events, busy time |
| Drive | Storage usage |
| Boards | Card completion |
| Clients | Revenue, activity |
| Time | Hours by client/project |

### Universal Search (Addon)

A full-featured universal search engine powered by Meilisearch, with MySQL fulltext/LIKE fallback. Searches across all modules in milliseconds with typo tolerance, AI-powered answer extraction, filter operators, and automatic background indexing. Gated by the `universal_search` addon toggle.

#### Search Architecture

```
┌──────────────────────────────────────────────────────────┐
│                  MailFlow Application                      │
│                                                            │
│  MariaDB (Primary Database)                                │
│  ├── Emails, Attachments, Drive files, Boards, Cards       │
│  ├── Todos, Clients, Contacts, Calendar events             │
│  ├── Chat messages, Mood board items, Collab docs          │
│  └── search_index table (indexed content mirror)           │
│                          │                                 │
│                    Index sync                               │
│                          │                                 │
│                          ▼                                 │
│  ┌────────────────────────────────────────────────────┐    │
│  │              Meilisearch (Search Engine)            │    │
│  │                                                     │    │
│  │  - Written in Rust                                  │    │
│  │  - Uses LMDB internally (Lightning Memory-Mapped DB)│    │
│  │  - Full-text search with typo tolerance             │    │
│  │  - Instant filtering & faceting                     │    │
│  │  - Millisecond response times                       │    │
│  │  - Zero configuration embedded key-value store      │    │
│  │  - Single-writer, multiple-reader model             │    │
│  │  - Memory-mapped I/O for extreme speed              │    │
│  └────────────────────────────────────────────────────┘    │
│                          │                                 │
│              Fallback (if Meilisearch unavailable)          │
│                          │                                 │
│                          ▼                                 │
│  ┌────────────────────────────────────────────────────┐    │
│  │         MySQL Fulltext / LIKE Fallback              │    │
│  │  - MySQL FULLTEXT indexes on search_index table     │    │
│  │  - LIKE-based search as last resort                 │    │
│  │  - Automatic engine detection and failover          │    │
│  └────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────┘
```

**Meilisearch is not a traditional database.** It is a search engine (like Elasticsearch), built in Rust, optimized for full-text search, typo tolerance, instant filtering, and millisecond response times. Internally it uses **LMDB (Lightning Memory-Mapped Database)** -- an embedded key-value store that is extremely fast due to memory-mapped I/O, requires zero configuration, and uses a single-writer, multiple-reader model with data stored locally on disk. Meilisearch acts as a **search index layer** alongside the primary MariaDB database, not as a replacement for it.

#### Core Features

| Feature | Description |
|---------|-------------|
| **Cross-Module Search** | Search across emails, attachments, Drive files, calendar events, boards, cards, todos, clients, contacts, chat messages, collab docs, and mood board items |
| **Quick Autocomplete** | Instant results as you type (debounced 200ms, top 8 results) |
| **Full Search** | Comprehensive search with grouped results, counts per type, and highlighted matches |
| **AI-Powered Answers** | GPT-enhanced answer extraction from search results (toggle per query) |
| **Typo Tolerance** | Meilisearch handles misspellings and partial matches automatically |
| **Search Engine Badge** | UI shows whether results come from Meilisearch, MySQL FULLTEXT, or LIKE fallback |
| **Keyboard Shortcut** | Ctrl+K / Cmd+K opens search modal globally from anywhere in the app |

#### Search Tabs

| Tab | Content Searched |
|-----|-----------------|
| **All** | Combined results across all modules |
| **Emails** | Email subjects, senders, recipients, body content |
| **Attachments** | Email attachment filenames, content (PDF, DOCX, etc.) |
| **Calendar** | Calendar event titles, descriptions, locations |
| **Drive** | Drive file and folder names, content |
| **Boards** | Board names and card titles, descriptions, checklists |
| **Todos** | Task titles and descriptions |
| **Clients** | Client names, contacts, domains |
| **Chats** | Chat message content across conversations |
| **MoodBoards** | Mood board item content and notes |

#### Filter Operators

| Operator | Example | Description |
|----------|---------|-------------|
| `from:` | `from:john` | Filter by sender/creator name |
| `client:` | `client:Acme` | Filter by client name |
| `in:` / `folder:` | `in:Projects` | Filter by folder name |
| `ext:` | `ext:pdf` | Filter by file extension (pdf, docx, xlsx, jpg, png, zip) |
| `after:` | `after:2025-01` | Results after date |
| `before:` | `before:2025-06` | Results before date |
| `type:` | `type:email` | Filter by result type (email, file, attachment, card, todo, event) |

Filters can be combined: `report from:john type:file ext:pdf after:2025-01`

#### Visual Filter Bar

| Feature | Description |
|---------|-------------|
| **Expandable Filter Panel** | Grid-based filter form with From, Client, Folder, File Type, After Date, Before Date fields |
| **Active Filter Pills** | Active filters shown as dismissable pill badges below the search input |
| **Filter Help Popup** | Interactive guide showing all filter operators with click-to-insert examples |
| **Filter Parsing** | Filters auto-parsed from typed query syntax into visual filter bar fields |

#### Background Indexing

| Feature | Description |
|---------|-------------|
| **Attachment Content Indexing** | Automatic background indexing of email attachment content (PDF, DOCX, XLSX text extraction) |
| **Email Body Indexing** | Automatic background indexing of full email body content via IMAP |
| **Periodic Indexing** | Attachment indexing every 10 minutes, body indexing every 5 minutes |
| **Batch Processing** | Processes 30 attachments or 20 email bodies per batch to avoid overload |
| **Remaining Tracking** | Tracks how many items still need indexing, schedules follow-up batches |
| **Full Index Rebuild** | One-click rebuild of entire search index from the search modal footer |
| **Index Statistics** | Hover tooltip showing indexed item counts per type (emails, attachments, events, files, cards, todos, clients, chats, mood boards) |
| **Real-Time Index Updates** | Individual items indexed/removed on create/update/delete via `indexItem()` / `removeFromIndex()` |

#### Search Result Features

| Feature | Description |
|---------|-------------|
| **Highlighted Matches** | Search terms highlighted with `<mark>` tags in titles and snippets |
| **Context Breadcrumbs** | Results show parent context (client, board, folder) as clickable breadcrumb trail |
| **Type Badges** | Color-coded badges identifying result type (Email, Attachment, File, Card, etc.) |
| **Gradient Icons** | Type-specific gradient icon backgrounds for visual distinction |
| **Image Thumbnails** | Image attachments show thumbnail previews loaded via authenticated API |
| **Grouped Drive View** | Drive results auto-group by folder when exceeding 10 results, with collapsible groups |
| **Date Formatting** | Relative timestamps (Just now, 5m ago, 2h ago, 3d ago) |
| **Click Navigation** | Click any result to navigate directly to that item in its native module |

#### Universal Search API Endpoints

- `GET /search/universal` - Full universal search (query, filters, types, AI toggle)
- `GET /search/quick` - Quick autocomplete search (top 8 results)
- `POST /search/index/rebuild` - Rebuild entire search index
- `POST /search/index/attachments` - Index email attachment content batch
- `POST /search/index/bodies` - Index email body content batch
- `GET /search/index/stats` - Get index statistics (counts per type)
- `POST /search/index/item` - Index a single item (real-time updates)
- `DELETE /search/index/item` - Remove item from index (on delete)

#### Universal Search Database / Index Schema

**MariaDB Table**
- `search_index` - Indexed content mirror (type, source_id, title, content, extra_data, user_email, created_at)
  - MySQL FULLTEXT index on `title` and `content` columns for fallback search

**Meilisearch Index**
- `webmail_search` - Primary search index synced from MariaDB
  - Searchable attributes: title, content, snippet
  - Filterable attributes: source_type, user_email, date, client_id, board_id
  - Sortable attributes: date, relevance score

### Financials Dashboard

| Feature | Description |
|---------|-------------|
| **Multi-Currency** | Support for HUF, EUR, USD, RON |
| **Timeline View** | Revenue and expenses over time with charts |
| **Client Breakdown** | Financial data grouped by client |
| **Board Breakdown** | Financial data grouped by project board |
| **Date Range Filtering** | Custom date range for analysis |
| **ApexCharts Visualizations** | Interactive charts and graphs |

#### Financial Ecosystem Architecture

The financial system spans five modules that stack and reinforce each other. Each layer adds capabilities, and data flows bidirectionally between them.

```
┌─────────────────────────────────────────────────────────────────────────┐
│                                                                         │
│                        CLIENTS (CRM Base)                               │
│                   The financial anchor point                             │
│                                                                         │
│   Every board, deal, invoice, expense, and time entry                   │
│   links back to a client via client_id                                  │
│                                                                         │
│   Aggregates: total revenue, expenses, profitability,                   │
│               health score, activity timeline                           │
│                                                                         │
└──────┬──────────────────┬──────────────────┬───────────────────┬────────┘
       │                  │                  │                   │
       │ client_id        │ client_id        │ client_id         │ client_id
       ▼                  ▼                  ▼                   ▼
┌──────────────┐  ┌───────────────┐  ┌──────────────┐  ┌────────────────┐
│   BOARDS     │  │  CRM PRO      │  │  CRM PRO     │  │  TIME TRACKER  │
│  (Kanban)    │  │  Deals        │  │  Expenses     │  │                │
│              │  │               │  │               │  │  Per-client    │
│  Milestones: │  │  Pipeline:    │  │  Categories:  │  │  time tracking │
│  - amount    │  │  - value      │  │  - amount     │  │  - hours       │
│  - currency  │  │  - probability│  │  - category   │  │  - activity    │
│  - paid/     │  │  - stage      │  │  - date       │  │    breakdown   │
│    unpaid    │  │  - currency   │  │  - client_id  │  │  - billable    │
│  - invoice   │  │               │  │               │  │    hours       │
│    date      │  │  Weighted     │  │  Feeds into   │  │                │
│              │  │  forecast:    │  │  profitability │  │  Feeds into    │
│  Cash flow   │  │  value *      │  │  reports       │  │  hourly rate   │
│  projections │  │  probability  │  │  (revenue -   │  │  calculations  │
│              │  │               │  │   expenses)   │  │                │
└──────┬───────┘  └───────┬───────┘  └──────────────┘  └───────┬────────┘
       │                  │                                     │
       │ board.client_id  │ deal won                            │ time_budget
       │                  │ triggers                            │ _hours
       ▼                  ▼                                     ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                                                                         │
│                      BOARD PRO (Enhanced Financials)                     │
│                                                                         │
│   Card-Level Financials (boardpro_card_financials table):               │
│   ┌─────────────────────────────────────────────────────────────┐       │
│   │  estimated_revenue  │  estimated_cost  │  margin (computed) │       │
│   │  time_budget_hours  │  currency        │  invoice_status    │       │
│   │  linked_invoice_id ─┼──────────────────┼─→ CRM INVOICES    │       │
│   └─────────────────────────────────────────────────────────────┘       │
│                                                                         │
│   Views:                                                                │
│   - Revenue Lens: cards grouped by list with revenue/cost totals        │
│   - Time Lens: cards with time budget vs actual hours                   │
│   - Client Lens: cards grouped by client with financial rollup          │
│                                                                         │
│   AI Features:                                                          │
│   - AI Estimation: suggest revenue/cost/time from card description      │
│   - Executive Reports: auto-generated financial summaries               │
│                                                                         │
│   Automations (create_invoice_draft action):                            │
│   - Card completed → auto-create invoice draft from card financials     │
│   - Card moved to "Done" list → auto-create invoice                    │
│   - Milestone completed → auto-create invoice for milestone amount      │
│                                                                         │
└──────────────────────────────────┬──────────────────────────────────────┘
                                   │
                   linked_invoice_id (bidirectional sync)
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                                                                         │
│                      CRM PRO INVOICES                                   │
│                                                                         │
│   Invoice Lifecycle:                                                    │
│   Draft → Sent → Viewed → Partial Payment → Paid                       │
│                                      └→ Overdue                         │
│                                                                         │
│   - client_id links to Client                                           │
│   - Line items with quantity, unit price, tax, discount                 │
│   - PDF generation + email sending                                      │
│   - Billing provider sync (Billingo / Szamlazz.hu)                     │
│   - Payment recording (partial + full)                                  │
│                                                                         │
│   When invoice status changes:                                          │
│   → Syncs back to card's invoice_status in Board Pro                    │
│   → Updates milestone payment_status in Boards                          │
│   → Appears in client unified timeline                                  │
│   → Triggers CRM automation rules (invoice_overdue, etc.)               │
│                                                                         │
└──────────────────────────────────┬──────────────────────────────────────┘
                                   │
                        All data aggregates to
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                                                                         │
│                      FINANCIALS DASHBOARD                               │
│                   (Unified Financial Intelligence)                       │
│                                                                         │
│   Aggregates from ALL sources:                                          │
│   ┌─────────────┬──────────────┬──────────────┬─────────────────┐       │
│   │ Board       │ Board Pro    │ CRM Pro      │ CRM Pro         │       │
│   │ Milestones  │ Card Revenue │ Invoices     │ Expenses        │       │
│   │ (paid/      │ (estimated   │ (actual      │ (categorized    │       │
│   │  unpaid)    │  rev/cost)   │  payments)   │  costs)         │       │
│   └─────────────┴──────────────┴──────────────┴─────────────────┘       │
│                                                                         │
│   Views:                                                                │
│   - Timeline: Revenue + expenses over time (ApexCharts)                 │
│   - By Client: Financial breakdown per client                           │
│   - By Board: Financial breakdown per project board                     │
│   - Cash Flow: Projected income based on invoice dates + payment terms  │
│   - Profitability: Revenue minus expenses per client                    │
│   - Multi-Currency: HUF, EUR, USD, RON with separate totals            │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

#### How Each Module Makes the Others Better

| Module Combination | Synergy |
|--------------------|---------|
| **Boards + Clients** | Boards linked to clients aggregate milestone revenue into client financial totals. Client payment terms auto-apply to board milestones. |
| **Boards + Board Pro** | Board Pro adds per-card revenue/cost tracking on top of milestone-level financials. Revenue Lens, Time Lens, and Client Lens views give multi-angle financial visibility. |
| **Board Pro + CRM Pro** | Card financials auto-create invoice drafts via automation. Invoice status syncs back to card `invoice_status`. The Workflow Guide explains the Project-to-Milestone-to-Invoice pipeline. |
| **Board Pro + Time Tracker** | Time budget hours stored per card. Effective hourly rate calculated as revenue / time. Time Lens view shows budget vs actual. |
| **CRM Pro Deals + Invoices** | Won deals can generate invoices. Deal values feed pipeline forecasting. Monthly forecast reports use `expected_value * probability`. |
| **CRM Pro Invoices + Expenses** | Profitability report calculates revenue (invoices) minus expenses per client. Invoice aging report tracks overdue payments. |
| **CRM Pro + Clients** | All invoices, deals, and expenses link to clients. Client health score factors in financial activity. Unified timeline shows all financial events chronologically. |
| **CRM Pro Automations + All** | `create_invoice_draft` pulls data from cards. `invoice_overdue` trigger chases late payments. `deal_won` triggers can start sequences or create invoices. Cross-module actions (email, chat, calendar) activate on financial events. |
| **Time Tracker + Clients** | Per-client time tracking enables billable hour calculations. Time data shown alongside client revenue for profitability analysis. |
| **All + Financials Dashboard** | Every financial data point from every module flows into the unified dashboard for timeline, client, and board breakdowns with multi-currency support. |

#### Financial Data Key Relationships

```
webmail_boards.client_id ──────────────→ clients.id
webmail_board_lists.board_id ──────────→ webmail_boards.id
webmail_board_cards.list_id ───────────→ webmail_board_lists.id
boardpro_card_financials.card_id ──────→ webmail_board_cards.id
boardpro_card_financials.linked_invoice_id → crm_invoices.id
crm_invoices.client_id ───────────────→ clients.id
crm_deals.client_id ──────────────────→ clients.id
crm_expenses.client_id ───────────────→ clients.id
```

### Push Notifications

| Feature | Description |
|---------|-------------|
| **Web Push** | Browser push notifications via VAPID |
| **Subscribe/Unsubscribe** | Per-device subscription management |
| **New Email Alerts** | Notifications for incoming emails |
| **Chat Message Alerts** | Notifications for new chat messages |

### Onboarding System

A multi-tier guided onboarding experience with interactive flow diagrams, step-by-step walkthroughs, knowledge quizzes with scoring, and admin notification for users who need follow-up.

#### Tier System

| Tier | Duration | Topics Covered |
|------|----------|----------------|
| **Beginner** | ~4 min | Email, Compose, Conversations, Labels, Multi-Account, Contacts, Tasks, Search, Calendar, Security |
| **Intermediate** | ~6 min | Boards, Chat, Drive, Video Calls, Huddles, Mood Boards, Colleagues, Time Tracking, Reactions, AI |
| **Advanced** | ~8 min | CRM Clients, Client Portal, Pipelines, CRM Automation, Board Automation, Sequences, Time Tracking, Reports, Invoices, Campaigns, Mailing Lists, Dashboard |

| Feature | Description |
|---------|-------------|
| **Tier Selection Screen** | Visual card-based tier selector with icons, descriptions, feature preview chips, and duration estimates |
| **Completion Tracking** | Per-tier completion badges, persisted in localStorage and synced to backend |
| **Retake Tiers** | Completed tiers can be retaken at any time |

#### Interactive Flow Diagrams

Each tier has a custom-built flow diagram that visually maps the features covered in that tier.

| Feature | Description |
|---------|-------------|
| **Per-Tier Diagrams** | Separate diagram components for Beginner, Intermediate, and Advanced |
| **Step-by-Step Navigation** | Navigate forward/back through diagram nodes with prev/next buttons |
| **Node Tooltips** | Hover over any node for detailed feature information with rich formatting |
| **Welcome Overlay** | Animated welcome screen shown on first step of each tier |
| **Completion Overlay** | Summary screen on final step with options to take quiz or continue to another tier |
| **Progress Dots** | Clickable dot navigation for jumping to any step |
| **Keyboard Navigation** | Arrow keys (left/right, up/down) for step navigation |
| **Step Counter** | Current step / total steps displayed in header |
| **Bold Keywords** | Key terms automatically bolded in step descriptions for emphasis |
| **Fullscreen Modal** | Diagrams open in a fullscreen modal for immersive experience |

#### Knowledge Quizzes

Each tier ends with a quiz to verify the user understood the features covered.

| Feature | Description |
|---------|-------------|
| **Randomized Questions** | 8 questions randomly selected from a pool of 12 per tier |
| **Multiple Choice** | A/B/C/D options with visual letter badges |
| **Instant Feedback** | Correct (green) / incorrect (red) feedback shown immediately after each answer |
| **Progress Bar** | Animated progress bar showing quiz completion |
| **Score Ring** | Animated circular progress ring showing final percentage |
| **Color-Coded Scores** | Green (>= 80%), amber (>= 50%), red (< 50%) |
| **Score Messages** | Perfect (100%), Great (>= 70%), Good (>= 40%), Needs Work (< 40%) |
| **Topic Breakdown** | Per-topic pass/fail breakdown showing which areas need attention |
| **Answer Review** | Scrollable list of all questions with correct/incorrect status |
| **Retake Option** | Re-shuffle and retake the quiz from the results screen |
| **Skip Option** | Skip the quiz entirely and still mark the tier as completed |

#### Scoring & Admin Follow-Up

| Feature | Description |
|---------|-------------|
| **Score Persistence** | Scores saved to `onboarding_quiz_scores` table (email, score, total, percent, attempts) |
| **Attempt Tracking** | Attempt counter incremented on each retake (ON DUPLICATE KEY UPDATE) |
| **Admin Email Notification** | Notification email sent to configurable admin address when any user completes a quiz |
| **Score Rating in Email** | Color-coded rating: Excellent (>= 90%), Good (>= 70%), Fair (>= 50%), Needs Training (< 50%) |
| **HTML Email Report** | Rich HTML email with score percentage, correct answers count, visual badge, and user identification |
| **Low-Score Follow-Up** | Admin receives notification for low-scoring users, enabling targeted training follow-up |
| **Configurable Notify Email** | Admin notification email set via `VITE_ONBOARDING_NOTIFY_EMAIL` environment variable |

#### Onboarding API Endpoints

- `POST /onboarding/quiz-score` - Save quiz score (score, total, percent, optional notify_email)
- `GET /onboarding/quiz-score` - Get current user's quiz score and attempts

#### Onboarding Database Schema

- `onboarding_quiz_scores` - Quiz results (email, score, total, percent, attempts, created_at)

### Device Management

| Feature | Description |
|---------|-------------|
| **Device Registry** | Track all logged-in devices (browser, OS, IP, location) |
| **Remote Wipe** | Remotely wipe sessions and data from a device |
| **Device Blocking** | Block or unblock specific devices |
| **Status Tracking** | Device states: active, blocked, wipe_pending, wiped |
| **Session-Device Linking** | Link active sessions to registered devices |

### In-App Feedback (Bug & Feature Reporting)

A comprehensive in-app feedback system for reporting bugs, design issues, broken functionality, and feature requests. Feedback is delivered as rich HTML emails with full debug context and optional screenshots directly to the development team.

#### Feedback Categories

| Category | Icon | Use Case |
|----------|------|----------|
| **Design Issue** | `palette` | Visual/layout problems, styling inconsistencies |
| **Error / Bug** | `bug_report` | Application errors, unexpected behavior, crashes |
| **Not Working Button** | `touch_app` | Buttons or interactive elements that don't respond |
| **Can't Find Something** | `search_off` | Missing features, confusing navigation, discoverability issues |
| **Something Not Working** | `error_outline` | Features that load but don't function correctly |
| **New Feature Idea** | `lightbulb` | Feature requests, improvements, suggestions |

#### Feedback Modal

| Feature | Description |
|---------|-------------|
| **Floating Trigger Button** | Fixed-position bug report button on every page with hover tooltip |
| **Page/View Selector** | Dropdown with 26+ page/view options covering all modules (Mailbox, Drive, Calendar, Boards, Mood Boards, Clients, CRM Pipeline, Chat, etc.) |
| **Auto-Detected View** | Current route name pre-selected when modal opens |
| **Category Pill Buttons** | Visual category selector with Google Material icons |
| **Description Field** | Multi-line text area for detailed issue description |
| **Submit Validation** | Requires page, category, and description before sending |
| **Send Progress** | Loading spinner during submission |
| **Success/Error Toast** | Confirmation or error notification after submit |

#### Screenshot Capture

| Feature | Description |
|---------|-------------|
| **html2canvas Capture** | Captures the current page state using html2canvas library |
| **Modal Hidden During Capture** | Feedback modal overlay temporarily hidden for clean screenshot |
| **Capture Delay** | 150ms delay after hiding modal ensures clean render |
| **Preview Thumbnail** | Captured screenshot shown as thumbnail with click-to-zoom |
| **Fullscreen Preview** | Click screenshot to view full-size in a backdrop-blurred overlay |
| **Retake** | Recapture screenshot if the first one wasn't right |
| **Remove** | Remove screenshot and submit without one |
| **Max Size** | 5 MB maximum screenshot size (base64 validated server-side) |

#### Email Delivery

| Feature | Description |
|---------|-------------|
| **Rich HTML Email** | Professional formatted email with header, metadata table, description block, and screenshot |
| **Embedded Screenshot** | Screenshot embedded as CID inline image (not attachment) for instant visibility |
| **Reply-To User** | Reply-To header set to the submitting user's email for direct follow-up |
| **Category Badge** | Category shown as a colored pill badge in the email |
| **Debug Context** | Every email includes: current URL, user agent, screen dimensions (widthxheight), timestamp |
| **Sent via SMTP** | PHPMailer via local SMTP (localhost:25) for reliable delivery |
| **Direct to Dev Team** | Emails sent directly to the configured development team address |
| **Plain Text Fallback** | Auto-generated plain text alternative for email clients that don't render HTML |

#### Feedback API Endpoints

- `POST /feedback` - Submit feedback (view, category, description, screenshot, user_agent, screen_size, url)

### System Health

| Feature | Description |
|---------|-------------|
| **Data Folder Checks** | Verify data directory permissions and structure |
| **Permission Fixing** | Utilities to repair file/folder permissions |
| **Health Monitoring** | System-level health checks for the application |

### Security

Enterprise-grade security features protecting user accounts, sessions, and data at every layer.

#### Authentication & Access Control

**Two-Factor Authentication (2FA/TOTP)**
- **TOTP Standard**: RFC 6238 compliant, works with Google Authenticator, Authy, Microsoft Authenticator
- **QR Code Setup**: One-time QR code generation during setup, secret stored encrypted
- **Time-Based Codes**: 6-digit codes valid for 30-second windows
- **Clock Skew Tolerance**: Accepts codes from ±1 time window (prevents clock sync issues)
- **Backup Codes**: 10 single-use recovery codes generated on setup
- **Regenerate Backup Codes**: Can regenerate codes (requires 2FA verification)
- **Enforcement**: Optional per-user, can be required organization-wide
- **Login Flow**: Password → 2FA code → Access token
- **API Endpoints**: `/2fa/setup`, `/2fa/verify`, `/2fa/login`, `/2fa/disable`, `/2fa/backup-codes`

**Trusted Devices**
- **Device Fingerprinting**: Unique device token generated per browser/device
- **7-Day Trust**: Trusted devices skip 2FA for 7 days
- **Device Registry**: Tracks device name, IP, user agent, last used timestamp
- **Revoke Devices**: Users can revoke individual or all trusted devices
- **Security**: Device tokens are cryptographically secure, cannot be forged
- **Storage**: `webmail_2fa_trusted_devices` table with expiry management

**OAuth Integration**
- **Google OAuth**: Full Google Workspace login support
- **Microsoft OAuth**: Office 365 / Azure AD login support
- **CSRF Protection**: HMAC-signed state parameters prevent CSRF attacks
- **Token Storage**: OAuth tokens encrypted and stored per-user
- **Token Refresh**: Automatic token refresh for long-lived sessions
- **Scope Management**: Minimal required scopes (email, profile)
- **Redirect URI Validation**: Strict redirect URI matching
- **State Verification**: 15-minute state expiry, HMAC signature validation

**Session Management**
- **JWT Tokens**: Stateless access tokens (HS256 signing)
- **Token Expiry**: Configurable expiry (default 1 hour for access, 7 days for refresh)
- **Refresh Token Rotation**: New refresh token issued on each refresh, old token invalidated
- **Replay Detection**: Detects reused refresh tokens (possible theft), invalidates entire session
- **Session Tracking**: Database-backed session tracking with device info
- **Multi-Device Support**: Multiple concurrent sessions per user
- **Session Revocation**: Users can view and revoke active sessions
- **Encrypted Password Storage**: Passwords encrypted server-side (AES-256) for IMAP operations
- **Session Token**: Additional session token for refresh operations (X-Session-Token header)

**Rate Limiting**
- **Login Rate Limiting**: Prevents brute force attacks
- **Per-Email Limits**: Tracks failed attempts per email address
- **Per-IP Limits**: Tracks failed attempts per IP address
- **Exponential Backoff**: Increasing lockout duration after multiple failures
- **Redis-Backed**: Uses Redis for distributed rate limiting
- **Configurable Thresholds**: Adjustable attempt limits and lockout durations
- **Retry-After Headers**: HTTP 429 responses include Retry-After header
- **Audit Logging**: All rate limit violations logged for security analysis

#### Data Protection

**Password Security**
- **IMAP Authentication**: Passwords validated against Dovecot/Postfix
- **No Plaintext Storage**: Passwords never stored in plaintext
- **Encrypted Storage**: Server-side encryption (AES-256) for IMAP operations
- **Password Hashing**: Uses system password hashing (bcrypt/argon2) where applicable
- **Secure Transmission**: All passwords transmitted over HTTPS/TLS
- **Password Reset**: Secure token-based password reset flow

**Data Encryption**
- **TLS/SSL**: All API communications over HTTPS
- **Database Encryption**: Sensitive fields encrypted at rest
- **Token Encryption**: JWT secrets and session tokens use strong encryption
- **File Storage**: Chat attachments stored with proper permissions
- **Backup Encryption**: Database backups can be encrypted

**Input Validation & Sanitization**
- **SQL Injection Prevention**: Prepared statements for all database queries
- **XSS Prevention**: Output escaping, Content Security Policy headers
- **CSRF Protection**: HMAC-signed state for OAuth, SameSite cookies
- **File Upload Validation**: File type, size, and content validation
- **Path Traversal Prevention**: Filename sanitization (basename() usage)
- **Header Injection Prevention**: Safe Content-Disposition header generation

#### API Security

**Authentication Middleware**
- **Bearer Token Auth**: JWT tokens in Authorization header
- **Token Validation**: Signature verification, expiry checking, issuer validation
- **Optional Auth**: Some endpoints public (OAuth URLs, health checks)
- **Fallback Auth**: Query parameter tokens for browser-loaded resources (images, audio)

**Authorization**
- **User Context**: All authenticated requests include user email
- **Resource Ownership**: Users can only access their own data
- **Conversation Membership**: Chat access requires conversation participation
- **Colleague Verification**: Cross-domain chat requires colleague relationship
- **Admin Roles**: Role-based access for admin functions

**Request Security**
- **CORS Configuration**: Proper CORS headers for cross-origin requests
- **Content-Type Validation**: Enforces JSON content type where required
- **Request Size Limits**: Maximum request body size limits
- **Rate Limiting**: API endpoint rate limiting (per-user, per-IP)
- **Request Logging**: Security-relevant requests logged for audit

#### WebSocket Security

**Connection Security**
- **JWT Authentication**: WebSocket connections require valid JWT token
- **Token in Message**: Preferred auth method (AUTHENTICATE message)
- **Legacy URL Auth**: Backward-compatible URL token auth
- **Auth Timeout**: 10-second timeout for authentication, auto-disconnect
- **Token Expiry Checking**: Warns on soon-to-expire tokens
- **IP Tracking**: Client IP logged for security audit

**Message Security**
- **Authenticated Only**: All messages require authentication
- **User Validation**: Messages validated against authenticated user
- **Event Filtering**: Users only receive events for their data
- **Subscription Validation**: Entity subscriptions validated (calendars, boards, etc.)

#### Audit & Logging

**Security Audit Logs**
- **Authentication Events**: All login attempts (success/failure) logged
- **2FA Events**: 2FA setup, verification, disable logged
- **Session Events**: Session creation, refresh, revocation logged
- **Rate Limit Events**: Rate limit violations logged
- **OAuth Events**: OAuth login attempts and token exchanges logged
- **WebSocket Events**: Connection, authentication, disconnection logged
- **IP Tracking**: All security events include IP address
- **User Agent Tracking**: Device/browser info logged
- **Timestamp Precision**: Millisecond-precision timestamps

**Audit Log Storage**
- **Database Table**: `webmail_audit_logs` with indexed queries
- **Log Retention**: Configurable retention period
- **Search & Filter**: Query logs by user, event type, date range
- **Privacy**: Sensitive data redacted in logs (passwords, tokens)

#### Infrastructure Security

**Server Security**
- **Fail2ban Integration**: Automatic IP banning on repeated failures
- **Firewall Management**: FirewallD integration for port management
- **ModSecurity WAF**: Web Application Firewall for HTTP request filtering
- **CPGuard Scanner**: Malware scanning and threat detection
- **SSL/TLS**: Strong cipher suites, TLS 1.2+ only
- **Security Headers**: HSTS, X-Frame-Options, X-Content-Type-Options, CSP

**Database Security**
- **Prepared Statements**: All queries use parameterized statements
- **Connection Encryption**: MySQL connections over TLS
- **Least Privilege**: Database users with minimal required permissions
- **Backup Encryption**: Database backups encrypted at rest

**File System Security**
- **Permission Management**: Proper file permissions (644/755)
- **User Isolation**: Files stored per-user, per-conversation
- **Path Validation**: All file paths validated and sanitized
- **Storage Quotas**: Per-user storage limits enforced

#### Compliance & Privacy

**Data Privacy**
- **GDPR Considerations**: User data export, deletion capabilities
- **Data Minimization**: Only collect necessary data
- **Retention Policies**: Configurable data retention periods
- **User Control**: Users can delete their own data
- **Privacy by Design**: Security built into architecture

**Security Best Practices**
- **Fail-Closed**: Security failures deny access (never fail-open)
- **Defense in Depth**: Multiple security layers (network, application, data)
- **Regular Updates**: Security patches applied promptly
- **Security Monitoring**: Continuous monitoring of security events
- **Incident Response**: Procedures for security incident handling

---

## Integration Points

### Fleet Manager -> Servers

1. **SSH Provisioning**
   - Full automated server setup via SSH/SFTP
   - Package installation, config deployment, app deployment
   - SSL certificate setup

2. **Package Deployment**
   - Deploys versioned Panel, Email App, and Agent packages
   - Build from source or upload pre-built packages
   - Idempotent config deployment with hash tracking

3. **Agent Communication**
   - Fleet Agent on each server reports health via heartbeat
   - Task queue for remote operations (start/progress/complete/fail)
   - Error and issue reporting back to Fleet Manager
   - X-Agent-Token authentication

4. **Blueprint System**
   - Snapshot running servers to create blueprints
   - Extract and templatize configurations
   - Variable detection for server-specific values

### Panel -> Email App

1. **Storage Configuration API**
   - Email App queries Panel for NAS config
   - Per-domain storage routing
   - Automatic fallback to local storage
   - 5-minute caching

2. **Shared Database**
   - Same MariaDB server
   - Mail accounts managed by Panel
   - DNS records for email domains

3. **VPN Tunnel**
   - OpenVPN to home NAS
   - NFS mount for Drive storage
   - Encrypted data transfer

### Email App -> Panel

1. **User Authentication**
   - Dovecot user database
   - Password managed via Panel

2. **Mail Configuration**
   - Domain settings
   - DKIM keys
   - Filters/Sieve rules

3. **Addon Status**
   - Email App queries Panel for all addon toggles (CRM Pro, Boards, Board Pro, Chat, Calendar, Project Hub, Search, AI, News Reader, etc.)
   - Per-user and per-group override resolution (user override > group override > global)
   - Cached in Redis for 5 minutes, auto-refreshes on tab focus
   - Features gated at both frontend route guards (`meta: { requiresAddonName }`) and backend middleware (`AddonService::isEnabled()`)

### Addon System

All addons are managed in the DEVCON Panel (`EmailAddonsPanel`) with global toggles, per-user overrides, and per-group overrides. The Email App fetches addon statuses via API (`GET /addons`), caches them in Redis (5-minute TTL), and auto-refreshes on tab focus. Frontend routes use `meta: { requiresAddonName: true }` guards, backend checks via `AddonService::isEnabled()`.

| Addon | Slug | Features Unlocked |
|-------|------|-------------------|
| **CRM Pro** | `crm_pro` | Deal pipeline, invoices, expenses, automation, sequences, reports, tags, custom fields, reminders, call log, meeting notes, dashboard, billing integration, client portal |
| **Kanban Boards** | `kanban_boards` | Trello-style boards, lists, cards, labels, checklists, attachments, email linking, progress reports, milestones, financials, activity log |
| **Board Pro** | `board_pro` | Email auto-link rules, card financials, board automations, multi-lens views, AI features (summarize, risk, estimate, draft), executive reports, advanced permissions |
| **Mood Boards** | `moodboards` | Infinite canvas, sticky notes, images, image sets, color swatches, drawings, shapes, tables, frames, presentation mode, ambient motion, public sharing, components |
| **Chat & Calls** | `chat` | DMs, group chat, channels, voice/video calls (WebRTC), huddles, webhooks, file sharing, scheduled messages, rich embeds, view together, external invitations |
| **Calendar** | `calendar` | Multiple calendars, events, recurring events, Google Calendar sync, Microsoft Calendar sync, ICS subscriptions, event invitations, quick add |
| **My Tasks** | `tasks` | Personal task management with priorities, subtasks, email-to-task conversion, board card conversion, todo panel |
| **Email Marketing** | `email_marketing` | Mailing lists, email campaigns, bulk sending, rate limiting, progress tracking, pause/resume, retry, failed tracking |
| **Team Management** | `team` | Colleagues, groups, sick status tracking, folder/board/calendar sharing, team collaboration, presence status |
| **Time Tracker** | `time_tracker` | Per-client time tracking, website time tracking, activity breakdowns, time statistics, board integration, billable hour reports |
| **Email Tracking** | `email_tracking` | Read receipt tracking with pixel insertion, open notifications, read time analytics, per-recipient tracking |
| **Reactions** | `reactions` | Outlook-style emoji reactions on emails, reaction display badges, incoming reaction detection, reaction notifications |
| **AI Assistant** | `ai_assistant` | AI-powered email summaries, text rewriting, draft reply generation, AI usage analytics |
| **Automation Hub** | `automation_hub` | Visual workflow automation engine with triggers, actions, conditions, server monitoring, Telegram bot integration, scheduled tasks |
| **Universal Search** | `universal_search` | Meilisearch-powered cross-module search, quick autocomplete, AI answers, filter operators, background indexing, attachment/body content search |
| **Project Hub** | `project_hub` | Full project management (requires Kanban Boards): spaces/folders, tasks & subtasks, time budgeting, workload planner, director dashboard, card dependencies, role management, public card sharing, calendar bridge, watch folders, inactivity alerts |
| **News Reader** | `news_reader` | RSS/Atom + YouTube feed reader, curated feed catalog, full-article extraction, live market/financial data |

---

## Tech Stack

### Backend

| Component | Technology |
|-----------|------------|
| Email App Backend | PHP 8.3, Custom Router |
| VPS Admin Backend | PHP 8.3, Custom Router |
| VPS Agent | PHP (systemd service) |
| Fleet Manager API | PHP 8.1+, Custom Router, phpseclib (SSH/SFTP) |
| Fleet Agent | PHP (heartbeat client, Unix socket) |
| Collab Server | Node.js, Y.js, WebSocket |
| Mailsync Realtime Worker | Node.js (`ws`, `ioredis`, `imapflow`) — IMAP IDLE + Redis pub/sub, port 1235 |
| Realtime Calls | LiveKit SFU (voice / video / huddles) + Coturn (TURN/STUN, HMAC) |
| Storage Health Daemon | `flowone-storage-monitord` (HMAC-signed NAS health state) |
| Database | MariaDB |
| Search Engine | Meilisearch (Rust, LMDB internally) with MySQL fulltext fallback |
| Cache | Redis (optional, but required for real-time + tiering kill-switch) |

### Frontend

| Component | Technology |
|-----------|------------|
| Email App | Vue 3, Vite, Tailwind CSS, Pinia |
| VPS Panel | Vue 3, Vite, Tailwind CSS, Pinia |
| Fleet Manager | Vue 3, Vite, Tailwind CSS, Pinia, CodeMirror 6 |
| Desktop Client | Electron, Vue 3, TypeScript |

### Server Software

| Service | Version |
|---------|---------|
| Web Server | OpenLiteSpeed |
| Database | MariaDB |
| SMTP | Postfix |
| IMAP | Dovecot |
| DNS | PowerDNS |
| Firewall | FirewallD |
| IDS | Fail2ban |
| WAF | ModSecurity |
| Scanner | CPGuard |
| Search Engine | Meilisearch (Rust, LMDB) |
| Cache | Redis |
| Realtime SFU | LiveKit (voice/video/huddles) |
| TURN/STUN | Coturn |
| PHP | 8.3 |

---

## Deployment

### Fleet Manager
- **Manages deployment of**: Panel, Email App, and Agent packages to all servers
- **Package directory**: `packages/panel/`, `packages/email/`, `packages/agent/`
- **Build scripts**: `packages/{type}/build.sh`
- **Install scripts**: `packages/{type}/install.sh`

### VPS Admin Panel
- **Production**: `/var/www/vps-admin/`
- **Agent Service**: `vpsadmin-agent.service`
- **URL**: `panel.devcon1.hu`
- **Version File**: `/var/www/vps-admin/VERSION`

### Email App
- **Staging**: `/home/email.devcon1.hu/public_html/`
- **Production**: `/var/www/vps-email/`
- **URL**: `email.devcon1.hu`
- **Deploy**: `./copy-email.sh`
- **Version File**: `/var/www/vps-email/VERSION`

### Fleet Agent
- **Install path**: `/opt/fleet-agent/`
- **Communication**: Unix socket at `/run/fleet-manager/agent.sock`
- **Auth**: X-Agent-Token header

### Background Cron Jobs

The Email App runs ~30 scheduled PHP workers under `email/backend/cron/` (all loaded via `cron/bootstrap.php`). Long-running workers self-loop within a time budget and use `flock` / Redis advisory locks to prevent overlapping runs; most accept `--once`, `--verbose`, `--dry-run`, and `--json` flags for manual operation.

**Mail sync & delivery**

| Schedule | Job | Description |
|----------|-----|-------------|
| `*/5 * * * *` | `sync-mailbox.php` | IMAP→DB mirror cache-warmer (self-loops ~240 s): folder registration, initial/incremental envelope paging, expunge sweep, UIDVALIDITY reset, new-mail fan-out. **Not** a read-state reconciler. |
| `* * * * *` | `drain-outbox.php` | Drains the `imap_outbox` durable write queue (mark-read / move / delete / rename) to IMAP (self-loops ~55 s). |
| `* * * * *` | `process-scheduled-emails.php` | Sends scheduled emails whose time has passed (SMTP, with retries). |
| `* * * * *` | `process-email-queue.php` | Email-marketing campaign queue with per-user rate limits (100/hr, 500/day). |
| `*/15 * * * *` | `sync-sieve-ooo.php` | Disables expired Out-of-Office schedules and regenerates the unified Dovecot Sieve script. |

**Search & indexing**

| Schedule | Job | Description |
|----------|-----|-------------|
| `17 */1 * * *` | `index-meilisearch.php` | Full-mailbox Meilisearch backfill (resumable via `last_indexed_uid`). |
| `*/5 * * * *` | `index-attachments.php` | Extracts full-text content from PDF / Word / text attachments for search. |
| `*/15 * * * *` | `register-attachments.php` | Populates `webmail_email_attachments` from IMAP MIME structure; self-heals false `has_attachment` flags. |

**Unread counts & OAuth tokens**

| Schedule | Job | Description |
|----------|-----|-------------|
| `*/2 * * * *` | `refresh-unread-counts.php` | Warms the Redis unread-count cache (`STATUS UNSEEN`); read-only, never mutates mail. |
| `*/15 * * * *` | `refresh-oauth-tokens.php` | Proactively refreshes OAuth tokens expiring within ~30 min (avoids sync-time thundering herd). |

**Calendar**

| Schedule | Job | Description |
|----------|-----|-------------|
| `*/5 * * * *` | `sync-google-calendars.php` | Google Calendar pull (`nextSyncToken`) + push-queue drain. |
| `0 */1 * * *` | `renew-calendar-push-channels.php` | Renews expiring Google Calendar webhook channels (stop-then-watch). |
| once | `recrypt-calendar-tokens.php` | One-shot migration: re-encrypt calendar tokens CBC → GCM (idempotent). |

**Drive / storage tiering**

| Schedule | Job | Description |
|----------|-----|-------------|
| `23 * * * *` | `drive-tier-down.php` | Hot→NAS tier-down, then (gated) destructive sweep of VPS shadows past grace. |
| `13 * * * *` | `drive-tier-backfill.php` | Reconciles `tier_state` with legacy `storage_location` (transition-window no-op once complete). |
| on-demand | `drive-recall-warm.php` | NAS→VPS recall, spawned when a user requests a cold file. |
| `*/5 * * * *` | `drive-pending-nas-migrate.php` | Replays uploads that fell back to VPS during a NAS outage. |
| `0 * * * *` | `cleanup-drive.php` | Deletes expired email-attachment files from Drive. |

**Automations, CRM & Project Hub**

| Schedule | Job | Description |
|----------|-----|-------------|
| `* * * * *` | `process-automation-hub.php` | Automation Hub engine: schedule + server-health triggers, delayed-node resume, stale-execution purge. |
| `*/5 * * * *` | `process-boardpro-automation.php` | Board Pro time-based triggers (overdue / idle cards). |
| `*/5 * * * *` | `process-crm-automation.php` | CRM rule evaluation + email-sequence step processing. |
| `0 9 * * *` | `process-scope-radar.php` | Daily scope-creep detection across boards (Board Pro). |
| `30 7 * * *` | `run-projecthub-inactivity.php` | Daily inactivity alerts for stale Project Hub cards (default 90 days). |
| `* * * * *` | `process-scheduled-chat.php` | Delivers chat messages whose `scheduled_at` has passed. |

**Folder identity system (Wave 2)**

| Schedule | Job | Description |
|----------|-----|-------------|
| `* * * * *` | `folder-rename-analyzer.php` | Diffs folder snapshots to detect IMAP renames, keeping `folder_id` stable. |
| `17 */6 * * *` | `backfill-folder-ids.php` | Assigns canonical UUIDv7 `folder_id` to legacy NULL rows. |
| `23 * * * *` | `prune-folder-snapshots.php` | Prunes old `webmail_folder_snapshots` (keep 2 + retention window). |
| `25 2 * * *` | `verify-folder-identity-consistency.php` | Read-only nightly drift detector (no auto-repair). |

**Monitoring, reporting & maintenance**

| Schedule | Job | Description |
|----------|-----|-------------|
| `30 2 * * *` | `all-mail-coverage-report.php` | Parses `[ALLMAIL]` log lines for degradations; emails admin only on issues. |
| `5 2 * * 0` | `dual-write-readiness.php` | Weekly post-cutover regression guard (invariant violations + resolve counters). |
| `0,15,30,45 * * * *` | `news-refresh.php` | Refreshes RSS/Atom + YouTube feeds; prunes old items. |
| periodic | `cleanup-stale-rooms.php` | Marks LiveKit rooms empty >15 min as ended (`portal_calls`). |
| `0 3 * * *` | `security-scan.sh` | Daily dependency/security scan. |

> Developer/utility scripts (not scheduled): `test-email-rules.php` (Board Pro email-to-card rule tester), `bootstrap.php` (shared loader).

---

## In-App Guidance & User Education

Every module in the platform includes built-in explanations, interactive guides, and feature discovery aids designed to make the system self-explanatory. Users never need external documentation -- everything is explained in context, right where they need it.

### Feature Guide Panels

A reusable collapsible panel (`FeatureGuide`) appears on every major module view, listing features organized by addon tier with integration highlights. Dismissable per user (stored in localStorage), these panels serve as a quick reference for what each module can do.

| Module | Features Listed |
|--------|----------------|
| **Drive** | File storage, folder sharing, versioning, NAS integration, ZIP archives, thumbnails |
| **Clients** | Contact management, email history, time tracking, linked boards, drive folders, mind maps, health scores |
| **Calendar** | Multiple calendars, Google/Microsoft sync, ICS subscriptions, invitations, quick add |
| **Time Tracker** | Per-client tracking, website tracking, activity breakdown, board integration, reports |
| **Mood Boards** | Infinite canvas, item types, connections, layers, alignment, presentation mode, sharing |
| **Team** | Auto-sync colleagues, groups, profiles, presence, group permissions, directory |
| **Chat** | DMs, groups, channels, calls, huddles, webhooks, embeds, scheduled messages, view together |
| **Email Marketing** | Mailing lists, campaigns, bulk sending, progress tracking, pause/resume, retry |
| **Automation Hub** | Visual workflows, triggers, actions, conditions, server monitoring, Telegram, scheduled tasks |
| **CRM Pipeline** | Deal pipeline, stages, activity feed, stage history, velocity metrics, forecast |
| **CRM Invoices** | Invoice CRUD, line items, PDF, email, billing provider integration, payment recording |
| **CRM Dashboard** | Revenue reports, pipeline reports, health reports, aging, ranking, profitability, forecast, funnel |
| **Financials** | Multi-currency, timeline view, client breakdown, board breakdown, date range filtering |
| **Boards** | Kanban boards, cards, labels, checklists, email linking, progress reports, milestones |

### Step-by-Step Guides

Interactive modal-based walkthroughs (`StepGuide`) walk users through complex features step by step. Each guide uses a numbered progression with clear descriptions and visual cues.

| Module | Steps | What It Explains |
|--------|-------|-----------------|
| **Drive** | Multi-step | File management, sharing, versioning |
| **Clients** | Multi-step | Client creation, contacts, email history, time tracking |
| **Calendar** | Multi-step | Calendar setup, event creation, sync configuration |
| **Time Tracker** | Multi-step | Starting timers, activity types, reports |
| **Mood Boards** | Multi-step | Canvas basics, item types, connections, sharing |
| **Team** | Multi-step | Colleague sync, groups, sharing, presence |
| **Chat** | Multi-step | DMs, groups, channels, calls, webhooks |
| **Automation Hub** | Multi-step | Workflow creation, triggers, actions, scheduling |
| **Mailing Lists** | Multi-step | List creation, contact import, sending |
| **Campaigns** | Multi-step | Campaign creation, recipient selection, monitoring |
| **CRM Pipeline** | Multi-step | Pipeline stages, deal creation, tracking |
| **CRM Invoices** | Multi-step | Invoice creation, line items, sending, payments |
| **CRM Dashboard** | Multi-step | Report types, date ranges, export |
| **Boards** | Multi-step | Board creation, lists, cards, collaboration |

### Automation Guides (Dedicated)

Complex automation features have their own dedicated multi-step explanation guides:

| Guide | Steps | Target Module | What It Explains |
|-------|-------|---------------|-----------------|
| **Board Automation Guide** | 7 steps | Board Pro | How board automations work: triggers, actions, deduplication, chain depth limits, time-based evaluation |
| **CRM Automation Guide** | 9 steps | CRM Pro | How CRM automations work: event triggers, time-based triggers, action types, cross-module actions, test firing, execution log |
| **Email Rules Guide** | 7 steps | Board Pro | How email auto-link rules work: matching by subject/sender/domain, routing emails to cards automatically |
| **CRM Sequences Guide** | 7 steps | CRM Pro | How email drip sequences work: multi-step campaigns, delays, enrollment, stage-based triggers, template variables |
| **Workflow Guide** | 7 steps | Board Pro + CRM | How Project-to-Milestone-to-Invoice workflow works when both Board Pro and CRM Pro are enabled |

### Automation Rule Templates

Both CRM Pro and Board Pro include pre-built one-click automation rule templates organized by category. These let users create common automations instantly without understanding the full trigger/action configuration system first. Each template pre-fills the trigger type, action type, and configuration -- users just click to create and optionally customize.

**CRM Pro Templates** (5 categories, 15 templates): Deal Management, Client Management, Cross-Feature, Team, Email Tracking

**Board Pro Templates** (4 categories, 11 templates): Notifications, Workflow, Billing, Communication

### Search Filter Help

The Universal Search modal includes an interactive filter help popup that explains all available search operators with click-to-insert examples. Users can click any example to instantly apply it to their current search query.

### AI Search Examples

The Universal Search AI toggle includes a hover popup with real-world example queries (in the user's language) showing what kind of questions the AI can answer from their email and file data.

### Tooltips & Inline Help

Throughout the application, contextual help is provided via:
- **Hover tooltips** on buttons, badges, and status indicators explaining what they do
- **Info buttons** (?) that expand to show detailed explanations
- **Empty state guidance** showing what a feature does when no data exists yet
- **Keyboard shortcut hints** displayed inline (e.g., ESC, Enter, Ctrl+K)
- **Search engine badges** showing which search backend is being used (Meilisearch/MySQL)
- **Index statistics tooltips** showing exactly how many items are indexed per type

---

## Ease of Use

### For End Users
- **Modern UI**: Clean Tailwind CSS design with dark mode
- **Mobile Responsive**: Works on all devices
- **Intuitive Navigation**: Sidebar with Google Material icons
- **Keyboard Shortcuts**: Power user support
- **Drag & Drop**: Files, cards, emails
- **Self-Explanatory**: Every module has built-in feature guides, step-by-step walkthroughs, and contextual tooltips
- **Automation Made Easy**: Pre-built one-click automation templates with interactive guides -- no configuration knowledge required
- **Progressive Onboarding**: Three-tier onboarding system (Beginner/Intermediate/Advanced) with flow diagrams and quizzes

### For Administrators
- **No Shell Access Needed**: All management via GUI
- **One-Click Operations**: Restart services, issue SSL, create backups
- **AI Assistance**: Ask questions about server issues
- **Visual Editors**: Config files, cron jobs, firewall rules
- **Audit Trail**: Every action logged

### For Developers
- **Modular Architecture**: Separate controllers/services
- **Clean API**: RESTful endpoints
- **Full Documentation**: Inline docs, setup guides
- **Git-Friendly**: Clean structure, no vendor lock-in

---

## Key Differentiators

1. **Self-Hosted**: Complete data ownership
2. **No Per-Seat Fees**: Unlimited users
3. **Integrated Ecosystem**: Email + Chat + Files + Projects + CRM
4. **Security-First Design**: No shell exposure, action-based API
5. **NAS Integration**: Use your own storage
6. **AI-Powered**: GPT assistance for users, admins, and fleet management
7. **Real-Time Collaboration**: Google Docs-like editing + Team Chat
8. **Desktop Sync**: Full sync client like Dropbox/OneDrive
9. **Team Management**: Colleagues, groups, presence status
10. **Marketing Tools**: Mailing lists with import/export
11. **Fleet Management**: Centralized provisioning, blueprints, and monitoring across servers
12. **Modular Addons**: 15 toggleable addons (CRM Pro, Boards, Chat, Calendar, Search, AI, etc.) - enable only what you need
13. **Meilisearch-Powered Search**: Sub-millisecond universal search with typo tolerance, AI answers, and MySQL fallback
14. **User Onboarding**: Interactive tours and quizzes for smooth adoption
15. **Device Security**: Device registry with remote wipe and blocking
16. **Self-Explanatory UI**: Every module has built-in feature guides, step-by-step walkthroughs, automation templates, and contextual tooltips -- zero external documentation needed

---

## Multilingual Support

Landing page available in:
- English, German, Hungarian, Romanian
- Croatian, Slovak, Slovenian, Czech, Polish
- Ukrainian, Serbian, Bulgarian
- French, Italian, Spanish, Portuguese
- Dutch, Danish, Swedish, Norwegian, Finnish, Greek

---

## Summary

This ecosystem provides everything needed to run a complete business communication infrastructure:

| Layer | Solution |
|-------|----------|
| **Fleet Management** | Fleet Manager (provisioning, blueprints, deployments, monitoring) |
| **Server Management** | DEVCON Panel |
| **User Management** | Multi-role admin users with site assignment |
| **VPN Management** | OpenVPN connection management |
| **Cache Management** | Redis monitoring and invalidation |
| **Database Access** | Secure phpMyAdmin with token auth |
| **Migration Tools** | DNS and Mail migration from CyberPanel |
| **Security Scanning** | Dependency vulnerability scanning |
| **Email** | MailFlow Webmail |
| **Scheduled Emails** | Compose now, send later |
| **Team Chat** | Direct & Group Messaging, Channels, Webhooks |
| **Voice & Video** | WebRTC Calls, Huddles, Meetings |
| **File Storage** | MailFlow Drive + Desktop Client |
| **Documents** | Collaborative Editor |
| **Projects** | Boards (Kanban) + Board Pro (financials, automation, AI) |
| **Visual Design** | Mood Boards (Infinite Canvas) |
| **CRM** | Clients, Contacts, Health Scores |
| **CRM Pro** | Pipeline, Deals, Invoices, Automation, Sequences |
| **Client Portal** | External portal with updates, e-signing, video calls |
| **Billing Integration** | Billingo / Szamlazz.hu invoice sync |
| **Financials** | Multi-currency revenue & expense tracking |
| **Scheduling** | Calendar with Google & Microsoft sync |
| **Email Marketing** | Campaigns + Templates + Drip Sequences |
| **Mailing Lists** | External Contact Management |
| **Team Directory** | Colleagues & Groups |
| **Auto-Reply** | Out of Office System |
| **Onboarding** | Interactive tours, quizzes, progress tracking |
| **Device Management** | Device registry, remote wipe, blocking |
| **In-App Feedback** | Screenshot-assisted feedback submission |
| **In-App Guidance** | Feature guides, step-by-step walkthroughs, automation templates, tooltips on every module |
| **Search** | Meilisearch-powered universal search (LMDB) with MySQL fallback, AI answers, filter operators |
| **Notifications** | Web Push + In-app |
| **Security** | Integrated across all layers |
| **System Health** | Application health monitoring and repair |
| **AI** | Assistance for users, admins, and fleet management |

All running on your own infrastructure with full data sovereignty.

---

## API Endpoints Summary

### Email App - 500+ endpoints including:
- `/auth/*` - Authentication, 2FA, OAuth
- `/mailbox/*` - Folders, messages, search, threads
- `/messages/*` - Send, reply, forward, drafts, scheduled
- `/drive/*` - Files, folders, sharing, versions
- `/calendar/*` - Events, invitations, sync
- `/boards/*` - Boards, lists, cards, labels
- `/board-pro/*` - Email auto-link rules, card financials, AI features, reports
- `/board-pro/boards/{id}/automations` - Board automation rules CRUD, board-level execution log
- `/board-pro/automations/{id}` - Update/delete rules, per-rule execution log
- `/mood-boards/*` - Mood boards, items, connections, members, sharing, components
- `/clients/*` - CRM, contacts, time tracking, tags, custom fields, portal management
- `/financials/*` - Revenue tracking, multi-currency analytics
- `/contacts/*` - Autocomplete, import
- `/filters/*` - Sieve rules
- `/labels/*` - Email labels
- `/todos/*` - Task management
- `/ai/*` - Summarize, rewrite, draft
- `/statistics/*` - Usage analytics
- `/chat/*` - Conversations, messages, reactions, typing, channels, huddles, scheduled, webhooks
- `/call/*` - WebRTC calls, ICE servers, call history
- `/colleagues/*` - Team members, groups, profiles, sync
- `/mailing-lists/*` - Contact lists, import, recipients
- `/email-templates/*` - Reusable email templates
- `/email-queue/*` - Bulk email campaign queue
- `/search/*` - Meilisearch-powered universal search, quick autocomplete, index management, AI answers
- `/push/*` - Web push notification subscriptions
- `/settings/ooo/*` - Out of office configuration
- `/onboarding/*` - Tours, quizzes, progress tracking
- `/devices/*` - Device registry, wipe, block
- `/feedback/*` - In-app feedback submission
- `/system/*` - Health checks, permission management
- `/crm/deals/*` - Deal pipeline, stages, activity
- `/crm/invoices/*` - Invoice CRUD, PDF, payments, send
- `/crm/expenses/*` - Expense tracking
- `/crm/tags/*` - Tag management, client tagging
- `/crm/custom-fields/*` - Custom field definitions and values
- `/crm/fields/*` - Field management
- `/crm/reminders/*` - Reminder CRUD, completion
- `/crm/dashboard` - CRM dashboard metrics
- `/crm/reports/*` - Revenue, pipeline, health, aging, ranking, profitability, forecast, funnel
- `/crm/automation/*` - Automation rules, toggle, execution log
- `/crm/sequences/*` - Email sequences, enrollment, cancellation
- `/billing/*` - Billing provider settings, push, PDF download, status sync
- `/portal/*` - Client portal auth, updates, documents, e-signing, calls
- `/clients/{id}/portal/*` - Internal portal management (grant/revoke access, updates, documents, calls)
- `/clients/{id}/call-log` - Call logging
- `/clients/{id}/meeting-notes` - Meeting notes
- `/clients/{id}/timeline` - Unified client timeline
- `/webhook/{token}` - Incoming webhooks (public)

### VPS Admin Panel - 200+ endpoints including:
- `/api/auth/*` - Authentication, 2FA, sessions
- `/api/sites/*` - Virtual hosts, configs
- `/api/ssl/*` - Certificates, Let's Encrypt
- `/api/databases/*` - MySQL management
- `/api/mail/*` - Domains, accounts, DKIM
- `/api/dns/*` - Zones, records
- `/api/fail2ban/*` - Jails, bans
- `/api/firewall/*` - Rules, ports
- `/api/backups/*` - Full backup system
- `/api/services/*` - Service management
- `/api/system/*` - Server configuration
- `/api/wordpress/*` - WP management
- `/api/docker/*` - Container management
- `/api/files/*` - File manager
- `/api/nas/*` - NAS storage
- `/api/vpn/*` - VPN connections, start/stop/restart, status, config files
- `/api/ai-helper/*` - AI diagnostics
- `/api/users/*` - User CRUD, roles, site assignment
- `/api/cache/*` - Redis stats, flush, selective invalidation
- `/api/phpmyadmin/*` - Token-based phpMyAdmin access
- `/api/agent/*` - Agent diagnostics, restart, handler status
- `/api/security/scans/*` - Dependency vulnerability scanning
- `/api/dns-migration/*` - DNS migration phases, verify, rollback
- `/api/mail-migration/*` - Mail migration phases, verify, rollback

### Fleet Manager - 80+ endpoints including:
- `/api/auth/*` - Authentication, 2FA, sessions
- `/api/servers/*` - Server CRUD, health, tasks, credentials, audit, reports, issues
- `/api/blueprints/*` - Blueprint CRUD, extract, templates, packages, variable detection
- `/api/deployments/*` - Deploy, preview, diff, rollback, batch, cancel, logs
- `/api/packages/*` - Package upload, build, versioning, download
- `/api/agent/*` - Heartbeat, errors, progress, config, task lifecycle
- `/api/ai-helper/*` - Conversations, log/config analysis
- `/api/system/*` - Health, migrations, self-check, bootstrap, snapshots

---

*Last updated: February 2026*

