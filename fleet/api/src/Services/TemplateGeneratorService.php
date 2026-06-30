<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Template Generator Service
 * 
 * Dynamically generates config templates FROM actual server configs.
 * 
 * Flow:
 * 1. Takes a snapshot (real server config content)
 * 2. Detects server-specific values (IPs, passwords, domains, paths)
 * 3. Replaces those values with {{VARIABLE}} placeholders
 * 4. Stores the generated templates in the blueprint_templates DB table
 * 
 * This is the CORE of Fleet Manager's golden rule:
 * "Read real configs -> generate templates -> deploy to new servers"
 * 
 * Templates are NEVER hand-written. They are ALWAYS generated from what
 * the server actually has.
 */
class TemplateGeneratorService
{
    private Container $container;
    private VariableDetectorService $variableDetector;

    // Services whose configs should become templates
    private const TEMPLATE_CATEGORIES = [
        'postfix' => [
            'service' => 'postfix',
            'restart_cmd' => 'systemctl restart postfix',
        ],
        'dovecot' => [
            'service' => 'dovecot',
            'restart_cmd' => 'systemctl restart dovecot',
        ],
        'openlitespeed' => [
            'service' => 'lsws',
            'restart_cmd' => 'systemctl restart lsws',
        ],
        'opendkim' => [
            'service' => 'opendkim',
            'restart_cmd' => 'systemctl restart opendkim',
        ],
        'opendmarc' => [
            'service' => 'opendmarc',
            'restart_cmd' => 'systemctl restart opendmarc',
        ],
        'fail2ban' => [
            'service' => 'fail2ban',
            'restart_cmd' => 'systemctl restart fail2ban',
        ],
        'redis' => [
            'service' => 'redis-server',
            'restart_cmd' => 'systemctl restart redis-server',
        ],
        'mariadb' => [
            'service' => 'mariadb',
            'restart_cmd' => 'systemctl restart mariadb',
        ],
        'firewalld' => [
            'service' => 'firewalld',
            'restart_cmd' => 'systemctl reload firewalld',
        ],
        'spamassassin' => [
            'service' => 'spamassassin',
            'restart_cmd' => 'systemctl restart spamassassin',
        ],
        'php' => [
            'service' => 'lsphp',
            'restart_cmd' => 'killall -9 lsphp',
        ],
        'ssh' => [
            'service' => 'sshd',
            'restart_cmd' => 'systemctl restart sshd',
        ],
        'systemd' => [
            'service' => null,
            'restart_cmd' => 'systemctl daemon-reload',
        ],
        'clamav' => [
            'service' => 'clamav-daemon',
            'restart_cmd' => 'systemctl restart clamav-daemon',
        ],
        'rspamd' => [
            'service' => 'rspamd',
            'restart_cmd' => 'systemctl restart rspamd',
        ],
    ];

    // Files that should always be skipped (not made into templates)
    private const SKIP_FILES = [
        '/etc/hostname',           // Unique per server
        '/etc/hosts',              // Generated per server
        '/etc/resolv.conf',        // DNS varies
        '/etc/machine-id',         // Unique per machine
    ];

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->variableDetector = $container->get(VariableDetectorService::class);
    }

    /**
     * Generate templates from a snapshot's extracted data.
     * 
     * This is the main entry point. It:
     * 1. Detects all server-specific variables across the full snapshot
     * 2. For each config file, replaces detected values with {{VARIABLE}} placeholders
     * 3. Returns the template data ready for DB storage
     * 
     * @param array $snapshotData Full snapshot data (with 'extracted' key)
     * @return array {
     *   'templates': array of generated templates,
     *   'variables': array of detected variable definitions,
     *   'variable_map': array of varName => detectedValue,
     *   'stats': array summary stats
     * }
     */
    public function generateFromSnapshot(array $snapshotData): array
    {
        $extracted = $snapshotData['extracted'] ?? [];
        
        if (empty($extracted)) {
            return [
                'templates' => [],
                'variables' => [],
                'variable_map' => [],
                'stats' => ['total_templates' => 0, 'total_variables' => 0, 'categories' => []],
            ];
        }

        // Step 1: Detect all variables across the entire snapshot
        $detection = $this->variableDetector->detectVariables([
            'server_info' => $snapshotData['server_info'] ?? [],
            'extracted' => $extracted,
        ]);

        $detectedValues = $detection['detected'] ?? [];
        $variableDefinitions = $detection['definitions'] ?? [];

        // Build the replacement map (varName => value to replace)
        $variableMap = $this->variableDetector->buildVariableMap($detectedValues);

        // Step 2: For each config file, replace detected values with {{VARIABLE}} placeholders
        $templates = [];
        $categoryStats = [];

        foreach ($extracted as $category => $categoryData) {
            $files = $categoryData['files'] ?? [];
            $catTemplateCount = 0;

            foreach ($files as $file) {
                $content = $file['content'] ?? '';
                $filePath = $file['path'] ?? '';

                // Skip empty files, binary files, or files in the skip list
                if (empty($content) || $this->shouldSkipFile($filePath)) {
                    continue;
                }

                // Skip dry-run entries
                if ($file['dry_run'] ?? false) {
                    continue;
                }

                // Generate the template by replacing server-specific values
                $templateContent = $this->variableDetector->replaceWithVariables($content, $variableMap);

                // Find which variables were actually used in this template
                $usedVariables = $this->findUsedVariables($templateContent);

                $templates[] = [
                    'category' => $category,
                    'filename' => basename($filePath),
                    'target_path' => $filePath,
                    'content' => $templateContent,
                    'original_content' => $content,
                    'permissions' => $file['permissions'] ?? '0644',
                    'owner' => $file['owner'] ?? 'root',
                    'group' => $file['group'] ?? 'root',
                    'variables_used' => $usedVariables,
                    'service' => self::TEMPLATE_CATEGORIES[$category]['service'] ?? null,
                    'restart_cmd' => self::TEMPLATE_CATEGORIES[$category]['restart_cmd'] ?? null,
                ];

                $catTemplateCount++;
            }

            if ($catTemplateCount > 0) {
                $categoryStats[$category] = $catTemplateCount;
            }
        }

        return [
            'templates' => $templates,
            'variables' => $variableDefinitions,
            'variable_map' => $variableMap,
            'stats' => [
                'total_templates' => count($templates),
                'total_variables' => count($variableMap),
                'categories' => $categoryStats,
            ],
        ];
    }

    /**
     * Generate templates and store them as a blueprint in the database.
     * 
     * @param array $snapshotData Full snapshot data
     * @param string $name Blueprint name
     * @param string $description Blueprint description
     * @param array|null $selectedCategories Optional filter to specific categories
     * @return array Result with blueprint_id on success
     */
    public function generateAndSaveBlueprint(
        array $snapshotData,
        string $name,
        string $description = '',
        ?array $selectedCategories = null
    ): array {
        // Filter categories if specified
        if ($selectedCategories !== null && !empty($snapshotData['extracted'])) {
            $snapshotData['extracted'] = array_intersect_key(
                $snapshotData['extracted'],
                array_flip($selectedCategories)
            );
        }

        // Generate templates from the snapshot
        $result = $this->generateFromSnapshot($snapshotData);

        if (empty($result['templates'])) {
            return [
                'success' => false,
                'error' => 'No templates could be generated from the snapshot. The extracted data may be empty.',
            ];
        }

        $db = $this->container->getDatabase();

        try {
            $db->beginTransaction();

            // Create blueprint record
            $stmt = $db->prepare(
                "INSERT INTO blueprints (name, description, source_server, variables, created_at)
                 VALUES (?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $name,
                $description,
                $snapshotData['server_info']['hostname'] ?? 'local',
                json_encode($result['variable_map']),
            ]);
            $blueprintId = (int) $db->lastInsertId();

            // Insert each template
            $stmtTpl = $db->prepare(
                "INSERT INTO blueprint_templates 
                 (blueprint_id, category, filename, target_path, content, permissions, owner, group_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $templateCount = 0;
            foreach ($result['templates'] as $tpl) {
                $stmtTpl->execute([
                    $blueprintId,
                    $tpl['category'],
                    $tpl['filename'],
                    $tpl['target_path'],
                    $tpl['content'],
                    $tpl['permissions'],
                    $tpl['owner'],
                    $tpl['group'],
                ]);
                $templateCount++;
            }

            $db->commit();

            return [
                'success' => true,
                'blueprint_id' => $blueprintId,
                'template_count' => $templateCount,
                'variable_count' => count($result['variable_map']),
                'variables' => $result['variables'],
                'stats' => $result['stats'],
            ];
        } catch (\Exception $e) {
            $db->rollBack();
            return [
                'success' => false,
                'error' => 'Failed to save blueprint: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Preview what templates would be generated from a snapshot
     * (without saving anything to DB)
     */
    public function previewFromSnapshot(array $snapshotData, ?array $selectedCategories = null): array
    {
        if ($selectedCategories !== null && !empty($snapshotData['extracted'])) {
            $snapshotData['extracted'] = array_intersect_key(
                $snapshotData['extracted'],
                array_flip($selectedCategories)
            );
        }

        $result = $this->generateFromSnapshot($snapshotData);

        // Strip original_content for lighter response
        foreach ($result['templates'] as &$tpl) {
            unset($tpl['original_content']);
        }

        return $result;
    }

    /**
     * Regenerate templates for an existing blueprint from a new snapshot
     */
    public function regenerateBlueprint(int $blueprintId, array $snapshotData): array
    {
        $db = $this->container->getDatabase();

        // Check blueprint exists
        $stmt = $db->prepare("SELECT id, name FROM blueprints WHERE id = ?");
        $stmt->execute([$blueprintId]);
        $blueprint = $stmt->fetch();

        if (!$blueprint) {
            return ['success' => false, 'error' => 'Blueprint not found'];
        }

        // Generate new templates
        $result = $this->generateFromSnapshot($snapshotData);

        if (empty($result['templates'])) {
            return ['success' => false, 'error' => 'No templates generated from snapshot'];
        }

        try {
            $db->beginTransaction();

            // Delete old templates
            $stmt = $db->prepare("DELETE FROM blueprint_templates WHERE blueprint_id = ?");
            $stmt->execute([$blueprintId]);

            // Update blueprint variables
            $stmt = $db->prepare(
                "UPDATE blueprints SET variables = ?, updated_at = NOW() WHERE id = ?"
            );
            $stmt->execute([json_encode($result['variable_map']), $blueprintId]);

            // Insert new templates
            $stmtTpl = $db->prepare(
                "INSERT INTO blueprint_templates 
                 (blueprint_id, category, filename, target_path, content, permissions, owner, group_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            foreach ($result['templates'] as $tpl) {
                $stmtTpl->execute([
                    $blueprintId,
                    $tpl['category'],
                    $tpl['filename'],
                    $tpl['target_path'],
                    $tpl['content'],
                    $tpl['permissions'],
                    $tpl['owner'],
                    $tpl['group'],
                ]);
            }

            $db->commit();

            return [
                'success' => true,
                'blueprint_id' => $blueprintId,
                'template_count' => count($result['templates']),
                'variable_count' => count($result['variable_map']),
                'stats' => $result['stats'],
            ];
        } catch (\Exception $e) {
            $db->rollBack();
            return ['success' => false, 'error' => 'Failed to regenerate: ' . $e->getMessage()];
        }
    }

    /**
     * Check if a file should be skipped from template generation
     */
    private function shouldSkipFile(string $filePath): bool
    {
        // Skip files in the explicit skip list
        foreach (self::SKIP_FILES as $skip) {
            if ($filePath === $skip) {
                return true;
            }
        }

        // Skip binary/non-config files
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $binaryExtensions = ['bin', 'so', 'o', 'gz', 'tar', 'zip', 'deb', 'rpm', 'jpg', 'png', 'gif'];
        if (in_array(strtolower($ext), $binaryExtensions)) {
            return true;
        }

        return false;
    }

    /**
     * Find which {{VARIABLE}} placeholders are used in template content
     */
    private function findUsedVariables(string $content): array
    {
        preg_match_all('/\{\{\s*([A-Z_][A-Z0-9_]*)\s*\}\}/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }
}

