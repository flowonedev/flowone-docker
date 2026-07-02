<?php
/**
 * API Routes
 */

use VpsAdmin\Api\Controllers\AuthController;
use VpsAdmin\Api\Controllers\DashboardController;
use VpsAdmin\Api\Controllers\ServiceController;
use VpsAdmin\Api\Controllers\SiteController;
use VpsAdmin\Api\Controllers\SslController;
use VpsAdmin\Api\Controllers\DatabaseController;
use VpsAdmin\Api\Controllers\MailController;
use VpsAdmin\Api\Controllers\Fail2banController;
use VpsAdmin\Api\Controllers\FirewallController;
use VpsAdmin\Api\Controllers\DnsController;
use VpsAdmin\Api\Controllers\ModsecController;
use VpsAdmin\Api\Controllers\BackupController;
use VpsAdmin\Api\Controllers\LogController;
use VpsAdmin\Api\Controllers\PhpController;
use VpsAdmin\Api\Controllers\MysqlController;
use VpsAdmin\Api\Controllers\PostfixController;
use VpsAdmin\Api\Controllers\DovecotController;
use VpsAdmin\Api\Controllers\MailSecurityController;
use VpsAdmin\Api\Controllers\AgentController;
use VpsAdmin\Api\Controllers\UserController;
use VpsAdmin\Api\Controllers\ClientController;
use VpsAdmin\Api\Controllers\BillingController;
use VpsAdmin\Api\Controllers\CpguardController;
use VpsAdmin\Api\Controllers\SystemLogsController;
use VpsAdmin\Api\Controllers\SystemController;
use VpsAdmin\Api\Controllers\OpenLiteSpeedController;
use VpsAdmin\Api\Controllers\CronController;
use VpsAdmin\Api\Controllers\AppController;
use VpsAdmin\Api\Controllers\WordPressController;
use VpsAdmin\Api\Controllers\DockerController;
use VpsAdmin\Api\Controllers\FileManagerController;
use VpsAdmin\Api\Controllers\CacheController;
use VpsAdmin\Api\Controllers\MailMigrationController;
use VpsAdmin\Api\Controllers\DnsMigrationController;
use VpsAdmin\Api\Controllers\ImapMigrationController;
use VpsAdmin\Api\Controllers\MigrationChecklistController;
use VpsAdmin\Api\Controllers\MigrationDataController;
use VpsAdmin\Api\Controllers\AIHelperController;
use VpsAdmin\Api\Controllers\PhpMyAdminController;
use VpsAdmin\Api\Controllers\NASController;
use VpsAdmin\Api\Controllers\VPNController;
use VpsAdmin\Api\Controllers\SecurityScanController;
use VpsAdmin\Api\Controllers\AddonController;
use VpsAdmin\Api\Controllers\EmailAddonsController;
use VpsAdmin\Api\Controllers\HealthController;
use VpsAdmin\Api\Controllers\SiteProvisioningController;
use VpsAdmin\Api\Controllers\JobsController;
use VpsAdmin\Api\Controllers\SftpUserController;

// All routes are rate-limited
$router->group(['middleware' => 'rate_limit'], function($router) {

// Public routes
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/refresh', [AuthController::class, 'refresh']);
$router->post('/api/auth/2fa/verify', [AuthController::class, 'verify2FA']);

// External API routes (API key auth, not JWT)
$router->get('/api/storage/config', [NASController::class, 'getStorageConfig']);
$router->get('/api/addons/status', [AddonController::class, 'status']);
$router->put('/api/addons/onboarding-assign', [AddonController::class, 'onboardingAssign']);
$router->post('/api/audit/ingest', [LogController::class, 'ingest']);
$router->post('/api/security/scans/ingest', [SecurityScanController::class, 'ingest']);

// Self-service quarantine (HMAC token-signed links from per-user digest emails).
// No JWT: each request authorises purely on the signed token in the link. GET
// renders a confirmation page (prefetch-safe); POST performs release/delete/allow.
$router->get('/api/mailsec-q', [MailSecurityController::class, 'quarantineLanding']);
$router->post('/api/mailsec-q', [MailSecurityController::class, 'quarantineAction']);

// Protected routes (require authentication)
$router->group(['middleware' => 'auth'], function($router) {
    
    // Auth
    $router->get('/api/auth/me', [AuthController::class, 'me']);
    $router->post('/api/auth/logout', [AuthController::class, 'logout']);
    $router->post('/api/auth/password', [AuthController::class, 'changePassword']);
    
    // 2FA Management
    $router->get('/api/auth/2fa/status', [AuthController::class, 'get2FAStatus']);
    $router->post('/api/auth/2fa/setup', [AuthController::class, 'setup2FA']);
    $router->post('/api/auth/2fa/enable', [AuthController::class, 'enable2FA']);
    $router->post('/api/auth/2fa/disable', [AuthController::class, 'disable2FA']);
    $router->post('/api/auth/2fa/backup-codes', [AuthController::class, 'regenerateBackupCodes']);
    
    // Session Management
    $router->get('/api/auth/sessions', [AuthController::class, 'getSessions']);
    $router->delete('/api/auth/sessions/{sessionId}', [AuthController::class, 'revokeSession']);
    $router->post('/api/auth/sessions/revoke-all', [AuthController::class, 'revokeAllSessions']);

    // Dashboard
    $router->get('/api/dashboard', [DashboardController::class, 'index']);
    $router->get('/api/dashboard/stats', [DashboardController::class, 'stats']);

    // Services
    $router->get('/api/services', [ServiceController::class, 'index']);
    $router->get('/api/services/{name}', [ServiceController::class, 'show']);
    $router->post('/api/services/{name}/restart', [ServiceController::class, 'restart']);
    $router->post('/api/services/{name}/reload', [ServiceController::class, 'reload']);
    $router->post('/api/services/{name}/start', [ServiceController::class, 'start']);
    $router->post('/api/services/{name}/stop', [ServiceController::class, 'stop']);
    $router->get('/api/services/{name}/logs', [ServiceController::class, 'logs']);

    // System Logs
    $router->get('/api/system-logs/{service}', [SystemLogsController::class, 'getLogs']);
    $router->get('/api/system-logs/{service}/types', [SystemLogsController::class, 'getLogTypes']);

    // Sites v2 — asynchronous queue-based provisioning (Step 6).
    // Registered BEFORE the legacy `/api/sites/{domain}` routes so the
    // literal `/api/sites/v2` segment is not captured as `domain=v2`.
    // The bare `/api/sites` routes remain for the legacy synchronous flow
    // during migration; the v2 routes enqueue jobs and return immediately.
    $router->get('/api/sites/v2', [SiteProvisioningController::class, 'index']);
    $router->post('/api/sites/v2', [SiteProvisioningController::class, 'create']);
    // Archive picker for the Restore flow. Listed BEFORE /api/sites/v2/{domain}
    // so the literal `/archives` segment is not captured as domain=archives.
    $router->get('/api/sites/v2/archives', [SiteProvisioningController::class, 'listArchives']);
    $router->get('/api/sites/v2/{domain}', [SiteProvisioningController::class, 'show']);
    $router->delete('/api/sites/v2/{domain}', [SiteProvisioningController::class, 'delete']);
    $router->get('/api/sites/v2/{domain}/archives', [SiteProvisioningController::class, 'listArchives']);
    // Lifecycle transitions (Step 4c). Each is a POST so the same route
    // can be re-issued safely on retry without ambiguity.
    $router->post('/api/sites/v2/{domain}/suspend', [SiteProvisioningController::class, 'suspend']);
    $router->post('/api/sites/v2/{domain}/resume', [SiteProvisioningController::class, 'resume']);
    $router->post('/api/sites/v2/{domain}/archive', [SiteProvisioningController::class, 'archive']);
    $router->post('/api/sites/v2/{domain}/restore', [SiteProvisioningController::class, 'restore']);
    // Hard-delete a tombstone (actual_state='absent' rows only). Removes
    // the sites row + dependent history tables + snapshot dir on disk.
    // Refuses to touch live sites.
    $router->post('/api/sites/v2/{domain}/purge', [SiteProvisioningController::class, 'purgeTombstone']);

    // Provisioning job queue — inspect, cancel, retry.
    $router->get('/api/jobs', [JobsController::class, 'index']);
    $router->get('/api/jobs/{id}', [JobsController::class, 'show']);
    $router->get('/api/jobs/{id}/events', [JobsController::class, 'events']);
    $router->post('/api/jobs/{id}/cancel', [JobsController::class, 'cancel']);
    $router->post('/api/jobs/{id}/retry', [JobsController::class, 'retry']);

    // Sites (Virtual Hosts) — V2-backed read + management surface.
    //
    // Phase 5 of the V2 consolidation deleted the synchronous create
    // and delete endpoints (POST /api/sites, DELETE /api/sites/{domain})
    // and pointed everything through the SiteProvisioningController
    // (`/api/sites/v2/*`). The remaining endpoints here are the
    // *management* surface still used by SiteManageV2View tabs:
    //   - GET    /api/sites              -> V2-backed dropdown listing
    //                                       (see SiteController::index)
    //   - GET    /api/sites/{domain}     -> OLS-coupled show (PHP
    //                                       version, php_limits, etc.)
    //                                       still reads OLS via
    //                                       vhost.get
    //   - PUT    /api/sites/{domain}     -> PHP version + docroot
    //                                       update (used by Overview
    //                                       tab); vhost.update only
    //                                       mutates OLS config, never
    //                                       provisions
    //   - GET    /api/sites/{domain}/config + PUT variants -> vhost
    //                                       config view/edit
    //   - GET    /api/sites/{domain}/{logs|ftp-status|...}
    //   - SSH-key + validation/fix endpoints
    $router->get('/api/sites', [SiteController::class, 'index']);
    $router->get('/api/sites/{domain}', [SiteController::class, 'show']);
    $router->put('/api/sites/{domain}', [SiteController::class, 'update']);
    $router->get('/api/sites/{domain}/config', [SiteController::class, 'getConfig']);
    $router->put('/api/sites/{domain}/config', [SiteController::class, 'updateConfig']);
    $router->put('/api/sites/{domain}/config/values', [SiteController::class, 'updateConfigValues']);
    $router->get('/api/sites/{domain}/logs', [SiteController::class, 'getLogs']);
    $router->get('/api/sites/{domain}/ftp-status', [SiteController::class, 'getFtpStatus']);
    $router->get('/api/sites/{domain}/databases', [SiteController::class, 'getDatabases']);
    $router->get('/api/sites/{domain}/ssh-keys', [SiteController::class, 'getSshKeys']);
    $router->post('/api/sites/{domain}/ssh-keys', [SiteController::class, 'addSshKey']);
    $router->put('/api/sites/{domain}/ssh-keys/{index}', [SiteController::class, 'updateSshKey']);
    $router->delete('/api/sites/{domain}/ssh-keys/{index}', [SiteController::class, 'removeSshKey']);
    $router->post('/api/sites/{domain}/fix-ssh-permissions', [SiteController::class, 'fixSshPermissions']);
    // Additional restricted SFTP users (chroot-jailed, per site)
    $router->get('/api/sites/{domain}/sftp-users', [SftpUserController::class, 'index']);
    $router->get('/api/sites/{domain}/sftp-users/browse', [SftpUserController::class, 'browse']);
    $router->post('/api/sites/{domain}/sftp-users', [SftpUserController::class, 'create']);
    $router->put('/api/sites/{domain}/sftp-users/{id}', [SftpUserController::class, 'update']);
    $router->delete('/api/sites/{domain}/sftp-users/{id}', [SftpUserController::class, 'delete']);
    $router->post('/api/sites/{domain}/sftp-users/{id}/password', [SftpUserController::class, 'setPassword']);
    $router->post('/api/sites/{domain}/sftp-users/{id}/keys', [SftpUserController::class, 'addKey']);
    $router->delete('/api/sites/{domain}/sftp-users/{id}/keys', [SftpUserController::class, 'removeKey']);
    $router->post('/api/sites/{domain}/sftp-users/{id}/repair', [SftpUserController::class, 'repair']);
    $router->get('/api/sites/{domain}/sftp-users/{id}/sessions', [SftpUserController::class, 'sessions']);
    // Global SFTP-user listing across all sites (admin-only, enforced in controller)
    $router->get('/api/sftp-users', [SftpUserController::class, 'indexGlobal']);
    // Force a journal -> sftp_sessions sync (admin-only, enforced in controller)
    $router->post('/api/sftp-sessions/sync', [SftpUserController::class, 'syncSessions']);
    $router->get('/api/sites/{domain}/validate', [SiteController::class, 'validateSite']);
    $router->post('/api/sites/{domain}/fix', [SiteController::class, 'fixSite']);
    $router->post('/api/sites/{domain}/fix-issue', [SiteController::class, 'fixSiteIssue']);
    $router->get('/api/sites/{domain}/validate-deletion', [SiteController::class, 'validateDeletion']);
    $router->post('/api/sites/{domain}/fix-deletion', [SiteController::class, 'fixDeletion']);

    // SSL Certificates - specific routes before {domain} wildcard
    $router->get('/api/ssl', [SslController::class, 'index']);
    $router->get('/api/ssl/health', [SslController::class, 'health']);
    $router->post('/api/ssl/health/fix', [SslController::class, 'fixHealth']);
    $router->post('/api/ssl/renew', [SslController::class, 'renew']);
    $router->get('/api/ssl/{domain}', [SslController::class, 'show']);
    $router->post('/api/ssl/{domain}/preflight', [SslController::class, 'preflight']);
    $router->get('/api/ssl/{domain}/dns-test', [SslController::class, 'dnsTest']);
    $router->get('/api/ssl/{domain}/test', [SslController::class, 'testCertificate']);
    $router->get('/api/ssl/{domain}/comprehensive', [SslController::class, 'comprehensiveCheck']);
    $router->get('/api/ssl/{domain}/comprehensive/saved', [SslController::class, 'getSavedCheck']);
    $router->post('/api/ssl/{domain}/fix-config', [SslController::class, 'fixSslConfig']);
    $router->post('/api/ssl/{domain}/issue', [SslController::class, 'issue']);
    $router->delete('/api/ssl/{domain}', [SslController::class, 'cleanup']);

    // Databases
    $router->get('/api/databases', [DatabaseController::class, 'index']);
    $router->get('/api/databases/orphans', [DatabaseController::class, 'orphans']);
    $router->post('/api/databases/auto-link', [DatabaseController::class, 'autoLink']);
    $router->get('/api/databases/site/{domain}', [DatabaseController::class, 'forSite']);
    $router->get('/api/databases/{name}', [DatabaseController::class, 'show']);
    $router->post('/api/databases', [DatabaseController::class, 'create']);
    $router->delete('/api/databases/{name}', [DatabaseController::class, 'delete']);
    $router->get('/api/databases/{name}/size', [DatabaseController::class, 'size']);
    $router->get('/api/databases/{name}/links', [DatabaseController::class, 'getLinks']);
    $router->post('/api/databases/{name}/link', [DatabaseController::class, 'linkToSite']);
    $router->delete('/api/databases/{name}/link/{domain}', [DatabaseController::class, 'unlinkFromSite']);
    
    // Database Users
    $router->get('/api/db-users', [DatabaseController::class, 'users']);
    $router->post('/api/db-users', [DatabaseController::class, 'createUser']);
    $router->delete('/api/db-users/{user}', [DatabaseController::class, 'deleteUser']);
    $router->post('/api/db-users/{user}/password', [DatabaseController::class, 'resetPassword']);

    // Mail
    $router->get('/api/mail/status', [MailController::class, 'status']);
    $router->get('/api/mail/domains', [MailController::class, 'domains']);
    $router->post('/api/mail/domains', [MailController::class, 'addDomain']);
    $router->delete('/api/mail/domains/{domain}', [MailController::class, 'removeDomain']);
    $router->get('/api/mail/accounts', [MailController::class, 'allAccounts']);
    $router->get('/api/mail/domains/{domain}/accounts', [MailController::class, 'accounts']);
    $router->post('/api/mail/accounts', [MailController::class, 'createAccount']);
    $router->post('/api/mail/accounts/bulk', [MailController::class, 'bulkCreateAccounts']);
    $router->delete('/api/mail/accounts/{email}', [MailController::class, 'deleteAccount']);
    $router->post('/api/mail/accounts/{email}/password', [MailController::class, 'resetPassword']);
    $router->post('/api/mail/accounts/{email}/force-password-change', [MailController::class, 'setForcePasswordChange']);
    $router->post('/api/mail/accounts/{email}/suspend', [MailController::class, 'suspendAccount']);
    $router->post('/api/mail/accounts/{email}/resume', [MailController::class, 'resumeAccount']);
    $router->post('/api/mail/accounts/{email}/quota', [MailController::class, 'setQuotas']);
    $router->post('/api/mail/accounts/{email}/reset-2fa', [MailController::class, 'reset2fa']);
    $router->get('/api/mail/forwards', [MailController::class, 'allForwards']);
    $router->get('/api/mail/domains/{domain}/forwards', [MailController::class, 'forwards']);
    $router->post('/api/mail/forwards', [MailController::class, 'addForward']);
    $router->delete('/api/mail/forwards/{source}', [MailController::class, 'removeForward']);
    $router->get('/api/mail/queue', [MailController::class, 'queue']);
    $router->post('/api/mail/queue/flush', [MailController::class, 'flushQueue']);
    $router->delete('/api/mail/queue/{id}', [MailController::class, 'deleteFromQueue']);
    
    // Mail DNS Records (SPF, DKIM, DMARC)
    $router->get('/api/mail/domains/{domain}/dns', [MailController::class, 'dnsRecords']);
    $router->get('/api/mail/domains/{domain}/dkim', [MailController::class, 'dkimStatus']);
    $router->post('/api/mail/domains/{domain}/dkim', [MailController::class, 'generateDkim']);
    $router->post('/api/mail/domains/{domain}/dns', [MailController::class, 'setupDnsRecord']);

    // Fail2ban
    $router->get('/api/fail2ban/status', [Fail2banController::class, 'status']);
    $router->get('/api/fail2ban/jails', [Fail2banController::class, 'jails']);
    $router->get('/api/fail2ban/jails/{name}', [Fail2banController::class, 'jail']);
    $router->get('/api/fail2ban/banned', [Fail2banController::class, 'banned']);
    $router->post('/api/fail2ban/jails/{name}/ban', [Fail2banController::class, 'ban']);
    $router->post('/api/fail2ban/jails/{name}/unban', [Fail2banController::class, 'unban']);
    $router->post('/api/fail2ban/jails', [Fail2banController::class, 'createJail']);
    $router->put('/api/fail2ban/jails/{name}', [Fail2banController::class, 'updateJail']);
    $router->post('/api/fail2ban/jails/{name}/enable', [Fail2banController::class, 'enableJail']);
    $router->post('/api/fail2ban/jails/{name}/disable', [Fail2banController::class, 'disableJail']);
    $router->delete('/api/fail2ban/jails/{name}', [Fail2banController::class, 'deleteJail']);

    // Firewall
    $router->get('/api/firewall/status', [FirewallController::class, 'status']);
    $router->get('/api/firewall/zones', [FirewallController::class, 'zones']);
    $router->get('/api/firewall/zones/{name}', [FirewallController::class, 'zone']);
    $router->post('/api/firewall/services', [FirewallController::class, 'addService']);
    $router->delete('/api/firewall/services/{service}', [FirewallController::class, 'removeService']);
    $router->post('/api/firewall/ports', [FirewallController::class, 'addPort']);
    $router->delete('/api/firewall/ports/{port}/{protocol}', [FirewallController::class, 'removePort']);
    $router->get('/api/firewall/rich-rules', [FirewallController::class, 'richRules']);
    $router->post('/api/firewall/rich-rules', [FirewallController::class, 'addRichRule']);
    $router->delete('/api/firewall/rich-rules', [FirewallController::class, 'removeRichRule']);
    $router->post('/api/firewall/reload', [FirewallController::class, 'reload']);

    // DNS
    $router->get('/api/dns/status', [DnsController::class, 'status']);
    $router->get('/api/dns/stats', [DnsController::class, 'stats']);
    $router->post('/api/dns/sync-all', [DnsController::class, 'syncAll']);
    $router->get('/api/dns/zones', [DnsController::class, 'zones']);
    $router->get('/api/dns/zones/{name}', [DnsController::class, 'zone']);
    $router->post('/api/dns/zones', [DnsController::class, 'createZone']);
    $router->delete('/api/dns/zones/{name}', [DnsController::class, 'deleteZone']);
    $router->get('/api/dns/zones/{name}/records', [DnsController::class, 'records']);
    $router->post('/api/dns/records', [DnsController::class, 'addRecord']);
    $router->put('/api/dns/records/{id}', [DnsController::class, 'updateRecord']);
    $router->delete('/api/dns/records/{id}', [DnsController::class, 'deleteRecord']);
    $router->post('/api/dns/zones/{name}/sync', [DnsController::class, 'syncZone']);
    $router->post('/api/dns/zones/{name}/fix-issues', [DnsController::class, 'fixIssues']);
    $router->get('/api/dns/ns-config', [DnsController::class, 'getNsConfig']);
    $router->put('/api/dns/ns-config', [DnsController::class, 'setNsConfig']);

    // ModSecurity
    $router->get('/api/modsec/status', [ModsecController::class, 'status']);
    $router->post('/api/modsec/mode', [ModsecController::class, 'setMode']);
    $router->get('/api/modsec/rules', [ModsecController::class, 'rules']);
    $router->post('/api/modsec/rules/{rule}/enable', [ModsecController::class, 'enableRule']);
    $router->post('/api/modsec/rules/{rule}/disable', [ModsecController::class, 'disableRule']);
    $router->get('/api/modsec/audit-log', [ModsecController::class, 'auditLog']);

    // CPGuard - Status & Statistics
    $router->get('/api/cpguard/status', [CpguardController::class, 'status']);
    $router->get('/api/cpguard/waf', [CpguardController::class, 'wafStatus']);
    $router->get('/api/cpguard/stats', [CpguardController::class, 'stats']);
    
    // CPGuard - Installation & License
    $router->post('/api/cpguard/install', [CpguardController::class, 'install']);
    $router->delete('/api/cpguard/uninstall', [CpguardController::class, 'uninstall']);
    $router->get('/api/cpguard/license', [CpguardController::class, 'getLicense']);
    $router->put('/api/cpguard/license', [CpguardController::class, 'updateLicense']);
    
    // CPGuard - Whitelists & Blacklists
    $router->get('/api/cpguard/lists', [CpguardController::class, 'getLists']);
    $router->post('/api/cpguard/whitelist/ip', [CpguardController::class, 'addWhitelistIp']);
    $router->delete('/api/cpguard/whitelist/ip/{ip}', [CpguardController::class, 'removeWhitelistIp']);
    $router->post('/api/cpguard/whitelist/domain', [CpguardController::class, 'addWhitelistDomain']);
    $router->delete('/api/cpguard/whitelist/domain/{domain}', [CpguardController::class, 'removeWhitelistDomain']);
    $router->post('/api/cpguard/blacklist/ip', [CpguardController::class, 'addBlacklistIp']);
    $router->delete('/api/cpguard/blacklist/ip/{ip}', [CpguardController::class, 'removeBlacklistIp']);
    $router->post('/api/cpguard/blacklist/file', [CpguardController::class, 'addBlacklistFile']);
    $router->delete('/api/cpguard/blacklist/file', [CpguardController::class, 'removeBlacklistFile']);
    
    // CPGuard - Configuration
    $router->get('/api/cpguard/config', [CpguardController::class, 'getConfig']);
    $router->put('/api/cpguard/config', [CpguardController::class, 'updateConfig']);
    $router->post('/api/cpguard/toggle', [CpguardController::class, 'toggleModule']);
    
    // CPGuard - Service Management
    $router->post('/api/cpguard/service', [CpguardController::class, 'restartService']);
    $router->post('/api/cpguard/scan', [CpguardController::class, 'triggerScan']);

    // Backups - IMPORTANT: Specific routes MUST come before wildcard {id} routes
    $router->get('/api/backups', [BackupController::class, 'index']);
    $router->get('/api/backups/categories', [BackupController::class, 'categories']);
    $router->post('/api/backups/create', [BackupController::class, 'create']);
    $router->post('/api/backups/cleanup', [BackupController::class, 'cleanup']);
    
    // Backup Schedules
    $router->get('/api/backups/schedules', [BackupController::class, 'schedules']);
    $router->post('/api/backups/schedules', [BackupController::class, 'createSchedule']);
    $router->put('/api/backups/schedules/{id}', [BackupController::class, 'updateSchedule']);
    $router->delete('/api/backups/schedules/{id}', [BackupController::class, 'deleteSchedule']);
    $router->post('/api/backups/schedules/{id}/run', [BackupController::class, 'runScheduleNow']);

    // Cron daemon health (self-heal for fresh deployments)
    $router->post('/api/backups/cron/repair', [BackupController::class, 'repairCron']);
    
    // Site Backups (Files + Database) - before {id} wildcard
    $router->get('/api/backups/sites', [BackupController::class, 'siteBackups']);
    $router->post('/api/backups/sites', [BackupController::class, 'backupSites']);
    $router->get('/api/backups/sites/{domain}', [BackupController::class, 'siteBackups']);
    $router->post('/api/backups/sites/{domain}', [BackupController::class, 'backupSite']);
    $router->post('/api/backups/sites/{domain}/restore', [BackupController::class, 'restoreSite']);
    
    // Backup Inspection (preview contents before restore)
    $router->get('/api/backups/inspect/site/{id}', [BackupController::class, 'inspectSiteBackup']);
    $router->get('/api/backups/inspect/config/{id}', [BackupController::class, 'inspectConfigBackup']);
    
    // Selective Config Restore
    $router->post('/api/backups/restore/config/{id}', [BackupController::class, 'restoreConfigBackupSelective']);
    
    // Database Backups - before {id} wildcard
    $router->post('/api/backups/database', [BackupController::class, 'backupDatabase']);
    $router->post('/api/backups/database/restore', [BackupController::class, 'restoreDatabase']);
    
    // NAS Remote Backup Configuration
    $router->get('/api/backups/nas/connections', [BackupController::class, 'nasConnections']);
    $router->get('/api/backups/nas/list', [BackupController::class, 'nasBackups']);
    $router->post('/api/backups/nas/delete', [BackupController::class, 'deleteNasBackups']);
    $router->post('/api/backups/transfer/nas', [BackupController::class, 'transferToNas']);
    
    // Backup Status Tracking (for long-running operations)
    $router->get('/api/backups/status', [BackupController::class, 'getBackupStatus']);
    $router->get('/api/backups/running', [BackupController::class, 'listRunningBackups']);
    
    // Email Backups - before {id} wildcard
    $router->get('/api/backups/mail/domains', [BackupController::class, 'listMailDomains']);
    $router->get('/api/backups/mail', [BackupController::class, 'mailBackups']);
    $router->get('/api/backups/mail/{domain}', [BackupController::class, 'mailBackups']);
    $router->post('/api/backups/mail/{domain}', [BackupController::class, 'backupMail']);
    $router->post('/api/backups/mail/{domain}/restore', [BackupController::class, 'restoreMail']);
    $router->get('/api/backups/mail/inspect/{id}', [BackupController::class, 'inspectMailBackup']);
    
    // Individual backup operations (wildcard {id} routes MUST come last)
    $router->get('/api/backups/{id}', [BackupController::class, 'show']);
    $router->get('/api/backups/{id}/download', [BackupController::class, 'download']);
    $router->post('/api/backups/{id}/restore', [BackupController::class, 'restore']);
    $router->delete('/api/backups/{id}', [BackupController::class, 'delete']);

    // Audit Logs
    $router->get('/api/logs', [LogController::class, 'index']);
    $router->get('/api/logs/stats', [LogController::class, 'stats']);
    $router->get('/api/logs/export', [LogController::class, 'export']);
    $router->get('/api/logs/{id}', [LogController::class, 'show']);

    // Security Dependency Scans
    $router->get('/api/security/scans', [SecurityScanController::class, 'latest']);
    $router->get('/api/security/scans/history', [SecurityScanController::class, 'history']);

    // OpenLiteSpeed Configuration
    $router->get('/api/ols/status', [OpenLiteSpeedController::class, 'status']);
    $router->get('/api/ols/settings', [OpenLiteSpeedController::class, 'settings']);
    $router->get('/api/ols/config/raw', [OpenLiteSpeedController::class, 'rawConfig']);
    $router->put('/api/ols/config/raw', [OpenLiteSpeedController::class, 'saveRawConfig']);
    $router->put('/api/ols/settings', [OpenLiteSpeedController::class, 'updateSettings']);
    $router->post('/api/ols/restart', [OpenLiteSpeedController::class, 'restart']);
    $router->post('/api/ols/reload', [OpenLiteSpeedController::class, 'reload']);
    $router->get('/api/ols/vhosts', [OpenLiteSpeedController::class, 'vhosts']);
    $router->get('/api/ols/calculator', [OpenLiteSpeedController::class, 'calculator']);

    // PHP Configuration
    $router->get('/api/php/versions', [PhpController::class, 'versions']);
    $router->get('/api/php/{version}/settings', [PhpController::class, 'settings']);
    $router->get('/api/php/{version}/config/raw', [PhpController::class, 'rawConfig']);
    $router->put('/api/php/{version}/config/raw', [PhpController::class, 'saveRawConfig']);
    $router->put('/api/php/{version}/settings', [PhpController::class, 'updateSettings']);
    $router->post('/api/php/{version}/restart', [PhpController::class, 'restart']);

    // MySQL Configuration
    $router->get('/api/mysql/status', [MysqlController::class, 'status']);
    $router->get('/api/mysql/settings', [MysqlController::class, 'settings']);
    $router->get('/api/mysql/config/raw', [MysqlController::class, 'rawConfig']);
    $router->put('/api/mysql/config/raw', [MysqlController::class, 'saveRawConfig']);
    $router->put('/api/mysql/settings', [MysqlController::class, 'updateSettings']);
    $router->post('/api/mysql/restart', [MysqlController::class, 'restart']);

    // Postfix Configuration
    $router->get('/api/postfix/status', [PostfixController::class, 'status']);
    $router->get('/api/postfix/settings', [PostfixController::class, 'settings']);
    $router->get('/api/postfix/config/raw', [PostfixController::class, 'rawConfig']);
    $router->put('/api/postfix/config/raw', [PostfixController::class, 'saveRawConfig']);
    $router->put('/api/postfix/settings', [PostfixController::class, 'updateSettings']);
    $router->post('/api/postfix/restart', [PostfixController::class, 'restart']);
    $router->post('/api/postfix/flush', [PostfixController::class, 'flush']);
    $router->get('/api/postfix/queue', [PostfixController::class, 'queue']);

    // Dovecot Configuration
    $router->get('/api/dovecot/status', [DovecotController::class, 'status']);
    $router->get('/api/dovecot/settings', [DovecotController::class, 'settings']);
    $router->get('/api/dovecot/config/raw', [DovecotController::class, 'rawConfig']);
    $router->put('/api/dovecot/config/raw', [DovecotController::class, 'saveRawConfig']);
    $router->put('/api/dovecot/settings', [DovecotController::class, 'updateSettings']);
    $router->post('/api/dovecot/restart', [DovecotController::class, 'restart']);
    $router->get('/api/dovecot/connections', [DovecotController::class, 'connections']);

    // Mail Security Gateway (Rspamd + ClamAV) - admin-only (enforced in controller)
    // Engine controls (proxy to agent, monitor-only - does NOT touch Postfix)
    $router->get('/api/mail-security/status', [MailSecurityController::class, 'status']);
    $router->post('/api/mail-security/install', [MailSecurityController::class, 'install']);
    $router->post('/api/mail-security/start', [MailSecurityController::class, 'start']);
    $router->post('/api/mail-security/stop', [MailSecurityController::class, 'stop']);
    $router->post('/api/mail-security/restart', [MailSecurityController::class, 'restart']);
    $router->get('/api/mail-security/config', [MailSecurityController::class, 'getConfig']);
    $router->put('/api/mail-security/config', [MailSecurityController::class, 'saveConfig']);
    $router->get('/api/mail-security/scores', [MailSecurityController::class, 'getScores']);
    $router->put('/api/mail-security/scores', [MailSecurityController::class, 'setScores']);
    $router->get('/api/mail-security/engine-stats', [MailSecurityController::class, 'getStats']);
    $router->get('/api/mail-security/clamav', [MailSecurityController::class, 'clamavStatus']);
    $router->post('/api/mail-security/clamav/update', [MailSecurityController::class, 'updateClamavSignatures']);
    $router->post('/api/mail-security/clamav/restart', [MailSecurityController::class, 'restartClamav']);
    $router->post('/api/mail-security/resolver/setup', [MailSecurityController::class, 'setupResolver']);
    // Delivery wiring (LIVE mail path: milter + quarantine routing; requires confirm)
    $router->get('/api/mail-security/delivery', [MailSecurityController::class, 'deliveryStatus']);
    $router->post('/api/mail-security/delivery/wire', [MailSecurityController::class, 'wireMilter']);
    $router->post('/api/mail-security/delivery/unwire', [MailSecurityController::class, 'unwireMilter']);
    // Live monitoring (read-only mail/rspamd log tail)
    $router->get('/api/mail-security/logs', [MailSecurityController::class, 'mailLog']);
    // Settings + dashboard (DB)
    $router->get('/api/mail-security/settings', [MailSecurityController::class, 'getSettings']);
    $router->put('/api/mail-security/settings', [MailSecurityController::class, 'updateSettings']);
    $router->get('/api/mail-security/overview', [MailSecurityController::class, 'overview']);
    $router->get('/api/mail-security/report', [MailSecurityController::class, 'report']);
    $router->get('/api/mail-security/report.csv', [MailSecurityController::class, 'reportCsv']);
    // Global lists (DB)
    $router->get('/api/mail-security/whitelist', [MailSecurityController::class, 'listWhitelist']);
    $router->post('/api/mail-security/whitelist', [MailSecurityController::class, 'addWhitelist']);
    $router->delete('/api/mail-security/whitelist', [MailSecurityController::class, 'deleteWhitelist']);
    $router->post('/api/mail-security/whitelist/import', [MailSecurityController::class, 'importWhitelist']);
    $router->get('/api/mail-security/whitelist.csv', [MailSecurityController::class, 'exportWhitelist']);
    $router->get('/api/mail-security/blacklist', [MailSecurityController::class, 'listBlacklist']);
    $router->post('/api/mail-security/blacklist', [MailSecurityController::class, 'addBlacklist']);
    $router->delete('/api/mail-security/blacklist', [MailSecurityController::class, 'deleteBlacklist']);
    $router->post('/api/mail-security/blacklist/import', [MailSecurityController::class, 'importBlacklist']);
    $router->get('/api/mail-security/blacklist.csv', [MailSecurityController::class, 'exportBlacklist']);
    // Push lists into the Rspamd engine (writes maps + reloads; no Postfix change)
    $router->post('/api/mail-security/sync', [MailSecurityController::class, 'syncEngine']);
    // Per-user allow/block lists (shared MailFlow webmail_* tables; resyncs Sieve)
    $router->get('/api/mail-security/user-lists/users', [MailSecurityController::class, 'listUserListUsers']);
    $router->get('/api/mail-security/user-lists', [MailSecurityController::class, 'getUserLists']);
    $router->post('/api/mail-security/user-lists/blocked', [MailSecurityController::class, 'addUserBlocked']);
    $router->delete('/api/mail-security/user-lists/blocked', [MailSecurityController::class, 'deleteUserBlocked']);
    $router->post('/api/mail-security/user-lists/safe', [MailSecurityController::class, 'addUserSafe']);
    $router->delete('/api/mail-security/user-lists/safe', [MailSecurityController::class, 'deleteUserSafe']);
    // Attachment policy + quarantine (DB, read)
    $router->get('/api/mail-security/attachment-policy', [MailSecurityController::class, 'listAttachmentPolicy']);
    $router->post('/api/mail-security/attachment-policy', [MailSecurityController::class, 'addAttachmentPolicy']);
    $router->delete('/api/mail-security/attachment-policy', [MailSecurityController::class, 'deleteAttachmentPolicy']);
    // Live SPF / DKIM / DMARC status across mail domains (read-only DNS lookups)
    $router->get('/api/mail-security/impersonation', [MailSecurityController::class, 'listImpersonation']);
    $router->post('/api/mail-security/impersonation', [MailSecurityController::class, 'addImpersonation']);
    $router->delete('/api/mail-security/impersonation', [MailSecurityController::class, 'deleteImpersonation']);
    // Mail flow rules engine (DB -> watched Rspamd map + Lua postfilter)
    $router->get('/api/mail-security/rules', [MailSecurityController::class, 'listRules']);
    $router->post('/api/mail-security/rules', [MailSecurityController::class, 'createRule']);
    $router->put('/api/mail-security/rules', [MailSecurityController::class, 'updateRule']);
    $router->delete('/api/mail-security/rules', [MailSecurityController::class, 'deleteRule']);
    // Geo-IP country filtering (global policy + per-domain overrides -> watched map + Lua)
    $router->get('/api/mail-security/geoip', [MailSecurityController::class, 'getGeoip']);
    $router->put('/api/mail-security/geoip', [MailSecurityController::class, 'updateGeoip']);
    $router->post('/api/mail-security/geoip/domain', [MailSecurityController::class, 'addGeoipDomain']);
    $router->delete('/api/mail-security/geoip/domain', [MailSecurityController::class, 'deleteGeoipDomain']);

    $router->get('/api/mail-security/auth-status', [MailSecurityController::class, 'authStatus']);
    // Per-domain Security Score (V3): derived from auth posture + inbound hygiene
    $router->get('/api/mail-security/security-score', [MailSecurityController::class, 'securityScore']);
    // Threat Center (V3): severity-bucketed threat aggregation over mail_security_events
    $router->get('/api/mail-security/threat-center', [MailSecurityController::class, 'threatCenter']);
    // AI phishing analysis (V3): on-demand message scoring via the AI Helper model
    $router->post('/api/mail-security/ai-analyze', [MailSecurityController::class, 'analyzePhishing']);
    // VirusTotal (V3): on-demand URL / file-hash reputation, cached
    $router->get('/api/mail-security/virustotal/config', [MailSecurityController::class, 'getVirustotalConfig']);
    $router->put('/api/mail-security/virustotal/config', [MailSecurityController::class, 'saveVirustotalConfig']);
    $router->post('/api/mail-security/virustotal/check', [MailSecurityController::class, 'checkVirustotal']);
    $router->get('/api/mail-security/virustotal/recent', [MailSecurityController::class, 'listVirustotalRecent']);
    $router->get('/api/mail-security/quarantine', [MailSecurityController::class, 'listQuarantine']);
    $router->post('/api/mail-security/quarantine/release', [MailSecurityController::class, 'releaseQuarantine']);
    $router->delete('/api/mail-security/quarantine', [MailSecurityController::class, 'deleteQuarantine']);
    $router->post('/api/mail-security/quarantine/maintenance', [MailSecurityController::class, 'runQuarantineMaintenance']);
    $router->post('/api/mail-security/events/sync', [MailSecurityController::class, 'syncEvents']);
    // Reactive learning loop: IMAPSieve feedback (mark-as-spam in any client trains Bayes)
    $router->get('/api/mail-security/learning', [MailSecurityController::class, 'learning']);
    $router->put('/api/mail-security/learning', [MailSecurityController::class, 'setLearning']);
    $router->put('/api/mail-security/learning/autolearn', [MailSecurityController::class, 'setBayesAutolearn']);

    // System Health Check (comprehensive audit with fix commands)
    $router->get('/api/system/health', [HealthController::class, 'index']);
    $router->post('/api/system/health/fix', [HealthController::class, 'fix']);

    // System Configuration
    $router->get('/api/system/info', [SystemController::class, 'info']);
    $router->get('/api/system/hostname', [SystemController::class, 'hostname']);
    $router->post('/api/system/hostname', [SystemController::class, 'hostname']);
    $router->get('/api/system/timezone', [SystemController::class, 'timezone']);
    $router->post('/api/system/timezone', [SystemController::class, 'timezone']);
    $router->get('/api/system/timezones', [SystemController::class, 'timezones']);
    $router->get('/api/system/ssh', [SystemController::class, 'ssh']);
    $router->get('/api/ssh/config/raw', [SystemController::class, 'sshRaw']);
    $router->put('/api/system/ssh', [SystemController::class, 'updateSsh']);
    $router->get('/api/system/swap', [SystemController::class, 'swap']);
    $router->post('/api/system/swap', [SystemController::class, 'createSwap']);
    $router->get('/api/system/swappiness', [SystemController::class, 'swappiness']);
    $router->post('/api/system/swappiness', [SystemController::class, 'swappiness']);
    $router->get('/api/system/uptime', [SystemController::class, 'uptime']);
    $router->post('/api/system/reboot', [SystemController::class, 'reboot']);

    // PowerDNS Configuration
    $router->get('/api/system/pdns', [SystemController::class, 'pdns']);
    $router->get('/api/system/pdns/status', [SystemController::class, 'pdnsStatus']);
    $router->put('/api/system/pdns', [SystemController::class, 'updatePdns']);
    $router->post('/api/system/pdns/restart', [SystemController::class, 'restartPdns']);

    // MOTD (Message of the Day)
    $router->get('/api/system/motd', [SystemController::class, 'motd']);
    $router->put('/api/system/motd', [SystemController::class, 'updateMotd']);

    // HTML Templates (Error Pages, Placeholder)
    $router->get('/api/system/templates', [SystemController::class, 'templates']);
    $router->get('/api/system/templates/sites', [SystemController::class, 'listSitesForTemplate']);
    $router->get('/api/system/templates/deployments', [SystemController::class, 'getTemplateDeployments']);
    $router->get('/api/system/templates/backups/{domain}', [SystemController::class, 'listTemplateBackups']);
    $router->post('/api/system/templates/revert/{domain}', [SystemController::class, 'revertTemplate']);
    $router->get('/api/system/templates/{id}', [SystemController::class, 'getTemplate']);
    $router->put('/api/system/templates/{id}', [SystemController::class, 'updateTemplate']);
    $router->post('/api/system/templates/{id}/apply', [SystemController::class, 'applyTemplateToSite']);
    $router->post('/api/system/templates/{id}/deploy-all', [SystemController::class, 'deployTemplateToAllSites']);

    // Service Config Permissions
    $router->get('/api/system/permissions', [SystemController::class, 'checkPermissions']);
    $router->get('/api/system/permissions/{service}', [SystemController::class, 'checkPermissions']);
    $router->post('/api/system/permissions/{service}/fix', [SystemController::class, 'fixPermissions']);

    // Config Syntax Check
    $router->post('/api/system/syntax-check', [SystemController::class, 'syntaxCheck']);

    // Cron Jobs
    $router->get('/api/cron', [CronController::class, 'index']);
    $router->get('/api/cron/logs', [CronController::class, 'logs']);
    $router->get('/api/cron/{id}', [CronController::class, 'show']);
    $router->post('/api/cron', [CronController::class, 'create']);
    $router->put('/api/cron/{id}', [CronController::class, 'update']);
    $router->delete('/api/cron/{id}', [CronController::class, 'delete']);
    $router->post('/api/cron/{id}/toggle', [CronController::class, 'toggle']);

    // Agent Diagnostics
    $router->get('/api/agent/diagnostics', [AgentController::class, 'diagnostics']);
    $router->post('/api/agent/restart', [AgentController::class, 'restart']);

    // Application Installer
    $router->get('/api/apps/templates', [AppController::class, 'templates']);
    $router->get('/api/apps', [AppController::class, 'index']);
    $router->get('/api/apps/site/{domain}', [AppController::class, 'siteApps']);
    $router->post('/api/apps/install', [AppController::class, 'install']);
    $router->get('/api/apps/{id}/status', [AppController::class, 'status']);
    $router->delete('/api/apps/{id}', [AppController::class, 'uninstall']);

    // WordPress Management
    $router->get('/api/wordpress/{domain}', [WordPressController::class, 'info']);
    $router->get('/api/wordpress/{domain}/plugins', [WordPressController::class, 'plugins']);
    $router->post('/api/wordpress/{domain}/plugins/update', [WordPressController::class, 'updatePlugin']);
    $router->post('/api/wordpress/{domain}/plugins/update-all', [WordPressController::class, 'updateAllPlugins']);
    $router->get('/api/wordpress/{domain}/themes', [WordPressController::class, 'themes']);
    $router->post('/api/wordpress/{domain}/themes/update-all', [WordPressController::class, 'updateAllThemes']);
    $router->get('/api/wordpress/{domain}/users', [WordPressController::class, 'users']);
    $router->post('/api/wordpress/{domain}/users/disable', [WordPressController::class, 'disableUser']);
    $router->post('/api/wordpress/{domain}/users/enable', [WordPressController::class, 'enableUser']);
    $router->post('/api/wordpress/{domain}/users/rename', [WordPressController::class, 'renameUser']);
    $router->get('/api/wordpress/{domain}/posts', [WordPressController::class, 'posts']);
    $router->post('/api/wordpress/{domain}/permissions', [WordPressController::class, 'permissions']);
    $router->post('/api/wordpress/{domain}/secure', [WordPressController::class, 'secureFiles']);
    $router->post('/api/wordpress/{domain}/unsecure', [WordPressController::class, 'unsecureFiles']);
    $router->post('/api/wordpress/{domain}/maintenance', [WordPressController::class, 'maintenance']);
    $router->get('/api/wordpress/{domain}/core', [WordPressController::class, 'core']);
    $router->post('/api/wordpress/{domain}/core/update', [WordPressController::class, 'updateCore']);
    $router->post('/api/wordpress/{domain}/update-all', [WordPressController::class, 'updateAll']);
    $router->get('/api/wordpress/{domain}/database', [WordPressController::class, 'dbInfo']);

    // Docker Management
    $router->get('/api/docker/status', [DockerController::class, 'status']);
    $router->post('/api/docker/install', [DockerController::class, 'install']);
    $router->get('/api/docker/overview', [DockerController::class, 'overview']);
    $router->get('/api/docker/containers', [DockerController::class, 'containers']);
    $router->get('/api/docker/images', [DockerController::class, 'images']);
    $router->get('/api/docker/volumes', [DockerController::class, 'volumes']);
    $router->get('/api/docker/networks', [DockerController::class, 'networks']);
    $router->get('/api/docker/containers/{id}', [DockerController::class, 'container']);
    $router->post('/api/docker/containers/{id}/start', [DockerController::class, 'start']);
    $router->post('/api/docker/containers/{id}/stop', [DockerController::class, 'stop']);
    $router->post('/api/docker/containers/{id}/restart', [DockerController::class, 'restart']);
    $router->get('/api/docker/containers/{id}/logs', [DockerController::class, 'logs']);
    $router->get('/api/docker/containers/{id}/stats', [DockerController::class, 'stats']);
    $router->get('/api/docker/containers/{id}/inspect', [DockerController::class, 'inspect']);
    $router->delete('/api/docker/containers/{id}', [DockerController::class, 'remove']);
    $router->post('/api/docker/images/pull', [DockerController::class, 'pull']);
    $router->post('/api/docker/compose/up', [DockerController::class, 'composeUp']);
    $router->post('/api/docker/compose/down', [DockerController::class, 'composeDown']);
    $router->get('/api/docker/stats', [DockerController::class, 'stats']);

    // File Manager (super_admin only – full file system access)
    $router->group(['middleware' => 'role:super_admin'], function($router) {
        $router->get('/api/files', [FileManagerController::class, 'list']);
        $router->get('/api/files/read', [FileManagerController::class, 'read']);
        $router->post('/api/files/write', [FileManagerController::class, 'write']);
        $router->post('/api/files/mkdir', [FileManagerController::class, 'mkdir']);
        $router->post('/api/files/delete', [FileManagerController::class, 'delete']);
        $router->post('/api/files/copy', [FileManagerController::class, 'copy']);
        $router->post('/api/files/move', [FileManagerController::class, 'move']);
        $router->post('/api/files/rename', [FileManagerController::class, 'rename']);
        $router->get('/api/files/info', [FileManagerController::class, 'info']);
        $router->post('/api/files/permissions', [FileManagerController::class, 'permissions']);
        $router->get('/api/files/search', [FileManagerController::class, 'search']);
        $router->post('/api/files/compress', [FileManagerController::class, 'compress']);
        $router->post('/api/files/extract', [FileManagerController::class, 'extract']);
        $router->get('/api/files/download', [FileManagerController::class, 'download']);
        $router->post('/api/files/upload', [FileManagerController::class, 'upload']);
    });

    // User Management (super_admin only - enforced in controller)
    $router->get('/api/users', [UserController::class, 'index']);
    $router->get('/api/users/{id}', [UserController::class, 'show']);
    $router->post('/api/users', [UserController::class, 'create']);
    $router->put('/api/users/{id}', [UserController::class, 'update']);
    $router->delete('/api/users/{id}', [UserController::class, 'delete']);
    $router->get('/api/users/{id}/sites', [UserController::class, 'getSites']);
    $router->put('/api/users/{id}/sites', [UserController::class, 'updateSites']);

    // Clients (super_admin only)
    $router->get('/api/clients', [ClientController::class, 'index']);
    $router->get('/api/clients/{id}', [ClientController::class, 'show']);
    $router->post('/api/clients', [ClientController::class, 'create']);
    $router->put('/api/clients/{id}', [ClientController::class, 'update']);
    $router->delete('/api/clients/{id}', [ClientController::class, 'delete']);
    $router->get('/api/clients/{id}/domains', [ClientController::class, 'getDomains']);
    $router->put('/api/clients/{id}/domains', [ClientController::class, 'updateDomains']);

    // Billing
    $router->get('/api/billing/subscriptions', [BillingController::class, 'subscriptions']);
    $router->get('/api/billing/subscriptions/{id}', [BillingController::class, 'showSubscription']);
    $router->post('/api/billing/subscriptions', [BillingController::class, 'createSubscription']);
    $router->put('/api/billing/subscriptions/{id}', [BillingController::class, 'updateSubscription']);
    $router->delete('/api/billing/subscriptions/{id}', [BillingController::class, 'deleteSubscription']);
    
    $router->get('/api/billing/payments', [BillingController::class, 'payments']);
    $router->post('/api/billing/payments', [BillingController::class, 'recordPayment']);
    $router->delete('/api/billing/payments/{id}', [BillingController::class, 'deletePayment']);
    
    $router->get('/api/billing/upcoming', [BillingController::class, 'upcoming']);
    $router->get('/api/billing/overdue', [BillingController::class, 'overdue']);
    $router->get('/api/billing/stats', [BillingController::class, 'stats']);
    $router->get('/api/billing/settings', [BillingController::class, 'getSettings']);
    $router->put('/api/billing/settings', [BillingController::class, 'updateSettings']);

    // Cache Management (super_admin only)
    $router->get('/api/cache/stats', [CacheController::class, 'stats']);
    $router->post('/api/cache/flush', [CacheController::class, 'flush']);
    $router->post('/api/cache/invalidate/{domain}', [CacheController::class, 'invalidateDomain']);
    $router->post('/api/cache/invalidate-type/{type}', [CacheController::class, 'invalidateType']);

    // Mail Migration (super_admin only)
    $router->get('/api/mail-migration/status', [MailMigrationController::class, 'status']);
    $router->post('/api/mail-migration/sync', [MailMigrationController::class, 'sync']);
    $router->post('/api/mail-migration/dual-write', [MailMigrationController::class, 'enableDualWrite']);
    $router->post('/api/mail-migration/switch', [MailMigrationController::class, 'switch']);
    $router->post('/api/mail-migration/rollback', [MailMigrationController::class, 'rollback']);
    $router->get('/api/mail-migration/verify', [MailMigrationController::class, 'verify']);
    $router->post('/api/mail-migration/complete', [MailMigrationController::class, 'complete']);

    // DNS Migration (super_admin only)
    $router->get('/api/dns-migration/status', [DnsMigrationController::class, 'status']);
    $router->post('/api/dns-migration/sync', [DnsMigrationController::class, 'sync']);
    $router->post('/api/dns-migration/dual-write', [DnsMigrationController::class, 'enableDualWrite']);
    $router->post('/api/dns-migration/switch', [DnsMigrationController::class, 'switch']);
    $router->post('/api/dns-migration/rollback', [DnsMigrationController::class, 'rollback']);
    $router->get('/api/dns-migration/verify', [DnsMigrationController::class, 'verify']);
    $router->post('/api/dns-migration/complete', [DnsMigrationController::class, 'complete']);

    // IMAP Migration (email transfer from external servers)
    $router->get('/api/imap-migration', [ImapMigrationController::class, 'list']);
    $router->get('/api/imap-migration/active', [ImapMigrationController::class, 'active']);
    $router->post('/api/imap-migration/start', [ImapMigrationController::class, 'start']);
    $router->post('/api/imap-migration/preflight', [ImapMigrationController::class, 'preflight']);
    $router->get('/api/imap-migration/{id}', [ImapMigrationController::class, 'show']);
    $router->get('/api/imap-migration/{id}/status', [ImapMigrationController::class, 'status']);
    $router->get('/api/imap-migration/{id}/logs', [ImapMigrationController::class, 'logs']);
    $router->post('/api/imap-migration/{id}/cancel', [ImapMigrationController::class, 'cancel']);
    // Delta-sync scheduler: periodic delta config, manual re-run, cutover+sweep.
    $router->post('/api/imap-migration/{id}/schedule', [ImapMigrationController::class, 'schedule']);
    $router->post('/api/imap-migration/{id}/run', [ImapMigrationController::class, 'runNow']);
    $router->post('/api/imap-migration/{id}/finalize', [ImapMigrationController::class, 'finalize']);
    $router->delete('/api/imap-migration/{id}', [ImapMigrationController::class, 'delete']);

    // Contacts (VCF/CSV) + Calendar (ICS) migration — forwards to FlowOne
    // /internal/dav-import. Lives alongside the imapsync mail migration.
    $router->get('/api/migration/dav-import', [MigrationDataController::class, 'list']);
    $router->post('/api/migration/dav-import', [MigrationDataController::class, 'import']);
    $router->delete('/api/migration/dav-import', [MigrationDataController::class, 'clear']);
    // Reverse direction: pull a user's contacts/calendar out of FlowOne.
    $router->post('/api/migration/dav-export', [MigrationDataController::class, 'export']);

    // Migration readiness checklist (DB-persisted board for the migration UI).
    // Specific routes before the wildcard {id} routes.
    $router->get('/api/migration-checklist', [MigrationChecklistController::class, 'index']);
    $router->post('/api/migration-checklist', [MigrationChecklistController::class, 'create']);
    $router->post('/api/migration-checklist/reset', [MigrationChecklistController::class, 'reset']);
    $router->put('/api/migration-checklist/{id}', [MigrationChecklistController::class, 'update']);
    $router->delete('/api/migration-checklist/{id}', [MigrationChecklistController::class, 'delete']);

    // AI Helper
    $router->get('/api/ai-helper/conversations', [AIHelperController::class, 'listConversations']);
    $router->post('/api/ai-helper/conversations', [AIHelperController::class, 'createConversation']);
    $router->get('/api/ai-helper/conversations/{id}', [AIHelperController::class, 'getConversation']);
    $router->delete('/api/ai-helper/conversations/{id}', [AIHelperController::class, 'deleteConversation']);
    $router->post('/api/ai-helper/conversations/{id}/messages', [AIHelperController::class, 'sendMessage']);
    $router->post('/api/ai-helper/dry-run', [AIHelperController::class, 'dryRunCommand']);
    $router->get('/api/ai-helper/cached-issues', [AIHelperController::class, 'getCachedIssues']);
    $router->post('/api/ai-helper/cached-issues/{id}/resolve', [AIHelperController::class, 'resolveIssue']);
    $router->get('/api/ai-helper/config-files', [AIHelperController::class, 'getConfigFiles']);
    $router->post('/api/ai-helper/analyze-config', [AIHelperController::class, 'analyzeConfig']);
    $router->post('/api/ai-helper/analyze-logs', [AIHelperController::class, 'analyzeLogs']);
    $router->get('/api/ai-helper/settings', [AIHelperController::class, 'getSettings']);
    $router->put('/api/ai-helper/settings', [AIHelperController::class, 'updateSettings']);

    // phpMyAdmin Access Token
    $router->post('/api/phpmyadmin/token', [PhpMyAdminController::class, 'generateToken']);

    // NAS Storage Management (super_admin only)
    $router->get('/api/nas', [NASController::class, 'index']);
    $router->get('/api/nas/health', [NASController::class, 'healthStatus']);
    $router->post('/api/nas/health/check', [NASController::class, 'healthCheck']);
    $router->get('/api/nas/health/history', [NASController::class, 'healthHistory']);
    $router->get('/api/nas/diagnostics', [NASController::class, 'diagnostics']);
    $router->get('/api/nas/domains', [NASController::class, 'allDomainOverrides']);
    $router->get('/api/nas/config/{domain}', [NASController::class, 'getConfigForDomain']);
    $router->get('/api/nas/{id}', [NASController::class, 'show']);
    $router->post('/api/nas', [NASController::class, 'create']);
    $router->put('/api/nas/{id}', [NASController::class, 'update']);
    $router->delete('/api/nas/{id}', [NASController::class, 'delete']);
    $router->post('/api/nas/{id}/test', [NASController::class, 'test']);
    $router->post('/api/nas/{id}/provision', [NASController::class, 'provision']);
    $router->post('/api/nas/{id}/mount', [NASController::class, 'mount']);
    $router->post('/api/nas/{id}/unmount', [NASController::class, 'unmount']);
    $router->get('/api/nas/{id}/stats', [NASController::class, 'stats']);
    $router->post('/api/nas/{id}/default', [NASController::class, 'setDefault']);
    $router->get('/api/nas/{id}/domains', [NASController::class, 'getDomainOverrides']);
    $router->post('/api/nas/{id}/domains', [NASController::class, 'assignDomain']);
    $router->delete('/api/nas/domains/{domain}', [NASController::class, 'removeDomain']);

    // Addon Management (admin)
    $router->get('/api/addons', [AddonController::class, 'list']);
    $router->put('/api/addons/{slug}/toggle', [AddonController::class, 'toggle']);

    // Email Addon Users, Groups & Assignments (admin)
    $router->get('/api/email-addons/users', [EmailAddonsController::class, 'users']);
    $router->get('/api/email-addons/users/{email}/sessions', [EmailAddonsController::class, 'userSessions']);
    $router->get('/api/email-addons/groups', [EmailAddonsController::class, 'listGroups']);
    $router->post('/api/email-addons/groups', [EmailAddonsController::class, 'createGroup']);
    $router->put('/api/email-addons/groups/{id}', [EmailAddonsController::class, 'updateGroup']);
    $router->delete('/api/email-addons/groups/{id}', [EmailAddonsController::class, 'deleteGroup']);
    $router->post('/api/email-addons/groups/{id}/members', [EmailAddonsController::class, 'addMembers']);
    $router->delete('/api/email-addons/groups/{id}/members/{email}', [EmailAddonsController::class, 'removeMember']);
    $router->get('/api/email-addons/assignments', [EmailAddonsController::class, 'listAssignments']);
    $router->put('/api/email-addons/assign', [EmailAddonsController::class, 'setAssignment']);
    $router->delete('/api/email-addons/assign', [EmailAddonsController::class, 'removeAssignment']);
    $router->get('/api/email-addons/resolve', [EmailAddonsController::class, 'resolveForUser']);

    // VPN Connection Management (super_admin only)
    $router->get('/api/vpn', [VPNController::class, 'index']);
    $router->get('/api/vpn/{name}', [VPNController::class, 'show']);
    $router->post('/api/vpn', [VPNController::class, 'create']);
    $router->put('/api/vpn/{name}', [VPNController::class, 'update']);
    $router->delete('/api/vpn/{name}', [VPNController::class, 'delete']);
    $router->post('/api/vpn/{name}/start', [VPNController::class, 'start']);
    $router->post('/api/vpn/{name}/stop', [VPNController::class, 'stop']);
    $router->post('/api/vpn/{name}/restart', [VPNController::class, 'restart']);
    $router->get('/api/vpn/{name}/logs', [VPNController::class, 'logs']);
    $router->get('/api/vpn/{name}/config', [VPNController::class, 'getConfig']);
});

}); // end rate_limit group

