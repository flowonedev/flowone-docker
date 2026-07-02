<?php
/**
 * Docker Service Bridge
 *
 * On hybrid boxes the mail/email tier runs as Docker containers (the "flowone"
 * compose stack) instead of native systemd units, so `systemctl is-active
 * mariadb` reports "inactive" even though the flowone-mariadb-1 container is
 * healthy. This bridge maps a panel service name to its Docker backing:
 *
 *   - container:  mariadb -> flowone-mariadb-1 (docker inspect / start / stop)
 *   - supervisor: postfix -> program "postfix" inside the host-net mail pod
 *                 (docker exec flowone-mail-1 supervisorctl ...)
 *
 * ServiceAction consults this ONLY when the systemd unit does not exist
 * (LoadState=not-found), so native installs keep their exact behavior.
 */

namespace VpsAdmin\Agent\Lib;

class DockerServiceBridge
{
    /** Compose project the email stack runs under (container name prefix). */
    private const PROJECT = 'flowone';

    /** Mail pod container (supervisord manages the mail programs inside it). */
    private const MAIL_POD = self::PROJECT . '-mail-1';

    /**
     * service name -> Docker backing.
     *  container: a whole compose service container
     *  program:   a supervisord program inside the mail pod
     */
    private const MAP = [
        'mysql'           => ['container' => self::PROJECT . '-mariadb-1'],
        'mariadb'         => ['container' => self::PROJECT . '-mariadb-1'],
        'redis'           => ['container' => self::PROJECT . '-redis-1'],
        'meilisearch'     => ['container' => self::PROJECT . '-meilisearch-1'],
        'mailsync-server' => ['container' => self::PROJECT . '-mailsync-1'],
        'collab-server'   => ['container' => self::PROJECT . '-collab-1'],
        'postfix'         => ['program' => 'postfix'],
        'dovecot'         => ['program' => 'dovecot'],
        'spamd'           => ['program' => 'spamd'],
        'spamassassin'    => ['program' => 'spamd'],
        'rspamd'          => ['program' => 'rspamd'],
        'clamav-daemon'   => ['program' => 'clamd'],
        'opendkim'        => ['program' => 'opendkim'],
        'opendmarc'       => ['program' => 'opendmarc'],
    ];

    /** @var callable(string, array, int): array  execCommand from the action */
    private $exec;

    public function __construct(callable $execCommand)
    {
        $this->exec = $execCommand;
    }

    /** Whether this service has a Docker backing on this stack. */
    public static function handles(string $name): bool
    {
        return isset(self::MAP[$name]);
    }

    /**
     * Status in the same shape ServiceAction::getServiceStatus returns, or
     * null when the service has no Docker mapping. `enabled` mirrors the
     * container's restart policy (unless-stopped/always = starts on boot).
     */
    public function status(string $name): ?array
    {
        $target = self::MAP[$name] ?? null;
        if ($target === null) {
            return null;
        }
        return isset($target['container'])
            ? $this->containerStatus($name, $target['container'])
            : $this->programStatus($name, $target['program']);
    }

    /** start|stop|restart through the Docker backing. */
    public function control(string $name, string $verb): ?array
    {
        $target = self::MAP[$name] ?? null;
        if ($target === null) {
            return null;
        }
        if (isset($target['container'])) {
            return $this->run('docker', [$verb, $target['container']], 60);
        }
        // supervisorctl has no "reload <prog>"; restart covers it.
        $svVerb = $verb === 'reload' ? 'restart' : $verb;
        return $this->run('docker', ['exec', self::MAIL_POD, 'supervisorctl', $svVerb, $target['program']], 60);
    }

    /** Recent logs (container logs, or the pod's log slice for a program). */
    public function logs(string $name, int $lines): ?array
    {
        $target = self::MAP[$name] ?? null;
        if ($target === null) {
            return null;
        }
        if (isset($target['container'])) {
            return $this->run('docker', ['logs', '--tail', (string) $lines, $target['container']], 30);
        }
        // The mail pod multiplexes program logs onto its stdout; grep the slice.
        $res = $this->run('docker', ['logs', '--tail', '2000', self::MAIL_POD], 30);
        if ($res['success']) {
            $needle = $target['program'];
            $matched = array_filter(
                explode("\n", $res['output']),
                fn (string $l) => stripos($l, $needle) !== false
            );
            $res['output'] = implode("\n", array_slice($matched, -$lines));
        }
        return $res;
    }

    // -------------------------------------------------------------------------

    private function containerStatus(string $name, string $container): array
    {
        $res = $this->run('docker', [
            'inspect', '--format',
            '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{end}}|{{.State.StartedAt}}|{{.HostConfig.RestartPolicy.Name}}',
            $container,
        ], 15);

        if (!$res['success']) {
            return $this->stopped($name, 'container ' . $container . ' not found');
        }

        [$state, $health, $startedAt, $restart] = array_pad(explode('|', trim($res['output'])), 4, '');
        $running = $state === 'running' && ($health === '' || $health === 'healthy' || $health === 'starting');

        $uptime = null;
        if ($running && $startedAt !== '') {
            $ts = strtotime($startedAt);
            if ($ts !== false) {
                $uptime = $this->humanizeSince($ts);
            }
        }

        return [
            'name'    => $name,
            'status'  => $running ? 'running' : ($state === 'running' ? 'failed' : 'stopped'),
            'active'  => $running,
            'enabled' => in_array($restart, ['always', 'unless-stopped'], true),
            'pid'     => null,
            'memory'  => $this->containerMemory($container),
            'uptime'  => $uptime,
            'runtime' => 'docker',
        ];
    }

    private function programStatus(string $name, string $program): array
    {
        $res = $this->run('docker', ['exec', self::MAIL_POD, 'supervisorctl', 'status', $program], 20);
        // supervisorctl exits non-zero for non-RUNNING programs; parse regardless.
        $line = trim($res['output']);
        if ($line === '' || stripos($line, 'no such process') !== false || stripos($line, 'error') === 0) {
            return $this->stopped($name, 'not supervised in mail pod');
        }

        $running = (bool) preg_match('/\bRUNNING\b/', $line);
        $uptime = null;
        if ($running && preg_match('/uptime\s+([\d:]+(?:\s+days?,\s*[\d:]+)?)/i', $line, $m)) {
            $uptime = $m[1];
        }

        return [
            'name'    => $name,
            'status'  => $running ? 'running' : (stripos($line, 'FATAL') !== false ? 'failed' : 'stopped'),
            'active'  => $running,
            // Supervised programs auto-start with the pod; the pod's restart
            // policy is what "enabled" means here.
            'enabled' => true,
            'pid'     => preg_match('/pid\s+(\d+)/', $line, $m) ? (int) $m[1] : null,
            'memory'  => null,
            'uptime'  => $uptime,
            'runtime' => 'docker',
        ];
    }

    private function containerMemory(string $container): ?string
    {
        $res = $this->run('docker', ['stats', '--no-stream', '--format', '{{.MemUsage}}', $container], 15);
        if (!$res['success'] || trim($res['output']) === '') {
            return null;
        }
        // "270.5MiB / 3.74GiB" -> keep the usage half.
        return trim(explode('/', $res['output'])[0]);
    }

    private function stopped(string $name, string $detail): array
    {
        return [
            'name'    => $name,
            'status'  => 'stopped',
            'active'  => false,
            'enabled' => false,
            'pid'     => null,
            'memory'  => null,
            'uptime'  => null,
            'runtime' => 'docker',
            'detail'  => $detail,
        ];
    }

    private function humanizeSince(int $ts): string
    {
        $d = max(0, time() - $ts);
        if ($d >= 86400) {
            return floor($d / 86400) . 'd ' . floor(($d % 86400) / 3600) . 'h';
        }
        if ($d >= 3600) {
            return floor($d / 3600) . 'h ' . floor(($d % 3600) / 60) . 'm';
        }
        return floor($d / 60) . 'm';
    }

    private function run(string $cmd, array $args, int $timeout): array
    {
        return ($this->exec)($cmd, $args, $timeout);
    }
}
