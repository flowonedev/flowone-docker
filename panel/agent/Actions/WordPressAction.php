<?php
/**
 * WordPress Management Action Handler
 * 
 * Manages WordPress installations via WP-CLI
 * Handles info, plugins, users, updates, permissions, and maintenance mode.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\Validator;

class WordPressAction extends BaseAction
{
    private string $wpCli = '/usr/local/bin/wp';
    
    public function getNamespace(): string
    {
        return 'wordpress';
    }

    public function getMethods(): array
    {
        return [
            'info',
            'plugins',
            'updatePlugin',
            'updateAllPlugins',
            'themes',
            'updateAllThemes',
            'users',
            'disableUser',
            'enableUser',
            'renameUser',
            'posts',
            'options',
            'permissions',
            'secureFiles',
            'unsecureFiles',
            'maintenance',
            'core',
            'updateCore',
            'updateAll',
            'dbInfo'
        ];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['updatePlugin', 'updateAllPlugins', 'updateAllThemes', 'updateCore', 'updateAll', 'secureFiles', 'unsecureFiles', 'permissions', 'renameUser']);
    }

    /**
     * Get the document root for a domain from vhost config
     */
    private function getDocumentRoot(string $domain): ?string
    {
        $vhostPath = $this->config['paths']['ols_vhosts'] ?? '/usr/local/lsws/conf/vhosts';
        
        foreach (['vhost.conf', 'vhconf.conf'] as $configName) {
            $configFile = "{$vhostPath}/{$domain}/{$configName}";
            if (file_exists($configFile)) {
                $content = file_get_contents($configFile);
                if (preg_match('/docRoot\s+(.+)$/m', $content, $matches)) {
                    $docRoot = trim($matches[1]);
                    // Replace OLS variables
                    $docRoot = str_replace('$VH_ROOT', "/home/{$domain}", $docRoot);
                    $docRoot = str_replace('$VH_NAME', $domain, $docRoot);
                    return $docRoot;
                }
            }
        }
        
        // Fallback to common paths
        $commonPaths = [
            "/home/{$domain}/public_html",
            "/home/{$domain}/www",
            "/home/{$domain}/htdocs",
            "/var/www/{$domain}/public_html",
            "/var/www/{$domain}",
        ];
        
        foreach ($commonPaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }
        
        return null;
    }

    /**
     * Get comprehensive WordPress installation info
     */
    protected function actionInfo(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        if (!$domain || !Validator::domain($domain)) {
            return $this->error('Valid domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot) {
            return $this->error("Could not find document root for {$domain}");
        }
        
        $wpConfig = "{$docRoot}/wp-config.php";

        if (!file_exists($wpConfig)) {
            return $this->error("WordPress not found at {$docRoot}");
        }

        $siteUser = $this->getSiteUser($domain);
        
        // Gather all WordPress info
        $info = [
            'domain' => $domain,
            'path' => $docRoot,
            'site_user' => $siteUser,
        ];

        // Core version - skip themes and plugins to avoid PHP errors
        $coreResult = $this->runWpCli($docRoot, $siteUser, 'core version', true, true);
        $info['version'] = $coreResult['success'] ? trim($coreResult['output']) : null;

        // Core update check - skip themes and plugins
        $updateResult = $this->runWpCli($docRoot, $siteUser, 'core check-update --format=json', true, true);
        $info['core_updates'] = [];
        if ($updateResult['success'] && $updateResult['output']) {
            $updates = @json_decode($updateResult['output'], true);
            if (is_array($updates)) {
                $info['core_updates'] = $updates;
            }
        }

        // Site URL and Home URL - skip themes and plugins to avoid PHP errors
        $siteUrlResult = $this->runWpCli($docRoot, $siteUser, 'option get siteurl', true, true);
        $info['site_url'] = $siteUrlResult['success'] ? trim($siteUrlResult['output']) : null;
        
        $homeUrlResult = $this->runWpCli($docRoot, $siteUser, 'option get home', true, true);
        $info['home_url'] = $homeUrlResult['success'] ? trim($homeUrlResult['output']) : null;

        // Blog name
        $blogNameResult = $this->runWpCli($docRoot, $siteUser, 'option get blogname', true, true);
        $info['blog_name'] = $blogNameResult['success'] ? trim($blogNameResult['output']) : null;

        // Get database info from wp-config.php
        $info['database'] = $this->parseDbConfig($wpConfig);

        // Plugin counts - skip themes to avoid theme errors
        $pluginsResult = $this->runWpCli($docRoot, $siteUser, 'plugin list --format=json', true, false);
        $plugins = [];
        if ($pluginsResult['success'] && $pluginsResult['output']) {
            $plugins = @json_decode($pluginsResult['output'], true) ?: [];
        }
        $info['plugins'] = [
            'total' => count($plugins),
            'active' => count(array_filter($plugins, fn($p) => $p['status'] === 'active')),
            'inactive' => count(array_filter($plugins, fn($p) => $p['status'] === 'inactive')),
            'updates_available' => count(array_filter($plugins, fn($p) => ($p['update'] ?? 'none') !== 'none')),
        ];

        // Theme counts - skip plugins to avoid plugin errors
        $themesResult = $this->runWpCli($docRoot, $siteUser, 'theme list --format=json', false, true);
        $themes = [];
        if ($themesResult['success'] && $themesResult['output']) {
            $themes = @json_decode($themesResult['output'], true) ?: [];
        }
        $info['themes'] = [
            'total' => count($themes),
            'active' => array_filter($themes, fn($t) => $t['status'] === 'active')[0]['name'] ?? null,
        ];

        // User counts by role - skip both themes and plugins
        $usersResult = $this->runWpCli($docRoot, $siteUser, 'user list --format=json', true, true);
        $users = [];
        if ($usersResult['success'] && $usersResult['output']) {
            $users = @json_decode($usersResult['output'], true) ?: [];
        }
        $roleCount = [];
        foreach ($users as $user) {
            $roles = explode(',', $user['roles'] ?? '');
            foreach ($roles as $role) {
                $role = trim($role);
                if ($role) {
                    $roleCount[$role] = ($roleCount[$role] ?? 0) + 1;
                }
            }
        }
        $info['users'] = [
            'total' => count($users),
            'by_role' => $roleCount,
        ];

        // Post counts - only relevant types (posts, pages, media, custom post types)
        $excludeTypes = [
            'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
            'oembed_cache', 'user_request', 'wp_block', 'wp_template',
            'wp_template_part', 'wp_global_styles', 'wp_navigation',
            'wp_font_family', 'wp_font_face', 'acf-field-group', 'acf-field',
            'acf-taxonomy', 'acf-ui-options-page', 'acf-post-type'
        ];
        $coreTypes = ['post', 'page', 'attachment'];

        $postTypesResult = $this->runWpCli($docRoot, $siteUser, 'post-type list --format=json', true, true);
        $postTypes = [];
        if ($postTypesResult['success'] && $postTypesResult['output']) {
            $postTypes = @json_decode($postTypesResult['output'], true) ?: [];
        }
        
        $postCounts = [];
        $customTypes = [];
        foreach ($postTypes as $type) {
            $typeName = $type['name'];
            
            // Skip excluded internal types
            if (in_array($typeName, $excludeTypes)) {
                continue;
            }
            
            $isCustom = !in_array($typeName, $coreTypes);
            
            // Only include custom types if they're public
            if ($isCustom && !($type['public'] ?? false)) {
                continue;
            }

            $countResult = $this->runWpCli($docRoot, $siteUser, "post list --post_type={$typeName} --post_status=any --format=count", true, true);
            $count = 0;
            if ($countResult['success']) {
                $count = (int) trim($countResult['output']);
            }
            
            $typeData = [
                'label' => $type['label'] ?? ucfirst($typeName),
                'count' => $count,
                'public' => $type['public'] ?? false,
            ];

            if ($isCustom) {
                $customTypes[$typeName] = $typeData;
            } elseif ($typeName === 'attachment') {
                $postCounts['media'] = $typeData;
                $postCounts['media']['label'] = 'Media';
            } else {
                $postCounts[$typeName] = $typeData;
            }
        }
        $info['posts'] = $postCounts;
        $info['custom_post_types'] = $customTypes;

        // Uploads folder size
        $uploadsDir = "{$docRoot}/wp-content/uploads";
        $info['uploads'] = [
            'path' => $uploadsDir,
            'size' => $this->getDirectorySize($uploadsDir),
            'size_human' => $this->formatBytes($this->getDirectorySize($uploadsDir)),
        ];

        // File permissions status
        $info['permissions'] = $this->checkFilePermissions($docRoot);

        // Maintenance mode status
        $maintenanceFile = "{$docRoot}/.maintenance";
        $info['maintenance_mode'] = file_exists($maintenanceFile);

        // Under development status
        $devFile = "{$docRoot}/.under-development";
        $info['under_development'] = file_exists($devFile);

        return $this->success($info);
    }

    /**
     * List all plugins with full details
     * Reads plugin files directly without loading WordPress to avoid PHP errors
     */
    protected function actionPlugins(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        if (!$domain) {
            return $this->error('Domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        $siteUser = $this->getSiteUser($domain);

        // Try WP-CLI first with skip-themes (skip plugins would prevent plugin listing)
        $result = $this->runWpCli($docRoot, $siteUser, 'plugin list --format=json', true, false);
        
        if ($result['success'] && $result['output']) {
            $plugins = @json_decode($result['output'], true);
            if (is_array($plugins) && !empty($plugins)) {
                // Check for updates
                $updateResult = $this->runWpCli($docRoot, $siteUser, 'plugin list --update=available --format=json', true, false);
                $updatesAvailable = [];
                if ($updateResult['success'] && $updateResult['output']) {
                    $updates = @json_decode($updateResult['output'], true) ?: [];
                    foreach ($updates as $update) {
                        $updatesAvailable[$update['name']] = $update['update_version'] ?? null;
                    }
                }

                foreach ($plugins as &$plugin) {
                    $plugin['update_available'] = isset($updatesAvailable[$plugin['name']]);
                    $plugin['update_version'] = $updatesAvailable[$plugin['name']] ?? null;
                }

                return $this->success(['plugins' => $plugins]);
            }
        }

        // Fallback: read plugin files directly
        $plugins = $this->scanPluginsDirectory($docRoot);
        
        return $this->success(['plugins' => $plugins]);
    }
    
    /**
     * Scan plugins directory and parse plugin headers directly
     */
    private function scanPluginsDirectory(string $docRoot): array
    {
        $pluginsDir = "{$docRoot}/wp-content/plugins";
        $activePlugins = $this->getActivePluginsFromDb($docRoot);
        $plugins = [];
        
        if (!is_dir($pluginsDir)) {
            return $plugins;
        }

        $items = scandir($pluginsDir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $pluginPath = "{$pluginsDir}/{$item}";
            
            // Single file plugin
            if (is_file($pluginPath) && pathinfo($item, PATHINFO_EXTENSION) === 'php') {
                $headers = $this->parsePluginHeaders($pluginPath);
                if ($headers['name']) {
                    $plugins[] = [
                        'name' => pathinfo($item, PATHINFO_FILENAME),
                        'status' => in_array($item, $activePlugins) ? 'active' : 'inactive',
                        'title' => $headers['name'],
                        'version' => $headers['version'],
                        'author' => $headers['author'],
                        'description' => $headers['description'],
                        'update_available' => false,
                        'update_version' => null,
                    ];
                }
            }
            // Directory plugin
            elseif (is_dir($pluginPath)) {
                $mainFile = "{$pluginPath}/{$item}.php";
                if (!file_exists($mainFile)) {
                    // Try to find the main plugin file
                    $phpFiles = glob("{$pluginPath}/*.php");
                    foreach ($phpFiles as $phpFile) {
                        $headers = $this->parsePluginHeaders($phpFile);
                        if ($headers['name']) {
                            $mainFile = $phpFile;
                            break;
                        }
                    }
                }
                
                if (file_exists($mainFile)) {
                    $headers = $this->parsePluginHeaders($mainFile);
                    if ($headers['name']) {
                        $pluginSlug = "{$item}/" . basename($mainFile);
                        $plugins[] = [
                            'name' => $item,
                            'status' => in_array($pluginSlug, $activePlugins) ? 'active' : 'inactive',
                            'title' => $headers['name'],
                            'version' => $headers['version'],
                            'author' => $headers['author'],
                            'description' => $headers['description'],
                            'update_available' => false,
                            'update_version' => null,
                        ];
                    }
                }
            }
        }

        return $plugins;
    }
    
    /**
     * Parse plugin file headers
     */
    private function parsePluginHeaders(string $file): array
    {
        $headers = [
            'name' => '',
            'version' => '',
            'author' => '',
            'description' => '',
        ];
        
        if (!file_exists($file)) {
            return $headers;
        }
        
        $content = file_get_contents($file, false, null, 0, 8192);
        
        if (preg_match('/Plugin Name:\s*(.+)/i', $content, $m)) {
            $headers['name'] = trim($m[1]);
        }
        if (preg_match('/Version:\s*(.+)/i', $content, $m)) {
            $headers['version'] = trim($m[1]);
        }
        if (preg_match('/Author:\s*(.+)/i', $content, $m)) {
            $headers['author'] = strip_tags(trim($m[1]));
        }
        if (preg_match('/Description:\s*(.+)/i', $content, $m)) {
            $headers['description'] = trim($m[1]);
        }
        
        return $headers;
    }
    
    /**
     * Get active plugins from database
     */
    private function getActivePluginsFromDb(string $docRoot): array
    {
        $dbConfig = $this->parseDbConfig("{$docRoot}/wp-config.php");
        if (!$dbConfig['name']) {
            return [];
        }
        
        try {
            $pdo = $this->getMySqlConnection();
            $prefix = $dbConfig['prefix'] ?? 'wp_';
            $stmt = $pdo->prepare("SELECT option_value FROM {$dbConfig['name']}.{$prefix}options WHERE option_name = 'active_plugins'");
            $stmt->execute();
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($result && $result['option_value']) {
                $active = @unserialize($result['option_value']);
                return is_array($active) ? $active : [];
            }
        } catch (\Exception $e) {
            // Ignore DB errors, return empty
        }
        
        return [];
    }

    /**
     * Update a single plugin
     */
    protected function actionUpdatePlugin(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        $plugin = $params['plugin'] ?? null;

        if (!$domain || !$plugin) {
            return $this->error('Domain and plugin name are required');
        }

        // Sanitize plugin name - only allow alphanumeric, dash, underscore
        $plugin = preg_replace('/[^a-zA-Z0-9_\-]/', '', $plugin);
        if (empty($plugin)) {
            return $this->error('Invalid plugin name');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        $siteUser = $this->getSiteUser($domain);

        // Escape plugin name for shell
        $escapedPlugin = escapeshellarg($plugin);
        $this->logger->info("Updating plugin {$plugin} in {$docRoot} as user {$siteUser}");
        
        // Skip themes to avoid theme dependency errors (like ACF)
        $result = $this->runWpCli($docRoot, $siteUser, "plugin update {$escapedPlugin}", true, false);
        
        $this->logger->info("Plugin update result for {$plugin}: exit={$result['exit_code']}, output=" . substr($result['output'], 0, 500));
        
        $output = $result['output'];
        $outputLower = strtolower($output);
        
        // Check for "already up to date" messages - these are success even if WP-CLI returns non-zero
        $alreadyUpToDate = strpos($outputLower, 'already up to date') !== false ||
                          strpos($outputLower, 'already updated') !== false ||
                          strpos($outputLower, 'no updates available') !== false;
        
        if ($alreadyUpToDate) {
            return $this->success([
                'plugin' => $plugin,
                'output' => $output,
                'already_updated' => true,
            ], "Plugin {$plugin} is already up to date");
        }
        
        // Check for successful update messages
        $updateSuccess = strpos($outputLower, 'updated successfully') !== false ||
                        strpos($outputLower, 'success') !== false ||
                        (strpos($outputLower, 'updating') !== false && strpos($outputLower, 'error') === false);
        
        if ($result['success'] || $updateSuccess) {
            return $this->success([
                'plugin' => $plugin,
                'output' => $output,
            ], "Plugin {$plugin} updated successfully");
        }
        
        // Check for common error patterns
        if (strpos($outputLower, 'error') !== false || 
            strpos($outputLower, 'failed') !== false ||
            strpos($outputLower, 'fatal') !== false) {
            return $this->error('Failed to update plugin: ' . $output);
        }
        
        // If we get here, treat any non-zero exit as a warning but try to determine success from output
        // WP-CLI sometimes returns non-zero for warnings
        if (strpos($outputLower, 'warning') !== false && strpos($outputLower, 'error') === false) {
            return $this->success([
                'plugin' => $plugin,
                'output' => $output,
                'warning' => true,
            ], "Plugin {$plugin} updated with warnings");
        }
        
        return $this->error('Failed to update plugin: ' . $output);
    }

    /**
     * Update all plugins
     */
    protected function actionUpdateAllPlugins(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        if (!$domain) {
            return $this->error('Domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        $siteUser = $this->getSiteUser($domain);

        // Skip themes to avoid theme dependency errors (like ACF)
        $result = $this->runWpCli($docRoot, $siteUser, 'plugin update --all', true, false);
        
        if (!$result['success']) {
            return $this->error('Failed to update plugins: ' . $result['output']);
        }

        return $this->success([
            'output' => $result['output'],
        ], 'All plugins updated successfully');
    }

    /**
     * Update all themes
     */
    protected function actionUpdateAllThemes(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        if (!$domain) {
            return $this->error('Domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        $siteUser = $this->getSiteUser($domain);

        // Skip themes and plugins during update to avoid dependency errors
        $result = $this->runWpCli($docRoot, $siteUser, 'theme update --all', true, true);
        
        if (!$result['success']) {
            return $this->error('Failed to update themes: ' . $result['output']);
        }

        return $this->success([
            'output' => $result['output'],
        ], 'All themes updated successfully');
    }

    /**
     * Update WordPress core
     */
    protected function actionUpdateCore(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        if (!$domain) {
            return $this->error('Domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        $siteUser = $this->getSiteUser($domain);

        // Get current version before update
        $oldVersionResult = $this->runWpCli($docRoot, $siteUser, 'core version', true, true);
        $oldVersion = $oldVersionResult['success'] ? trim($oldVersionResult['output']) : 'unknown';

        // Update core - skip themes and plugins to avoid dependency errors
        $result = $this->runWpCli($docRoot, $siteUser, 'core update', true, true);
        if (!$result['success']) {
            return $this->error('Failed to update WordPress core: ' . $result['output']);
        }

        // Update database schema
        $dbResult = $this->runWpCli($docRoot, $siteUser, 'core update-db', true, true);

        // Get new version
        $versionResult = $this->runWpCli($docRoot, $siteUser, 'core version', true, true);
        $newVersion = $versionResult['success'] ? trim($versionResult['output']) : 'unknown';

        // Verify checksums
        $verifyResult = $this->runWpCli($docRoot, $siteUser, 'core verify-checksums', true, true);

        return $this->success([
            'old_version' => $oldVersion,
            'version' => $newVersion,
            'db_updated' => $dbResult['success'],
            'checksums_valid' => $verifyResult['success'],
            'output' => $result['output'],
        ], "WordPress core updated from {$oldVersion} to {$newVersion}");
    }

    /**
     * Update everything: WordPress core, all plugins, and all themes
     */
    protected function actionUpdateAll(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        if (!$domain) {
            return $this->error('Domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        $siteUser = $this->getSiteUser($domain);

        $results = [
            'core' => null,
            'plugins' => null,
            'themes' => null,
            'errors' => [],
        ];

        // 1. Update Core - skip themes and plugins to avoid dependency errors
        $oldVersionResult = $this->runWpCli($docRoot, $siteUser, 'core version', true, true);
        $oldVersion = $oldVersionResult['success'] ? trim($oldVersionResult['output']) : null;

        $coreResult = $this->runWpCli($docRoot, $siteUser, 'core update', true, true);
        if ($coreResult['success']) {
            $this->runWpCli($docRoot, $siteUser, 'core update-db', true, true);
            $versionResult = $this->runWpCli($docRoot, $siteUser, 'core version', true, true);
            $newVersion = $versionResult['success'] ? trim($versionResult['output']) : null;
            $results['core'] = [
                'success' => true,
                'old_version' => $oldVersion,
                'version' => $newVersion,
                'output' => $coreResult['output'],
            ];
        } else {
            $results['core'] = [
                'success' => false,
                'old_version' => $oldVersion,
                'output' => $coreResult['output'],
            ];
            $results['errors'][] = 'Core update failed: ' . $coreResult['output'];
        }

        // 2. Update All Plugins - skip themes to avoid dependency errors
        $pluginResult = $this->runWpCli($docRoot, $siteUser, 'plugin update --all', true, false);
        $results['plugins'] = [
            'success' => $pluginResult['success'],
            'output' => $pluginResult['output'],
        ];
        if (!$pluginResult['success']) {
            $results['errors'][] = 'Plugin updates failed: ' . $pluginResult['output'];
        }

        // 3. Update All Themes - skip themes and plugins to avoid dependency errors
        $themeResult = $this->runWpCli($docRoot, $siteUser, 'theme update --all', true, true);
        $results['themes'] = [
            'success' => $themeResult['success'],
            'output' => $themeResult['output'],
        ];
        if (!$themeResult['success']) {
            $results['errors'][] = 'Theme updates failed: ' . $themeResult['output'];
        }

        // 4. Flush caches
        $this->runWpCli($docRoot, $siteUser, 'cache flush', true, true);

        // 5. Verify core checksums
        $verifyResult = $this->runWpCli($docRoot, $siteUser, 'core verify-checksums', true, true);
        $results['checksums_valid'] = $verifyResult['success'];

        $allSuccess = empty($results['errors']);
        $message = $allSuccess 
            ? 'WordPress fully updated (core, plugins, themes)'
            : 'Updates completed with some issues: ' . implode('; ', $results['errors']);
        
        return $allSuccess 
            ? $this->success($results, $message)
            : $this->success($results, $message); // Still return success with partial results
    }

    /**
     * List all themes
     */
    protected function actionThemes(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        if (!$domain) {
            return $this->error('Domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        $siteUser = $this->getSiteUser($domain);

        // Skip both themes and plugins to avoid PHP errors from theme/plugin dependencies
        $result = $this->runWpCli($docRoot, $siteUser, 'theme list --format=json', true, true);
        if (!$result['success']) {
            // Fallback: try to scan themes directory directly
            $themes = $this->scanThemesDirectory($docRoot);
            if (!empty($themes)) {
                return $this->success(['themes' => $themes]);
            }
            return $this->error('Failed to get themes: ' . $result['output']);
        }

        $themes = @json_decode($result['output'], true) ?: [];

        return $this->success(['themes' => $themes]);
    }
    
    /**
     * Scan themes directory and parse theme headers directly
     */
    private function scanThemesDirectory(string $docRoot): array
    {
        $themesDir = "{$docRoot}/wp-content/themes";
        if (!is_dir($themesDir)) {
            return [];
        }
        
        // Get active theme from database
        $activeTheme = $this->getActiveThemeFromDb($docRoot);
        
        $themes = [];
        $dirs = @scandir($themesDir);
        if (!$dirs) {
            return [];
        }
        
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            
            $stylePath = "{$themesDir}/{$dir}/style.css";
            if (!file_exists($stylePath)) continue;
            
            $styleContent = @file_get_contents($stylePath, false, null, 0, 8192);
            if (!$styleContent) continue;
            
            $theme = [
                'name' => $dir,
                'status' => ($activeTheme === $dir) ? 'active' : 'inactive',
                'update' => 'none',
                'version' => '',
                'title' => $dir,
            ];
            
            // Parse theme headers
            if (preg_match('/Theme Name:\s*(.+)/i', $styleContent, $m)) {
                $theme['title'] = trim($m[1]);
            }
            if (preg_match('/Version:\s*(.+)/i', $styleContent, $m)) {
                $theme['version'] = trim($m[1]);
            }
            
            $themes[] = $theme;
        }
        
        return $themes;
    }
    
    /**
     * Get active theme slug from database
     */
    private function getActiveThemeFromDb(string $docRoot): ?string
    {
        $dbConfig = $this->parseDbConfig("{$docRoot}/wp-config.php");
        if (!$dbConfig['name']) return null;
        
        try {
            $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8mb4";
            $pdo = new \PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            
            $prefix = $dbConfig['prefix'] ?? 'wp_';
            if (!Validator::wpPrefix($prefix)) { return null; }
            $stmt = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name = 'stylesheet' LIMIT 1");
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return $row ? $row['option_value'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * List all users with roles, pagination, filtering, and status
     */
    protected function actionUsers(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        if (!$domain) {
            return $this->error('Domain is required');
        }

        $limit = (int) ($params['limit'] ?? 10);
        $page = (int) ($params['page'] ?? 1);
        $search = $params['search'] ?? null;
        $role = $params['role'] ?? null;

        // Validate limit (1000 for fetching all users for client-side filtering)
        $allowedLimits = [10, 20, 50, 100, 500, 1000];
        if (!in_array($limit, $allowedLimits)) {
            $limit = 10;
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        $siteUser = $this->getSiteUser($domain);

        // Build WP-CLI command with filters
        $cmd = 'user list --format=json';
        if ($role) {
            $cmd .= ' --role=' . escapeshellarg($role);
        }

        // Skip themes and plugins to avoid PHP errors
        $result = $this->runWpCli($docRoot, $siteUser, $cmd, true, true);
        
        $allUsers = [];
        if ($result['success'] && $result['output']) {
            $allUsers = @json_decode($result['output'], true) ?: [];
        }
        
        // If WP-CLI fails, try database directly
        if (empty($allUsers)) {
            $allUsers = $this->getUsersFromDb($docRoot, $role);
        }
        
        // Get disabled users list from database
        $disabledUsers = $this->getDisabledUsers($docRoot);

        // Add status to each user
        foreach ($allUsers as &$user) {
            $userId = $user['ID'] ?? $user['id'] ?? null;
            $user['is_disabled'] = in_array((int)$userId, $disabledUsers);
            $user['status'] = $user['is_disabled'] ? 'disabled' : 'active';
        }
        unset($user);

        // Apply search filter
        if ($search) {
            $searchLower = strtolower($search);
            $allUsers = array_filter($allUsers, function($user) use ($searchLower) {
                return str_contains(strtolower($user['user_login'] ?? ''), $searchLower) ||
                       str_contains(strtolower($user['display_name'] ?? ''), $searchLower) ||
                       str_contains(strtolower($user['user_email'] ?? ''), $searchLower);
            });
            $allUsers = array_values($allUsers);
        }

        // Get total count before pagination
        $total = count($allUsers);
        
        // Get all available roles for filter dropdown
        $availableRoles = $this->getAvailableRoles($allUsers);

        // Apply pagination
        $offset = ($page - 1) * $limit;
        $users = array_slice($allUsers, $offset, $limit);

        return $this->success([
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit),
            'available_roles' => $availableRoles,
        ]);
    }
    
    /**
     * Disable a WordPress user (prevents login)
     * - Stores password hash with DISABLED: prefix
     * - Stores original email and changes to invalid email (blocks password reset)
     * - Destroys all sessions
     * - Original password AND email preserved and restored on enable
     */
    protected function actionDisableUser(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        $userId = $params['user_id'] ?? null;
        
        if (!$domain) {
            return $this->error('Domain is required');
        }
        
        if (!$userId) {
            return $this->error('User ID is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        
        // Prevent disabling user ID 1
        if ((int)$userId === 1) {
            return $this->error('Cannot disable the primary administrator account');
        }
        
        $dbConfig = $this->parseDbConfig("{$docRoot}/wp-config.php");
        if (!$dbConfig['name']) {
            return $this->error('Could not read database config');
        }
        
        $siteUser = $this->getSiteUser($domain);

        try {
            $pdo = $this->getMySqlConnection();
            $prefix = $dbConfig['prefix'] ?? 'wp_';
            $db = $dbConfig['name'];
            
            // Get user info including password hash and email
            $stmt = $pdo->prepare("SELECT ID, user_login, user_pass, user_email FROM {$db}.{$prefix}users WHERE ID = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$user) {
                return $this->error('User not found');
            }
            
            $userLogin = $user['user_login'];
            $currentPassHash = $user['user_pass'];
            $currentEmail = $user['user_email'];
            
            // Check if already disabled (password starts with DISABLED:)
            if (strpos($currentPassHash, 'DISABLED:') === 0) {
                return $this->error('User is already disabled');
            }
            
            // Store original password hash with DISABLED: prefix
            $disabledHash = 'DISABLED:' . $currentPassHash;
            
            // Create invalid email to block password reset
            $disabledEmail = "disabled.{$userId}@blocked.invalid";
            
            // Update password and email
            $stmt = $pdo->prepare("UPDATE {$db}.{$prefix}users SET user_pass = ?, user_email = ? WHERE ID = ?");
            $stmt->execute([$disabledHash, $disabledEmail, $userId]);
            
            // Store original email in user meta
            $stmt = $pdo->prepare("DELETE FROM {$db}.{$prefix}usermeta WHERE user_id = ? AND meta_key = 'vpsadmin_original_email'");
            $stmt->execute([$userId]);
            $stmt = $pdo->prepare("INSERT INTO {$db}.{$prefix}usermeta (user_id, meta_key, meta_value) VALUES (?, 'vpsadmin_original_email', ?)");
            $stmt->execute([$userId, $currentEmail]);
            
            // Mark as disabled in user meta (for UI)
            $stmt = $pdo->prepare("DELETE FROM {$db}.{$prefix}usermeta WHERE user_id = ? AND meta_key = 'vpsadmin_disabled'");
            $stmt->execute([$userId]);
            $stmt = $pdo->prepare("INSERT INTO {$db}.{$prefix}usermeta (user_id, meta_key, meta_value) VALUES (?, 'vpsadmin_disabled', '1')");
            $stmt->execute([$userId]);
            
            // Destroy all sessions
            $stmt = $pdo->prepare("DELETE FROM {$db}.{$prefix}usermeta WHERE user_id = ? AND meta_key = 'session_tokens'");
            $stmt->execute([$userId]);
            
            return $this->success([
                'user_id' => $userId,
                'user_login' => $userLogin,
                'status' => 'disabled',
            ], "User '{$userLogin}' has been disabled");
            
        } catch (\Exception $e) {
            return $this->error('Database error: ' . $e->getMessage());
        }
    }
    
    /**
     * Enable a WordPress user (allows login)
     * - Restores original password hash
     * - Restores original email
     * - User can log in with their original password
     */
    protected function actionEnableUser(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        $userId = $params['user_id'] ?? null;
        
        if (!$domain) {
            return $this->error('Domain is required');
        }
        
        if (!$userId) {
            return $this->error('User ID is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        
        $dbConfig = $this->parseDbConfig("{$docRoot}/wp-config.php");
        if (!$dbConfig['name']) {
            return $this->error('Could not read database config');
        }

        try {
            $pdo = $this->getMySqlConnection();
            $prefix = $dbConfig['prefix'] ?? 'wp_';
            $db = $dbConfig['name'];
            
            // Get user info
            $stmt = $pdo->prepare("SELECT ID, user_login, user_pass FROM {$db}.{$prefix}users WHERE ID = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$user) {
                return $this->error('User not found');
            }
            
            $userLogin = $user['user_login'];
            $currentPassHash = $user['user_pass'];
            
            // Check if disabled (password starts with DISABLED:)
            if (strpos($currentPassHash, 'DISABLED:') !== 0) {
                return $this->error('User is not disabled');
            }
            
            // Restore original password hash
            $originalHash = substr($currentPassHash, 9); // Remove 'DISABLED:' prefix
            
            // Get original email from meta
            $stmt = $pdo->prepare("SELECT meta_value FROM {$db}.{$prefix}usermeta WHERE user_id = ? AND meta_key = 'vpsadmin_original_email'");
            $stmt->execute([$userId]);
            $emailRow = $stmt->fetch(\PDO::FETCH_ASSOC);
            $originalEmail = $emailRow ? $emailRow['meta_value'] : null;
            
            if ($originalEmail) {
                // Restore both password and email
                $stmt = $pdo->prepare("UPDATE {$db}.{$prefix}users SET user_pass = ?, user_email = ? WHERE ID = ?");
                $stmt->execute([$originalHash, $originalEmail, $userId]);
            } else {
                // Just restore password (email wasn't stored - older disable)
                $stmt = $pdo->prepare("UPDATE {$db}.{$prefix}users SET user_pass = ? WHERE ID = ?");
                $stmt->execute([$originalHash, $userId]);
            }
            
            // Remove disabled meta
            $stmt = $pdo->prepare("DELETE FROM {$db}.{$prefix}usermeta WHERE user_id = ? AND meta_key IN ('vpsadmin_disabled', 'vpsadmin_original_email')");
            $stmt->execute([$userId]);
            
            return $this->success([
                'user_id' => $userId,
                'user_login' => $userLogin,
                'email' => $originalEmail,
                'status' => 'active',
            ], "User '{$userLogin}' has been enabled. Original password and email restored.");
            
        } catch (\Exception $e) {
            return $this->error('Database error: ' . $e->getMessage());
        }
    }

    /**
     * Rename a WordPress user (change username/login)
     * This is useful for security - renaming 'admin' to something less predictable
     */
    protected function actionRenameUser(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        $userId = $params['user_id'] ?? null;
        $newUsername = $params['new_username'] ?? null;
        
        if (!$domain) {
            return $this->error('Domain is required');
        }
        
        if (!$userId) {
            return $this->error('User ID is required');
        }
        
        if (!$newUsername) {
            return $this->error('New username is required');
        }
        
        // Validate new username format (WordPress rules)
        $newUsername = trim($newUsername);
        if (strlen($newUsername) < 3) {
            return $this->error('Username must be at least 3 characters');
        }
        if (strlen($newUsername) > 60) {
            return $this->error('Username must be less than 60 characters');
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.@]+$/', $newUsername)) {
            return $this->error('Username can only contain letters, numbers, underscores, hyphens, periods, and @');
        }
        // Prevent problematic usernames
        $reserved = ['admin', 'administrator', 'root', 'webmaster', 'postmaster', 'hostmaster'];
        if (in_array(strtolower($newUsername), $reserved)) {
            return $this->error('This username is reserved or insecure. Choose a different username.');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        
        $dbConfig = $this->parseDbConfig("{$docRoot}/wp-config.php");
        if (!$dbConfig['name']) {
            return $this->error('Could not read database config');
        }

        try {
            $pdo = $this->getMySqlConnection();
            $prefix = $dbConfig['prefix'] ?? 'wp_';
            $db = $dbConfig['name'];
            
            // Check if user exists
            $stmt = $pdo->prepare("SELECT ID, user_login, user_nicename, display_name, user_email FROM {$db}.{$prefix}users WHERE ID = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$user) {
                return $this->error('User not found');
            }
            
            $oldUsername = $user['user_login'];
            
            // Check if new username already exists
            $stmt = $pdo->prepare("SELECT ID FROM {$db}.{$prefix}users WHERE user_login = ? AND ID != ?");
            $stmt->execute([$newUsername, $userId]);
            if ($stmt->fetch()) {
                return $this->error('Username already exists');
            }
            
            // Also check user_nicename (URL-friendly version)
            $newNicename = $this->sanitizeNicename($newUsername);
            $stmt = $pdo->prepare("SELECT ID FROM {$db}.{$prefix}users WHERE user_nicename = ? AND ID != ?");
            $stmt->execute([$newNicename, $userId]);
            if ($stmt->fetch()) {
                // Append ID to make unique
                $newNicename = $newNicename . '-' . $userId;
            }
            
            // Update the user
            $stmt = $pdo->prepare("UPDATE {$db}.{$prefix}users SET user_login = ?, user_nicename = ? WHERE ID = ?");
            $stmt->execute([$newUsername, $newNicename, $userId]);
            
            // If display_name was same as old username, update it too
            if ($user['display_name'] === $oldUsername) {
                $stmt = $pdo->prepare("UPDATE {$db}.{$prefix}users SET display_name = ? WHERE ID = ?");
                $stmt->execute([$newUsername, $userId]);
            }
            
            // Invalidate all sessions for this user (security - force re-login)
            $stmt = $pdo->prepare("DELETE FROM {$db}.{$prefix}usermeta WHERE user_id = ? AND meta_key = 'session_tokens'");
            $stmt->execute([$userId]);
            
            // Update any author rewrite rules if needed (flush rewrite rules via WP-CLI)
            $siteUser = $this->getSiteUser($domain);
            $this->runWpCli($docRoot, $siteUser, 'rewrite flush', true, true);
            
            return $this->success([
                'user_id' => $userId,
                'old_username' => $oldUsername,
                'new_username' => $newUsername,
                'new_nicename' => $newNicename,
            ], "User renamed from '{$oldUsername}' to '{$newUsername}'");
            
        } catch (\Exception $e) {
            return $this->error('Database error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get list of disabled user IDs from database
     */
    private function getDisabledUsers(string $docRoot): array
    {
        $dbConfig = $this->parseDbConfig("{$docRoot}/wp-config.php");
        if (!$dbConfig['name']) {
            return [];
        }
        
        try {
            $pdo = $this->getMySqlConnection();
            $prefix = $dbConfig['prefix'] ?? 'wp_';
            $db = $dbConfig['name'];
            if (!Validator::wpPrefix($prefix) || !Validator::dbIdentifier($db)) { return []; }
            
            $stmt = $pdo->query("SELECT user_id FROM `{$db}`.{$prefix}usermeta WHERE meta_key = 'vpsadmin_disabled' AND meta_value = '1'");
            $results = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            return array_map('intval', $results);
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Get users directly from database
     */
    private function getUsersFromDb(string $docRoot, ?string $role = null): array
    {
        $dbConfig = $this->parseDbConfig("{$docRoot}/wp-config.php");
        if (!$dbConfig['name']) {
            return [];
        }
        
        try {
            $pdo = $this->getMySqlConnection();
            $prefix = $dbConfig['prefix'] ?? 'wp_';
            $db = $dbConfig['name'];
            if (!Validator::wpPrefix($prefix) || !Validator::dbIdentifier($db)) { return []; }
            
            $sql = "SELECT u.ID, u.user_login, u.user_email, u.display_name, u.user_registered,
                           (SELECT meta_value FROM `{$db}`.{$prefix}usermeta WHERE user_id = u.ID AND meta_key = '{$prefix}capabilities') as capabilities
                    FROM `{$db}`.{$prefix}users u
                    ORDER BY u.ID";
            
            $stmt = $pdo->query($sql);
            $users = [];
            
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $caps = @unserialize($row['capabilities']);
                $roles = is_array($caps) ? implode(',', array_keys($caps)) : '';
                
                // Filter by role if specified
                if ($role && !str_contains($roles, $role)) {
                    continue;
                }
                
                $users[] = [
                    'ID' => $row['ID'],
                    'user_login' => $row['user_login'],
                    'user_email' => $row['user_email'],
                    'display_name' => $row['display_name'],
                    'user_registered' => $row['user_registered'],
                    'roles' => $roles,
                ];
            }
            
            return $users;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Get list of all available roles from users
     */
    private function getAvailableRoles(array $users): array
    {
        $roles = [];
        foreach ($users as $user) {
            $userRoles = explode(',', $user['roles'] ?? '');
            foreach ($userRoles as $role) {
                $role = trim($role);
                if ($role && !isset($roles[$role])) {
                    $roles[$role] = ucfirst(str_replace('_', ' ', $role));
                }
            }
        }
        return $roles;
    }

    /**
     * Get post/page counts by type
     */
    protected function actionPosts(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;

        if (!$domain) {
            return $this->error('Domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        $siteUser = $this->getSiteUser($domain);

        // Internal post types to exclude (not useful for users)
        $excludeTypes = [
            'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
            'oembed_cache', 'user_request', 'wp_block', 'wp_template',
            'wp_template_part', 'wp_global_styles', 'wp_navigation',
            'wp_font_family', 'wp_font_face', 'acf-field-group', 'acf-field',
            'acf-taxonomy', 'acf-ui-options-page', 'acf-post-type'
        ];

        // Core types we always want to show
        $coreTypes = ['post', 'page', 'attachment'];

        // Try WP-CLI first
        $typesResult = $this->runWpCli($docRoot, $siteUser, 'post-type list --format=json', true, true);
        $postTypes = [];
        if ($typesResult['success'] && $typesResult['output']) {
            $postTypes = @json_decode($typesResult['output'], true) ?: [];
        }

        // If WP-CLI fails, get counts from database directly
        if (empty($postTypes)) {
            return $this->getPostCountsFromDb($docRoot, $coreTypes, $excludeTypes);
        }

        $counts = [];
        $customTypes = [];

        foreach ($postTypes as $type) {
            $typeName = $type['name'];
            
            // Skip excluded internal types
            if (in_array($typeName, $excludeTypes)) {
                continue;
            }

            // Determine if it's a custom post type (not core)
            $isCustom = !in_array($typeName, $coreTypes);
            
            // Only include custom types if they're public
            if ($isCustom && !($type['public'] ?? false)) {
                continue;
            }

            $countResult = $this->runWpCli(
                $docRoot, 
                $siteUser, 
                "post list --post_type={$typeName} --post_status=any --format=count",
                true,
                true
            );
            
            $count = 0;
            if ($countResult['success']) {
                $count = (int) trim($countResult['output']);
            }

            $typeData = [
                'name' => $typeName,
                'label' => $type['label'] ?? ucfirst($typeName),
                'count' => $count,
                'public' => $type['public'] ?? false,
                'is_custom' => $isCustom,
            ];

            if ($isCustom) {
                $customTypes[$typeName] = $typeData;
            } else {
                $counts[$typeName] = $typeData;
            }
        }

        // Rename 'attachment' to 'media' for clarity
        if (isset($counts['attachment'])) {
            $counts['media'] = $counts['attachment'];
            $counts['media']['label'] = 'Media';
            $counts['media']['name'] = 'media';
            unset($counts['attachment']);
        }

        return $this->success([
            'post_types' => $counts,
            'custom_types' => $customTypes,
            'summary' => [
                'posts' => $counts['post']['count'] ?? 0,
                'pages' => $counts['page']['count'] ?? 0,
                'media' => $counts['media']['count'] ?? 0,
                'custom' => array_sum(array_column($customTypes, 'count')),
            ],
        ]);
    }
    
    /**
     * Get post counts directly from database
     */
    private function getPostCountsFromDb(string $docRoot, array $coreTypes, array $excludeTypes): array
    {
        $dbConfig = $this->parseDbConfig("{$docRoot}/wp-config.php");
        if (!$dbConfig['name']) {
            return $this->error('Could not read database config');
        }
        
        try {
            $pdo = $this->getMySqlConnection();
            $prefix = $dbConfig['prefix'] ?? 'wp_';
            $db = $dbConfig['name'];
            
            // Get all post type counts
            $sql = "SELECT post_type, COUNT(*) as count 
                    FROM {$db}.{$prefix}posts 
                    WHERE post_status != 'auto-draft'
                    GROUP BY post_type";
            
            $stmt = $pdo->query($sql);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $counts = [];
            $customTypes = [];
            
            foreach ($results as $row) {
                $typeName = $row['post_type'];
                
                // Skip excluded types
                if (in_array($typeName, $excludeTypes)) {
                    continue;
                }
                
                $isCustom = !in_array($typeName, $coreTypes);
                
                $typeData = [
                    'name' => $typeName === 'attachment' ? 'media' : $typeName,
                    'label' => ucfirst($typeName === 'attachment' ? 'Media' : $typeName),
                    'count' => (int) $row['count'],
                    'public' => true,
                    'is_custom' => $isCustom,
                ];

                if ($isCustom) {
                    $customTypes[$typeName] = $typeData;
                } else {
                    $key = $typeName === 'attachment' ? 'media' : $typeName;
                    $counts[$key] = $typeData;
                }
            }

            return $this->success([
                'post_types' => $counts,
                'custom_types' => $customTypes,
                'summary' => [
                    'posts' => $counts['post']['count'] ?? 0,
                    'pages' => $counts['page']['count'] ?? 0,
                    'media' => $counts['media']['count'] ?? 0,
                    'custom' => array_sum(array_column($customTypes, 'count')),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error('Database error: ' . $e->getMessage());
        }
    }

    /**
     * Get WordPress options
     */
    protected function actionOptions(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        if (!$domain) {
            return $this->error('Domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        $siteUser = $this->getSiteUser($domain);

        $options = [];
        $optionKeys = ['siteurl', 'home', 'blogname', 'admin_email', 'users_can_register', 'default_role'];
        
        foreach ($optionKeys as $key) {
            // Skip themes and plugins to avoid PHP errors
            $result = $this->runWpCli($docRoot, $siteUser, "option get {$key}", true, true);
            if ($result['success']) {
                $options[$key] = trim($result['output']);
            }
        }

        return $this->success(['options' => $options]);
    }

    /**
     * Set file permissions
     */
    protected function actionPermissions(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        $target = $params['target'] ?? null; // wp-config, uploads, all
        $mode = $params['mode'] ?? 'secure';

        if (!$domain) {
            return $this->error('Domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot) {
            return $this->error('Could not find document root');
        }
        $siteUser = $this->getSiteUser($domain);
        $results = [];

        switch ($target) {
            case 'wp-config':
                $file = "{$docRoot}/wp-config.php";
                if (file_exists($file)) {
                    $perm = $mode === 'secure' ? '0440' : '0644';
                    chmod($file, octdec($perm));
                    $results['wp-config.php'] = $perm;
                }
                break;

            case 'htaccess':
                $file = "{$docRoot}/.htaccess";
                if (file_exists($file)) {
                    $perm = $mode === 'secure' ? '0444' : '0644';
                    chmod($file, octdec($perm));
                    $results['.htaccess'] = $perm;
                }
                break;

            case 'uploads':
                $uploadsDir = "{$docRoot}/wp-content/uploads";
                if (is_dir($uploadsDir)) {
                    $this->execCommand('find', [$uploadsDir, '-type', 'd', '-exec', 'chmod', '755', '{}', ';']);
                    $this->execCommand('find', [$uploadsDir, '-type', 'f', '-exec', 'chmod', '644', '{}', ';']);
                    $results['uploads'] = 'dirs:755, files:644';
                }
                break;

            case 'secure-uploads':
                $uploadsDir = "{$docRoot}/wp-content/uploads";
                if (is_dir($uploadsDir)) {
                    $htaccessPath = "{$uploadsDir}/.htaccess";
                    $htaccessContent = $this->getUploadsSecurityHtaccess();
                    file_put_contents($htaccessPath, $htaccessContent);
                    chmod($htaccessPath, 0444);
                    if ($siteUser) {
                        chown($htaccessPath, $siteUser);
                    }
                    $results['uploads_htaccess'] = 'Script execution blocked';
                    $results['protected_extensions'] = 'php, phtml, cgi, pl, py, sh, exe, js, svg';
                }
                break;

            case 'all':
            default:
                // Directories: 755, Files: 644
                $this->execCommand('find', [$docRoot, '-type', 'd', '-exec', 'chmod', '755', '{}', ';']);
                $this->execCommand('find', [$docRoot, '-type', 'f', '-exec', 'chmod', '644', '{}', ';']);
                
                // wp-config.php: 440 (secure)
                $wpConfig = "{$docRoot}/wp-config.php";
                if (file_exists($wpConfig)) {
                    chmod($wpConfig, 0440);
                }
                
                // Set ownership
                if ($siteUser) {
                    $this->execCommand('chown', ['-R', "{$siteUser}:{$siteUser}", $docRoot]);
                }
                
                $results = [
                    'directories' => '755',
                    'files' => '644',
                    'wp-config.php' => '0440',
                    'ownership' => $siteUser,
                ];
                break;
        }

        return $this->success(['permissions' => $results], 'Permissions updated successfully');
    }

    /**
     * Secure sensitive WordPress files
     */
    protected function actionSecureFiles(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        if (!$domain) {
            return $this->error('Domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        $siteUser = $this->getSiteUser($domain);
        $secured = [];

        // wp-config.php - most restrictive
        $wpConfig = "{$docRoot}/wp-config.php";
        if (file_exists($wpConfig)) {
            chmod($wpConfig, 0440);
            $secured[] = 'wp-config.php (0440)';
        }

        // .htaccess
        $htaccess = "{$docRoot}/.htaccess";
        if (file_exists($htaccess)) {
            chmod($htaccess, 0444);
            $secured[] = '.htaccess (0444)';
        }

        // wp-includes directory
        $wpIncludes = "{$docRoot}/wp-includes";
        if (is_dir($wpIncludes)) {
            // Make sure files aren't directly executable
            $this->execCommand('find', [$wpIncludes, '-type', 'f', '-name', '*.php', '-exec', 'chmod', '444', '{}', ';']);
            $secured[] = 'wp-includes/*.php (0444)';
        }

        // Remove readme.html and license.txt (security info disclosure)
        $removeFiles = ['readme.html', 'license.txt', 'wp-config-sample.php'];
        foreach ($removeFiles as $file) {
            $path = "{$docRoot}/{$file}";
            if (file_exists($path)) {
                unlink($path);
                $secured[] = "{$file} (removed)";
            }
        }

        // Disable file editing in wp-admin
        $wpConfig = "{$docRoot}/wp-config.php";
        if (file_exists($wpConfig)) {
            $content = file_get_contents($wpConfig);
            if (strpos($content, 'DISALLOW_FILE_EDIT') === false) {
                // Add before "That's all, stop editing!"
                $content = preg_replace(
                    '/\/\*.*That\'s all.*\*\//i',
                    "define('DISALLOW_FILE_EDIT', true);\n\n/* That's all, stop editing! */",
                    $content
                );
                // Make writable temporarily
                chmod($wpConfig, 0644);
                file_put_contents($wpConfig, $content);
                chmod($wpConfig, 0440);
                $secured[] = 'DISALLOW_FILE_EDIT added';
            }
        }

        // Secure uploads directory - block script execution
        $uploadsDir = "{$docRoot}/wp-content/uploads";
        if (is_dir($uploadsDir)) {
            $htaccessPath = "{$uploadsDir}/.htaccess";
            $htaccessContent = $this->getUploadsSecurityHtaccess();
            file_put_contents($htaccessPath, $htaccessContent);
            chmod($htaccessPath, 0444);
            if ($siteUser) {
                chown($htaccessPath, $siteUser);
            }
            $secured[] = 'uploads/.htaccess (script execution blocked)';
        }

        // Set ownership
        if ($siteUser) {
            $this->execCommand('chown', ['-R', "{$siteUser}:{$siteUser}", $docRoot]);
        }

        return $this->success([
            'secured' => $secured,
            'message' => 'WordPress files secured',
        ], 'WordPress files have been secured');
    }

    /**
     * Unlock sensitive WordPress files for editing.
     *
     * Reverses actionSecureFiles(): restores wp-config.php and .htaccess to
     * writable (0644), unlocks wp-includes/*.php (0644) and removes the
     * DISALLOW_FILE_EDIT constant so the in-dashboard plugin/theme editor
     * works again. The uploads/.htaccess script-execution block is left in
     * place on purpose - it is unrelated to editing config/.htaccess/plugins
     * and removing it would needlessly weaken security.
     */
    protected function actionUnsecureFiles(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        if (!$domain) {
            return $this->error('Domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        $siteUser = $this->getSiteUser($domain);
        $unlocked = [];

        // wp-config.php - writable
        $wpConfig = "{$docRoot}/wp-config.php";
        if (file_exists($wpConfig)) {
            chmod($wpConfig, 0644);
            $unlocked[] = 'wp-config.php (0644)';
        }

        // .htaccess - writable
        $htaccess = "{$docRoot}/.htaccess";
        if (file_exists($htaccess)) {
            chmod($htaccess, 0644);
            $unlocked[] = '.htaccess (0644)';
        }

        // wp-includes directory - restore writable .php files
        $wpIncludes = "{$docRoot}/wp-includes";
        if (is_dir($wpIncludes)) {
            $this->execCommand('find', [$wpIncludes, '-type', 'f', '-name', '*.php', '-exec', 'chmod', '644', '{}', ';']);
            $unlocked[] = 'wp-includes/*.php (0644)';
        }

        // Re-enable file editing in wp-admin (remove DISALLOW_FILE_EDIT)
        if (file_exists($wpConfig)) {
            $content = file_get_contents($wpConfig);
            if (strpos($content, 'DISALLOW_FILE_EDIT') !== false) {
                // Remove the whole define() line (and the trailing newline).
                $newContent = preg_replace(
                    "/^\\s*define\\(\\s*['\"]DISALLOW_FILE_EDIT['\"].*?\\);\\s*\\r?\\n/mi",
                    '',
                    $content
                );
                if ($newContent !== null && $newContent !== $content) {
                    chmod($wpConfig, 0644);
                    file_put_contents($wpConfig, $newContent);
                    chmod($wpConfig, 0644);
                    $unlocked[] = 'DISALLOW_FILE_EDIT removed';
                }
            }
        }

        // Restore ownership
        if ($siteUser) {
            $this->execCommand('chown', ['-R', "{$siteUser}:{$siteUser}", $docRoot]);
        }

        return $this->success([
            'unlocked' => $unlocked,
            'message' => 'WordPress files unlocked for editing',
        ], 'WordPress files have been unlocked for editing');
    }

    /**
     * Enable/disable maintenance mode or under development page
     */
    protected function actionMaintenance(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        $mode = $params['mode'] ?? 'enable'; // enable, disable
        $type = $params['type'] ?? 'development'; // maintenance, development

        if (!$domain) {
            return $this->error('Domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot) {
            return $this->error('Could not find document root');
        }
        $siteUser = $this->getSiteUser($domain);

        if ($type === 'development') {
            // Simple HTML under development page
            $devFile = "{$docRoot}/.under-development";
            $indexBackup = "{$docRoot}/index.php.bak";
            $indexFile = "{$docRoot}/index.php";
            
            if ($mode === 'enable') {
                // Backup original index.php
                if (file_exists($indexFile) && !file_exists($indexBackup)) {
                    copy($indexFile, $indexBackup);
                }
                
                // Create under development flag
                file_put_contents($devFile, time());
                
                // Create simple HTML page
                $html = $this->generateUnderDevelopmentPage($domain);
                file_put_contents($indexFile, $html);
                
                if ($siteUser) {
                    chown($devFile, $siteUser);
                    chown($indexFile, $siteUser);
                }
                
                return $this->success([
                    'mode' => 'development',
                    'status' => 'enabled',
                    'domain' => $domain,
                ], "Under development page enabled for {$domain}");
                
            } else {
                // Restore original index.php
                if (file_exists($indexBackup)) {
                    copy($indexBackup, $indexFile);
                    unlink($indexBackup);
                }
                
                // Remove flag
                if (file_exists($devFile)) {
                    unlink($devFile);
                }
                
                if ($siteUser && file_exists($indexFile)) {
                    chown($indexFile, $siteUser);
                }
                
                return $this->success([
                    'mode' => 'development',
                    'status' => 'disabled',
                    'domain' => $domain,
                ], "Under development page disabled for {$domain}");
            }
        } else {
            // WordPress maintenance mode
            $maintenanceFile = "{$docRoot}/.maintenance";
            
            if ($mode === 'enable') {
                $content = "<?php \$upgrading = " . time() . ";";
                file_put_contents($maintenanceFile, $content);
                if ($siteUser) {
                    chown($maintenanceFile, $siteUser);
                }
                
                return $this->success([
                    'mode' => 'maintenance',
                    'status' => 'enabled',
                ], 'WordPress maintenance mode enabled');
            } else {
                if (file_exists($maintenanceFile)) {
                    unlink($maintenanceFile);
                }
                
                return $this->success([
                    'mode' => 'maintenance',
                    'status' => 'disabled',
                ], 'WordPress maintenance mode disabled');
            }
        }
    }

    /**
     * Get WordPress core info and update status
     */
    protected function actionCore(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        if (!$domain) {
            return $this->error('Domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot || !file_exists("{$docRoot}/wp-config.php")) {
            return $this->error('WordPress not found');
        }
        $siteUser = $this->getSiteUser($domain);

        $info = [];

        // Current version - skip themes and plugins to avoid PHP errors
        $versionResult = $this->runWpCli($docRoot, $siteUser, 'core version', true, true);
        $info['version'] = $versionResult['success'] ? trim($versionResult['output']) : null;

        // Check for updates - skip themes and plugins
        $updateResult = $this->runWpCli($docRoot, $siteUser, 'core check-update --format=json', true, true);
        $info['updates'] = [];
        if ($updateResult['success'] && $updateResult['output']) {
            $info['updates'] = @json_decode($updateResult['output'], true) ?: [];
        }

        // Verify checksums - skip themes and plugins
        $verifyResult = $this->runWpCli($docRoot, $siteUser, 'core verify-checksums 2>&1', true, true);
        $info['checksums_valid'] = $verifyResult['success'];
        $info['checksum_errors'] = $verifyResult['success'] ? [] : explode("\n", $verifyResult['output']);

        return $this->success($info);
    }

    /**
     * Get database info
     */
    protected function actionDbInfo(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        if (!$domain) {
            return $this->error('Domain is required');
        }

        $docRoot = $this->getDocumentRoot($domain);
        if (!$docRoot) {
            return $this->error('Could not find document root');
        }
        
        $wpConfig = "{$docRoot}/wp-config.php";

        if (!file_exists($wpConfig)) {
            return $this->error("WordPress not found at {$docRoot}");
        }

        $dbInfo = $this->parseDbConfig($wpConfig);
        
        // Get database size
        if ($dbInfo['name']) {
            try {
                $pdo = $this->getMySqlConnection();
                $stmt = $pdo->prepare("
                    SELECT 
                        SUM(data_length + index_length) as size,
                        COUNT(*) as table_count
                    FROM information_schema.tables 
                    WHERE table_schema = ?
                ");
                $stmt->execute([$dbInfo['name']]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                $dbInfo['size'] = (int) ($result['size'] ?? 0);
                $dbInfo['size_human'] = $this->formatBytes($dbInfo['size']);
                $dbInfo['table_count'] = (int) ($result['table_count'] ?? 0);
            } catch (\Exception $e) {
                $dbInfo['error'] = $e->getMessage();
            }
        }

        return $this->success(['database' => $dbInfo]);
    }

    // ============ Helper Methods ============

    /**
     * Run WP-CLI command
     * Try multiple approaches for compatibility
     * 
     * @param string $path Document root path
     * @param string|null $user Site user
     * @param string $command WP-CLI command to run
     * @param bool $skipThemes Skip loading themes (avoids theme errors)
     * @param bool $skipPlugins Skip loading plugins (avoids plugin errors)
     */
    private function runWpCli(string $path, ?string $user, string $command, bool $skipThemes = false, bool $skipPlugins = false): array
    {
        // Find wp-cli binary
        $wpBinaries = [
            '/usr/local/bin/wp',
            '/usr/bin/wp',
            '/home/*/bin/wp',
        ];
        
        $wpBin = null;
        foreach ($wpBinaries as $bin) {
            if (strpos($bin, '*') !== false) {
                $found = glob($bin);
                if (!empty($found)) {
                    $wpBin = $found[0];
                    break;
                }
            } elseif (file_exists($bin) && is_executable($bin)) {
                $wpBin = $bin;
                break;
            }
        }
        
        // Fallback: check PATH
        if (!$wpBin) {
            exec('which wp 2>/dev/null', $whichOutput, $whichCode);
            if ($whichCode === 0 && !empty($whichOutput[0])) {
                $wpBin = trim($whichOutput[0]);
            }
        }
        
        if (!$wpBin) {
            return [
                'success' => false,
                'output' => 'WP-CLI not found. Install with: curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp',
                'exit_code' => 127,
            ];
        }
        
        // Build skip flags
        $skipFlags = '';
        if ($skipThemes) {
            $skipFlags .= ' --skip-themes';
        }
        if ($skipPlugins) {
            $skipFlags .= ' --skip-plugins';
        }
        
        // Build command - try running directly first (agent usually runs as root)
        $escapedPath = escapeshellarg($path);
        $baseCmd = "{$wpBin} {$command} --path={$escapedPath} --allow-root{$skipFlags}";
        
        // First try: run directly with --allow-root (works when agent runs as root)
        $fullCommand = "cd {$escapedPath} && {$baseCmd} 2>&1";
        exec($fullCommand, $output, $exitCode);
        
        if ($exitCode === 0) {
            return [
                'success' => true,
                'output' => implode("\n", $output),
                'exit_code' => $exitCode,
            ];
        }
        
        // Second try: if user is set, try sudo -u (may work on some setups)
        if ($user && $user !== 'root' && $user !== 'nobody') {
            $output = [];
            $userCmd = "{$wpBin} {$command} --path={$escapedPath}{$skipFlags}";
            $fullCommand = "cd {$escapedPath} && sudo -u " . escapeshellarg($user) . " {$userCmd} 2>&1";
            exec($fullCommand, $output, $exitCode);
        }
        
        return [
            'success' => $exitCode === 0,
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Get site user from vhost config
     */
    private function getSiteUser(string $domain): ?string
    {
        $vhostPath = $this->config['paths']['ols_vhosts'] ?? '/usr/local/lsws/conf/vhosts';
        $vhostPath .= '/' . $domain;
        
        foreach (['vhost.conf', 'vhconf.conf'] as $configName) {
            $configFile = $vhostPath . '/' . $configName;
            if (file_exists($configFile)) {
                $config = file_get_contents($configFile);
                if (preg_match('/extUser\s+(\S+)/m', $config, $matches)) {
                    return $matches[1];
                }
            }
        }

        // Fallback: check home directory ownership
        $homeDir = "/home/{$domain}";
        if (is_dir($homeDir)) {
            $stat = stat($homeDir);
            if ($stat) {
                $userInfo = posix_getpwuid($stat['uid']);
                if ($userInfo && $userInfo['name'] !== 'root') {
                    return $userInfo['name'];
                }
            }
        }

        return null;
    }

    /**
     * Parse database config from wp-config.php
     */
    private function parseDbConfig(string $wpConfigPath): array
    {
        $config = [
            'name' => null,
            'user' => null,
            'host' => null,
            'prefix' => null,
        ];

        if (!file_exists($wpConfigPath)) {
            return $config;
        }

        $content = file_get_contents($wpConfigPath);

        if (preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $m)) {
            $config['name'] = $m[1];
        }
        if (preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $m)) {
            $config['user'] = $m[1];
        }
        if (preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $m)) {
            $config['host'] = $m[1];
        }
        if (preg_match("/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
            $config['prefix'] = $m[1];
        }

        return $config;
    }

    /**
     * Get directory size recursively
     */
    private function getDirectorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $result = $this->execCommand('du', ['-sb', $path]);
        if ($result['success'] && preg_match('/^(\d+)/', $result['output'], $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * Format bytes to human-readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Sanitize username for URL-friendly nicename (like WordPress sanitize_title)
     */
    private function sanitizeNicename(string $username): string
    {
        // Convert to lowercase
        $nicename = strtolower($username);
        // Replace spaces and underscores with hyphens
        $nicename = preg_replace('/[\s_]+/', '-', $nicename);
        // Remove non-alphanumeric except hyphens
        $nicename = preg_replace('/[^a-z0-9\-]/', '', $nicename);
        // Remove consecutive hyphens
        $nicename = preg_replace('/-+/', '-', $nicename);
        // Trim hyphens from ends
        $nicename = trim($nicename, '-');
        // Limit length
        $nicename = substr($nicename, 0, 50);
        
        return $nicename ?: 'user';
    }

    /**
     * Check file permissions status
     */
    private function checkFilePermissions(string $docRoot): array
    {
        $checks = [];
        
        $files = [
            'wp-config.php' => ['secure' => '0440', 'file' => true],
            '.htaccess' => ['secure' => '0444', 'file' => true],
            'wp-content' => ['secure' => '0755', 'file' => false],
            'wp-content/uploads' => ['secure' => '0755', 'file' => false],
        ];

        foreach ($files as $name => $config) {
            $path = "{$docRoot}/{$name}";
            if (file_exists($path) || is_dir($path)) {
                $perms = substr(sprintf('%o', fileperms($path)), -4);
                $checks[$name] = [
                    'current' => $perms,
                    'recommended' => $config['secure'],
                    'secure' => $perms === $config['secure'] || 
                               (int)$perms <= (int)$config['secure'],
                ];
            }
        }

        // Check uploads script protection
        $uploadsHtaccess = "{$docRoot}/wp-content/uploads/.htaccess";
        $checks['uploads-scripts'] = [
            'current' => file_exists($uploadsHtaccess) ? 'Protected' : 'Unprotected',
            'recommended' => 'Protected',
            'secure' => file_exists($uploadsHtaccess) && $this->isUploadsHtaccessSecure($uploadsHtaccess),
        ];

        // Whether the in-dashboard plugin/theme file editor is disabled
        // (DISALLOW_FILE_EDIT). Surfaced so the UI can show/toggle the
        // "Allow file editing" state.
        $checks['file_editor_locked'] = $this->isFileEditingDisabled($docRoot);

        return $checks;
    }

    /**
     * Detect whether DISALLOW_FILE_EDIT is set to true in wp-config.php.
     */
    private function isFileEditingDisabled(string $docRoot): bool
    {
        $wpConfig = "{$docRoot}/wp-config.php";
        if (!file_exists($wpConfig) || !is_readable($wpConfig)) {
            return false;
        }
        $content = file_get_contents($wpConfig);
        if ($content === false) {
            return false;
        }
        return (bool) preg_match(
            "/define\\(\\s*['\"]DISALLOW_FILE_EDIT['\"]\\s*,\\s*true\\s*\\)/i",
            $content
        );
    }

    /**
     * Check if uploads .htaccess has script protection
     */
    private function isUploadsHtaccessSecure(string $htaccessPath): bool
    {
        if (!file_exists($htaccessPath)) {
            return false;
        }
        
        $content = file_get_contents($htaccessPath);
        // Check for PHP blocking rules
        return (
            stripos($content, 'FilesMatch') !== false &&
            stripos($content, '.php') !== false &&
            (stripos($content, 'Deny from all') !== false || stripos($content, 'deny from all') !== false)
        );
    }

    /**
     * Generate security .htaccess for uploads directory
     */
    private function getUploadsSecurityHtaccess(): string
    {
        return <<<'HTACCESS'
# WordPress Uploads Security - Block script execution
# Generated by VPS Admin Panel

# Disable script execution for dangerous file types
<FilesMatch "\.(php|phtml|php3|php4|php5|php7|php8|phps|phar|cgi|pl|py|pyc|sh|bash|exe|bat|cmd|com|js|htaccess|htpasswd)$">
    Order Deny,Allow
    Deny from all
</FilesMatch>

# Additional protection - block all PHP files
<Files *.php>
    deny from all
</Files>
<Files *.phtml>
    deny from all
</Files>

# Block SVG files (can contain JavaScript)
<Files *.svg>
    deny from all
</Files>

# Prevent directory browsing
Options -Indexes

# Disable script execution via handler
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>

# LiteSpeed specific - disable PHP
<IfModule LiteSpeed>
    php_flag engine off
</IfModule>

# Remove handlers for script files
RemoveHandler .cgi .pl .py
RemoveType .cgi .pl .py

# Prevent hotlinking and direct access to scripts
<IfModule mod_rewrite.c>
    RewriteEngine On
    # Block direct access to script files
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteCond %{REQUEST_URI} \.(php|phtml|php[3-8]|cgi|pl|py|sh|exe|js)$ [NC]
    RewriteRule .* - [F,L]
</IfModule>
HTACCESS;
    }

    /**
     * Generate under development HTML page
     */
    private function generateUnderDevelopmentPage(string $domain): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$domain} - Under Development</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            color: #e4e4e7;
        }
        .container {
            text-align: center;
            padding: 3rem;
            max-width: 600px;
        }
        .icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 2rem;
            background: linear-gradient(135deg, #4ade80 0%, #22c55e 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 40px rgba(74, 222, 128, 0.3);
        }
        .icon svg {
            width: 40px;
            height: 40px;
            fill: white;
        }
        h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #4ade80, #22c55e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        p {
            font-size: 1.125rem;
            color: #a1a1aa;
            line-height: 1.7;
            margin-bottom: 1.5rem;
        }
        .domain {
            font-family: monospace;
            background: rgba(255,255,255,0.1);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            display: inline-block;
            margin-top: 1rem;
            color: #4ade80;
        }
        .progress {
            width: 100%;
            height: 4px;
            background: rgba(255,255,255,0.1);
            border-radius: 2px;
            margin-top: 2rem;
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            width: 60%;
            background: linear-gradient(90deg, #4ade80, #22c55e);
            border-radius: 2px;
            animation: progress 2s ease-in-out infinite;
        }
        @keyframes progress {
            0%, 100% { width: 20%; margin-left: 0; }
            50% { width: 60%; margin-left: 40%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
            </svg>
        </div>
        <h1>Under Development</h1>
        <p>We're working hard to bring you something amazing. This site is currently under construction and will be available soon.</p>
        <div class="domain">{$domain}</div>
        <div class="progress">
            <div class="progress-bar"></div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get MySQL root connection
     */
    private function getMySqlConnection(): \PDO
    {
        $password = $this->getMySqlPassword();
        return new \PDO(
            "mysql:unix_socket=/var/run/mysqld/mysqld.sock",
            'root',
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * Get MySQL root password
     */
    private function getMySqlPassword(): string
    {
        $mycnf = '/root/.my.cnf';
        if (file_exists($mycnf)) {
            $content = file_get_contents($mycnf);
            if (preg_match('/password\s*=\s*["\']?([^"\'\\n]+)["\']?/i', $content, $matches)) {
                return trim($matches[1]);
            }
        }
        return '';
    }
}

