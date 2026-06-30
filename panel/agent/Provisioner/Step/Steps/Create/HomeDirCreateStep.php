<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\Create;

use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Support\ResourceNameDeriver;

/**
 * Create the home directory structure that the SFTP user will own.
 *
 * Layout (matches legacy VhostAction.php so existing sites are
 * compatible):
 *
 *   /home/<domain>/                                      0755 root:root       (entry point, world-traversable)
 *   /home/<domain>/public_html/                          0750 <user>:<group>  (document root)
 *   /home/<domain>/public_html/index.html                <user>:<group>       placeholder "Welcome to <domain>" page
 *   /home/<domain>/public_html/.well-known/              0755 <user>:<group>  ACME parent
 *   /home/<domain>/public_html/.well-known/acme-challenge/ 0755 <user>:<group> required by certbot --webroot
 *   /home/<domain>/public_html/error/                    0755 <user>:<group>  vhost.conf errorPage targets
 *   /home/<domain>/public_html/error/{404,403,500,503}.html (copied from /var/www/vps-admin/templates/ if present)
 *   /home/<domain>/logs/                                 0750 <user>:<group>  (vhost logs)
 *   /home/<domain>/tmp/                                  0750 <user>:<group>  (per-vhost tmp)
 *
 * Why root:root 0755 on the entry point (and not root:<group> 0750):
 * matches the convention used by every legacy hand-built site on this
 * box (akademiaonline.hu, krona.hu, sterlingcooper.hu, ...) so an
 * operator listing /home/ sees a uniform pattern. The actual privacy
 * boundary is enforced one level down on each subdir (0750
 * <user>:<group>) - other site users can `cd` into /home/<domain>/
 * but cannot read public_html / logs / tmp.
 *
 * Why the extra docroot subdirs / files:
 *   - acme-challenge: certbot --webroot writes its HTTP-01 challenge
 *     under here. If the dir is missing, the SSL step would have to
 *     mkdir it as root (which becomes a hidden ownership trap when
 *     certbot tries to chown later). Creating it up front with the
 *     same owner as the docroot eliminates that.
 *   - error/<code>.html: the rendered vhost.conf has `errorPage`
 *     directives pointing at these files. Without the files, OLS
 *     serves its built-in error pages (with the OpenLiteSpeed brand
 *     and confusing language). The legacy creator copies templates
 *     from /var/www/vps-admin/templates/; we mirror that exactly.
 *     If the templates dir is missing (fresh install) the step logs
 *     a warning event and skips the copy - the dir still exists so
 *     an operator can drop files in later.
 *   - index.html: gives a friendly "Welcome to <domain>" landing
 *     page before the user uploads their site. Skipped when
 *     index.html already exists (idempotent re-run + restore-from-
 *     archive both rely on this).
 *
 * Compensation: SAFE_ROLLBACK only when the directory is brand-new and
 * empty. Once content lands, rmtree on compensate would destroy user
 * data. We approximate this by:
 *   - storing `was_empty_at_create` in StepState
 *   - compensating only when that flag is true
 *
 * For long-lived sites where the user has uploaded content, the
 * delete saga uses HomeDirRemoveStep with DEGRADE_ONLY policy, NOT
 * this step's compensate().
 */
final class HomeDirCreateStep extends AbstractStep
{
    private const SUBDIRS = ['public_html', 'logs', 'tmp'];
    private const HOME_MODE = 0755;
    private const SUBDIR_MODE = 0750;
    // ACME + error/ live under public_html where the vhost's docroot
    // points; the docroot itself is the privacy boundary, but these
    // two subdirs are 0755 because Let's Encrypt's HTTP-01 prober
    // and OLS's error renderer both need world-readable content.
    private const PUBLIC_DOCROOT_DIRS = [
        'public_html/.well-known' => 0755,
        'public_html/.well-known/acme-challenge' => 0755,
        'public_html/error' => 0755,
    ];
    private const ERROR_CODES = ['404', '403', '500', '503'];
    private const ERROR_TEMPLATES_DIR = '/var/www/vps-admin/templates';
    // Fallback owner used when the operator unchecked "Create SFTP
    // user" in the create modal (payload['skip_sftp']=true). OLS
    // runs as www-data on this stack, so docroots are owned by the
    // web user directly with no separate Linux account.
    private const FALLBACK_OWNER = 'www-data';
    private const FALLBACK_GROUP = 'www-data';

    public function name(): string
    {
        return StepName::HOME_DIR_CREATE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        // The directory tree itself is safe to roll back IFF it was
        // created in this saga (was_empty_at_create=true). Once content
        // lands, removal becomes destructive and the delete saga
        // handles it explicitly.
        return CompensationPolicy::PARTIAL;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $home = $this->resolveHomeDir($ctx, $state);
        $fs = $ctx->requireAdapters()->fs;
        if (!$fs->isDirectory($home)) {
            return false;
        }
        foreach (self::SUBDIRS as $sub) {
            if (!$fs->isDirectory($home . '/' . $sub)) {
                return false;
            }
        }
        // The docroot must contain the legacy-required scaffolding
        // (acme-challenge, error/) - if any is missing, execute() runs
        // again to top up. We deliberately do NOT check for the
        // index.html / error/<code>.html files: those are content
        // the operator may have replaced, so a missing copy means
        // "they've put their own site here" not "we should re-run".
        foreach (array_keys(self::PUBLIC_DOCROOT_DIRS) as $sub) {
            if (!$fs->isDirectory($home . '/' . $sub)) {
                return false;
            }
        }
        return true;
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $fs = $ctx->requireAdapters()->fs;
        $home = $this->resolveHomeDir($ctx, $state);
        $owner = $this->resolveOwnerSpec($ctx);

        $wasEmpty = !$fs->exists($home) || $this->isEmptyDir($home);
        $events = [StepEvent::info('creating home directory tree', [
            'home' => $home, 'owner' => $owner, 'was_empty' => $wasEmpty,
        ])];

        try {
            $fs->ensureDirectory($home, self::HOME_MODE);
            foreach (self::SUBDIRS as $sub) {
                $path = $home . '/' . $sub;
                $fs->ensureDirectory($path, self::SUBDIR_MODE);
            }
            // Docroot scaffolding (acme-challenge + error/). We list
            // .well-known explicitly (rather than relying on mkdir -p
            // to materialize it as a parent of acme-challenge) so the
            // intermediate dir lands with the mode WE specified
            // (0755) instead of mkdir -p's mode argument applied
            // recursively. Belt-and-braces against future schema
            // changes where modes diverge between parent and leaf.
            foreach (self::PUBLIC_DOCROOT_DIRS as $rel => $mode) {
                $fs->ensureDirectory($home . '/' . $rel, $mode);
            }
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state->mergeData(['home' => $home, 'was_empty_at_create' => $wasEmpty]),
                "mkdir tree failed: " . $e->getMessage(),
                $events,
            );
        }

        // Ownership. Entry point is root:root by convention (matches
        // every legacy hand-built site under /home/); the privacy
        // boundary lives on each subdir which is <user>:<group> 0750.
        // The chownRecursive on public_html will sweep the .well-known
        // and error/ subdirs we just created, so we do NOT chown them
        // a second time (that would be a redundant fork+exec).
        try {
            $fs->chownPath($home, 'root:root');
            foreach (self::SUBDIRS as $sub) {
                $fs->chownRecursive($home . '/' . $sub, $owner);
            }
        } catch (\Throwable $e) {
            $events[] = StepEvent::warning(
                'chown failed on some path - tree still present, will need manual fix',
                ['error' => $e->getMessage()]
            );
            return StepResult::failure(
                $state->mergeData(['home' => $home, 'was_empty_at_create' => $wasEmpty]),
                "chown failed: " . $e->getMessage(),
                $events,
            );
        }

        // Content drop: placeholder index + error templates. Failures
        // here are NON-FATAL (logged as warning events but the step
        // succeeds). Reasoning: the directory tree + ownership are the
        // hard contracts the rest of the saga and OLS depend on; the
        // error pages and welcome page are cosmetic. A missing
        // /var/www/vps-admin/templates/ on a fresh box should not
        // fail the entire site creation.
        // We pass $ctx->domain() rather than basename($home) so a
        // sandbox home (e.g. /tmp/flowone_homedir_XXXX in tests, or
        // /home/<sftpuser> in custom layouts) still gets a welcome
        // page that names the actual domain.
        $contentEvents = $this->dropDocrootContent($fs, $home, $owner, $ctx->domain());
        $events = array_merge($events, $contentEvents);

        $events[] = StepEvent::info('home tree ready', ['home' => $home]);

        return StepResult::success(
            $state->mergeData([
                'home' => $home,
                'was_empty_at_create' => $wasEmpty,
                'subdirs' => self::SUBDIRS,
                'docroot_dirs' => array_keys(self::PUBLIC_DOCROOT_DIRS),
            ])->withCompleted(),
            $events,
        );
    }

    /**
     * Drop the legacy-equivalent content into public_html:
     *   - index.html (placeholder welcome page) if not already present
     *   - error/<code>.html copied from /var/www/vps-admin/templates/
     *     (only those that exist in the templates dir; missing ones
     *     are skipped with a warning event)
     *
     * @return list<StepEvent> events to merge into the parent result
     */
    private function dropDocrootContent(
        \VpsAdmin\Agent\Provisioner\Adapters\FilesystemAdapter $fs,
        string $home,
        string $owner,
        string $domain
    ): array {
        $events = [];
        $docroot = $home . '/public_html';

        // ── 1. Placeholder index.html ─────────────────────────────
        $indexPath = $docroot . '/index.html';
        if (!$fs->exists($indexPath)) {
            try {
                $fs->writeAtomic($indexPath, $this->placeholderIndex($domain), 0644);
                $fs->chownPath($indexPath, $owner);
                $events[] = StepEvent::info('placeholder index.html dropped', [
                    'path' => $indexPath, 'domain' => $domain,
                ]);
            } catch (\Throwable $e) {
                $events[] = StepEvent::warning('failed to drop placeholder index', [
                    'path' => $indexPath, 'error' => $e->getMessage(),
                ]);
            }
        }

        // ── 2. Error templates ────────────────────────────────────
        // Copy each <code>.html if (a) the template exists in the
        // shared templates dir, and (b) the destination doesn't
        // already exist (so an operator's customizations survive a
        // re-run). Missing templates are tolerated - we log and
        // move on.
        $errorDir = $docroot . '/error';
        $copied = 0;
        $missing = [];
        foreach (self::ERROR_CODES as $code) {
            $src = self::ERROR_TEMPLATES_DIR . '/' . $code . '.html';
            $dest = $errorDir . '/' . $code . '.html';
            if (!is_file($src)) {
                $missing[] = $code;
                continue;
            }
            if ($fs->exists($dest)) {
                continue;
            }
            try {
                // Use writeAtomic so a partial copy can never end up
                // visible to OLS mid-write.
                $contents = @file_get_contents($src);
                if ($contents === false) {
                    $events[] = StepEvent::warning('failed to read error template', [
                        'src' => $src,
                    ]);
                    continue;
                }
                $fs->writeAtomic($dest, $contents, 0644);
                $fs->chownPath($dest, $owner);
                $copied++;
            } catch (\Throwable $e) {
                $events[] = StepEvent::warning('failed to drop error page', [
                    'code' => $code, 'error' => $e->getMessage(),
                ]);
            }
        }
        if ($copied > 0) {
            $events[] = StepEvent::info('error templates dropped', [
                'count' => $copied, 'codes' => self::ERROR_CODES,
            ]);
        }
        if (!empty($missing)) {
            $events[] = StepEvent::warning(
                'error templates missing on disk; OLS will fall back to built-in pages',
                ['missing_codes' => $missing, 'templates_dir' => self::ERROR_TEMPLATES_DIR],
            );
        }

        return $events;
    }

    /**
     * Minimal welcome page rendered when no index exists yet.
     * Kept inline (no separate template file) so the step has zero
     * filesystem dependencies for the placeholder - one less thing to
     * misconfigure on a fresh server.
     */
    private function placeholderIndex(string $domain): string
    {
        $safe = htmlspecialchars($domain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Welcome to {$safe}</title>
<style>
  body { margin:0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
         background: linear-gradient(135deg,#0f172a 0%,#1e293b 100%);
         color:#e2e8f0; min-height:100vh; display:flex; align-items:center; justify-content:center; }
  .card { background: rgba(15,23,42,0.6); backdrop-filter: blur(8px);
          border: 1px solid rgba(148,163,184,0.2); border-radius: 16px;
          padding: 3rem 4rem; text-align:center; max-width: 36rem; }
  h1 { margin: 0 0 1rem; font-size: 2.25rem; font-weight: 600; }
  p { margin: 0.5rem 0; color: #94a3b8; line-height: 1.6; }
  code { background: rgba(148,163,184,0.1); padding: 0.15rem 0.4rem;
         border-radius: 4px; font-family: ui-monospace, SFMono-Regular, monospace; }
</style>
</head>
<body>
<main class="card">
  <h1>Welcome to {$safe}</h1>
  <p>Your site has been provisioned successfully.</p>
  <p>Upload your content to <code>/home/{$safe}/public_html/</code> to replace this page.</p>
</main>
</body>
</html>
HTML;
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $home = $state->data['home'] ?? null;
        $wasEmpty = (bool) ($state->data['was_empty_at_create'] ?? false);
        if (!is_string($home) || $home === '') {
            return StepResult::success(
                $state,
                [StepEvent::info('compensate: no home recorded, nothing to do')]
            );
        }

        $fs = $ctx->requireAdapters()->fs;

        if (!$wasEmpty) {
            // The home dir pre-existed with content. We never delete
            // user content from a compensate path; the operator must
            // decide via the explicit delete saga.
            return StepResult::success(
                $state,
                [StepEvent::warning(
                    'compensate: home dir pre-existed with content; not deleting',
                    ['home' => $home]
                )]
            );
        }

        // The home dir was created in this saga and remained empty
        // (no content landed). Safe to remove.
        $events = [StepEvent::info('compensate: removing freshly-created empty home tree', ['home' => $home])];
        try {
            // FilesystemAdapter's allowedRoots must include /home for
            // this to succeed. The orchestrator registers it via
            // FilesystemAdapter::addAllowedRoot('/home/<domain>') before
            // running this step.
            $fs->rmtree($home);
        } catch (\Throwable $e) {
            $events[] = StepEvent::warning(
                'compensate: rmtree failed',
                ['home' => $home, 'error' => $e->getMessage()]
            );
        }
        return StepResult::success($state, $events);
    }

    private function resolveHomeDir(SiteContext $ctx, StepState $state): string
    {
        if (!empty($state->data['home']) && is_string($state->data['home'])) {
            return $state->data['home'];
        }
        // Reuse the SftpUserCreateStep's persisted home if available.
        $row = $ctx->siteRow;
        $stateJson = $row['state'] ?? null;
        $stateMap = is_string($stateJson) ? json_decode($stateJson, true) : (is_array($stateJson) ? $stateJson : null);
        if (is_array($stateMap)
            && isset($stateMap[StepName::SFTP_USER_CREATE]['data']['home'])
            && is_string($stateMap[StepName::SFTP_USER_CREATE]['data']['home'])
        ) {
            return $stateMap[StepName::SFTP_USER_CREATE]['data']['home'];
        }
        if (!empty($row['home_dir']) && is_string($row['home_dir'])) {
            return $row['home_dir'];
        }
        $payload = $ctx->payload;
        if (!empty($payload['home_dir']) && is_string($payload['home_dir'])) {
            return $payload['home_dir'];
        }
        return '/home/' . $ctx->domain();
    }

    private function resolveOwnerSpec(SiteContext $ctx): string
    {
        // skip_sftp mode: docroots are owned by www-data:www-data;
        // OLS runs as the same user so PHP can read/write without
        // group-membership gymnastics.
        if (!empty($ctx->payload['skip_sftp'])) {
            return self::FALLBACK_OWNER . ':' . $this->resolveGroupName($ctx);
        }
        // Normal SFTP mode: resolve the user with the same priority
        // order SftpUserCreateStep uses. Falls through to a shared
        // derivation when no explicit hint is given, so the user we
        // chown to always matches the one useradd actually created.
        $user = $this->lookupFromPriorStep($ctx, StepName::SFTP_USER_CREATE, 'user')
            ?? ($ctx->payload['sftp_user'] ?? $ctx->siteRow['sftp_user'] ?? null);
        if (!is_string($user) || $user === '') {
            $user = ResourceNameDeriver::sftpName($ctx->domain());
        }
        return $user . ':' . $this->resolveGroupName($ctx);
    }

    private function resolveGroupName(SiteContext $ctx): string
    {
        // skip_sftp mode: docroots are owned by www-data:www-data.
        if (!empty($ctx->payload['skip_sftp'])) {
            return self::FALLBACK_GROUP;
        }
        $g = $this->lookupFromPriorStep($ctx, StepName::SFTP_GROUP_CREATE, 'group')
            ?? ($ctx->payload['sftp_group'] ?? $ctx->siteRow['sftp_group'] ?? null);
        if (!is_string($g) || $g === '') {
            // Derive via the shared helper so the group we chown to
            // is byte-identical to the one SftpGroupCreateStep made
            // - the failure path that triggered Job #543.
            return ResourceNameDeriver::sftpName($ctx->domain());
        }
        return $g;
    }

    private function lookupFromPriorStep(SiteContext $ctx, string $stepName, string $key): ?string
    {
        $stateJson = $ctx->siteRow['state'] ?? null;
        $map = is_string($stateJson) ? json_decode($stateJson, true) : (is_array($stateJson) ? $stateJson : null);
        if (is_array($map)
            && isset($map[$stepName]['data'][$key])
            && is_string($map[$stepName]['data'][$key])
        ) {
            return $map[$stepName]['data'][$key];
        }
        return null;
    }

    private function isEmptyDir(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }
        $entries = @scandir($path);
        if (!is_array($entries)) {
            return false;
        }
        foreach ($entries as $e) {
            if ($e !== '.' && $e !== '..') {
                return false;
            }
        }
        return true;
    }
}
