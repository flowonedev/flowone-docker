# DEVCON Ecosystem - Complete Product Audit

*Last Updated: January 2026*

---

## Executive Summary

The DEVCON ecosystem consists of three interconnected products that together provide a complete self-hosted business infrastructure solution:

1. **MailFlow** - Business email + productivity suite
2. **DEVCON Panel** - VPS/Server administration panel
3. **Fleet Manager** - Multi-server deployment & management

This audit documents every feature across all three products to inform the landing page rebuild.

---

## Product 1: MailFlow (Email App)

**URL:** email.devcon1.hu  
**Stack:** PHP 8.3 backend, Vue 3 frontend, MariaDB  
**Purpose:** Replace SaaS email (Gmail Workspace, Outlook 365) with self-hosted alternative

### 1.1 Email Core

| Feature | Description | Status |
|---------|-------------|--------|
| **IMAP/SMTP Support** | Full Postfix/Dovecot integration, works with any IMAP server | Complete |
| **Conversation Threading** | Smart thread grouping with database-backed indexing | Complete |
| **Multi-Account** | Multiple email accounts in one unified inbox | Complete |
| **Email Tracking** | Pixel-based open/read notifications with timestamps | Complete |
| **Email Reactions** | Outlook-style emoji reactions on emails | Complete |
| **Server-Side Filters** | Sieve filter support with visual drag-drop editor | Complete |
| **Spam Management** | Block/safe senders, spam reporting, SpamAssassin integration | Complete |
| **Labels & Folders** | Custom labels with colors, nested folders | Complete |
| **Bulk Actions** | Mass move, delete, label, archive operations | Complete |
| **Rich Text Compose** | Full HTML editor with inline images, attachments | Complete |
| **Unsubscribe Detection** | Auto-detect and one-click unsubscribe from newsletters | Complete |
| **Email Signatures** | HTML signatures per account | Complete |
| **Drafts** | Auto-save drafts with recovery | Complete |
| **Search** | Full-text search across all emails and attachments | Complete |
| **Keyboard Shortcuts** | Power user keyboard navigation | Complete |

### 1.2 Drive (Cloud Storage)

| Feature | Description | Status |
|---------|-------------|--------|
| **File Storage** | Upload, download, organize files in folders | Complete |
| **Folder Sharing** | Share folders with collaborators (view/edit permissions) | Complete |
| **Public Links** | Password-protected share links with expiry | Complete |
| **File Versioning** | Track and restore previous versions | Complete |
| **NAS Integration** | Store files on home NAS via VPN tunnel | Complete |
| **Trash & Restore** | Soft delete with 30-day recovery | Complete |
| **ZIP Archives** | Create/download archives, large file splitting | Complete |
| **Thumbnails** | Auto-generated image previews | Complete |
| **Drag & Drop** | Drag files into folders, emails | Complete |
| **Storage Quotas** | Per-user storage limits | Complete |

### 1.3 FlowOneDrive (Desktop Client)

| Feature | Description | Status |
|---------|-------------|--------|
| **Electron App** | Cross-platform Windows/Mac/Linux | Complete |
| **Two-Way Sync** | Bi-directional file synchronization | Complete |
| **System Tray** | Background operation with tray icon | Complete |
| **Conflict Resolution** | Handle simultaneous edits gracefully | Complete |
| **Shared Notifications** | Desktop notifications for shared activity | Complete |
| **Selective Sync** | Choose which folders to sync | Planned |

### 1.4 Collaborative Editing

| Feature | Description | Status |
|---------|-------------|--------|
| **Documents** | Google Docs-style real-time text editing | Complete |
| **Presentations** | Slide editor with themes | Complete |
| **Y.js CRDT** | Conflict-free real-time collaboration | Complete |
| **WebSocket Server** | Node.js presence and awareness server | Complete |
| **Version History** | Named snapshots with visual compare | Complete |
| **Comments** | Inline commenting with resolution | Complete |
| **Permissions** | Owner/Editor/Viewer access levels | Complete |

### 1.5 Calendar

| Feature | Description | Status |
|---------|-------------|--------|
| **Multiple Calendars** | Color-coded calendars per category | Complete |
| **Event Management** | Create, edit, delete events | Complete |
| **Recurring Events** | Daily, weekly, monthly, yearly patterns | Complete |
| **Google Sync** | Two-way sync with Google Calendar | Complete |
| **Microsoft Sync** | Two-way sync with Outlook/Office 365 | Complete |
| **ICS Subscriptions** | Subscribe to public calendar feeds | Complete |
| **Event Invitations** | Send/accept/decline meeting invites | Complete |
| **Quick Add** | Natural language event creation | Complete |
| **Day/Week/Month Views** | Multiple calendar view options | Complete |

### 1.6 Boards (Project Management)

| Feature | Description | Status |
|---------|-------------|--------|
| **Kanban Boards** | Trello-style boards with lists and cards | Complete |
| **Cards** | Title, description, due dates, checklists | Complete |
| **Labels** | Color-coded labels per board | Complete |
| **Attachments** | Files, URLs, Drive integration | Complete |
| **Comments** | Card discussions with @mentions | Complete |
| **Member Assignment** | Assign cards to team members | Complete |
| **Email Linking** | Create cards from emails, link back to threads | Complete |
| **Progress Reports** | Auto-generated project status updates | Complete |
| **Milestones** | Financial tracking per milestone | Complete |
| **Activity Log** | Full audit trail of all changes | Complete |
| **Drag & Drop** | Drag cards between lists/boards | Complete |
| **Due Date Reminders** | Notifications for upcoming/overdue cards | Complete |

### 1.7 Clients (CRM)

| Feature | Description | Status |
|---------|-------------|--------|
| **Auto-Discovery** | Contacts auto-extracted from email domains | Complete |
| **Email History** | All conversations with each client | Complete |
| **Time Tracking** | Track time spent per client | Complete |
| **Linked Boards** | Associate projects with clients | Complete |
| **Drive Folders** | Client-specific file storage | Complete |
| **Mind Maps** | Visual relationship mapping | Complete |
| **Financial Tracking** | Client revenue and billing tracking | Complete |
| **Activity Log** | Complete client timeline | Complete |
| **Team Members** | Track who works with each client | Complete |
| **Signature Extraction** | Auto-extract contact details from signatures | Complete |
| **Status Indicators** | Active/Waiting/Needs Attention flags | Complete |

### 1.8 Time Tracking

| Feature | Description | Status |
|---------|-------------|--------|
| **Per-Client Tracking** | Clock time per client | Complete |
| **Website Tracking** | Track time on websites via desktop client | Complete |
| **Time Breakdown** | Reports by activity type | Complete |
| **Board Integration** | Track time per card/project | Complete |
| **Statistics** | Daily/weekly/monthly reports | Complete |

### 1.9 Todo System

| Feature | Description | Status |
|---------|-------------|--------|
| **Quick Tasks** | Create tasks with due dates | Complete |
| **Email Integration** | Create todos from emails | Complete |
| **Priority Ordering** | Drag to reorder priorities | Complete |
| **Checklist Support** | Sub-tasks within todos | Complete |
| **Recurring Tasks** | Repeating task patterns | Complete |

### 1.10 AI Features

| Feature | Description | Status |
|---------|-------------|--------|
| **Email Summarization** | Condense long threads into key points | Complete |
| **Reply Drafting** | AI-suggested response drafts | Complete |
| **Text Rewriting** | Improve/reformat text in compose | Complete |
| **OpenAI Integration** | GPT-4 powered (user provides API key) | Complete |

### 1.11 Statistics Dashboard

| Metric | Tracked |
|--------|---------|
| **Emails** | Sent, received, by time period |
| **Conversations** | Active threads, response times |
| **Contacts** | Top contacts, growth trends |
| **Folders** | Size, message counts |
| **Tasks** | Completion rates, overdue |
| **Calendar** | Events, busy time analysis |
| **Drive** | Storage usage, file types |
| **Boards** | Card completion, velocity |
| **Clients** | Revenue, activity levels |
| **Time** | Hours by client/project |

### 1.12 Security

| Feature | Description | Status |
|---------|-------------|--------|
| **Two-Factor Auth** | TOTP authenticator app support | Complete |
| **Session Management** | View and revoke active sessions | Complete |
| **Trusted Devices** | Skip 2FA on trusted devices | Complete |
| **OAuth Login** | Google/Microsoft single sign-on | Complete |
| **Backup Codes** | Recovery codes for lost 2FA | Complete |

### 1.13 Universal Search

| Feature | Description | Status |
|---------|-------------|--------|
| **Cross-Module Search** | Search emails, files, boards, clients at once | Complete |
| **Instant Results** | Fast typeahead with previews | Complete |
| **Filters** | Filter by type, date, sender | Complete |

---

## Product 2: DEVCON Panel (VPS Admin)

**URL:** panel.devcon1.hu  
**Stack:** PHP 8.3 backend, Vue 3 frontend, Local Agent (systemd)  
**Purpose:** Replace cPanel/Plesk with modern, secure server management

### 2.1 Core Philosophy

- **Security First:** No shell exposure, no arbitrary command execution
- **Agent + UI Separation:** Privileged agent runs locally, UI communicates via secure API
- **Action-Based API:** Named, allowlisted tasks - never raw shell commands
- **Full Auditability:** Every action creates backups, diffs, and logs

### 2.2 Server Stack Management

| Service | Features | Status |
|---------|----------|--------|
| **OpenLiteSpeed** | Virtual hosts, SSL, listeners, rewrites, caching | Complete |
| **MariaDB/MySQL** | Databases, users, permissions, backups | Complete |
| **Postfix** | SMTP, mail queue, domain management, DKIM/SPF/DMARC | Complete |
| **Dovecot** | IMAP, mailboxes, connections, quotas | Complete |
| **PowerDNS** | DNS zones, records, DNSSEC | Complete |
| **Fail2ban** | Jails, bans, custom rules, IP management | Complete |
| **FirewallD** | Zones, ports, services, rich rules | Complete |
| **ModSecurity** | WAF modes, rule management, audit logs | Complete |
| **CPGuard** | Malware scanning, WAF integration | Complete |
| **PHP** | Multiple versions, extensions, php.ini management | Complete |

### 2.3 Site Management

| Feature | Description | Status |
|---------|-------------|--------|
| **Virtual Hosts** | Full CRUD with config editor | Complete |
| **SSL Certificates** | Auto-issue via Let's Encrypt, preflight checks | Complete |
| **SSL Monitoring** | Expiry alerts, auto-renewal | Complete |
| **Database Linking** | Per-site database assignment, size tracking | Complete |
| **SSH Keys** | Per-site key management | Complete |
| **Site Validation** | Detect and fix common misconfigurations | Complete |
| **Site Cloning** | Clone entire sites with databases | Complete |

### 2.4 Backup System

| Backup Type | Features | Status |
|-------------|----------|--------|
| **Site Backups** | Files + Database combined archives | Complete |
| **Config Backups** | All server configs with selective restore | Complete |
| **Email Backups** | Per-domain mail archiving | Complete |
| **Database Backups** | Individual DB dumps with compression | Complete |
| **NAS Remote** | Push backups to NAS via VPN | Complete |
| **Scheduled Backups** | Cron-based automated backups | Complete |
| **Backup Inspection** | Preview contents before restore | Complete |
| **Retention Policies** | Automatic cleanup of old backups | Complete |

### 2.5 Security Features

| Feature | Description | Status |
|---------|-------------|--------|
| **Two-Factor Auth** | TOTP authenticator app | Complete |
| **Session Management** | Revoke sessions remotely | Complete |
| **Role-Based Access** | super_admin, admin, user roles | Complete |
| **IP Banning** | Manual and auto-ban via Fail2ban | Complete |
| **Firewall Management** | Visual rich rule editor | Complete |
| **ModSecurity WAF** | Rule customization, whitelist/blacklist | Complete |
| **Audit Logging** | Every action logged with user/timestamp | Complete |

### 2.6 AI Helper

| Feature | Description | Status |
|---------|-------------|--------|
| **Server Diagnostics** | GPT-powered issue analysis | Complete |
| **Conversation Mode** | Multi-turn troubleshooting sessions | Complete |
| **Config Analysis** | Review and suggest config improvements | Complete |
| **Log Analysis** | Parse error logs for root causes | Complete |
| **Issue Caching** | Remember past issues and solutions | Complete |
| **Dry-Run Preview** | Preview commands before execution | Complete |

### 2.7 NAS Storage Management

| Feature | Description | Status |
|---------|-------------|--------|
| **NFS Connections** | Mount NAS via VPN tunnel | Complete |
| **Per-Domain Overrides** | Route storage by email domain | Complete |
| **Storage Config API** | Centralized config for Email App | Complete |
| **Health Monitoring** | Connection status checks | Complete |
| **Storage Statistics** | Usage, free space, alerts | Complete |

### 2.8 Additional Features

| Feature | Description | Status |
|---------|-------------|--------|
| **File Manager** | Web-based file browser with permissions | Complete |
| **Docker Management** | Containers, images, compose files | Complete |
| **Cron Jobs** | Visual cron editor with presets | Complete |
| **WordPress Management** | Plugins, themes, users, security scans | Complete |
| **IMAP Migration** | Transfer emails from external servers | Complete |
| **App Installer** | One-click WordPress/app installation | Complete |
| **Billing Management** | Subscriptions, payments, invoices | Complete |
| **Client Management** | Multi-tenant client tracking | Complete |
| **System Logs** | Real-time log viewer with filters | Complete |
| **VPN Management** | OpenVPN connection management | Complete |

### 2.9 Dashboard Overview

| Widget | Shows | Status |
|--------|-------|--------|
| **System Resources** | CPU, RAM, disk usage | Complete |
| **Service Status** | All services with restart buttons | Complete |
| **SSL Status** | Certificate expiry countdown | Complete |
| **Mail Status** | Queue size, recent activity | Complete |
| **DNS Status** | Zone health checks | Complete |
| **Security Alerts** | Recent bans, threats detected | Complete |
| **Recent Backups** | Last backup status and size | Complete |

---

## Product 3: Fleet Manager

**URL:** fleet.devcon1.hu  
**Stack:** PHP 8.3 backend, Vue 3 frontend, SSH/Agent communication  
**Purpose:** Deploy and manage multiple DEVCON servers at scale

### 3.1 Core Capabilities

| Feature | Description | Status |
|---------|-------------|--------|
| **One-Click Deploy** | Deploy full stack to barebone Ubuntu VPS | Complete |
| **Multi-Server Dashboard** | Manage all servers from single interface | Complete |
| **Real-Time Monitoring** | Live health metrics from all servers | Complete |
| **Blueprint System** | Reusable deployment templates | Complete |
| **Agent Communication** | Lightweight agent reports health every 60s | Complete |

### 3.2 Provisioning Engine

| Step | What It Does | Status |
|------|--------------|--------|
| **Connect** | SSH to target server | Complete |
| **System Update** | apt update/upgrade | Complete |
| **Install Dependencies** | Core packages, libraries | Complete |
| **Install OLS** | OpenLiteSpeed web server | Complete |
| **Deploy Vhosts** | Create vhost directories and configs | Complete |
| **Install PHP** | PHP 8.3 with extensions | Complete |
| **Install MariaDB** | Database server setup | Complete |
| **Install Postfix** | SMTP server | Complete |
| **Install Dovecot** | IMAP server | Complete |
| **Install Security** | Fail2ban, ModSec, CPGuard | Complete |
| **Configure Firewall** | FirewallD rules | Complete |
| **Deploy Configs** | Apply template configurations | Complete |
| **Setup Databases** | Create DBs for Panel/Email | Complete |
| **Deploy Panel** | Install DEVCON Panel | Complete |
| **Deploy Email** | Install MailFlow | Complete |
| **Install Agent** | Fleet Agent for monitoring | Complete |
| **Setup SSL** | Let's Encrypt certificates | Complete |
| **Finalize** | Service restarts, cleanup | Complete |

### 3.3 Server Management

| Feature | Description | Status |
|---------|-------------|--------|
| **Server List** | All managed servers with status | Complete |
| **Server Detail** | Deep dive into individual server | Complete |
| **Health Metrics** | CPU, RAM, disk, load averages | Complete |
| **Service Status** | All services with last check time | Complete |
| **Error Reporting** | Aggregated errors from all servers | Complete |
| **SSL Monitoring** | Certificate expiry across all servers | Complete |
| **Batch Actions** | Execute on multiple servers at once | Planned |

### 3.4 Blueprint System

| Feature | Description | Status |
|---------|-------------|--------|
| **Create Blueprint** | Define reusable configurations | Complete |
| **Template Variables** | Dynamic values (IP, domain, passwords) | Complete |
| **Service Configs** | OLS, Postfix, Dovecot, etc. templates | Complete |
| **Blueprint Library** | Pre-built blueprints for common setups | Complete |
| **Blueprint Versioning** | Track changes to blueprints | Planned |

### 3.5 Template Variables

| Variable | Description |
|----------|-------------|
| `{{SERVER_IP}}` | Server's IP address |
| `{{SERVER_HOSTNAME}}` | Server hostname |
| `{{PANEL_DOMAIN}}` | Panel domain (panel.example.com) |
| `{{EMAIL_DOMAIN}}` | Email app domain (email.example.com) |
| `{{MAIL_DOMAIN}}` | Mail domain (for email addresses) |
| `{{DB_ROOT_PASS}}` | Auto-generated database root password |
| `{{PANEL_DB_PASS}}` | Panel database password |
| `{{EMAIL_DB_PASS}}` | Email app database password |

### 3.6 Agent Features

| Feature | Description | Status |
|---------|-------------|--------|
| **Heartbeat** | Reports every 60 seconds | Complete |
| **Service Status** | OLS, MariaDB, Postfix, Dovecot status | Complete |
| **System Metrics** | CPU, memory, disk usage | Complete |
| **SSL Expiry** | Certificate expiration dates | Complete |
| **Error Collection** | Aggregates recent error logs | Complete |
| **Command Execution** | Receive and execute tasks | Complete |

### 3.7 Packages System

| Package | Description | Status |
|---------|-------------|--------|
| **Agent Package** | Standalone Fleet Agent installer | Complete |
| **Email Package** | MailFlow deployment package | Complete |
| **Fleet Package** | Fleet Manager deployment | Complete |
| **Panel Package** | DEVCON Panel deployment | Complete |

### 3.8 Security

| Feature | Description | Status |
|---------|-------------|--------|
| **JWT Authentication** | Secure API access | Complete |
| **2FA Support** | TOTP authenticator | Complete |
| **SSH Key Auth** | Password-less server access | Complete |
| **Encrypted Credentials** | Server passwords encrypted at rest | Complete |
| **Audit Logging** | All actions logged | Complete |

---

## Integration Points

### Panel <-> Email App

| Integration | Description |
|-------------|-------------|
| **Storage Config API** | Email App queries Panel for NAS config |
| **Per-Domain Routing** | Storage routing by email domain |
| **Shared Database** | Same MariaDB server |
| **Mail Account Management** | Dovecot users managed by Panel |
| **DNS Records** | Email DNS (MX, SPF, DKIM) via Panel |

### Fleet Manager <-> Panel/Email

| Integration | Description |
|-------------|-------------|
| **Deployment** | Fleet deploys both Panel and Email |
| **Monitoring** | Fleet monitors Panel/Email services |
| **Config Push** | Update configs across all servers |
| **Health Aggregation** | Central view of all deployments |

---

## Tech Stack Summary

| Component | Technology |
|-----------|------------|
| **Email Backend** | PHP 8.3, Custom Router |
| **Panel Backend** | PHP 8.3, Custom Router |
| **Fleet Backend** | PHP 8.3, Custom Router |
| **Collab Server** | Node.js, Y.js, WebSocket |
| **All Frontends** | Vue 3, Vite, Tailwind CSS, Pinia |
| **Desktop Client** | Electron, Vue 3, TypeScript |
| **Database** | MariaDB |
| **Web Server** | OpenLiteSpeed |
| **Email** | Postfix (SMTP), Dovecot (IMAP) |
| **DNS** | PowerDNS |
| **Security** | Fail2ban, FirewallD, ModSecurity, CPGuard |

---

## Key Differentiators

### vs. SaaS Email (Gmail, Outlook)

| Factor | SaaS | MailFlow |
|--------|------|----------|
| **Data Ownership** | Third-party | 100% yours |
| **Pricing** | Per-seat/month | Flat/unlimited |
| **Lock-in** | High | None |
| **Tracking** | Limited/paid | Built-in |
| **AI** | Extra cost | Included (BYOK) |
| **Customization** | None | Full source access |

### vs. cPanel/Plesk

| Factor | cPanel/Plesk | DEVCON Panel |
|--------|--------------|--------------|
| **Security Model** | Shell access | Action-based API |
| **Pricing** | Monthly license | One-time |
| **Audit Trail** | Minimal | Full |
| **AI Helper** | None | GPT-powered |
| **Modern UI** | Legacy | Vue 3 + Tailwind |

### vs. Cloudflare/Vercel

| Factor | Edge Platforms | Fleet Manager |
|--------|----------------|---------------|
| **Control** | Limited | Full root access |
| **Pricing** | Usage-based | Flat VPS cost |
| **Lock-in** | High | Zero |
| **Email** | None | Full mail server |
| **Deployment** | Their infra | Your servers |

---

## Target Audiences

### 1. Technical Founders / CTOs
- Want data sovereignty
- Building SaaS products
- Need reliable email + infra

### 2. Digital Agencies
- Manage multiple clients
- Need white-label option
- Project management built-in

### 3. Privacy-Conscious Businesses
- Legal, medical, financial
- GDPR/compliance concerns
- No third-party data access

### 4. Growing Teams (10-50)
- Sick of per-seat pricing
- Need email + projects + files
- Want unified platform

### 5. MSPs / Hosting Providers
- Deploy servers for clients
- Fleet Manager is their product
- White-label everything

---

## Landing Page Recommendations

### Current State
- Focuses heavily on Email App only
- Panel mentioned but not prominent
- Fleet Manager completely absent
- Positioning around "privacy" and "self-hosted"

### Recommended Changes

1. **Product Hierarchy**
   - Lead with the ecosystem story (all 3 products)
   - Show how they work together
   - Different pages for different audiences

2. **Hero Positioning Options**
   - **For Teams:** "Email + Projects + Files. No per-seat pricing."
   - **For Agencies:** "Deploy email infrastructure for clients in minutes."
   - **For Privacy:** "Your data. Your servers. Your rules."

3. **Feature Sections**
   - Email App features (current)
   - Panel features (new section)
   - Fleet Manager features (new section)
   - Integration story (new section)

4. **Pricing Clarity**
   - Self-Hosted: Free (all products)
   - Managed Email: $19/mo
   - Managed Everything: $49/mo
   - Fleet Manager: Custom (for MSPs)

5. **Demo Access**
   - Live demo of Email App
   - Live demo of Panel
   - Video walkthrough of Fleet Manager

6. **Trust Signals**
   - Open source commitment
   - Security audit results
   - Customer testimonials
   - Technology stack transparency

---

## Appendix: API Endpoint Counts

| Product | Endpoint Count |
|---------|----------------|
| **Email App** | 200+ endpoints |
| **DEVCON Panel** | 150+ endpoints |
| **Fleet Manager** | 50+ endpoints |

### Email App Endpoint Categories
- `/auth/*` - Authentication, 2FA, OAuth
- `/mailbox/*` - Folders, messages, search, threads
- `/messages/*` - Send, reply, forward, drafts
- `/drive/*` - Files, folders, sharing, versions
- `/calendar/*` - Events, invitations, sync
- `/boards/*` - Boards, lists, cards, labels
- `/clients/*` - CRM, contacts, time tracking
- `/contacts/*` - Autocomplete, import
- `/filters/*` - Sieve rules
- `/labels/*` - Email labels
- `/todos/*` - Task management
- `/ai/*` - Summarize, rewrite, draft
- `/statistics/*` - Usage analytics

### Panel Endpoint Categories
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
- `/api/vpn/*` - VPN connections
- `/api/ai-helper/*` - AI diagnostics

### Fleet Manager Endpoint Categories
- `/api/auth/*` - Authentication, 2FA
- `/api/servers/*` - Server management
- `/api/deployments/*` - Provisioning
- `/api/blueprints/*` - Templates
- `/api/packages/*` - Package management
- `/api/agent/*` - Agent communication

---

*This audit serves as the source of truth for all marketing materials, landing pages, and documentation.*

