# Fleet Manager Deployment Packages

This directory contains build scripts and installers for deploying applications via Fleet Manager.

## Directory Structure

```
packages/
├── panel/
│   ├── build.sh          # Creates panel-vX.X.X.tar.gz
│   ├── install.sh        # Remote installer (included in package)
│   └── panel-latest.tar.gz -> panel-v1.0.0.tar.gz
├── email/
│   ├── build.sh          # Creates email-vX.X.X.tar.gz
│   ├── install.sh        # Remote installer (included in package)
│   └── email-latest.tar.gz -> email-v1.0.0.tar.gz
└── agent/
    ├── build.sh          # Creates agent-vX.X.X.tar.gz
    ├── install.sh        # Remote installer (included in package)
    └── agent-latest.tar.gz -> agent-v1.0.0.tar.gz
```

## Building Packages

### Prerequisites

- Source projects must be present in sibling directories:
  - `../00 VPS ADMIN/` - VPS Admin Panel source
  - `../00 EMAIL APP/` - MailFlow Email App source
- Dashboard/Frontend must be built (`npm run build`)
- Make scripts executable: `chmod +x build.sh install.sh`

### Build Commands

```bash
# Build VPS Admin Panel package
cd packages/panel
./build.sh 1.0.0

# Build MailFlow Email App package
cd packages/email
./build.sh 1.0.0

# Build Fleet Agent package
cd packages/agent
./build.sh 1.0.0
```

Each build script:
1. Copies required files from source
2. Creates a versioned tar.gz (e.g., `panel-v1.0.0.tar.gz`)
3. Creates/updates the `*-latest.tar.gz` symlink

## Package Contents

### Panel Package
- `api/` - PHP API backend
- `dashboard/dist/` - Built Vue.js dashboard
- `agent/` - VPS Admin Agent files
- `database/` - SQL schema and migrations
- `install.sh` - Remote installer script

### Email Package
- `backend/` - PHP backend with controllers, services
- `frontend/dist/` - Built Vue.js frontend
- `collab/` - Collaboration server (optional)
- `install.sh` - Remote installer script

### Agent Package
- `agent.php` - Main agent daemon
- `Actions/` - Agent action handlers
- `config.php` - Configuration template
- `fleet-agent.service` - Systemd service file
- `install.sh` - Remote installer script

## Remote Installation

The installers are run automatically by Fleet Manager's ProvisioningService, but can also be run manually:

### Panel
```bash
./install.sh \
  --domain=panel.example.com \
  --db-name=panel_db \
  --db-user=panel_user \
  --db-pass="secret" \
  --admin-email=admin@example.com \
  --admin-pass="admin123" \
  --agent-token="xxxxx"
```

### Email
```bash
./install.sh \
  --domain=email.example.com \
  --mail-domain=mail.example.com \
  --db-name=email_db \
  --db-user=email_user \
  --db-pass="secret"
```

### Agent
```bash
./install.sh \
  --fleet-url=https://fleet.example.com \
  --agent-token="xxxxx" \
  --panel-domain=panel.example.com \
  --email-domain=email.example.com
```

## Deployment Flow

```
Fleet Manager                          Target Server
     |                                      |
     |  1. Upload package via SFTP          |
     |------------------------------------->|
     |                                      |
     |  2. Extract to /tmp/fleet-deploy/    |
     |------------------------------------->|
     |                                      |
     |  3. Run install.sh with variables    |
     |------------------------------------->|
     |                                      |
     |  4. Verify installation              |
     |<-------------------------------------|
     |                                      |
     |  5. Cleanup temp files               |
     |------------------------------------->|
```

## Configuration

Fleet Manager config (`api/config.php`):

```php
'packages' => [
    'path' => __DIR__ . '/../packages/',
    'panel' => 'panel/panel-latest.tar.gz',
    'email' => 'email/email-latest.tar.gz',
    'agent' => 'agent/agent-latest.tar.gz',
],
```

