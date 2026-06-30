<?php
/**
 * Docker Management Action Handler
 * 
 * Manages Docker containers, images, and installation.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;

class DockerAction extends BaseAction
{
    public function getNamespace(): string
    {
        return 'docker';
    }

    public function getMethods(): array
    {
        return [
            'status',
            'install',
            'containers',
            'images',
            'container',
            'start',
            'stop',
            'restart',
            'logs',
            'stats',
            'inspect',
            'remove',
            'pull',
            'composeUp',
            'composeDown'
        ];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['remove']);
    }

    /**
     * Find Docker binary
     */
    private function findDockerBin(): ?string
    {
        $paths = [
            '/usr/bin/docker',
            '/usr/local/bin/docker',
            '/snap/bin/docker',
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }
        
        // Try which as fallback
        exec('which docker 2>/dev/null', $output, $code);
        if ($code === 0 && !empty($output[0])) {
            return trim($output[0]);
        }
        
        return null;
    }
    
    /**
     * Find Docker Compose binary
     */
    private function findDockerComposeBin(): ?string
    {
        // Docker Compose v2 (plugin)
        $dockerBin = $this->findDockerBin();
        if ($dockerBin) {
            exec("{$dockerBin} compose version 2>/dev/null", $output, $code);
            if ($code === 0) {
                return "{$dockerBin} compose";
            }
        }
        
        // Docker Compose v1 (standalone)
        $paths = [
            '/usr/bin/docker-compose',
            '/usr/local/bin/docker-compose',
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }
        
        return null;
    }

    /**
     * Check Docker installation status
     */
    protected function actionStatus(array $params, string $actor): array
    {
        $status = [
            'installed' => false,
            'running' => false,
            'version' => null,
            'compose_installed' => false,
            'compose_version' => null,
        ];

        // Find Docker binary
        $dockerBin = $this->findDockerBin();
        $status['installed'] = $dockerBin !== null;
        $status['docker_path'] = $dockerBin;

        if ($status['installed']) {
            // Get Docker version
            exec("{$dockerBin} --version 2>&1", $versionOutput, $versionCode);
            if ($versionCode === 0) {
                $versionStr = implode("\n", $versionOutput);
                if (preg_match('/Docker version ([0-9.]+)/', $versionStr, $m)) {
                    $status['version'] = $m[1];
                }
            }

            // Check if Docker daemon is running
            exec("{$dockerBin} info --format '{{.ServerVersion}}' 2>&1", $infoOutput, $infoCode);
            $status['running'] = $infoCode === 0;
            if ($status['running']) {
                $status['server_version'] = trim(implode('', $infoOutput));
            } else {
                $status['error'] = implode("\n", $infoOutput);
            }
        }

        // Check Docker Compose
        $composeBin = $this->findDockerComposeBin();
        $status['compose_installed'] = $composeBin !== null;
        $status['compose_path'] = $composeBin;

        if ($status['compose_installed']) {
            if (strpos($composeBin, ' compose') !== false) {
                // Docker Compose v2
                exec("{$composeBin} version --short 2>&1", $composeOutput, $composeCode);
            } else {
                // Docker Compose v1
                exec("{$composeBin} --version 2>&1", $composeOutput, $composeCode);
            }
            
            if ($composeCode === 0) {
                $composeStr = implode('', $composeOutput);
                if (preg_match('/([0-9]+\.[0-9]+\.[0-9]+)/', $composeStr, $m)) {
                    $status['compose_version'] = $m[1];
                } else {
                    $status['compose_version'] = trim($composeStr);
                }
            }
        }

        // Get resource usage if running
        if ($status['running']) {
            $dfResult = $this->execCommand('docker', ['system', 'df', '--format', 'json']);
            if ($dfResult['success']) {
                $lines = explode("\n", trim($dfResult['output']));
                $status['disk_usage'] = [];
                foreach ($lines as $line) {
                    $data = @json_decode($line, true);
                    if ($data) {
                        $status['disk_usage'][] = $data;
                    }
                }
            }
        }

        return $this->success($status);
    }

    /**
     * Install Docker
     */
    protected function actionInstall(array $params, string $actor): array
    {
        $includeCompose = $params['include_compose'] ?? true;
        
        // Check if already installed
        $checkResult = $this->execCommand('which', ['docker']);
        if ($checkResult['success']) {
            return $this->error('Docker is already installed');
        }

        $installLog = [];

        // Update package lists
        $installLog[] = 'Updating package lists...';
        $updateResult = $this->execCommand('apt-get', ['update', '-y']);
        if (!$updateResult['success']) {
            return $this->error('Failed to update packages: ' . $updateResult['output']);
        }

        // Install prerequisites
        $installLog[] = 'Installing prerequisites...';
        $prereqs = ['apt-transport-https', 'ca-certificates', 'curl', 'gnupg', 'lsb-release'];
        $prereqResult = $this->execCommand('apt-get', array_merge(['install', '-y'], $prereqs));
        if (!$prereqResult['success']) {
            return $this->error('Failed to install prerequisites: ' . $prereqResult['output']);
        }

        // Add Docker GPG key
        $installLog[] = 'Adding Docker GPG key...';
        $gpgResult = $this->execCommand('bash', ['-c', 'curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg']);
        
        // Add Docker repository
        $installLog[] = 'Adding Docker repository...';
        $codename = trim(shell_exec('lsb_release -cs'));
        $repoLine = "deb [arch=amd64 signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu {$codename} stable";
        file_put_contents('/etc/apt/sources.list.d/docker.list', $repoLine);

        // Update again
        $this->execCommand('apt-get', ['update', '-y']);

        // Install Docker
        $installLog[] = 'Installing Docker...';
        $dockerInstall = $this->execCommand('apt-get', ['install', '-y', 'docker-ce', 'docker-ce-cli', 'containerd.io']);
        if (!$dockerInstall['success']) {
            return $this->error('Failed to install Docker: ' . $dockerInstall['output']);
        }

        // Start and enable Docker
        $installLog[] = 'Starting Docker service...';
        $this->execCommand('systemctl', ['start', 'docker']);
        $this->execCommand('systemctl', ['enable', 'docker']);

        // Install Docker Compose v2
        if ($includeCompose) {
            $installLog[] = 'Installing Docker Compose...';
            $composeInstall = $this->execCommand('apt-get', ['install', '-y', 'docker-compose-plugin']);
            if (!$composeInstall['success']) {
                $installLog[] = 'Warning: Docker Compose plugin install failed, trying standalone...';
                // Try standalone docker-compose
                $this->execCommand('bash', ['-c', 'curl -SL https://github.com/docker/compose/releases/latest/download/docker-compose-linux-x86_64 -o /usr/local/bin/docker-compose']);
                $this->execCommand('chmod', ['+x', '/usr/local/bin/docker-compose']);
            }
        }

        // Verify installation
        $verifyResult = $this->execCommand('docker', ['--version']);
        if (!$verifyResult['success']) {
            return $this->error('Docker installation verification failed');
        }

        $installLog[] = 'Docker installed successfully!';

        return $this->success([
            'installed' => true,
            'version' => trim($verifyResult['output']),
            'log' => $installLog,
        ], 'Docker installed successfully');
    }

    /**
     * List all containers
     */
    protected function actionContainers(array $params, string $actor): array
    {
        $dockerBin = $this->findDockerBin();
        if (!$dockerBin) {
            return $this->error('Docker not found');
        }
        
        $all = $params['all'] ?? true; // Include stopped containers
        
        $cmd = "{$dockerBin} ps --format json";
        if ($all) {
            $cmd .= " -a";
        }

        exec("{$cmd} 2>&1", $output, $code);
        if ($code !== 0) {
            return $this->error('Failed to list containers: ' . implode("\n", $output));
        }

        $containers = [];
        foreach ($output as $line) {
            if ($line) {
                $container = @json_decode($line, true);
                if ($container) {
                    // Parse status for uptime
                    $container['running'] = strpos($container['State'] ?? '', 'running') !== false;
                    $containers[] = $container;
                }
            }
        }

        return $this->success(['containers' => $containers]);
    }

    /**
     * List all images
     */
    protected function actionImages(array $params, string $actor): array
    {
        $dockerBin = $this->findDockerBin();
        if (!$dockerBin) {
            return $this->error('Docker not found');
        }
        
        exec("{$dockerBin} images --format json 2>&1", $output, $code);
        if ($code !== 0) {
            return $this->error('Failed to list images: ' . implode("\n", $output));
        }

        $images = [];
        foreach ($output as $line) {
            if ($line) {
                $image = @json_decode($line, true);
                if ($image) {
                    $images[] = $image;
                }
            }
        }

        return $this->success(['images' => $images]);
    }

    /**
     * Get container details
     */
    protected function actionContainer(array $params, string $actor): array
    {
        $dockerBin = $this->findDockerBin();
        if (!$dockerBin) {
            return $this->error('Docker not found');
        }
        
        $id = $params['id'] ?? null;
        if (!$id) {
            return $this->error('Container ID is required');
        }

        $escapedId = escapeshellarg($id);
        exec("{$dockerBin} inspect {$escapedId} 2>&1", $output, $code);
        if ($code !== 0) {
            return $this->error('Container not found: ' . implode("\n", $output));
        }

        $data = @json_decode(implode("\n", $output), true);
        if (!$data || !isset($data[0])) {
            return $this->error('Failed to parse container info');
        }

        $container = $data[0];
        
        // Get resource stats
        exec("{$dockerBin} stats {$escapedId} --no-stream --format json 2>&1", $statsOutput, $statsCode);
        if ($statsCode === 0) {
            $stats = @json_decode(implode("\n", $statsOutput), true);
            if ($stats) {
                $container['stats'] = $stats;
            }
        }

        return $this->success(['container' => $container]);
    }

    /**
     * Start a container
     */
    protected function actionStart(array $params, string $actor): array
    {
        $dockerBin = $this->findDockerBin();
        if (!$dockerBin) {
            return $this->error('Docker not found');
        }
        
        $id = $params['id'] ?? null;
        if (!$id) {
            return $this->error('Container ID is required');
        }

        $escapedId = escapeshellarg($id);
        exec("{$dockerBin} start {$escapedId} 2>&1", $output, $code);
        if ($code !== 0) {
            return $this->error('Failed to start container: ' . implode("\n", $output));
        }

        return $this->success(['id' => $id], 'Container started successfully');
    }

    /**
     * Stop a container
     */
    protected function actionStop(array $params, string $actor): array
    {
        $dockerBin = $this->findDockerBin();
        if (!$dockerBin) {
            return $this->error('Docker not found');
        }
        
        $id = $params['id'] ?? null;
        if (!$id) {
            return $this->error('Container ID is required');
        }

        $timeout = (int) ($params['timeout'] ?? 10);
        $escapedId = escapeshellarg($id);
        exec("{$dockerBin} stop -t {$timeout} {$escapedId} 2>&1", $output, $code);
        if ($code !== 0) {
            return $this->error('Failed to stop container: ' . implode("\n", $output));
        }

        return $this->success(['id' => $id], 'Container stopped successfully');
    }

    /**
     * Restart a container
     */
    protected function actionRestart(array $params, string $actor): array
    {
        $dockerBin = $this->findDockerBin();
        if (!$dockerBin) {
            return $this->error('Docker not found');
        }
        
        $id = $params['id'] ?? null;
        if (!$id) {
            return $this->error('Container ID is required');
        }

        $timeout = (int) ($params['timeout'] ?? 10);
        $escapedId = escapeshellarg($id);
        exec("{$dockerBin} restart -t {$timeout} {$escapedId} 2>&1", $output, $code);
        if ($code !== 0) {
            return $this->error('Failed to restart container: ' . implode("\n", $output));
        }

        return $this->success(['id' => $id], 'Container restarted successfully');
    }

    /**
     * Get container logs
     */
    protected function actionLogs(array $params, string $actor): array
    {
        $dockerBin = $this->findDockerBin();
        if (!$dockerBin) {
            return $this->error('Docker not found');
        }
        
        $id = $params['id'] ?? null;
        if (!$id) {
            return $this->error('Container ID is required');
        }

        $lines = (int) ($params['lines'] ?? 100);
        $escapedId = escapeshellarg($id);
        exec("{$dockerBin} logs --tail {$lines} {$escapedId} 2>&1", $output, $code);

        return $this->success([
            'id' => $id,
            'logs' => $output,
        ]);
    }

    /**
     * Get container stats
     */
    protected function actionStats(array $params, string $actor): array
    {
        $dockerBin = $this->findDockerBin();
        if (!$dockerBin) {
            return $this->error('Docker not found');
        }
        
        $id = $params['id'] ?? null;
        
        $cmd = "{$dockerBin} stats --no-stream --format json";
        if ($id) {
            $cmd .= " " . escapeshellarg($id);
        }

        exec("{$cmd} 2>&1", $output, $code);
        if ($code !== 0) {
            return $this->error('Failed to get stats: ' . implode("\n", $output));
        }

        $stats = [];
        foreach ($output as $line) {
            if ($line) {
                $stat = @json_decode($line, true);
                if ($stat) {
                    $stats[] = $stat;
                }
            }
        }

        return $this->success(['stats' => $stats]);
    }

    /**
     * Inspect container/image
     */
    protected function actionInspect(array $params, string $actor): array
    {
        $dockerBin = $this->findDockerBin();
        if (!$dockerBin) {
            return $this->error('Docker not found');
        }
        
        $id = $params['id'] ?? null;
        if (!$id) {
            return $this->error('Container/Image ID is required');
        }

        $escapedId = escapeshellarg($id);
        exec("{$dockerBin} inspect {$escapedId} 2>&1", $output, $code);
        if ($code !== 0) {
            return $this->error('Failed to inspect: ' . implode("\n", $output));
        }

        $data = @json_decode(implode("\n", $output), true);

        return $this->success(['data' => $data[0] ?? $data]);
    }

    /**
     * Remove a container
     */
    protected function actionRemove(array $params, string $actor): array
    {
        $dockerBin = $this->findDockerBin();
        if (!$dockerBin) {
            return $this->error('Docker not found');
        }
        
        $id = $params['id'] ?? null;
        if (!$id) {
            return $this->error('Container ID is required');
        }

        $force = $params['force'] ?? false;
        $removeVolumes = $params['remove_volumes'] ?? false;

        $opts = '';
        if ($force) {
            $opts .= ' -f';
        }
        if ($removeVolumes) {
            $opts .= ' -v';
        }
        
        $escapedId = escapeshellarg($id);
        exec("{$dockerBin} rm{$opts} {$escapedId} 2>&1", $output, $code);
        if ($code !== 0) {
            return $this->error('Failed to remove container: ' . implode("\n", $output));
        }

        return $this->success(['id' => $id], 'Container removed successfully');
    }

    /**
     * Pull an image
     */
    protected function actionPull(array $params, string $actor): array
    {
        $dockerBin = $this->findDockerBin();
        if (!$dockerBin) {
            return $this->error('Docker not found');
        }
        
        $image = $params['image'] ?? null;
        if (!$image) {
            return $this->error('Image name is required');
        }

        $escapedImage = escapeshellarg($image);
        exec("{$dockerBin} pull {$escapedImage} 2>&1", $output, $code);
        if ($code !== 0) {
            return $this->error('Failed to pull image: ' . implode("\n", $output));
        }

        return $this->success([
            'image' => $image,
            'output' => implode("\n", $output),
        ], "Image {$image} pulled successfully");
    }

    /**
     * Run docker-compose up
     */
    protected function actionComposeUp(array $params, string $actor): array
    {
        $composeBin = $this->findDockerComposeBin();
        if (!$composeBin) {
            return $this->error('Docker Compose not found');
        }
        
        $path = $params['path'] ?? null;
        if (!$path || !is_dir($path)) {
            return $this->error('Valid path to docker-compose.yml directory is required');
        }

        $file = $params['file'] ?? 'docker-compose.yml';
        $detached = $params['detached'] ?? true;

        $escapedPath = escapeshellarg($path);
        $escapedFile = escapeshellarg($file);
        
        $cmd = "cd {$escapedPath} && {$composeBin} -f {$escapedFile} up";
        if ($detached) {
            $cmd .= " -d";
        }

        exec("{$cmd} 2>&1", $output, $code);
        if ($code !== 0) {
            return $this->error('Failed to start compose: ' . implode("\n", $output));
        }

        return $this->success([
            'path' => $path,
            'output' => implode("\n", $output),
        ], 'Docker Compose started successfully');
    }

    /**
     * Run docker-compose down
     */
    protected function actionComposeDown(array $params, string $actor): array
    {
        $composeBin = $this->findDockerComposeBin();
        if (!$composeBin) {
            return $this->error('Docker Compose not found');
        }
        
        $path = $params['path'] ?? null;
        if (!$path || !is_dir($path)) {
            return $this->error('Valid path to docker-compose.yml directory is required');
        }

        $file = $params['file'] ?? 'docker-compose.yml';
        $removeVolumes = $params['remove_volumes'] ?? false;

        $escapedPath = escapeshellarg($path);
        $escapedFile = escapeshellarg($file);
        
        $cmd = "cd {$escapedPath} && {$composeBin} -f {$escapedFile} down";
        if ($removeVolumes) {
            $cmd .= " -v";
        }

        exec("{$cmd} 2>&1", $output, $code);
        if ($code !== 0) {
            return $this->error('Failed to stop compose: ' . implode("\n", $output));
        }

        return $this->success([
            'path' => $path,
            'output' => implode("\n", $output),
        ], 'Docker Compose stopped successfully');
    }
}

