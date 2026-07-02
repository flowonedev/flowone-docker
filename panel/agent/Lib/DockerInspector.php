<?php
/**
 * Docker Inspector
 *
 * Read-only, deep snapshot of the local Docker engine for the panel's Docker
 * page: engine info, containers (enriched with compose labels), images,
 * volumes (with sizes when the engine reports them), networks and disk usage.
 *
 * Every collector degrades gracefully: if one docker subcommand fails the
 * others still return, so a partially-broken engine never blanks the page.
 */

namespace VpsAdmin\Agent\Lib;

class DockerInspector
{
    /** @var callable(string, array, int): array  execCommand from the action */
    private $exec;

    private string $dockerBin;

    public function __construct(callable $execCommand, string $dockerBin)
    {
        $this->exec = $execCommand;
        $this->dockerBin = $dockerBin;
    }

    /**
     * Full snapshot: everything the Docker details page needs in one call.
     */
    public function overview(): array
    {
        $containers = $this->containers();
        $volumesInUse = $this->volumeNamesInUse($containers);

        return [
            'info'       => $this->engineInfo(),
            'containers' => $containers,
            'images'     => $this->images($containers),
            'volumes'    => $this->volumes($volumesInUse),
            'networks'   => $this->networks(),
            'disk_usage' => $this->diskUsage(),
            'stacks'     => $this->composeStacks($containers),
        ];
    }

    /** Engine-level facts (version, storage driver, root dir, counts). */
    public function engineInfo(): array
    {
        $res = $this->run(['info', '--format', '{{json .}}'], 20);
        if (!$res['success']) {
            return ['available' => false, 'error' => trim($res['output'])];
        }
        $info = json_decode($res['output'], true) ?: [];

        return [
            'available'       => true,
            'server_version'  => $info['ServerVersion'] ?? null,
            'storage_driver'  => $info['Driver'] ?? null,
            'root_dir'        => $info['DockerRootDir'] ?? null,
            'cgroup_version'  => $info['CgroupVersion'] ?? null,
            'containers'      => $info['Containers'] ?? 0,
            'running'         => $info['ContainersRunning'] ?? 0,
            'paused'          => $info['ContainersPaused'] ?? 0,
            'stopped'         => $info['ContainersStopped'] ?? 0,
            'images'          => $info['Images'] ?? 0,
            'ncpu'            => $info['NCPU'] ?? null,
            'mem_total'       => $info['MemTotal'] ?? null,
            'kernel'          => $info['KernelVersion'] ?? null,
            'os'              => $info['OperatingSystem'] ?? null,
        ];
    }

    /**
     * All containers with compose project/service labels parsed out.
     */
    public function containers(): array
    {
        $rows = $this->jsonLines(['ps', '-a', '--no-trunc', '--format', 'json'], 30);
        $containers = [];
        foreach ($rows as $c) {
            $labels = $this->parseLabels($c['Labels'] ?? '');
            $containers[] = [
                'id'              => substr($c['ID'] ?? '', 0, 12),
                'name'            => $c['Names'] ?? '',
                'image'           => $c['Image'] ?? '',
                'state'           => $c['State'] ?? '',
                'status'          => $c['Status'] ?? '',
                'running'         => ($c['State'] ?? '') === 'running',
                'ports'           => $c['Ports'] ?? '',
                'created_at'      => $c['CreatedAt'] ?? '',
                'running_for'     => $c['RunningFor'] ?? '',
                'compose_project' => $labels['com.docker.compose.project'] ?? null,
                'compose_service' => $labels['com.docker.compose.service'] ?? null,
                'compose_dir'     => $labels['com.docker.compose.project.working_dir'] ?? null,
                'health'          => $this->healthFromStatus($c['Status'] ?? ''),
            ];
        }
        return $containers;
    }

    /**
     * All images, flagged with how many containers currently use each.
     */
    public function images(array $containers): array
    {
        $rows = $this->jsonLines(['images', '--format', 'json'], 30);
        $images = [];
        foreach ($rows as $img) {
            $ref = ($img['Repository'] ?? '') . ':' . ($img['Tag'] ?? '');
            $usedBy = 0;
            foreach ($containers as $c) {
                if ($c['image'] === $ref || strpos($c['image'], (string) ($img['ID'] ?? '###')) === 0) {
                    $usedBy++;
                }
            }
            $images[] = [
                'repository' => $img['Repository'] ?? '',
                'tag'        => $img['Tag'] ?? '',
                'id'         => $img['ID'] ?? '',
                'size'       => $img['Size'] ?? '',
                'created'    => $img['CreatedSince'] ?? ($img['CreatedAt'] ?? ''),
                'dangling'   => ($img['Repository'] ?? '') === '<none>',
                'used_by'    => $usedBy,
            ];
        }
        return $images;
    }

    /**
     * All volumes; size/ref-count merged in from `docker system df -v` when
     * the engine provides it (older engines simply omit sizes).
     */
    public function volumes(array $inUseNames): array
    {
        $rows = $this->jsonLines(['volume', 'ls', '--format', 'json'], 30);
        $sizes = $this->volumeSizes();
        $volumes = [];
        foreach ($rows as $v) {
            $name = $v['Name'] ?? '';
            $volumes[] = [
                'name'       => $name,
                'driver'     => $v['Driver'] ?? '',
                'scope'      => $v['Scope'] ?? '',
                'mountpoint' => $v['Mountpoint'] ?? '',
                'size'       => $sizes[$name]['size'] ?? null,
                'links'      => $sizes[$name]['links'] ?? null,
                'in_use'     => in_array($name, $inUseNames, true),
            ];
        }
        return $volumes;
    }

    public function networks(): array
    {
        $rows = $this->jsonLines(['network', 'ls', '--format', 'json'], 30);
        $networks = [];
        foreach ($rows as $n) {
            $networks[] = [
                'id'     => substr($n['ID'] ?? '', 0, 12),
                'name'   => $n['Name'] ?? '',
                'driver' => $n['Driver'] ?? '',
                'scope'  => $n['Scope'] ?? '',
            ];
        }
        return $networks;
    }

    /** Per-type disk usage summary (`docker system df`). */
    public function diskUsage(): array
    {
        $rows = $this->jsonLines(['system', 'df', '--format', 'json'], 60);
        $usage = [];
        foreach ($rows as $row) {
            $usage[] = [
                'type'        => $row['Type'] ?? '',
                'total'       => $row['TotalCount'] ?? ($row['Total'] ?? ''),
                'active'      => $row['Active'] ?? '',
                'size'        => $row['Size'] ?? '',
                'reclaimable' => $row['Reclaimable'] ?? '',
            ];
        }
        return $usage;
    }

    /** Group containers into compose stacks (project -> containers). */
    public function composeStacks(array $containers): array
    {
        $stacks = [];
        foreach ($containers as $c) {
            $project = $c['compose_project'];
            if ($project === null || $project === '') {
                continue;
            }
            if (!isset($stacks[$project])) {
                $stacks[$project] = [
                    'project'     => $project,
                    'working_dir' => $c['compose_dir'],
                    'services'    => [],
                    'running'     => 0,
                    'total'       => 0,
                ];
            }
            $stacks[$project]['services'][] = [
                'service' => $c['compose_service'],
                'name'    => $c['name'],
                'state'   => $c['state'],
            ];
            $stacks[$project]['total']++;
            if ($c['running']) {
                $stacks[$project]['running']++;
            }
        }
        return array_values($stacks);
    }

    // -------------------------------------------------------------------------

    /**
     * Volume sizes from `docker system df -v`. Newer engines emit one JSON
     * object; older ones emit JSON-lines. Parse both defensively.
     *
     * @return array<string, array{size: string, links: ?int}>
     */
    private function volumeSizes(): array
    {
        $res = $this->run(['system', 'df', '-v', '--format', 'json'], 60);
        if (!$res['success']) {
            return [];
        }
        $sizes = [];
        $collect = function ($volumeRows) use (&$sizes): void {
            foreach ((array) $volumeRows as $v) {
                $name = $v['Name'] ?? null;
                if ($name === null) {
                    continue;
                }
                $sizes[$name] = [
                    'size'  => $v['Size'] ?? ($v['UsageData']['Size'] ?? null),
                    'links' => isset($v['Links']) ? (int) $v['Links']
                        : (isset($v['UsageData']['RefCount']) ? (int) $v['UsageData']['RefCount'] : null),
                ];
            }
        };

        $whole = json_decode(trim($res['output']), true);
        if (is_array($whole) && isset($whole['Volumes'])) {
            $collect($whole['Volumes']);
            return $sizes;
        }
        // JSON-lines fallback: volume rows are the ones that carry Name+Driver.
        foreach (explode("\n", trim($res['output'])) as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && isset($row['Name'], $row['Driver'])) {
                $collect([$row]);
            }
        }
        return $sizes;
    }

    /** Volume names referenced by any container's mounts. */
    public function volumeNamesInUse(array $containers): array
    {
        if ($containers === []) {
            return [];
        }
        $names = array_map(fn (array $c) => $c['name'], $containers);
        $args = array_merge(
            ['inspect', '--format', '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}}{{"\n"}}{{end}}{{end}}'],
            $names
        );
        $res = $this->run($args, 30);
        if (!$res['success']) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('trim', explode("\n", $res['output'])))));
    }

    /** "k1=v1,k2=v2" -> map. Values may contain '=' (split on first only). */
    private function parseLabels(string $raw): array
    {
        $labels = [];
        foreach (explode(',', $raw) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq !== false) {
                $labels[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
            }
        }
        return $labels;
    }

    /** "Up 3 hours (healthy)" -> healthy|unhealthy|starting|null */
    private function healthFromStatus(string $status): ?string
    {
        if (preg_match('/\((healthy|unhealthy|health: starting)\)/', $status, $m)) {
            return $m[1] === 'health: starting' ? 'starting' : $m[1];
        }
        return null;
    }

    /** Run a docker subcommand, parse JSON-lines output. */
    private function jsonLines(array $args, int $timeout): array
    {
        $res = $this->run($args, $timeout);
        if (!$res['success']) {
            return [];
        }
        $rows = [];
        foreach (explode("\n", trim($res['output'])) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function run(array $args, int $timeout): array
    {
        return ($this->exec)($this->dockerBin, $args, $timeout);
    }
}
