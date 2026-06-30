<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Snapshot Service
 * 
 * Orchestrates taking a full server snapshot via the local agent daemon.
 * Stores the result as JSON on disk under var/snapshots/.
 * Fleet Manager ONLY READS from the server -- never edits configs.
 * The snapshot is the raw material for blueprint/template generation.
 */
class SnapshotService
{
    private Container $container;
    private AgentService $agent;
    private string $snapshotsDir;
    private string $indexFile;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->agent = $container->get(AgentService::class);

        $projectRoot = dirname(__DIR__, 3);
        $this->snapshotsDir = $projectRoot . '/var/snapshots';
        $this->indexFile = $this->snapshotsDir . '/index.json';

        // Ensure directory exists
        if (!is_dir($this->snapshotsDir)) {
            @mkdir($this->snapshotsDir, 0775, true);
        }
    }

    /**
     * Take a new snapshot of the local server
     * 
     * @param array $options [mode, categories, label]
     * @return array Result with snapshot_id on success
     */
    public function take(array $options = []): array
    {
        $mode = $options['mode'] ?? 'full_clone';
        $categories = $options['categories'] ?? null;
        $label = $options['label'] ?? '';

        // Check agent is running
        if (!$this->agent->isRunning()) {
            return [
                'success' => false,
                'error' => 'Fleet Agent is not running. Start it with: systemctl start fleet-agent',
            ];
        }

        // Trigger extraction via agent
        $result = $this->agent->extract(false, $categories, ['mode' => $mode]);

        if (!($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Agent extraction failed',
                'details' => $result['details'] ?? null,
            ];
        }

        // Build snapshot data
        $snapshotId = date('Y-m-d_His');

        // The agent returns: { success: true, data: { extracted: {...}, server_info: {...}, summary: {...}, ... }}
        // We need to unwrap the 'data' envelope first
        $agentData = $result['data'] ?? $result;
        $extracted = $agentData['extracted'] ?? [];

        // Defensive guard: never persist an empty snapshot (zero categories), even if the
        // agent reported success (e.g. agent version skew). An empty snapshot only yields
        // a broken/empty blueprint downstream.
        if (empty($extracted)) {
            return [
                'success' => false,
                'error' => 'Extraction returned no categories - refusing to save an empty snapshot.',
            ];
        }

        // Use agent-provided server_info (the agent already gathers hostname, IP, OS, kernel)
        // Fall back to our own parsing if agent didn't provide it
        $serverInfo = $agentData['server_info'] ?? $this->extractServerInfo($extracted);

        // Always build installed_services from extracted categories (agent returns booleans,
        // but frontend needs [{category, name, files_extracted}])
        $installedServices = $this->detectInstalledServices($extracted);

        $snapshot = [
            'id' => $snapshotId,
            'label' => $label ?: 'Snapshot ' . date('Y-m-d H:i:s'),
            'timestamp' => date('c'),
            'mode' => $mode,
            'server_info' => $serverInfo,
            'installed_services' => $installedServices,
            'categories' => array_keys($extracted),
            'categories_count' => count($extracted),
            'extracted' => $extracted,
            'summary' => $agentData['summary'] ?? $this->buildSummary($extracted),
        ];

        // Store to disk
        $filePath = $this->snapshotsDir . '/' . $snapshotId . '.json';
        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if (file_put_contents($filePath, $json) === false) {
            return [
                'success' => false,
                'error' => 'Failed to write snapshot file',
            ];
        }

        // Update index
        $this->updateIndex($snapshotId, $snapshot);

        return [
            'success' => true,
            'snapshot_id' => $snapshotId,
            'size' => strlen($json),
            'categories_count' => count($extracted),
            'file' => $filePath,
        ];
    }

    /**
     * List all available snapshots (from index)
     */
    public function list(): array
    {
        if (!file_exists($this->indexFile)) {
            // Rebuild index from files on disk
            $this->rebuildIndex();
        }

        $index = $this->readIndex();
        
        // Sort newest first
        usort($index, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));

        return $index;
    }

    /**
     * Get a single snapshot by ID
     */
    public function get(string $id): ?array
    {
        $filePath = $this->snapshotsDir . '/' . $id . '.json';

        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if (!$content) {
            return null;
        }

        return json_decode($content, true);
    }

    /**
     * Delete a snapshot
     */
    public function delete(string $id): bool
    {
        $filePath = $this->snapshotsDir . '/' . $id . '.json';

        if (!file_exists($filePath)) {
            return false;
        }

        @unlink($filePath);
        $this->removeFromIndex($id);

        return true;
    }

    /**
     * Create a blueprint from a stored snapshot.
     * 
     * Delegates to TemplateGeneratorService which:
     * 1. Reads real config content from the snapshot
     * 2. Detects server-specific values (IPs, passwords, domains)
     * 3. Replaces them with {{VARIABLE}} placeholders
     * 4. Stores the generated templates in blueprint_templates DB table
     * 
     * Templates are ALWAYS dynamically generated from what the server
     * actually has -- never hand-written.
     */
    public function createBlueprintFromSnapshot(
        string $snapshotId,
        string $name,
        string $description = '',
        ?array $selectedCategories = null
    ): array {
        $snapshot = $this->get($snapshotId);
        if (!$snapshot) {
            return ['success' => false, 'error' => 'Snapshot not found'];
        }

        $templateGenerator = $this->container->get(TemplateGeneratorService::class);

        return $templateGenerator->generateAndSaveBlueprint(
            $snapshot,
            $name,
            $description,
            $selectedCategories
        );
    }

    /**
     * Preview templates that would be generated from a snapshot
     * (without saving to DB)
     */
    public function previewTemplatesFromSnapshot(
        string $snapshotId,
        ?array $selectedCategories = null
    ): array {
        $snapshot = $this->get($snapshotId);
        if (!$snapshot) {
            return ['success' => false, 'error' => 'Snapshot not found'];
        }

        $templateGenerator = $this->container->get(TemplateGeneratorService::class);

        return $templateGenerator->previewFromSnapshot($snapshot, $selectedCategories);
    }

    // ----- Private helpers -----

    /**
     * Extract server info from the extraction data
     */
    private function extractServerInfo(array $extracted): array
    {
        $info = [
            'hostname' => null,
            'ip_address' => null,
            'os' => null,
            'kernel' => null,
            'uptime' => null,
        ];

        // system_info category often has command outputs
        $sysInfo = $extracted['system_info'] ?? [];
        $commands = $sysInfo['commands'] ?? [];

        foreach ($commands as $cmd) {
            $output = $cmd['output'] ?? '';
            $command = $cmd['command'] ?? '';

            if (strpos($command, 'hostname') !== false && !$info['hostname']) {
                $info['hostname'] = trim($output);
            }
            if (strpos($command, 'uname') !== false) {
                $info['kernel'] = trim($output);
            }
            if (strpos($command, 'uptime') !== false) {
                $info['uptime'] = trim($output);
            }
            if (strpos($command, 'lsb_release') !== false || strpos($command, 'cat /etc/os-release') !== false) {
                $info['os'] = trim($output);
            }
        }

        // Try to get IP from network category
        $network = $extracted['network'] ?? [];
        $netCommands = $network['commands'] ?? [];
        foreach ($netCommands as $cmd) {
            $output = $cmd['output'] ?? '';
            if (preg_match('/inet\s+(\d+\.\d+\.\d+\.\d+)/', $output, $m)) {
                if ($m[1] !== '127.0.0.1' && !$info['ip_address']) {
                    $info['ip_address'] = $m[1];
                }
            }
        }

        return $info;
    }

    /**
     * Detect which services are installed based on extracted categories
     */
    private function detectInstalledServices(array $extracted): array
    {
        $serviceMap = [
            'openlitespeed' => 'OpenLiteSpeed',
            'postfix' => 'Postfix',
            'dovecot' => 'Dovecot',
            'mariadb' => 'MariaDB',
            'redis' => 'Redis',
            'fail2ban' => 'Fail2ban',
            'firewalld' => 'Firewalld',
            'opendkim' => 'OpenDKIM',
            'opendmarc' => 'OpenDMARC',
            'spamassassin' => 'SpamAssassin',
            'openvpn' => 'OpenVPN',
            'wireguard' => 'WireGuard',
            'clamav' => 'ClamAV',
            'rspamd' => 'Rspamd',
            'modsecurity' => 'ModSecurity',
            'cpguard' => 'CPGuard',
            'panel' => 'VPS Admin Panel',
            'emailapp' => 'MailFlow Email App',
            'fleetmanager' => 'Fleet Manager',
            'collab_server' => 'Collab Server',
            'mailsync_server' => 'MailSync Server',
        ];

        $installed = [];
        foreach ($extracted as $category => $data) {
            if (isset($serviceMap[$category])) {
                $fileCount = count($data['files'] ?? []);
                $installed[] = [
                    'category' => $category,
                    'name' => $serviceMap[$category],
                    'files_extracted' => $fileCount,
                ];
            }
        }

        return $installed;
    }

    /**
     * Build a summary of the snapshot
     */
    private function buildSummary(array $extracted): array
    {
        $totalFiles = 0;
        $totalSize = 0;
        $categorySummary = [];

        foreach ($extracted as $category => $data) {
            $files = $data['files'] ?? [];
            $fileCount = count($files);
            $catSize = 0;

            foreach ($files as $file) {
                $catSize += strlen($file['content'] ?? '');
            }

            $totalFiles += $fileCount;
            $totalSize += $catSize;

            $categorySummary[$category] = [
                'files' => $fileCount,
                'size' => $catSize,
            ];
        }

        return [
            'total_categories' => count($extracted),
            'total_files' => $totalFiles,
            'total_size' => $totalSize,
            'categories' => $categorySummary,
        ];
    }

    /**
     * Read the index file
     */
    private function readIndex(): array
    {
        if (!file_exists($this->indexFile)) {
            return [];
        }

        $content = file_get_contents($this->indexFile);
        return json_decode($content, true) ?? [];
    }

    /**
     * Update the index file with a new snapshot entry
     */
    private function updateIndex(string $id, array $snapshot): void
    {
        $index = $this->readIndex();

        $index[] = [
            'id' => $id,
            'label' => $snapshot['label'] ?? $id,
            'timestamp' => $snapshot['timestamp'] ?? date('c'),
            'mode' => $snapshot['mode'] ?? 'full_clone',
            'categories_count' => $snapshot['categories_count'] ?? 0,
            'size' => filesize($this->snapshotsDir . '/' . $id . '.json') ?: 0,
            'server_info' => $snapshot['server_info'] ?? [],
        ];

        file_put_contents($this->indexFile, json_encode($index, JSON_PRETTY_PRINT));
    }

    /**
     * Remove a snapshot from the index
     */
    private function removeFromIndex(string $id): void
    {
        $index = $this->readIndex();
        $index = array_values(array_filter($index, fn($entry) => ($entry['id'] ?? '') !== $id));
        file_put_contents($this->indexFile, json_encode($index, JSON_PRETTY_PRINT));
    }

    /**
     * Rebuild index from snapshot files on disk
     */
    private function rebuildIndex(): void
    {
        $files = glob($this->snapshotsDir . '/*.json');
        $index = [];

        foreach ($files as $file) {
            $basename = basename($file, '.json');
            if ($basename === 'index') continue;

            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if ($data) {
                $index[] = [
                    'id' => $data['id'] ?? $basename,
                    'label' => $data['label'] ?? $basename,
                    'timestamp' => $data['timestamp'] ?? date('c', filemtime($file)),
                    'mode' => $data['mode'] ?? 'unknown',
                    'categories_count' => $data['categories_count'] ?? 0,
                    'size' => filesize($file),
                    'server_info' => $data['server_info'] ?? [],
                ];
            }
        }

        file_put_contents($this->indexFile, json_encode($index, JSON_PRETTY_PRINT));
    }
}

