<?php

namespace FleetManager\Api\Controllers;

use FleetManager\Api\Core\Request;
use FleetManager\Api\Core\Response;
use FleetManager\Api\Services\ConfigExtractorService;
use FleetManager\Api\Services\TemplateService;
use FleetManager\Api\Services\VariableDetectorService;

/**
 * Blueprint management controller
 */
class BlueprintController extends BaseController
{
    /**
     * List all blueprints
     */
    public function index(Request $request): Response
    {
        $db = $this->getDatabase();

        $stmt = $db->query(
            "SELECT b.*, 
                    (SELECT COUNT(*) FROM servers WHERE blueprint_id = b.id) as server_count,
                    (SELECT COUNT(*) FROM blueprint_templates WHERE blueprint_id = b.id) as template_count
             FROM blueprints b
             ORDER BY b.is_default DESC, b.name ASC"
        );

        return Response::success($stmt->fetchAll());
    }

    /**
     * Get single blueprint with templates
     */
    public function show(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT * FROM blueprints WHERE id = ?");
        $stmt->execute([$id]);
        $blueprint = $stmt->fetch();

        if (!$blueprint) {
            return Response::notFound('Blueprint not found');
        }

        // Get templates grouped by category
        $stmt = $db->prepare(
            "SELECT * FROM blueprint_templates WHERE blueprint_id = ? ORDER BY category, filename"
        );
        $stmt->execute([$id]);
        $templates = $stmt->fetchAll();

        // Group by category
        $blueprint['templates'] = [];
        foreach ($templates as $template) {
            $category = $template['category'];
            if (!isset($blueprint['templates'][$category])) {
                $blueprint['templates'][$category] = [];
            }
            $blueprint['templates'][$category][] = $template;
        }

        // Parse variables JSON
        $blueprint['variables'] = json_decode($blueprint['variables'] ?? '{}', true);

        return Response::success($blueprint);
    }

    /**
     * Create a new blueprint
     */
    public function create(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['name']);
        if ($validation) return $validation;

        $db = $this->getDatabase();

        $stmt = $db->prepare(
            "INSERT INTO blueprints (name, description, source_server, source_ip, version, 
                                     panel_version, email_app_version, variables)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $request->input('name'),
            $request->input('description'),
            $request->input('source_server'),
            $request->input('source_ip'),
            $request->input('version', '1.0.0'),
            $request->input('panel_version'),
            $request->input('email_app_version'),
            json_encode($request->input('variables', [])),
        ]);

        $blueprintId = (int)$db->lastInsertId();

        $this->logAction('blueprint.create', null, $request->input('name'), 'success');

        return Response::success(['id' => $blueprintId], 'Blueprint created successfully');
    }

    /**
     * Update blueprint
     */
    public function update(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT id, name FROM blueprints WHERE id = ?");
        $stmt->execute([$id]);
        $blueprint = $stmt->fetch();

        if (!$blueprint) {
            return Response::notFound('Blueprint not found');
        }

        $updates = [];
        $params = [];

        $fields = ['name', 'description', 'version', 'panel_version', 'email_app_version', 'is_default'];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $updates[] = "{$field} = ?";
                $params[] = $request->input($field);
            }
        }

        if ($request->has('variables')) {
            $updates[] = "variables = ?";
            $params[] = json_encode($request->input('variables'));
        }

        if (empty($updates)) {
            return Response::error('No fields to update', 400);
        }

        // If setting as default, unset others first
        if ($request->input('is_default')) {
            $db->exec("UPDATE blueprints SET is_default = 0");
        }

        $params[] = $id;
        $stmt = $db->prepare("UPDATE blueprints SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        $this->logAction('blueprint.update', null, $blueprint['name'], 'success');

        return Response::success(null, 'Blueprint updated successfully');
    }

    /**
     * Delete blueprint
     */
    public function delete(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT id, name FROM blueprints WHERE id = ?");
        $stmt->execute([$id]);
        $blueprint = $stmt->fetch();

        if (!$blueprint) {
            return Response::notFound('Blueprint not found');
        }

        // Check if any servers use this blueprint
        $stmt = $db->prepare("SELECT COUNT(*) FROM servers WHERE blueprint_id = ?");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            return Response::error('Cannot delete blueprint that is in use by servers', 400);
        }

        $stmt = $db->prepare("DELETE FROM blueprints WHERE id = ?");
        $stmt->execute([$id]);

        $this->logAction('blueprint.delete', null, $blueprint['name'], 'success');

        return Response::success(null, 'Blueprint deleted successfully');
    }

    /**
     * Add or update a template in blueprint
     */
    public function saveTemplate(Request $request): Response
    {
        $blueprintId = (int)$request->getParam('id');
        $db = $this->getDatabase();

        // Verify blueprint exists
        $stmt = $db->prepare("SELECT id FROM blueprints WHERE id = ?");
        $stmt->execute([$blueprintId]);
        if (!$stmt->fetch()) {
            return Response::notFound('Blueprint not found');
        }

        $validation = $this->validateRequired($request, ['category', 'filename', 'target_path', 'content']);
        if ($validation) return $validation;

        $templateId = $request->input('template_id');

        if ($templateId) {
            // Update existing template
            $stmt = $db->prepare(
                "UPDATE blueprint_templates SET 
                    category = ?, filename = ?, target_path = ?, content = ?,
                    permissions = ?, owner = ?, group_name = ?, is_optional = ?, requires_module = ?
                 WHERE id = ? AND blueprint_id = ?"
            );
            $stmt->execute([
                $request->input('category'),
                $request->input('filename'),
                $request->input('target_path'),
                $request->input('content'),
                $request->input('permissions', '0644'),
                $request->input('owner', 'root'),
                $request->input('group_name', 'root'),
                $request->input('is_optional', 0),
                $request->input('requires_module'),
                $templateId,
                $blueprintId,
            ]);
        } else {
            // Create new template
            $stmt = $db->prepare(
                "INSERT INTO blueprint_templates 
                    (blueprint_id, category, filename, target_path, content, permissions, owner, group_name, is_optional, requires_module)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $blueprintId,
                $request->input('category'),
                $request->input('filename'),
                $request->input('target_path'),
                $request->input('content'),
                $request->input('permissions', '0644'),
                $request->input('owner', 'root'),
                $request->input('group_name', 'root'),
                $request->input('is_optional', 0),
                $request->input('requires_module'),
            ]);
            $templateId = (int)$db->lastInsertId();
        }

        return Response::success(['id' => $templateId], 'Template saved successfully');
    }

    /**
     * Delete a template from blueprint
     */
    public function deleteTemplate(Request $request): Response
    {
        $blueprintId = (int)$request->getParam('id');
        $templateId = (int)$request->getParam('templateId');
        $db = $this->getDatabase();

        $stmt = $db->prepare("DELETE FROM blueprint_templates WHERE id = ? AND blueprint_id = ?");
        $stmt->execute([$templateId, $blueprintId]);

        if ($stmt->rowCount() === 0) {
            return Response::notFound('Template not found');
        }

        return Response::success(null, 'Template deleted successfully');
    }

    /**
     * Get single template
     */
    public function getTemplate(Request $request): Response
    {
        $blueprintId = (int)$request->getParam('id');
        $templateId = (int)$request->getParam('templateId');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT * FROM blueprint_templates WHERE id = ? AND blueprint_id = ?");
        $stmt->execute([$templateId, $blueprintId]);
        $template = $stmt->fetch();

        if (!$template) {
            return Response::notFound('Template not found');
        }

        return Response::success($template);
    }

    /**
     * Duplicate a blueprint
     */
    public function duplicate(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT * FROM blueprints WHERE id = ?");
        $stmt->execute([$id]);
        $blueprint = $stmt->fetch();

        if (!$blueprint) {
            return Response::notFound('Blueprint not found');
        }

        $newName = $request->input('name', $blueprint['name'] . ' (Copy)');

        // Create new blueprint
        $stmt = $db->prepare(
            "INSERT INTO blueprints (name, description, source_server, source_ip, version, 
                                     panel_version, email_app_version, variables, is_default)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)"
        );
        $stmt->execute([
            $newName,
            $blueprint['description'],
            $blueprint['source_server'],
            $blueprint['source_ip'],
            $blueprint['version'],
            $blueprint['panel_version'],
            $blueprint['email_app_version'],
            $blueprint['variables'],
        ]);

        $newBlueprintId = (int)$db->lastInsertId();

        // Copy templates
        $stmt = $db->prepare("SELECT * FROM blueprint_templates WHERE blueprint_id = ?");
        $stmt->execute([$id]);
        $templates = $stmt->fetchAll();

        $insertStmt = $db->prepare(
            "INSERT INTO blueprint_templates 
                (blueprint_id, category, filename, target_path, content, permissions, owner, group_name, is_optional, requires_module)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($templates as $template) {
            $insertStmt->execute([
                $newBlueprintId,
                $template['category'],
                $template['filename'],
                $template['target_path'],
                $template['content'],
                $template['permissions'],
                $template['owner'],
                $template['group_name'],
                $template['is_optional'],
                $template['requires_module'],
            ]);
        }

        $this->logAction('blueprint.duplicate', null, $newName, 'success', ['source_id' => $id]);

        return Response::success(['id' => $newBlueprintId], 'Blueprint duplicated successfully');
    }

    /**
     * Get all available extraction categories
     * GET /api/blueprints/categories
     * 
     * For local extraction, uses agent to get categories
     */
    public function getCategories(Request $request): Response
    {
        $isLocal = (bool)$request->getQuery('is_local', false);
        $mode = $request->getQuery('mode', 'full_clone');
        
        // For local extraction, get categories from agent (ensures we have what agent can extract)
        if ($isLocal) {
            $agent = $this->container->get(\FleetManager\Api\Services\AgentService::class);
            
            if (!$agent->isRunning()) {
                return Response::error(
                    'Agent service is not running. Please start it with: systemctl start fleet-agent',
                    503
                );
            }
            
            $result = $agent->getCategories($mode);
            if ($result['success'] && isset($result['data'])) {
                return Response::success($result['data']);
            }
            
            return Response::error($result['error'] ?? 'Failed to get categories', 500);
        }
        
        // For remote extraction, use local service definition
        $categories = ConfigExtractorService::getAllCategories();
        
        // Get the full category info with names
        $reflection = new \ReflectionClass(ConfigExtractorService::class);
        $extractionMap = $reflection->getConstant('EXTRACTION_MAP');
        
        $categoryList = [];
        foreach ($categories as $key) {
            $categoryList[] = [
                'key' => $key,
                'name' => $extractionMap[$key]['name'] ?? $key,
            ];
        }

        // Split into chunks for chunked extraction (groups of 8 categories)
        $chunks = array_chunk($categoryList, 8);
        $chunkKeys = [];
        foreach ($chunks as $i => $chunk) {
            $chunkKeys[] = [
                'chunk_index' => $i,
                'categories' => array_column($chunk, 'key'),
                'names' => array_column($chunk, 'name'),
            ];
        }

        return Response::success([
            'categories' => $categoryList,
            'total' => count($categoryList),
            'chunks' => $chunkKeys,
            'chunk_count' => count($chunkKeys),
        ]);
    }

    /**
     * Extract configs from a server (dry run or actual)
     * POST /api/blueprints/extract
     * 
     * Supports chunked extraction via 'categories' parameter:
     * - If not provided, extracts all categories (may timeout for large extractions)
     * - If provided, only extracts specified categories (for chunked extraction)
     * 
     * Supports local extraction via 'is_local' parameter:
     * - If true, uses agent daemon for privileged access (runs as root)
     * - If false, connects via SSH to remote server
     */
    public function extract(Request $request): Response
    {
        // Increase timeout for extraction
        set_time_limit(300);
        ini_set('max_execution_time', 300);

        $dryRun = (bool)$request->input('dry_run', false);
        $isLocal = (bool)$request->input('is_local', false);
        $categories = $request->input('categories', null); // Optional: specific categories to extract

        // LOCAL MODE: Use agent daemon (runs as root, has access to all files)
        if ($isLocal) {
            $agent = $this->container->get(\FleetManager\Api\Services\AgentService::class);
            
            // Check if agent is running
            if (!$agent->isRunning()) {
                return Response::error(
                    'Agent service is not running. Please start it with: systemctl start fleet-agent',
                    503
                );
            }
            
            // Prepare extraction options
            $extractionOptions = [
                'mode' => $request->input('mode', 'full_clone'),
                'include_core_apps' => $request->input('include_core_apps', ['panel', 'emailapp', 'fleetmanager']),
                'selected_vhosts' => $request->input('selected_vhosts', []),
            ];
            
            // Extract via agent
            $result = $agent->extract($dryRun, $categories, $extractionOptions);
            
            if (!$result['success']) {
                return Response::error($result['error'] ?? 'Extraction failed', 500);
            }
            
            // Return the data from agent
            $data = $result['data'] ?? $result;
            $data['chunk_info'] = [
                'categories_requested' => $categories,
                'is_chunked' => $categories !== null,
                'is_local' => true,
                'mode' => $extractionOptions['mode'],
            ];
            
            return Response::success($data, $dryRun ? 'Dry run complete' : 'Extraction complete');
        }

        // REMOTE MODE: Validate SSH credentials and connect
        $validation = $this->validateRequired($request, ['ip_address']);
        if ($validation) return $validation;

        $authMethod = $request->input('auth_method', 'key');
        if ($authMethod === 'password' && !$request->input('ssh_password')) {
            return Response::error('SSH password is required', 422);
        }
        if ($authMethod === 'key' && !$request->input('key_path')) {
            return Response::error('SSH key path is required for key-based auth', 422);
        }

        $server = [
            'ip_address' => $request->input('ip_address'),
            'ssh_port' => (int)$request->input('ssh_port', 22),
            'ssh_user' => $request->input('ssh_user', 'root'),
            'ssh_password_encrypted' => null,
            'is_local' => false,
        ];

        // Create extractor
        $extractor = $this->container->get(ConfigExtractorService::class);
        $extractor->setDryRun($dryRun);

        // Set specific categories if provided (for chunked extraction)
        if ($categories && is_array($categories)) {
            $extractor->setCategories($categories);
        }

        // Create SSH service and connect
        $ssh = $this->container->get(\FleetManager\Api\Services\SSHService::class);
        
        if (!$dryRun) {
            if ($authMethod === 'key') {
                $connected = $ssh->connectWithKey(
                    $server['ip_address'],
                    $server['ssh_port'],
                    $server['ssh_user'],
                    $request->input('key_path'),
                    $request->input('key_passphrase', '')
                );
            } else {
                $connected = $ssh->connect(
                    $server['ip_address'],
                    $server['ssh_port'],
                    $server['ssh_user'],
                    $request->input('ssh_password')
                );
            }

            if (!$connected) {
                return Response::error('Failed to connect to server. Check credentials.', 400);
            }
        }

        $extractor->setSSH($ssh);

        try {
            $result = $extractor->extract($server);

            if (!$dryRun && isset($ssh)) {
                $ssh->disconnect();
            }

            // Add chunk info to response
            $result['chunk_info'] = [
                'categories_requested' => $categories,
                'is_chunked' => $categories !== null,
                'is_local' => $isLocal,
            ];

            return Response::success($result, $dryRun ? 'Dry run complete' : 'Extraction complete');
        } catch (\Exception $e) {
            if (!$isLocal && !$dryRun && isset($ssh)) {
                $ssh->disconnect();
            }
            return Response::error('Extraction failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create blueprint from extracted configs
     * POST /api/blueprints/create-from-extraction
     */
    public function createFromExtraction(Request $request): Response
    {
        // Debug logging for large request issues
        $rawInput = file_get_contents('php://input');
        $inputSize = strlen($rawInput);
        error_log("Blueprint save: received {$inputSize} bytes of input");
        
        if (empty($rawInput)) {
            error_log("Blueprint save: WARNING - empty input received");
            return Response::error('No data received. Request body may be too large.', 400);
        }
        
        $validation = $this->validateRequired($request, ['name', 'extracted_data']);
        if ($validation) return $validation;

        $extractedData = $request->input('extracted_data');
        $db = $this->getDatabase();

        // Create the blueprint
        $stmt = $db->prepare(
            "INSERT INTO blueprints (name, description, source_server, source_ip, version, variables)
             VALUES (?, ?, ?, ?, '1.0.0', ?)"
        );

        $serverInfo = $extractedData['server_info'] ?? [];
        
        // Use enhanced variable detection
        $detector = $this->container->get(VariableDetectorService::class);
        $variables = $detector->detectVariables($extractedData);

        $stmt->execute([
            $request->input('name'),
            $request->input('description', 'Extracted from ' . ($serverInfo['hostname'] ?? 'server')),
            $serverInfo['hostname'] ?? null,
            $serverInfo['ip'] ?? null,
            json_encode($variables),
        ]);

        $blueprintId = (int)$db->lastInsertId();

        // Convert extracted configs to templates and save
        $extractor = $this->container->get(ConfigExtractorService::class);
        
        // Build variable values for replacement using detector
        // Merge detected values with any user-provided overrides
        $customVars = $request->input('variables', []);
        $variableValues = $detector->buildVariableMap($variables['detected'] ?? [], $customVars);

        $templates = $extractor->convertToTemplates($extractedData['extracted'] ?? [], $variableValues);

        // Insert templates
        $insertStmt = $db->prepare(
            "INSERT INTO blueprint_templates 
                (blueprint_id, category, filename, target_path, content, permissions, owner, group_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $templateCount = 0;
        foreach ($templates as $template) {
            $insertStmt->execute([
                $blueprintId,
                $template['category'],
                $template['filename'],
                $template['target_path'],
                $template['content'],
                $template['permissions'],
                $template['owner'],
                $template['group'],
            ]);
            $templateCount++;
        }

        // Convert and insert extracted packages
        $packageCount = 0;
        if (!empty($extractedData['packages'])) {
            $packages = $extractor->convertToPackages($extractedData['packages']);
            
            $pkgInsertStmt = $db->prepare(
                "INSERT INTO blueprint_packages 
                    (blueprint_id, category, package_name, version_constraint, is_required, install_order)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            foreach ($packages as $pkg) {
                $pkgInsertStmt->execute([
                    $blueprintId,
                    $pkg['category'],
                    $pkg['package_name'],
                    $pkg['version_constraint'],
                    $pkg['is_required'] ? 1 : 0,
                    $pkg['install_order'],
                ]);
                $packageCount++;
            }
        }

        $this->logAction('blueprint.create_from_extraction', null, $request->input('name'), 'success', [
            'template_count' => $templateCount,
            'package_count' => $packageCount,
            'source_ip' => $serverInfo['ip'] ?? null,
        ]);

        return Response::success([
            'id' => $blueprintId,
            'template_count' => $templateCount,
            'package_count' => $packageCount,
        ], 'Blueprint created from extraction');
    }

    /**
     * Detect variables that should be parameterized
     * Uses VariableDetectorService for comprehensive pattern matching
     */
    private function detectVariables(array $extractedData): array
    {
        $detector = $this->container->get(VariableDetectorService::class);
        return $detector->detectVariables($extractedData);
    }

    /**
     * Detect variables from extraction data (API endpoint)
     * POST /api/blueprints/detect-variables
     */
    public function detectVariablesFromExtraction(Request $request): Response
    {
        $extractedData = $request->input('extracted_data');
        
        if (empty($extractedData)) {
            return Response::error('Missing extracted_data', 400);
        }

        $detector = $this->container->get(VariableDetectorService::class);
        $result = $detector->detectVariables($extractedData);

        // Also include variable categories for UI
        $result['categories'] = $detector->getVariableCategories();

        return Response::success($result);
    }

    /**
     * Test connection to a server before extraction
     * POST /api/blueprints/test-connection
     */
    public function testConnection(Request $request): Response
    {
        $isLocal = (bool)$request->input('is_local', false);

        // Local server - test agent is running
        if ($isLocal) {
            $agent = $this->container->get(\FleetManager\Api\Services\AgentService::class);
            
            if (!$agent->isRunning()) {
                return Response::error(
                    'Agent service is not running. Please start it with: systemctl start fleet-agent',
                    503
                );
            }
            
            $result = $agent->execute('extractor.test');
            if ($result['success']) {
                return Response::success([
                    'success' => true,
                    'hostname' => $result['data']['hostname'] ?? gethostname(),
                    'os' => php_uname('s') . ' ' . php_uname('r'),
                    'uptime' => $this->getLocalUptime(),
                    'agent' => 'running',
                ], 'Agent connection successful');
            }
            
            return Response::error($result['error'] ?? 'Agent test failed', 500);
        }

        // Remote server - require SSH credentials
        $validation = $this->validateRequired($request, ['ip_address']);
        if ($validation) return $validation;

        $authMethod = $request->input('auth_method', 'password');
        
        $ssh = $this->container->get(\FleetManager\Api\Services\SSHService::class);

        if ($authMethod === 'key') {
            if (!$request->input('key_path')) {
                return Response::error('SSH key path is required', 422);
            }
            $result = $ssh->testConnectionWithKey(
                $request->input('ip_address'),
                (int)$request->input('ssh_port', 22),
                $request->input('ssh_user', 'root'),
                $request->input('key_path'),
                $request->input('key_passphrase', '')
            );
        } else {
            if (!$request->input('ssh_password')) {
                return Response::error('SSH password is required', 422);
            }
            $result = $ssh->testConnection(
                $request->input('ip_address'),
                (int)$request->input('ssh_port', 22),
                $request->input('ssh_user', 'root'),
                $request->input('ssh_password')
            );
        }

        if ($result['success']) {
            return Response::success($result, 'Connection successful');
        }

        return Response::error($result['error'] ?? 'Connection failed', 400);
    }

    /**
     * Get local server uptime
     */
    private function getLocalUptime(): string
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $uptime = @file_get_contents('/proc/uptime');
            if ($uptime) {
                $seconds = (int)explode(' ', $uptime)[0];
                $days = floor($seconds / 86400);
                $hours = floor(($seconds % 86400) / 3600);
                return "{$days} days, {$hours} hours";
            }
        }
        return 'N/A';
    }

    // =====================================================
    // Package Management
    // =====================================================

    /**
     * Get packages for a blueprint
     * GET /api/blueprints/{id}/packages
     */
    public function getPackages(Request $request): Response
    {
        $blueprintId = (int)$request->getParam('id');
        $db = $this->getDatabase();

        // Verify blueprint exists
        $stmt = $db->prepare("SELECT id FROM blueprints WHERE id = ?");
        $stmt->execute([$blueprintId]);
        if (!$stmt->fetch()) {
            return Response::notFound('Blueprint not found');
        }

        // Get packages grouped by category
        $stmt = $db->prepare(
            "SELECT * FROM blueprint_packages WHERE blueprint_id = ? ORDER BY category, install_order, package_name"
        );
        $stmt->execute([$blueprintId]);
        $packages = $stmt->fetchAll();

        // Group by category
        $grouped = [];
        foreach ($packages as $pkg) {
            $category = $pkg['category'];
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $pkg;
        }

        return Response::success([
            'packages' => $packages,
            'by_category' => $grouped,
            'total' => count($packages),
            'categories' => array_keys($grouped),
        ]);
    }

    /**
     * Save packages for a blueprint
     * POST /api/blueprints/{id}/packages
     */
    public function savePackages(Request $request): Response
    {
        $blueprintId = (int)$request->getParam('id');
        $db = $this->getDatabase();

        // Verify blueprint exists
        $stmt = $db->prepare("SELECT id FROM blueprints WHERE id = ?");
        $stmt->execute([$blueprintId]);
        if (!$stmt->fetch()) {
            return Response::notFound('Blueprint not found');
        }

        $packages = $request->input('packages', []);

        if (!is_array($packages)) {
            return Response::validationError(['packages' => 'Packages must be an array']);
        }

        // Start transaction
        $db->beginTransaction();

        try {
            // Delete existing packages
            $stmt = $db->prepare("DELETE FROM blueprint_packages WHERE blueprint_id = ?");
            $stmt->execute([$blueprintId]);

            // Insert new packages
            $insertStmt = $db->prepare(
                "INSERT INTO blueprint_packages 
                    (blueprint_id, category, package_name, version_constraint, is_required, install_order, pre_install_script, post_install_script)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            foreach ($packages as $index => $pkg) {
                $insertStmt->execute([
                    $blueprintId,
                    $pkg['category'] ?? 'base',
                    $pkg['package_name'],
                    $pkg['version_constraint'] ?? null,
                    $pkg['is_required'] ?? 1,
                    $pkg['install_order'] ?? $index,
                    $pkg['pre_install_script'] ?? null,
                    $pkg['post_install_script'] ?? null,
                ]);
            }

            $db->commit();

            $this->logAction('blueprint.packages_updated', null, $blueprintId, 'success', [
                'package_count' => count($packages),
            ]);

            return Response::success([
                'saved' => count($packages),
            ], 'Packages saved successfully');

        } catch (\Exception $e) {
            $db->rollBack();
            return Response::error('Failed to save packages: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Add a single package to a blueprint
     * POST /api/blueprints/{id}/packages/add
     */
    public function addPackage(Request $request): Response
    {
        $blueprintId = (int)$request->getParam('id');
        $db = $this->getDatabase();

        // Verify blueprint exists
        $stmt = $db->prepare("SELECT id FROM blueprints WHERE id = ?");
        $stmt->execute([$blueprintId]);
        if (!$stmt->fetch()) {
            return Response::notFound('Blueprint not found');
        }

        $validation = $this->validateRequired($request, ['package_name']);
        if ($validation) return $validation;

        // Get max install order
        $stmt = $db->prepare(
            "SELECT COALESCE(MAX(install_order), 0) + 1 FROM blueprint_packages WHERE blueprint_id = ?"
        );
        $stmt->execute([$blueprintId]);
        $nextOrder = (int)$stmt->fetchColumn();

        $stmt = $db->prepare(
            "INSERT INTO blueprint_packages 
                (blueprint_id, category, package_name, version_constraint, is_required, install_order, pre_install_script, post_install_script)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $blueprintId,
            $request->input('category', 'base'),
            $request->input('package_name'),
            $request->input('version_constraint'),
            $request->input('is_required', 1),
            $request->input('install_order', $nextOrder),
            $request->input('pre_install_script'),
            $request->input('post_install_script'),
        ]);

        $packageId = (int)$db->lastInsertId();

        return Response::success(['id' => $packageId], 'Package added successfully');
    }

    /**
     * Update a single package
     * PUT /api/blueprints/{id}/packages/{packageId}
     */
    public function updatePackage(Request $request): Response
    {
        $blueprintId = (int)$request->getParam('id');
        $packageId = (int)$request->getParam('packageId');
        $db = $this->getDatabase();

        $stmt = $db->prepare("SELECT id FROM blueprint_packages WHERE id = ? AND blueprint_id = ?");
        $stmt->execute([$packageId, $blueprintId]);
        if (!$stmt->fetch()) {
            return Response::notFound('Package not found');
        }

        $updates = [];
        $params = [];
        $fields = ['category', 'package_name', 'version_constraint', 'is_required', 'install_order', 'pre_install_script', 'post_install_script'];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $updates[] = "{$field} = ?";
                $params[] = $request->input($field);
            }
        }

        if (empty($updates)) {
            return Response::error('No fields to update', 400);
        }

        $params[] = $packageId;
        $stmt = $db->prepare("UPDATE blueprint_packages SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        return Response::success(null, 'Package updated successfully');
    }

    /**
     * Delete a package from blueprint
     * DELETE /api/blueprints/{id}/packages/{packageId}
     */
    public function deletePackage(Request $request): Response
    {
        $blueprintId = (int)$request->getParam('id');
        $packageId = (int)$request->getParam('packageId');
        $db = $this->getDatabase();

        $stmt = $db->prepare("DELETE FROM blueprint_packages WHERE id = ? AND blueprint_id = ?");
        $stmt->execute([$packageId, $blueprintId]);

        if ($stmt->rowCount() === 0) {
            return Response::notFound('Package not found');
        }

        return Response::success(null, 'Package deleted successfully');
    }

    /**
     * Import default package set for a category
     * POST /api/blueprints/{id}/packages/import-defaults
     */
    public function importDefaultPackages(Request $request): Response
    {
        $blueprintId = (int)$request->getParam('id');
        $category = $request->input('category');
        $db = $this->getDatabase();

        // Verify blueprint exists
        $stmt = $db->prepare("SELECT id FROM blueprints WHERE id = ?");
        $stmt->execute([$blueprintId]);
        if (!$stmt->fetch()) {
            return Response::notFound('Blueprint not found');
        }

        // Default package sets
        $defaults = [
            'base' => [
                'curl', 'wget', 'git', 'unzip', 'software-properties-common',
                'apt-transport-https', 'ca-certificates', 'gnupg', 'lsb-release',
            ],
            'web' => ['openlitespeed'],
            'php' => [
                'lsphp83', 'lsphp83-common', 'lsphp83-mysql', 'lsphp83-curl',
                'lsphp83-imap', 'lsphp83-intl', 'lsphp83-opcache',
            ],
            'database' => ['mariadb-server', 'mariadb-client'],
            'mail' => [
                'postfix', 'postfix-mysql',
                'dovecot-core', 'dovecot-imapd', 'dovecot-lmtpd',
                'dovecot-mysql', 'dovecot-sieve', 'dovecot-managesieved',
            ],
            'security' => ['fail2ban', 'firewalld', 'certbot'],
        ];

        if ($category && !isset($defaults[$category])) {
            return Response::error('Unknown category: ' . $category, 400);
        }

        $categoriesToImport = $category ? [$category => $defaults[$category]] : $defaults;

        $insertStmt = $db->prepare(
            "INSERT IGNORE INTO blueprint_packages (blueprint_id, category, package_name, install_order)
             VALUES (?, ?, ?, ?)"
        );

        $imported = 0;
        $order = 0;

        foreach ($categoriesToImport as $cat => $packages) {
            foreach ($packages as $pkg) {
                $insertStmt->execute([$blueprintId, $cat, $pkg, $order++]);
                if ($insertStmt->rowCount() > 0) {
                    $imported++;
                }
            }
        }

        return Response::success([
            'imported' => $imported,
            'categories' => array_keys($categoriesToImport),
        ], "Imported {$imported} packages");
    }

    /**
     * Get available package categories
     * GET /api/blueprints/package-categories
     */
    public function getPackageCategories(Request $request): Response
    {
        $categories = [
            ['key' => 'base', 'name' => 'Base System', 'icon' => 'foundation'],
            ['key' => 'web', 'name' => 'Web Server', 'icon' => 'dns'],
            ['key' => 'php', 'name' => 'PHP', 'icon' => 'code'],
            ['key' => 'database', 'name' => 'Database', 'icon' => 'database'],
            ['key' => 'mail', 'name' => 'Mail Server', 'icon' => 'mail'],
            ['key' => 'security', 'name' => 'Security', 'icon' => 'shield'],
        ];

        return Response::success($categories);
    }
}

