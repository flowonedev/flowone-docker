<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class DatabaseController extends BaseController
{
    /**
     * Ensure database_links table exists
     */
    private function ensureLinkTable(): void
    {
        static $checked = false;
        if ($checked) return;
        
        $db = $this->container->getDatabase();
        $db->exec("
            CREATE TABLE IF NOT EXISTS database_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                db_name VARCHAR(64) NOT NULL,
                db_user VARCHAR(64),
                domain VARCHAR(255) NOT NULL,
                db_host VARCHAR(255) NOT NULL DEFAULT 'localhost',
                created_by INT,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_db_domain (db_name, domain),
                INDEX idx_domain (domain),
                INDEX idx_db_name (db_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $checked = true;
    }

    /**
     * Get database links from our tracking table
     */
    private function getDatabaseLinks(): array
    {
        $this->ensureLinkTable();
        $db = $this->container->getDatabase();
        
        $stmt = $db->query("
            SELECT db_name, db_user, domain, db_host, notes, created_at 
            FROM database_links
        ");
        
        $links = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $dbName = $row['db_name'];
            if (!isset($links[$dbName])) {
                $links[$dbName] = [];
            }
            $links[$dbName][] = [
                'domain' => $row['domain'],
                'db_user' => $row['db_user'],
                'db_host' => $row['db_host'],
                'notes' => $row['notes'],
                'created_at' => $row['created_at'],
            ];
        }
        
        return $links;
    }

    /**
     * List all databases with link information
     */
    public function index(Request $request): Response
    {
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = 'db:list';
        
        // Get databases from agent (possibly cached)
        $result = null;
        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $result = ['success' => true, 'data' => $cached];
            }
        }
        
        if (!$result) {
            $result = $this->agent->execute('db.list', [], $this->getActor());
            if ($result['success']) {
                $this->cache->set($cacheKey, $result['data']);
            }
        }
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to list databases');
        }
        
        // Enhance with our link tracking
        $databases = $result['data']['databases'] ?? [];
        $links = $this->getDatabaseLinks();
        
        foreach ($databases as &$db) {
            $dbName = $db['name'];
            
            // Add linked sites from our tracking
            if (isset($links[$dbName])) {
                $db['linked_sites'] = $links[$dbName];
                // For backward compatibility, set first domain as linked_site
                $db['linked_site'] = $links[$dbName][0]['domain'] ?? null;
            } else {
                $db['linked_sites'] = [];
                // Keep the guessed linked_site for databases not in our tracking
            }
        }
        
        return Response::success(['databases' => $databases], 'Success');
    }

    /**
     * Get database details
     */
    public function show(Request $request): Response
    {
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('db.get', ['name' => $name], $this->getActor());
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to get database');
        }
        
        // Add link info
        $this->ensureLinkTable();
        $db = $this->container->getDatabase();
        
        $stmt = $db->prepare("SELECT domain, db_user, db_host, notes, created_at FROM database_links WHERE db_name = ?");
        $stmt->execute([$name]);
        $result['data']['linked_sites'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return Response::success($result['data'], $result['message'] ?? 'Success');
    }

    /**
     * Create a database
     */
    public function create(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['name']);
        if ($validation) return $validation;

        $params = [
            'name' => $request->input('name'),
        ];

        if ($request->input('user')) {
            $params['user'] = $request->input('user');
            $params['password'] = $request->input('password');
            $params['host'] = $request->input('host', 'localhost');
        }

        $result = $this->agent->execute('db.create', $params, $this->getActor());
        
        if ($result['success']) {
            // If a domain is provided, create the site link. Accept
            // `site_domain` as an alias so both the per-site Databases
            // tab and the global/WP-install callers record the link.
            $domain = $request->input('domain') ?? $request->input('site_domain');
            if ($domain) {
                $this->linkDatabaseToSite(
                    $params['name'],
                    $domain,
                    $params['user'] ?? null,
                    $params['host'] ?? 'localhost',
                    $request->input('notes')
                );
            }
            
            $this->cache->invalidateDatabases();
            $this->logAction('database.create', $params['name'], 'success', [
                'domain' => $domain,
            ]);
            return Response::success($result['data'], $result['message'] ?? 'Database created');
        }
        
        $this->logAction('database.create', $params['name'], 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to create database');
    }

    /**
     * Delete a database
     */
    public function delete(Request $request): Response
    {
        $name = $request->getParam('name');

        $result = $this->agent->execute('db.delete', ['name' => $name], $this->getActor());
        
        if ($result['success']) {
            // Remove all links for this database
            $this->ensureLinkTable();
            $db = $this->container->getDatabase();
            $stmt = $db->prepare("DELETE FROM database_links WHERE db_name = ?");
            $stmt->execute([$name]);
            
            $this->cache->invalidateDatabases();
            $this->logAction('database.delete', $name, 'success', [
                'backup' => $result['data']['backup'] ?? null,
            ]);
            return Response::success($result['data'], $result['message'] ?? "Database {$name} deleted");
        }
        
        $this->logAction('database.delete', $name, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to delete database');
    }

    /**
     * Get database size
     */
    public function size(Request $request): Response
    {
        $name = $request->getParam('name');
        return $this->agentAction('db.size', ['name' => $name]);
    }

    /**
     * List database users
     */
    public function users(Request $request): Response
    {
        return $this->agentAction('db.users');
    }

    /**
     * Create database user
     */
    public function createUser(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['user']);
        if ($validation) return $validation;

        $params = [
            'user' => $request->input('user'),
            'password' => $request->input('password'),
            'host' => $request->input('host', 'localhost'),
            'database' => $request->input('database'),
        ];

        $result = $this->agent->execute('db.createUser', $params, $this->getActor());
        
        if ($result['success']) {
            $this->logAction('db_user.create', $params['user'], 'success');
            return Response::success($result['data'], $result['message'] ?? 'User created');
        }
        
        $this->logAction('db_user.create', $params['user'], 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to create user');
    }

    /**
     * Delete database user
     */
    public function deleteUser(Request $request): Response
    {
        $user = $request->getParam('user');
        $host = $request->input('host', 'localhost');

        $result = $this->agent->execute('db.deleteUser', [
            'user' => $user,
            'host' => $host,
        ], $this->getActor());
        
        if ($result['success']) {
            $this->logAction('db_user.delete', $user, 'success');
            return Response::success($result['data'], $result['message'] ?? 'User deleted');
        }
        
        $this->logAction('db_user.delete', $user, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to delete user');
    }

    /**
     * Reset user password
     */
    public function resetPassword(Request $request): Response
    {
        $user = $request->getParam('user');

        $params = [
            'user' => $user,
            'password' => $request->input('password'),
            'host' => $request->input('host', 'localhost'),
            // Optional: re-apply ALL PRIVILEGES on this DB so a reset also
            // heals a missing/incomplete grant from a degraded provision.
            'database' => $request->input('database'),
        ];

        $result = $this->agent->execute('db.resetPassword', $params, $this->getActor());
        
        if ($result['success']) {
            $this->logAction('db_user.reset_password', $user, 'success');
            return Response::success($result['data'], $result['message'] ?? 'Password reset');
        }
        
        $this->logAction('db_user.reset_password', $user, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to reset password');
    }

    // =========================================================================
    // Database-Site Link Management
    // =========================================================================

    /**
     * Link a database to a site
     */
    public function linkToSite(Request $request): Response
    {
        $dbName = $request->getParam('name');
        $validation = $this->validateRequired($request, ['domain']);
        if ($validation) return $validation;

        $domain = $request->input('domain');
        $dbUser = $request->input('db_user');
        $dbHost = $request->input('db_host', 'localhost');
        $notes = $request->input('notes');

        $success = $this->linkDatabaseToSite($dbName, $domain, $dbUser, $dbHost, $notes);
        
        if ($success) {
            $this->cache->invalidateDatabases();
            $this->logAction('database.link', $dbName, 'success', ['domain' => $domain]);
            return Response::success([
                'db_name' => $dbName,
                'domain' => $domain,
            ], "Database {$dbName} linked to {$domain}");
        }
        
        return Response::error("Failed to link database to site");
    }

    /**
     * Unlink a database from a site
     */
    public function unlinkFromSite(Request $request): Response
    {
        $dbName = $request->getParam('name');
        $domain = $request->getParam('domain');

        $this->ensureLinkTable();
        $db = $this->container->getDatabase();
        
        $stmt = $db->prepare("DELETE FROM database_links WHERE db_name = ? AND domain = ?");
        $stmt->execute([$dbName, $domain]);
        
        if ($stmt->rowCount() > 0) {
            $this->cache->invalidateDatabases();
            $this->logAction('database.unlink', $dbName, 'success', ['domain' => $domain]);
            return Response::success([
                'db_name' => $dbName,
                'domain' => $domain,
            ], "Database {$dbName} unlinked from {$domain}");
        }
        
        return Response::error("Link not found");
    }

    /**
     * Get links for a specific database
     */
    public function getLinks(Request $request): Response
    {
        $dbName = $request->getParam('name');
        
        $this->ensureLinkTable();
        $db = $this->container->getDatabase();
        
        $stmt = $db->prepare("
            SELECT domain, db_user, db_host, notes, created_at 
            FROM database_links 
            WHERE db_name = ?
            ORDER BY domain
        ");
        $stmt->execute([$dbName]);
        $links = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return Response::success([
            'db_name' => $dbName,
            'links' => $links,
        ], 'Success');
    }

    /**
     * Detect orphan databases (not linked to any site)
     */
    public function orphans(Request $request): Response
    {
        // Get all databases from agent
        $result = $this->agent->execute('db.list', ['show_all' => false], $this->getActor());
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to list databases');
        }
        
        $databases = $result['data']['databases'] ?? [];
        $links = $this->getDatabaseLinks();
        
        $orphans = [];
        $linked = [];
        
        foreach ($databases as $db) {
            if ($db['is_system']) continue;
            
            $dbName = $db['name'];
            
            if (isset($links[$dbName]) && !empty($links[$dbName])) {
                $linked[] = [
                    'name' => $dbName,
                    'size' => $db['size'],
                    'size_human' => $db['size_human'],
                    'linked_to' => $links[$dbName],
                ];
            } else {
                $orphans[] = [
                    'name' => $dbName,
                    'size' => $db['size'],
                    'size_human' => $db['size_human'],
                    'tables_count' => $db['tables_count'],
                    'users' => $db['users'],
                    'guessed_site' => $db['linked_site'], // The pattern-based guess
                ];
            }
        }
        
        return Response::success([
            'orphans' => $orphans,
            'orphan_count' => count($orphans),
            'linked' => $linked,
            'linked_count' => count($linked),
            'total' => count($orphans) + count($linked),
        ], 'Success');
    }

    /**
     * Bulk link databases to sites (auto-detect based on naming patterns)
     */
    public function autoLink(Request $request): Response
    {
        // Get all databases and sites
        $dbResult = $this->agent->execute('db.list', ['show_all' => false], $this->getActor());
        if (!$dbResult['success']) {
            return Response::error('Failed to list databases');
        }
        
        $siteResult = $this->agent->execute('vhost.list', [], $this->getActor());
        if (!$siteResult['success']) {
            return Response::error('Failed to list sites');
        }
        
        $databases = $dbResult['data']['databases'] ?? [];
        $sites = $siteResult['data']['vhosts'] ?? [];
        $existingLinks = $this->getDatabaseLinks();
        
        // Create domain name variations for matching
        $sitePatterns = [];
        foreach ($sites as $site) {
            $domain = $site['domain'];
            // Generate possible prefixes from domain
            $parts = explode('.', $domain);
            $prefix = strtolower(preg_replace('/[^a-z0-9]/', '', $parts[0]));
            
            $sitePatterns[$prefix] = $domain;
            $sitePatterns[substr($prefix, 0, 5)] = $domain;
            // Also match full domain with underscores
            $sitePatterns[str_replace(['.', '-'], '_', $domain)] = $domain;
        }
        
        $linked = [];
        $skipped = [];
        
        foreach ($databases as $db) {
            if ($db['is_system']) continue;
            
            $dbName = $db['name'];
            
            // Skip if already linked
            if (isset($existingLinks[$dbName]) && !empty($existingLinks[$dbName])) {
                $skipped[] = [
                    'name' => $dbName,
                    'reason' => 'already_linked',
                    'to' => $existingLinks[$dbName][0]['domain'],
                ];
                continue;
            }
            
            // Try to match by naming pattern
            $matched = null;
            
            // Check if db name starts with any site pattern
            foreach ($sitePatterns as $pattern => $domain) {
                if (str_starts_with($dbName, $pattern . '_') || $dbName === $pattern) {
                    $matched = $domain;
                    break;
                }
            }
            
            // Also check db users
            if (!$matched && !empty($db['users'])) {
                foreach ($db['users'] as $user) {
                    $userName = $user['User'];
                    foreach ($sitePatterns as $pattern => $domain) {
                        if (str_starts_with($userName, $pattern) || $userName === $pattern) {
                            $matched = $domain;
                            break 2;
                        }
                    }
                }
            }
            
            if ($matched) {
                $success = $this->linkDatabaseToSite(
                    $dbName, 
                    $matched,
                    $db['users'][0]['User'] ?? null,
                    'localhost',
                    'Auto-linked based on naming pattern'
                );
                
                if ($success) {
                    $linked[] = [
                        'name' => $dbName,
                        'domain' => $matched,
                    ];
                }
            } else {
                $skipped[] = [
                    'name' => $dbName,
                    'reason' => 'no_match',
                ];
            }
        }
        
        if (count($linked) > 0) {
            $this->cache->invalidateDatabases();
        }
        
        $this->logAction('database.autolink', null, 'success', [
            'linked_count' => count($linked),
            'skipped_count' => count($skipped),
        ]);
        
        return Response::success([
            'linked' => $linked,
            'linked_count' => count($linked),
            'skipped' => $skipped,
            'skipped_count' => count($skipped),
        ], count($linked) . ' databases linked');
    }

    /**
     * Get databases for a specific site
     */
    public function forSite(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check access
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        
        $this->ensureLinkTable();
        $db = $this->container->getDatabase();
        
        $stmt = $db->prepare("
            SELECT db_name, db_user, db_host, notes, created_at 
            FROM database_links 
            WHERE domain = ?
            ORDER BY db_name
        ");
        $stmt->execute([$domain]);
        $links = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Get size info for each linked database
        foreach ($links as &$link) {
            $sizeResult = $this->agent->execute('db.size', ['name' => $link['db_name']], $this->getActor());
            if ($sizeResult['success']) {
                $link['size_bytes'] = $sizeResult['data']['size_bytes'] ?? 0;
                $link['size_human'] = $sizeResult['data']['size_human'] ?? '0 B';
            }
        }
        
        return Response::success([
            'domain' => $domain,
            'databases' => $links,
            'count' => count($links),
        ], 'Success');
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Create a link between database and site
     */
    private function linkDatabaseToSite(
        string $dbName, 
        string $domain, 
        ?string $dbUser = null, 
        string $dbHost = 'localhost',
        ?string $notes = null
    ): bool {
        $this->ensureLinkTable();
        $db = $this->container->getDatabase();
        
        try {
            // Get current user ID
            $user = $this->getCurrentUser();
            $userId = $user?->sub ?? null;
            
            $stmt = $db->prepare("
                INSERT INTO database_links (db_name, db_user, domain, db_host, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    db_user = VALUES(db_user),
                    db_host = VALUES(db_host),
                    notes = VALUES(notes),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$dbName, $dbUser, $domain, $dbHost, $notes, $userId]);
            
            return true;
        } catch (\Exception $e) {
            debug_log("Failed to link database to site: " . $e->getMessage());
            return false;
        }
    }
}
