# VPS Admin Panel - Deployment Guide

## Server Structure (Clean)

```
/var/www/vps-admin/
├── agent/          # PHP agent scripts
├── api/            # PHP API backend
├── assets/         # Vue built JS/CSS files
├── database/       # DB migration scripts
├── favicon.svg     # Site icon
└── index.html      # Vue app entry point
```

## Server Paths

| Component            | VPS Path                       | Notes                               |
| -------------------- | ------------------------------ | ----------------------------------- |
| Dashboard (frontend) | `/var/www/vps-admin/`          | Built Vue app (index.html, assets/) |
| API (backend)        | `/var/www/vps-admin/api/`      | PHP API                             |
| Agent                | `/var/www/vps-admin/agent/`    | PHP agent scripts (systemd service) |
| Database             | `/var/www/vps-admin/database/` | Migration scripts (keep!)           |

## Agent Service

The agent runs as a systemd service: `vpsadmin-agent.service`

```bash
# Restart agent after deploying new Action files
systemctl restart vpsadmin-agent

# Check status
systemctl status vpsadmin-agent

# View logs
journalctl -u vpsadmin-agent -f
```

**IMPORTANT**: After deploying new agent Action files, you MUST restart the agent service!

## Upload Location

Files are first uploaded to: `/home/panel.devcon1.hu/public_html/`

## Build Commands (Local - Windows)

```powershell
cd dashboard

# Clean old build first
Remove-Item -Recurse -Force dist -ErrorAction SilentlyContinue

# Build
npm run build
```

## Deployment Commands (VPS)

### Full Deployment (Dashboard + API + Agent)

```bash
# 1. CLEAN old dashboard files first (important!)
rm -rf /var/www/vps-admin/assets
rm -f /var/www/vps-admin/index.html
rm -f /var/www/vps-admin/favicon.svg

# 2. Deploy Dashboard (from uploaded dist folder)
cp -r /home/panel.devcon1.hu/public_html/dashboard/dist/* /var/www/vps-admin/

# 3. Deploy API
cp -r /home/panel.devcon1.hu/public_html/api/* /var/www/vps-admin/api/

# 4. Deploy Agent
cp -r /home/panel.devcon1.hu/public_html/agent/* /var/www/vps-admin/agent/

# 5. Set permissions
chown -R www-data:www-data /var/www/vps-admin/
```

### Dashboard Only

```bash
rm -rf /var/www/vps-admin/assets
rm -f /var/www/vps-admin/index.html
rm -f /var/www/vps-admin/favicon.svg
cp -r /home/panel.devcon1.hu/public_html/dashboard/dist/* /var/www/vps-admin/
chown -R www-data:www-data /var/www/vps-admin/
```

### API Only

```bash
cp -r /home/panel.devcon1.hu/public_html/api/* /var/www/vps-admin/api/
chown -R www-data:www-data /var/www/vps-admin/api/
```

### Agent Only

```bash
cp -r /home/panel.devcon1.hu/public_html/agent/* /var/www/vps-admin/agent/
chown -R www-data:www-data /var/www/vps-admin/agent/

# IMPORTANT: Restart agent to load new Action files!
systemctl restart vpsadmin-agent
```

## Verify Deployment

```bash
# Check dashboard structure
ls -la /var/www/vps-admin/
# Should see: index.html, favicon.svg, assets/

# Check assets are fresh (should be only ONE of each view file)
ls /var/www/vps-admin/assets/ | grep -c SitesView
# Should return: 1

# Check index.html timestamp
stat /var/www/vps-admin/index.html | grep Modify
```

## Common Mistakes to Avoid

1. **Don't deploy to `/var/www/vps-admin/dashboard/`** - deploy directly to `/var/www/vps-admin/`
2. **Always clean old assets before deploying** - prevents accumulation of old build files
3. **Don't forget permissions** - www-data must own the files
4. **Clean local dist before building** - ensures fresh build without leftover files

## Red Flags (Things That Should NOT Exist)

If you see any of these, something went wrong:

| Bad Item                            | Problem                                      |
| ----------------------------------- | -------------------------------------------- |
| `/opt/vps-admin/`                   | Old agent path - removed, use /var/www/...   |
| `/var/www/vps-admin/dashboard/`     | Nested folder - files should be in root      |
| `/var/www/vps-admin/api-backend/`   | Old duplicate API folder                     |
| `/var/www/vps-admin/_index.html`    | Backup file - should be deleted              |
| Multiple `SitesView-*.js` in assets | Old builds accumulated - clean assets first  |
| `.vue` files in assets              | Source files deployed instead of built files |

## Quick One-Liner (Full Clean Deploy)

```bash
rm -rf /var/www/vps-admin/assets /var/www/vps-admin/index.html /var/www/vps-admin/favicon.svg && cp -r /home/panel.devcon1.hu/public_html/dashboard/dist/* /var/www/vps-admin/ && cp -r /home/panel.devcon1.hu/public_html/api/* /var/www/vps-admin/api/ && cp -r /home/panel.devcon1.hu/public_html/agent/* /var/www/vps-admin/agent/ && chown -R www-data:www-data /var/www/vps-admin/ && systemctl restart vpsadmin-agent
```
