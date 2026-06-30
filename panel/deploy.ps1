# VPS Admin Dashboard Deploy Script
# Usage: .\deploy.ps1 [dashboard|api|agent|mail|both|all]
#
#   dashboard  - build + deploy the Vue dashboard
#   api        - deploy the PHP API (routes/controllers/scripts) + clear opcache
#   agent      - deploy the privileged agent to /opt/vps-admin + restart daemon
#   mail       - api + agent together (needed for Contacts/Calendar migration,
#                mailbox actions, etc. — the API calls the agent over a socket)
#   both       - dashboard + api
#   all        - dashboard + api + agent

param(
    [string]$Target = "dashboard"
)

$ServerHost = "root@panel.devcon1.hu"
$UploadPath = "/home/panel.devcon1.hu/public_html"
$DeployPath = "/var/www/vps-admin"
# The agent daemon (vpsadmin-agent) runs from here, NOT from $DeployPath. It is a
# long-running process, so new code only takes effect after a service restart.
$AgentPath  = "/opt/vps-admin"

function Deploy-Dashboard {
    Write-Host "`n=== Building Dashboard ===" -ForegroundColor Cyan
    
    Push-Location dashboard
    npm run build
    if ($LASTEXITCODE -ne 0) {
        Write-Host "Build failed!" -ForegroundColor Red
        Pop-Location
        exit 1
    }
    Pop-Location
    
    Write-Host "`n=== Uploading Dashboard ===" -ForegroundColor Cyan
    
    # Upload dist folder
    scp -r "dashboard/dist" "${ServerHost}:${UploadPath}/dashboard/"
    
    Write-Host "`n=== Deploying Dashboard ===" -ForegroundColor Cyan
    
    # Run deploy commands on server
    ssh $ServerHost @"
rm -rf ${DeployPath}/assets
cp -r ${UploadPath}/dashboard/dist/assets ${DeployPath}/
cp ${UploadPath}/dashboard/dist/index.html ${DeployPath}/
cp ${UploadPath}/dashboard/dist/favicon.svg ${DeployPath}/
chown -R www-data:www-data ${DeployPath}/
echo 'Dashboard deployed successfully!'
"@
    
    Write-Host "`nDashboard deployed!" -ForegroundColor Green
}

function Deploy-Api {
    Write-Host "`n=== Uploading API ===" -ForegroundColor Cyan
    
    # Upload API files (excluding vendor and config.local.php)
    scp -r "api/src" "${ServerHost}:${UploadPath}/api/"
    scp -r "api/public" "${ServerHost}:${UploadPath}/api/"
    scp -r "api/scripts" "${ServerHost}:${UploadPath}/api/"
    scp "api/routes.php" "${ServerHost}:${UploadPath}/api/"
    scp "api/config.php" "${ServerHost}:${UploadPath}/api/"
    scp "api/composer.json" "${ServerHost}:${UploadPath}/api/"
    # schema.sql must stay current on the server: the Fleet panel package is
    # built FROM the server, and its installer layers api/schema.sql onto fresh
    # deploys (mail security defaults, force_password_change, ...).
    scp "api/schema.sql" "${ServerHost}:${UploadPath}/api/"
    
    Write-Host "`n=== Deploying API ===" -ForegroundColor Cyan
    
    ssh $ServerHost @"
cp -r ${UploadPath}/api/src ${DeployPath}/api/
cp -r ${UploadPath}/api/public ${DeployPath}/api/
cp -r ${UploadPath}/api/scripts ${DeployPath}/api/
cp ${UploadPath}/api/routes.php ${DeployPath}/api/
cp ${UploadPath}/api/config.php ${DeployPath}/api/
cp ${UploadPath}/api/composer.json ${DeployPath}/api/
cp ${UploadPath}/api/schema.sql ${DeployPath}/api/
# Normalize Windows CRLF -> LF in PHP (stray \r can break parsing / route registration)
find ${DeployPath}/api -name '*.php' -exec sed -i 's/\r`$//' {} \;
chown -R www-data:www-data ${DeployPath}/api/
# Clear PHP opcache so updated routes.php / new controllers actually take effect.
# Without this, lsphp keeps serving the OLD cached routes.php -> new endpoints 404.
systemctl restart lsphp83 2>/dev/null || true
killall -USR1 lsphp 2>/dev/null || true
systemctl restart lsws 2>/dev/null || systemctl restart lshttpd 2>/dev/null || /usr/local/lsws/bin/lswsctrl restart 2>/dev/null || true
echo 'API deployed successfully (opcache cleared)!'
"@
    
    Write-Host "`nAPI deployed!" -ForegroundColor Green
}

function Deploy-Agent {
    Write-Host "`n=== Uploading Agent ===" -ForegroundColor Cyan

    # Upload the agent source tree to staging
    scp -r "agent" "${ServerHost}:${UploadPath}/"

    Write-Host "`n=== Deploying Agent ===" -ForegroundColor Cyan

    # Copy into the agent runtime path (/opt/vps-admin/agent) and RESTART the daemon.
    # The agent is a long-running PHP process; copying files alone changes nothing
    # until vpsadmin-agent is restarted — that is why new agent actions (e.g.
    # mail.davMigrate for Contacts/Calendar migration) return "unknown method" / 502.
    ssh $ServerHost @"
cp -r ${UploadPath}/agent/* ${AgentPath}/agent/
# Normalize Windows CRLF -> LF in PHP (a long-running daemon will hard-fail on a parse error)
find ${AgentPath}/agent -name '*.php' -exec sed -i 's/\r`$//' {} \;
chown -R root:root ${AgentPath}/agent
chmod -R 750 ${AgentPath}/agent
systemctl restart vpsadmin-agent
sleep 1
if systemctl is-active --quiet vpsadmin-agent; then
  echo 'Agent deployed + restarted successfully!'
else
  echo 'AGENT FAILED TO START — check: journalctl -u vpsadmin-agent -n 50'
fi
"@

    Write-Host "`nAgent deployed!" -ForegroundColor Green
}

# Main
Write-Host "VPS Admin Deploy Script" -ForegroundColor Yellow
Write-Host "Target: $Target" -ForegroundColor Yellow

switch ($Target) {
    "dashboard" { Deploy-Dashboard }
    "api" { Deploy-Api }
    "agent" { Deploy-Agent }
    "mail" {
        # API + agent together: the migration/mailbox endpoints call the agent,
        # so both sides must be in sync (and the agent must be restarted).
        Deploy-Api
        Deploy-Agent
    }
    "both" { 
        Deploy-Dashboard
        Deploy-Api
    }
    "all" {
        Deploy-Dashboard
        Deploy-Api
        Deploy-Agent
    }
    default {
        Write-Host "Usage: .\deploy.ps1 [dashboard|api|agent|mail|both|all]" -ForegroundColor Yellow
    }
}
