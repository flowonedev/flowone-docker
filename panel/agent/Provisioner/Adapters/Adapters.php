<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Adapters;

use VpsAdmin\Agent\Provisioner\Ols\OlsRestartCoordinator;

/**
 * Immutable container for the full set of infrastructure adapters a
 * step might need.
 *
 * Why a DTO and not a service-locator-style global:
 *   - Steps see EXACTLY the adapter surface they're allowed to call.
 *     If a future step has no business touching MySQL, the orchestrator
 *     can build a SiteContext with $mysql=null and the type system
 *     enforces the boundary.
 *   - One container is cheaper than threading 6+ parameters into every
 *     step lifecycle method.
 *   - Test contexts can swap in fakes by constructing this DTO with
 *     stubbed adapters.
 *
 * Mail/DNS adapters intentionally absent here - they get added when
 * their respective steps land. Adding properties to a final class is
 * non-breaking; removing them is.
 *
 * The OlsRestartCoordinator is NULLABLE so unit tests that don't need
 * to debounce real OLS restarts can build an Adapters bundle without
 * wiring SiteLock + PanelDatabase. OlsRestartStep falls back to a
 * direct OlsAdapter::restart() when the coordinator is absent, with a
 * clear warning event.
 *
 * SslAdapter is NULLABLE for the same reason: legacy unit tests built
 * Adapters bundles before SSL was a saga concern, and some never need
 * to call certbot. SslIssueStep checks for null and degrades to a
 * skip-with-warning when the adapter isn't wired.
 */
final class Adapters
{
    public function __construct(
        public readonly CommandRunner $runner,
        public readonly FilesystemAdapter $fs,
        public readonly OlsAdapter $ols,
        public readonly MysqlAdapter $mysql,
        public readonly SftpAdapter $sftp,
        public readonly NasAdapter $nas,
        public readonly ?OlsRestartCoordinator $olsRestart = null,
        public readonly ?SslAdapter $ssl = null,
    ) {
    }
}
