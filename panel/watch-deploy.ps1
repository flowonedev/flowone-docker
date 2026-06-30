# Watch and Auto-Deploy Script
# Usage: .\watch-deploy.ps1
# Press Ctrl+C to stop

$WatchPath = "dashboard/src"
$DebounceSeconds = 3
$LastDeployTime = [DateTime]::MinValue

Write-Host "Watching for changes in $WatchPath..." -ForegroundColor Cyan
Write-Host "Press Ctrl+C to stop" -ForegroundColor Yellow
Write-Host ""

$watcher = New-Object System.IO.FileSystemWatcher
$watcher.Path = $WatchPath
$watcher.IncludeSubdirectories = $true
$watcher.EnableRaisingEvents = $true

$action = {
    $global:LastChangeTime = Get-Date
}

Register-ObjectEvent $watcher "Changed" -Action $action | Out-Null
Register-ObjectEvent $watcher "Created" -Action $action | Out-Null
Register-ObjectEvent $watcher "Deleted" -Action $action | Out-Null

$global:LastChangeTime = $null

try {
    while ($true) {
        Start-Sleep -Milliseconds 500
        
        if ($global:LastChangeTime -and ((Get-Date) - $global:LastChangeTime).TotalSeconds -gt $DebounceSeconds) {
            $timeSinceLastDeploy = ((Get-Date) - $LastDeployTime).TotalSeconds
            
            if ($timeSinceLastDeploy -gt 10) {
                Write-Host "`n[$(Get-Date -Format 'HH:mm:ss')] Changes detected, deploying..." -ForegroundColor Yellow
                
                & .\deploy.ps1 dashboard
                
                $LastDeployTime = Get-Date
                $global:LastChangeTime = $null
                
                Write-Host "`nWatching for changes..." -ForegroundColor Cyan
            }
        }
    }
}
finally {
    $watcher.Dispose()
    Write-Host "`nStopped watching." -ForegroundColor Red
}

