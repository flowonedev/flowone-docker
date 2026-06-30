# DEVCON Fleet Manager

A centralized management panel for deploying and managing multiple MailFlow + DEVCON Panel server instances.

## Overview

Fleet Manager allows you to:
- Deploy the full DEVCON ecosystem to new barebone Ubuntu servers
- Manage all deployed servers from a single dashboard
- Monitor server health, services, and errors in real-time
- Push updates to deployed servers with a few clicks
- Create and manage deployment blueprints (templates)

## Components

### API (`/api`)
PHP backend that provides:
- JWT authentication with 2FA support
- Server management endpoints
- Blueprint/template management
- Agent communication endpoints

### Dashboard (`/dashboard`)
Vue 3 frontend featuring:
- Real-time server monitoring
- Server health visualization
- Blueprint management UI
- Add server wizard

### Agent (`/agent`)
Lightweight PHP daemon that runs on managed servers:
- Reports health metrics every 60 seconds
- Collects and reports errors
- Receives commands from Fleet Manager

### Templates (`/templates`)
Configuration templates with variables:
- OpenLiteSpeed vhost configs
- Postfix/Dovecot email configs
- Fail2ban rules
- Systemd service files

### Installer (`/installer`)
Bash scripts for provisioning new servers:
- Base system setup
- Mail stack installation
- Security hardening
- Panel/Email App deployment

## Requirements

### Fleet Manager Server
- PHP 8.1+
- MariaDB 10.4+
- Node.js 18+ (for dashboard build)
- Composer

### Managed Servers
- Ubuntu 22.04 LTS
- Clean installation (barebone)
- Root SSH access
- Public IP address

## Installation

### 1. Database Setup

```bash
mysql -u root -p < database/schema.sql
```

### 2. API Setup

```bash
cd api
composer install
cp config.local.example.php config.local.php
# Edit config.local.php with your database credentials and secrets
```

Generate secrets:
```bash
# JWT secret
php -r "echo bin2hex(random_bytes(32));"

# Encryption key
php -r "echo base64_encode(random_bytes(32));"
```

### 3. Dashboard Setup

```bash
cd dashboard
npm install
npm run build
```

For development:
```bash
npm run dev
```

### 4. Web Server Configuration

Point your domain to `api/public` for the API and serve `dashboard/dist` for the frontend.

Example OpenLiteSpeed vhost:
```
docRoot: /var/www/fleet-manager/dashboard/dist

context /api {
    location: /var/www/fleet-manager/api/public
    rewrite: {
        RewriteRule ^(.*)$ /index.php [QSA,L]
    }
}
```

## Default Login

- Username: `admin`
- Password: `admin`

**Change this immediately after first login!**

## Adding a Server

1. Go to Servers > Add Server
2. Enter the server's IP and SSH credentials
3. Configure domains (panel, email, mail)
4. Select a blueprint (optional)
5. Review and create

The server will be created in "pending" status. Use the provisioning feature to deploy the full stack.

## Creating a Blueprint

Blueprints are templates that define the configuration for new server deployments.

1. Go to Blueprints
2. Click "Create Blueprint"
3. Add configuration templates for each service
4. Define variables that will be replaced during deployment

### Template Variables

Templates support the following variables:
- `{{SERVER_IP}}` - Server's IP address
- `{{SERVER_HOSTNAME}}` - Server hostname
- `{{PANEL_DOMAIN}}` - Panel domain
- `{{EMAIL_DOMAIN}}` - Email app domain
- `{{MAIL_DOMAIN}}` - Mail domain (for email addresses)
- `{{DB_ROOT_PASS}}` - Database root password
- `{{PANEL_DB_PASS}}` - Panel database password
- `{{EMAIL_DB_PASS}}` - Email app database password

## Fleet Agent

The Fleet Agent runs on each managed server and reports:
- Service status (OLS, MariaDB, Postfix, Dovecot, etc.)
- System resources (CPU, memory, disk)
- SSL certificate expiry dates
- Error logs

### Installing the Agent

On the managed server:

```bash
# Create directory
mkdir -p /opt/fleet-agent

# Copy agent files
cp fleet-agent.php /opt/fleet-agent/
cp config.php /opt/fleet-agent/

# Install service
cp fleet-agent.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable fleet-agent
systemctl start fleet-agent
```

## Project Structure

```
fleet-manager/
├── api/
│   ├── public/          # Entry point
│   ├── src/
│   │   ├── Controllers/ # API controllers
│   │   ├── Core/        # Framework core
│   │   └── Services/    # Business logic
│   ├── routes.php       # Route definitions
│   └── config.php       # Configuration
├── dashboard/
│   ├── src/
│   │   ├── views/       # Page components
│   │   ├── components/  # Reusable components
│   │   ├── stores/      # Pinia stores
│   │   └── services/    # API client
│   └── index.html
├── agent/
│   ├── fleet-agent.php  # Agent daemon
│   └── config.php       # Agent config
├── templates/           # Config templates
├── installer/           # Provisioning scripts
└── database/
    └── schema.sql       # Database schema
```

## Tech Stack

- **Backend**: PHP 8.1, MariaDB
- **Frontend**: Vue 3, Pinia, Tailwind CSS
- **Agent**: PHP (runs as systemd service)
- **Communication**: REST API, JWT auth

## License

Proprietary - DEVCON

