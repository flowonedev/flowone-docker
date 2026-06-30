<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class SiteController extends BaseController
{
    /**
     * List all sites (filtered by user permissions).
     *
     * V2 cutover (consolidation plan, Phase 4):
     *   This endpoint is the back-compat surface for the four dropdown
     *   consumers (UsersView, OverviewView, NASStorageView,
     *   ClientDetailView). Instead of scanning OLS vhosts via the
     *   soon-to-be-deleted `vhost.list` agent action, we read the V2
     *   `sites` table and reshape each row into the legacy vhost
     *   format the consumers already expect. That keeps the dropdowns
     *   working after Phase 5 deletes the legacy create/delete code.
     *
     * The fallback to `vhost.list` only fires if the V2 listing
     * returned nothing AND the cache is empty - which only happens on
     * a freshly-installed box where no sites have been provisioned
     * through V2 yet. Operators with mixed legacy+V2 sites should run
     * `backfill-sites-from-vhosts.php` to populate the V2 table.
     */
    public function index(Request $request): Response
    {
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = 'sites:list';

        if (!$forceRefresh) {
            $cachedData = $this->cache->get($cacheKey);
            if ($cachedData !== null) {
                return $this->filterSitesResponse($cachedData);
            }
        }

        // V2 source of truth: paginate up to 500 rows in one shot so
        // dropdowns get the whole list. The V2 listSites endpoint
        // already filters out tombstones (actual_state='absent') by
        // default, which is the right behavior here.
        $v2Result = $this->agent->execute('provisioning.listSites', [
            'per_page' => 500,
            'page' => 1,
        ], $this->getActor());

        $vhostsShape = null;
        if (($v2Result['success'] ?? false) && isset($v2Result['data']['sites'])) {
            $vhostsShape = [
                'vhosts' => $this->v2SitesToLegacyVhosts($v2Result['data']['sites']),
                'count' => count($v2Result['data']['sites']),
                'source' => 'v2',
            ];
        }

        // Fallback for environments that haven't been backfilled yet.
        if ($vhostsShape === null || ($vhostsShape['count'] === 0)) {
            $legacy = $this->agent->execute('vhost.list', [], $this->getActor());
            if (!empty($legacy['success'])) {
                $vhostsShape = $legacy['data'];
                $vhostsShape['source'] = 'legacy-vhost-list';
            }
        }

        if ($vhostsShape === null) {
            return Response::error(
                $v2Result['error'] ?? 'Failed to list sites');
        }

        $this->cache->set($cacheKey, $vhostsShape);
        return $this->filterSitesResponse($vhostsShape);
    }

    /**
     * Map a list of V2 site rows into the legacy vhost shape consumers
     * expect. Only the fields actually used by dropdown consumers are
     * mapped; everything else from the V2 row passes through so a
     * curious consumer that reads e.g. `actual_state` still works.
     *
     * @param list<array<string,mixed>> $sites
     * @return list<array<string,mixed>>
     */
    private function v2SitesToLegacyVhosts(array $sites): array
    {
        $out = [];
        foreach ($sites as $s) {
            $out[] = array_merge($s, [
                // Legacy `vhost.list` shape keys (still consumed by
                // UsersView / OverviewView / NASStorageView /
                // ClientDetailView site dropdowns).
                'domain' => $s['domain'] ?? '',
                'php_handler' => $s['php_version'] ?? 'lsphp83',
                'php_version' => $s['php_version'] ?? 'lsphp83',
                'document_root' => $s['document_root']
                    ?? ('/home/' . ($s['domain'] ?? '') . '/public_html'),
                'home_dir' => $s['home_dir']
                    ?? ('/home/' . ($s['domain'] ?? '')),
                'system_user' => $s['sftp_user'] ?? null,
                'sftp_user' => $s['sftp_user'] ?? null,
                'enabled' => ($s['actual_state'] ?? '') === 'active',
                'ssl' => (bool) ($s['ssl_enabled'] ?? false),
                'ssl_expires_at' => $s['ssl_expires_at'] ?? null,
                'ssl_issuer' => $s['ssl_issuer'] ?? null,
                'size_bytes' => $s['size_bytes'] ?? null,
                'state' => $s['actual_state'] ?? null,
            ]);
        }
        return $out;
    }
    
    /**
     * Filter sites response based on user permissions
     */
    private function filterSitesResponse(array $data): Response
    {
        $allowedSites = $this->getAllowedSites();
        
        // If null (super_admin), return all sites
        if ($allowedSites === null) {
            return Response::success($data, 'Success');
        }
        
        // Filter sites for regular users
        $vhosts = $data['vhosts'] ?? [];
        $filteredVhosts = array_values(array_filter($vhosts, function($vhost) use ($allowedSites) {
            return in_array($vhost['domain'], $allowedSites);
        }));
        
        return Response::success([
            'vhosts' => $filteredVhosts,
            'count' => count($filteredVhosts),
        ], 'Success');
    }

    /**
     * Get site details
     */
    public function show(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        // Check for force refresh
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = "site:{$domain}";
        
        if (!$forceRefresh) {
            $cachedData = $this->cache->get($cacheKey);
            if ($cachedData !== null) {
                return Response::success($cachedData, 'Success');
            }
        }
        
        $result = $this->agent->execute('vhost.get', ['domain' => $domain], $this->getActor());
        
        if ($result['success']) {
            $this->cache->set($cacheKey, $result['data']);
            return Response::success($result['data'], $result['message'] ?? 'Success');
        }
        
        return Response::error($result['error'] ?? 'Failed to get site details');
    }

    // ------------------------------------------------------------------
    // Legacy synchronous create() removed in Phase 5 of the V2
    // consolidation. Site creation is now exclusively the
    // SiteProvisioningController POST /api/sites/v2 endpoint which
    // enqueues a saga job. The legacy `vhost.create` agent action and
    // the PHP-side `VhostAction::actionCreate` are also being retired.
    // ------------------------------------------------------------------

    /**
     * Update site configuration
     */
    public function update(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Infrastructure-level mutation (document_root / PHP runtime):
        // site-owner access is not enough, this rewrites the vhost
        // config on disk. Admin only.
        if ($err = $this->requireAdmin()) {
            return $err;
        }

        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        $params = array_filter([
            'domain' => $domain,
            'document_root' => $request->input('document_root'),
            'php_lsapi' => $request->input('php_version'),
        ]);

        $result = $this->agent->execute('vhost.update', $params, $this->getActor());
        
        if ($result['success']) {
            $this->logAction('site.update', $domain, 'success', [
                'diff' => $result['data']['diff'] ?? null,
            ]);
            return Response::success($result['data'], $result['message'] ?? "Site {$domain} updated");
        }
        
        $this->logAction('site.update', $domain, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to update site');
    }

    // ------------------------------------------------------------------
    // Legacy synchronous delete() removed in Phase 5 of the V2
    // consolidation. Site deletion now goes through
    // DELETE /api/sites/v2/{domain} (enqueues a delete saga). The
    // SiteProvisioningController also offers POST .../archive,
    // .../restore, and .../purge for the full lifecycle.
    // ------------------------------------------------------------------

    /**
     * Get site vhost configuration
     */
    public function getConfig(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        return $this->agentAction('vhost.config', ['domain' => $domain]);
    }

    /**
     * Update site vhost configuration
     */
    public function updateConfig(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        $validation = $this->validateRequired($request, ['config']);
        if ($validation) return $validation;

        $result = $this->agent->execute('vhost.saveConfig', [
            'domain' => $domain,
            'config' => $request->input('config'),
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('site.config.update', $domain, 'success');
            return Response::success($result['data'], $result['message'] ?? "Configuration for {$domain} saved");
        }
        
        $this->logAction('site.config.update', $domain, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to save configuration');
    }

    /**
     * Update specific config values (safe targeted updates)
     */
    public function updateConfigValues(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        $changes = $request->input('changes');
        if (!is_array($changes) || empty($changes)) {
            return Response::error('Changes array is required');
        }

        $result = $this->agent->execute('vhost.updateConfigValues', [
            'domain' => $domain,
            'changes' => $changes,
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('site.config.values', $domain, 'success', [
                'applied' => count($result['data']['applied'] ?? []),
            ]);
            return Response::success($result['data'], $result['message'] ?? "Configuration values updated");
        }
        
        $this->logAction('site.config.values', $domain, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to update configuration values');
    }

    /**
     * Get site logs
     */
    public function getLogs(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        $type = $request->input('type', 'error'); // 'error' or 'access'
        $lines = (int) $request->input('lines', 100);

        return $this->agentAction('vhost.logs', [
            'domain' => $domain,
            'type' => $type,
            'lines' => min($lines, 500), // Cap at 500 lines
        ]);
    }

    /**
     * Get FTP/SFTP status and SSH info
     */
    public function getFtpStatus(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        return $this->agentAction('vhost.ftpStatus', ['domain' => $domain]);
    }

    /**
     * Get SSH keys for site user
     */
    public function getSshKeys(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        return $this->agentAction('vhost.sshKeys', ['domain' => $domain]);
    }

    /**
     * Add SSH key for site user
     */
    public function addSshKey(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        $validation = $this->validateRequired($request, ['key']);
        if ($validation) return $validation;

        $result = $this->agent->execute('vhost.addSshKey', [
            'domain' => $domain,
            'key' => $request->input('key'),
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('site.ssh_key.add', $domain, 'success');
            return Response::success($result['data'], $result['message'] ?? 'SSH key added');
        }
        
        $this->logAction('site.ssh_key.add', $domain, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to add SSH key');
    }

    /**
     * Update SSH key for site user
     */
    public function updateSshKey(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        $index = (int) $request->getParam('index');
        
        $validation = $this->validateRequired($request, ['key']);
        if ($validation) return $validation;

        $result = $this->agent->execute('vhost.updateSshKey', [
            'domain' => $domain,
            'index' => $index,
            'key' => $request->input('key'),
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('site.ssh_key.update', $domain, 'success');
            return Response::success($result['data'], $result['message'] ?? 'SSH key updated');
        }
        
        $this->logAction('site.ssh_key.update', $domain, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to update SSH key');
    }

    /**
     * Remove SSH key for site user
     */
    public function removeSshKey(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        $index = (int) $request->getParam('index');

        $result = $this->agent->execute('vhost.removeSshKey', [
            'domain' => $domain,
            'index' => $index,
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('site.ssh_key.remove', $domain, 'success');
            return Response::success($result['data'], $result['message'] ?? 'SSH key removed');
        }
        
        $this->logAction('site.ssh_key.remove', $domain, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to remove SSH key');
    }

    /**
     * Fix SSH permissions for site
     */
    public function fixSshPermissions(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('vhost.fixSshPermissions', [
            'domain' => $domain,
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('site.ssh_permissions.fix', $domain, 'success', [
                'fixed' => $result['data']['fixed'] ?? [],
            ]);
            return Response::success($result['data'], $result['message'] ?? 'SSH permissions fixed');
        }
        
        $this->logAction('site.ssh_permissions.fix', $domain, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to fix SSH permissions');
    }

    /**
     * Get databases associated with a site
     * Detects from wp-config.php or by naming convention
     */
    public function getDatabases(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('vhost.getDatabases', [
            'domain' => $domain,
        ], $this->getActor());
        
        if ($result['success']) {
            return Response::success($result['data'], 'Success');
        }
        
        return Response::error($result['error'] ?? 'Failed to get databases');
    }

    /**
     * Validate site configuration, permissions, folders, SSL, and syntax
     */
    public function validateSite(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        $result = $this->agent->execute('vhost.validateSite', ['domain' => $domain], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('site.validate', $domain, 'success', [
                'valid' => $result['data']['valid'] ?? false,
                'issues_count' => count($result['data']['issues'] ?? []),
                'warnings_count' => count($result['data']['warnings'] ?? []),
            ]);
            return Response::success($result['data'], $result['message'] ?? 'Validation completed');
        }
        
        $this->logAction('site.validate', $domain, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to validate site');
    }

    /**
     * Fix site validation issues
     */
    public function fixSite(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        $result = $this->agent->execute('vhost.fixSite', ['domain' => $domain], $this->getActor());
        
        if ($result['success']) {
            // Invalidate cache after fixes
            $this->cache->invalidateForDomain($domain);
            
            $this->logAction('site.fix', $domain, 'success', [
                'fixed_count' => $result['data']['fixed_count'] ?? 0,
                'fixed' => $result['data']['fixed'] ?? [],
            ]);
            return Response::success($result['data'], $result['message'] ?? 'Fixes applied');
        }
        
        $this->logAction('site.fix', $domain, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to fix site');
    }

    /**
     * Fix a single site validation issue
     */
    public function fixSiteIssue(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check if user can access this site
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        $validation = $this->validateRequired($request, ['issue_type']);
        if ($validation) return $validation;
        
        $result = $this->agent->execute('vhost.fixSiteIssue', [
            'domain' => $domain,
            'issue_type' => $request->input('issue_type'),
        ], $this->getActor());
        
        if ($result['success']) {
            // Invalidate cache after fix
            $this->cache->invalidateForDomain($domain);
            
            $this->logAction('site.fix.issue', $domain, 'success', [
                'issue_type' => $request->input('issue_type'),
                'fixed' => $result['data']['fixed'] ?? [],
            ]);
            return Response::success($result['data'], $result['message'] ?? 'Issue fixed');
        }
        
        $this->logAction('site.fix.issue', $domain, 'failed', [
            'issue_type' => $request->input('issue_type'),
            'error' => $result['error'],
        ]);
        return Response::error($result['error'] ?? 'Failed to fix issue');
    }

    /**
     * Validate site deletion cleanup
     */
    public function validateDeletion(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Only super_admin can validate deletions
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $result = $this->agent->execute('vhost.validateDeletion', ['domain' => $domain], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('site.deletion.validate', $domain, 'success', [
                'clean' => $result['data']['clean'] ?? false,
                'issues_count' => count($result['data']['issues'] ?? []),
            ]);
            return Response::success($result['data'], $result['message'] ?? 'Deletion validation completed');
        }
        
        $this->logAction('site.deletion.validate', $domain, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to validate deletion');
    }

    /**
     * Fix site deletion cleanup issues
     */
    public function fixDeletion(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Only super_admin can fix deletions
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $result = $this->agent->execute('vhost.fixDeletion', ['domain' => $domain], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('site.deletion.fix', $domain, 'success', [
                'fixed_count' => $result['data']['fixed_count'] ?? 0,
                'fixed' => $result['data']['fixed'] ?? [],
            ]);
            return Response::success($result['data'], $result['message'] ?? 'Cleanup fixes applied');
        }
        
        $this->logAction('site.deletion.fix', $domain, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to fix deletion cleanup');
    }
}

