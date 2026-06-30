<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step;

/**
 * Defines what the orchestrator is allowed to do when a step fails AFTER
 * preceding steps have already produced side effects.
 *
 * The discipline here is the user's explicit ask: we never destroy user
 * data to "clean up" from a partial failure. Instead, we mark the site
 * as degraded and let an operator decide.
 */
enum CompensationPolicy: string
{
    /**
     * Step's side effects can be reversed without destroying anything
     * meaningful. Suitable for early steps that only created OS resources
     * the user has not yet touched:
     *   - HomeDirStep (rmdir if empty)
     *   - SftpGroupStep / SftpUserStep (groupdel/userdel)
     *   - VhostConfigStep (delete the vhost.conf file)
     *   - OlsMainConfigStep (revert via timestamped backup)
     *
     * On failure of a later step, the orchestrator runs compensate()
     * for every successful prior step in reverse order.
     */
    case SAFE_ROLLBACK = 'safe_rollback';

    /**
     * Step touches user data that survives the job (DBs, DNS zones,
     * mail config, SSL certs, content in /home). We REFUSE to delete
     * this on failure of a later step. The site goes into the
     * `degraded` state instead so an operator can heal manually.
     *
     * Examples:
     *   - DatabaseCreateStep (DROP DATABASE would lose customer data)
     *   - DnsZoneCreateStep (records may already be propagated)
     *   - MailDomainStep (dovecot/postfix already serving real mail)
     *   - CertRequestStep (LE rate limits punish duplicate revoke+request)
     */
    case DEGRADE_ONLY = 'degrade_only';

    /**
     * Step has SAFE_ROLLBACK side effects in some sub-states and
     * DEGRADE_ONLY in others. The step's compensate() method inspects
     * its StepState and decides what to actually undo. Use sparingly -
     * splitting into two smaller steps is almost always cleaner.
     */
    case PARTIAL = 'partial';

    public function isSafeToRollback(): bool
    {
        return $this === self::SAFE_ROLLBACK;
    }

    public function requiresDegradeOnFailure(): bool
    {
        return $this === self::DEGRADE_ONLY;
    }
}
