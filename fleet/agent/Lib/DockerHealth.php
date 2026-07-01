<?php

namespace FleetManager\Agent\Lib;

/**
 * DockerHealth — report FlowOne stack health from Docker instead of systemd.
 *
 * Phase D of the native->docker migration. On a native box the agent reads
 * `systemctl is-active <unit>`; on a Docker box those services are containers,
 * so the systemd probe would report every app-tier service as 'disabled'
 * (misleading green/grey in the dashboard). This class reports container state
 * for the compose stack instead, generalizing the existing `checkOfficeContainer()`
 * `docker inspect` pattern to every FlowOne service.
 *
 * STRICTLY ADDITIVE + DETECTION-GATED: collect() returns null unless Docker is
 * installed AND the `flowone` compose project has containers. On a native box it
 * returns null and heartbeat.php keeps its exact current systemd behavior.
 *
 * Container states are keyed by compose service (com.docker.compose.service
 * label); mapContainerStates() remaps them to the dashboard's existing
 * server_health service keys, so no dashboard/schema change is needed.
 */
class DockerHealth
{
    /** Compose project the Fleet Docker deploy runs under (see DockerProvisioningService::PROJECT). */
    public const PROJECT = 'flowone';

    /**
     * compose service => dashboard health key. The OLS web tier reports under the
     * native 'openlitespeed' key so existing dashboard columns keep working.
     * Mail/security services stay host-managed (Phase E pod) and are left to the
     * systemd probe.
     */
    public const SERVICE_TO_HEALTHKEY = [
        'web'         => 'openlitespeed',
        'mariadb'     => 'mariadb',
        'redis'       => 'redis',
        'meilisearch' => 'meilisearch',
        'collab'      => 'collab',
        'mailsync'    => 'mailsync',
    ];

    /**
     * Collect dashboard-keyed health from the running compose stack, or null when
     * this box is not Docker-managed (so the caller falls back to systemd).
     */
    public static function collect(): ?array
    {
        if (!self::dockerAvailable()) {
            return null;
        }
        $states = self::collectComposeStates();
        if ($states === null || $states === []) {
            return null; // no flowone stack on this host
        }
        return self::mapContainerStates($states);
    }

    /** True if the docker CLI is on PATH. */
    public static function dockerAvailable(): bool
    {
        $out = [];
        $code = -1;
        @exec('command -v docker 2>/dev/null', $out, $code);
        return $code === 0;
    }

    /**
     * Raw compose-service => docker-state map for the flowone project, e.g.
     * ['web' => 'running', 'mariadb' => 'exited']. Null if the query fails.
     */
    public static function collectComposeStates(): ?array
    {
        $out = [];
        $code = -1;
        $cmd = 'docker ps -a --filter ' . escapeshellarg('label=com.docker.compose.project=' . self::PROJECT)
            . ' --format ' . escapeshellarg('{{.Label "com.docker.compose.service"}}={{.State}}') . ' 2>/dev/null';
        @exec($cmd, $out, $code);
        if ($code !== 0) {
            return null;
        }
        return self::parsePsLabelOutput($out);
    }

    /**
     * PURE: parse `service=state` lines from the docker ps label query.
     *
     * @param array $lines raw stdout lines
     * @return array compose service => docker state (lowercased)
     */
    public static function parsePsLabelOutput(array $lines): array
    {
        $states = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }
            [$svc, $state] = explode('=', $line, 2);
            $svc = trim($svc);
            if ($svc === '') {
                continue;
            }
            $states[$svc] = strtolower(trim($state));
        }
        return $states;
    }

    /**
     * PURE: remap compose service states to dashboard health keys.
     * docker running => 'running'; anything else (exited/created/paused/dead/
     * restarting) => 'stopped'. Only known services are emitted.
     *
     * @param array $composeStates compose service => docker state
     * @return array dashboard health key => 'running'|'stopped'
     */
    public static function mapContainerStates(array $composeStates): array
    {
        $health = [];
        foreach (self::SERVICE_TO_HEALTHKEY as $service => $healthKey) {
            if (!array_key_exists($service, $composeStates)) {
                continue;
            }
            $health[$healthKey] = $composeStates[$service] === 'running' ? 'running' : 'stopped';
        }
        return $health;
    }
}
