<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class MailController extends BaseController
{
    public function status(Request $request): Response
    {
        return $this->agentAction('mail.status');
    }

    public function domains(Request $request): Response
    {
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = 'mail:domains';
        
        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return Response::success($cached, 'Success');
            }
        }
        
        $result = $this->agent->execute('mail.domains', [], $this->getActor());
        
        if ($result['success']) {
            $this->cache->set($cacheKey, $result['data']);
            return Response::success($result['data'], $result['message'] ?? 'Success');
        }
        
        return Response::error($result['error'] ?? 'Failed to get domains');
    }

    public function addDomain(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['domain']);
        if ($validation) return $validation;

        $domain = $request->input('domain');
        
        $result = $this->agent->execute('mail.addDomain', [
            'domain' => $domain,
        ], $this->getActor());

        if ($result['success']) {
            // Invalidate mail caches
            $this->cache->delete('mail:domains');
            $this->logAction('mail.add_domain', $domain, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Domain added')
            : Response::error($result['error']);
    }

    public function removeDomain(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        $result = $this->agent->execute('mail.removeDomain', [
            'domain' => $domain,
        ], $this->getActor());

        if ($result['success']) {
            // Invalidate mail caches for this domain
            $this->cache->delete('mail:domains');
            $this->cache->invalidateMail($domain);
            $this->logAction('mail.remove_domain', $domain, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Domain removed')
            : Response::error($result['error']);
    }

    public function accounts(Request $request): Response
    {
        $domain = $request->getParam('domain');
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = "mail:{$domain}:accounts";
        
        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return Response::success($cached, 'Success');
            }
        }
        
        $result = $this->agent->execute('mail.accounts', ['domain' => $domain], $this->getActor());
        
        if ($result['success']) {
            $this->cache->set($cacheKey, $result['data']);
            return Response::success($result['data'], $result['message'] ?? 'Success');
        }
        
        return Response::error($result['error'] ?? 'Failed to get accounts');
    }

    public function allAccounts(Request $request): Response
    {
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = 'mail:all:accounts';
        
        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return Response::success($cached, 'Success');
            }
        }
        
        $result = $this->agent->execute('mail.allAccounts', [], $this->getActor());
        
        if ($result['success']) {
            $this->cache->set($cacheKey, $result['data']);
            return Response::success($result['data'], $result['message'] ?? 'Success');
        }
        
        return Response::error($result['error'] ?? 'Failed to get accounts');
    }

    public function createAccount(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['email', 'password']);
        if ($validation) return $validation;

        $email = $request->input('email');
        $domain = substr($email, strpos($email, '@') + 1);
        
        $result = $this->agent->execute('mail.createAccount', [
            'email' => $email,
            'password' => $request->input('password'),
            // Optional mailbox quota in MB at creation (0 = unlimited). Accept
            // quota_mb (preferred) or legacy quota; the agent sanitizes it.
            'quota_mb' => $request->input('quota_mb', $request->input('quota')),
        ], $this->getActor());

        if ($result['success']) {
            // Invalidate accounts cache for this domain
            $this->cache->delete("mail:{$domain}:accounts");
            $this->cache->delete('mail:all:accounts');
            $this->logAction('mail.create_account', $email, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Account created')
            : Response::error($result['error']);
    }

    /**
     * Bulk-create mail accounts in one call.
     *
     * Body: { "accounts": [ { "email": "a@d.com", "password": "..." }, ... ] }
     * Used by the Panel migration flow to provision every destination mailbox
     * before imapsync runs. Idempotent — already-existing accounts are
     * reported as "skipped" rather than failing the batch.
     */
    public function bulkCreateAccounts(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $accounts = $request->input('accounts');
        if (empty($accounts) || !is_array($accounts)) {
            return Response::error('accounts array is required');
        }
        if (count($accounts) > 500) {
            return Response::error('Too many accounts in one batch (max 500)');
        }

        $result = $this->agent->execute('mail.bulkCreateAccounts', [
            'accounts' => $accounts,
            // Migration provisioning sets this so migrated users are forced to
            // pick a new password on their first webmail login.
            'force_password_change' => (bool) $request->input('force_password_change', false),
        ], $this->getActor());

        if ($result['success']) {
            // Invalidate every touched domain's account cache plus the global list.
            $domains = [];
            foreach ($accounts as $entry) {
                if (!empty($entry['email']) && strpos($entry['email'], '@') !== false) {
                    $domains[substr(strrchr($entry['email'], '@'), 1)] = true;
                }
            }
            foreach (array_keys($domains) as $domain) {
                $this->cache->delete("mail:{$domain}:accounts");
            }
            $this->cache->delete('mail:all:accounts');

            $data = $result['data'] ?? [];
            $this->logAction('mail.bulk_create_accounts', implode(',', array_keys($domains)), 'success', [
                'total' => $data['total'] ?? count($accounts),
                'created' => $data['created'] ?? null,
                'skipped' => $data['skipped'] ?? null,
                'failed' => $data['failed'] ?? null,
            ]);
        }

        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Accounts created')
            : Response::error($result['error']);
    }

    public function deleteAccount(Request $request): Response
    {
        $email = $request->getParam('email');
        $domain = substr($email, strpos($email, '@') + 1);
        
        $result = $this->agent->execute('mail.deleteAccount', [
            'email' => $email,
            'delete_mail' => $request->input('delete_mail', false),
        ], $this->getActor());

        if ($result['success']) {
            // Invalidate accounts cache for this domain
            $this->cache->delete("mail:{$domain}:accounts");
            $this->cache->delete('mail:all:accounts');
            $this->logAction('mail.delete_account', $email, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Account deleted')
            : Response::error($result['error']);
    }

    public function resetPassword(Request $request): Response
    {
        $email = $request->getParam('email');
        $validation = $this->validateRequired($request, ['password']);
        if ($validation) return $validation;

        $result = $this->agent->execute('mail.resetPassword', [
            'email' => $email,
            'password' => $request->input('password'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('mail.reset_password', $email, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Password reset')
            : Response::error($result['error']);
    }

    /**
     * Toggle the "force password change on next login" flag for a mailbox.
     *
     * Body: { "enabled": true|false } (defaults to true). Routed through the
     * agent so the agent stays the single writer of mail_accounts.
     */
    public function setForcePasswordChange(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $email = $request->getParam('email');
        $domain = substr($email, strpos($email, '@') + 1);
        $enabled = (bool) $request->input('enabled', true);

        $result = $this->agent->execute('mail.setForcePasswordChange', [
            'email' => $email,
            'enabled' => $enabled,
        ], $this->getActor());

        if ($result['success']) {
            $this->cache->delete("mail:{$domain}:accounts");
            $this->cache->delete('mail:all:accounts');
            $this->logAction('mail.force_password_change', $email, 'success', ['enabled' => $enabled]);
        }

        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Flag updated')
            : Response::error($result['error']);
    }

    /**
     * Suspend a mailbox: block login (IMAP/POP3/SMTP/webmail) while it keeps
     * receiving mail. Admin-only; the agent stays the single writer of
     * mail_accounts and also kicks any live IMAP sessions.
     *
     * Body: { "reason": "optional note" }
     */
    public function suspendAccount(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $email = $request->getParam('email');
        $domain = substr($email, strpos($email, '@') + 1);

        $result = $this->agent->execute('mail.suspendAccount', [
            'email' => $email,
            'reason' => $request->input('reason'),
        ], $this->getActor());

        if ($result['success']) {
            $this->cache->delete("mail:{$domain}:accounts");
            $this->cache->delete('mail:all:accounts');
            $this->logAction('mail.suspend_account', $email, 'success');
        }

        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Account suspended')
            : Response::error($result['error']);
    }

    /**
     * Resume a suspended mailbox: re-enable login. Admin-only.
     */
    public function resumeAccount(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $email = $request->getParam('email');
        $domain = substr($email, strpos($email, '@') + 1);

        $result = $this->agent->execute('mail.resumeAccount', [
            'email' => $email,
        ], $this->getActor());

        if ($result['success']) {
            $this->cache->delete("mail:{$domain}:accounts");
            $this->cache->delete('mail:all:accounts');
            $this->logAction('mail.resume_account', $email, 'success');
        }

        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Account resumed')
            : Response::error($result['error']);
    }

    /**
     * Set a mailbox's DRIVE and/or EMAIL (Dovecot) quota. Admin-only. Either
     * quota may be omitted; at least one must be present. Ranges are validated
     * here as a first line of defense and re-validated strictly in the agent.
     *
     * Body: { "quota_mb": int (0 = unlimited), "drive_quota_bytes": int (-1 = unlimited) }
     */
    public function setQuotas(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $email = $request->getParam('email');
        $domain = substr($email, strpos($email, '@') + 1);

        $hasMailbox = $request->input('quota_mb') !== null && $request->input('quota_mb') !== '';
        $hasDrive = $request->input('drive_quota_bytes') !== null && $request->input('drive_quota_bytes') !== '';

        if (!$hasMailbox && !$hasDrive) {
            return Response::error('Provide quota_mb and/or drive_quota_bytes', 400);
        }

        $params = ['email' => $email];

        if ($hasMailbox) {
            $quotaMb = (int) $request->input('quota_mb');
            if ($quotaMb !== 0 && ($quotaMb < 100 || $quotaMb > 1048576)) {
                return Response::error('Mailbox quota must be 0 (unlimited) or between 100 MB and 1,048,576 MB (1 TB)', 400);
            }
            $params['quota_mb'] = $quotaMb;
        }

        if ($hasDrive) {
            $driveBytes = (int) $request->input('drive_quota_bytes');
            if ($driveBytes !== -1 && $driveBytes < 104857600) {
                return Response::error('Drive quota must be -1 (unlimited) or at least 100 MB', 400);
            }
            $params['drive_quota_bytes'] = $driveBytes;
        }

        $result = $this->agent->execute('mailacct.setQuotas', $params, $this->getActor());

        if ($result['success']) {
            $this->cache->delete("mail:{$domain}:accounts");
            $this->cache->delete('mail:all:accounts');
            $this->logAction('mail.set_quotas', $email, 'success', $params);
        }

        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Quotas updated')
            : Response::error($result['error']);
    }

    /**
     * Reset a mailbox's webmail 2FA so the user can sign in with password only
     * and re-enroll. Clears the TOTP secret + backup codes, revokes trusted
     * devices, and signs out active webmail sessions. Admin-only.
     */
    public function reset2fa(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $email = $request->getParam('email');

        $result = $this->agent->execute('mailacct.reset2fa', [
            'email' => $email,
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('mail.reset_2fa', $email, 'success', $result['data'] ?? []);
        }

        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? '2FA reset')
            : Response::error($result['error']);
    }

    public function forwards(Request $request): Response
    {
        $domain = $request->getParam('domain');
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = "mail:{$domain}:forwards";
        
        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return Response::success($cached, 'Success');
            }
        }
        
        $result = $this->agent->execute('mail.forwards', ['domain' => $domain], $this->getActor());
        
        if ($result['success']) {
            $this->cache->set($cacheKey, $result['data']);
            return Response::success($result['data'], $result['message'] ?? 'Success');
        }
        
        return Response::error($result['error'] ?? 'Failed to get forwards');
    }

    public function allForwards(Request $request): Response
    {
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = 'mail:all:forwards';
        
        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return Response::success($cached, 'Success');
            }
        }
        
        $result = $this->agent->execute('mail.allForwards', [], $this->getActor());
        
        if ($result['success']) {
            $this->cache->set($cacheKey, $result['data']);
            return Response::success($result['data'], $result['message'] ?? 'Success');
        }
        
        return Response::error($result['error'] ?? 'Failed to get forwards');
    }

    public function addForward(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['source', 'destination']);
        if ($validation) return $validation;

        $source = $request->input('source');
        $domain = substr($source, strpos($source, '@') + 1);
        
        $result = $this->agent->execute('mail.addForward', [
            'source' => $source,
            'destination' => $request->input('destination'),
        ], $this->getActor());

        if ($result['success']) {
            // Invalidate forwards cache
            $this->cache->delete("mail:{$domain}:forwards");
            $this->cache->delete('mail:all:forwards');
            $this->logAction('mail.add_forward', $source, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Forward added')
            : Response::error($result['error']);
    }

    public function removeForward(Request $request): Response
    {
        $source = $request->getParam('source');
        $destination = $request->input('destination'); // Optional: specific destination to remove
        $domain = substr($source, strpos($source, '@') + 1);
        
        $params = ['source' => $source];
        if ($destination) {
            $params['destination'] = $destination;
        }
        
        $result = $this->agent->execute('mail.removeForward', $params, $this->getActor());

        if ($result['success']) {
            // Invalidate forwards cache
            $this->cache->delete("mail:{$domain}:forwards");
            $this->cache->delete('mail:all:forwards');
            $this->logAction('mail.remove_forward', $source . ($destination ? " -> {$destination}" : ''), 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Forward removed')
            : Response::error($result['error']);
    }

    public function queue(Request $request): Response
    {
        return $this->agentAction('mail.queue');
    }

    public function flushQueue(Request $request): Response
    {
        $result = $this->agent->execute('mail.queueFlush', [], $this->getActor());

        if ($result['success']) {
            $this->logAction('mail.flush_queue', 'queue', 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Queue flushed')
            : Response::error($result['error']);
    }

    public function deleteFromQueue(Request $request): Response
    {
        $id = $request->getParam('id');
        
        $result = $this->agent->execute('mail.queueDelete', [
            'queue_id' => $id,
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('mail.delete_queued', $id, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Message deleted')
            : Response::error($result['error']);
    }

    public function dnsRecords(Request $request): Response
    {
        $domain = $request->getParam('domain');
        return $this->agentAction('mail.dnsRecords', ['domain' => $domain]);
    }

    public function dkimStatus(Request $request): Response
    {
        $domain = $request->getParam('domain');
        return $this->agentAction('mail.dkimStatus', ['domain' => $domain]);
    }

    public function generateDkim(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        $result = $this->agent->execute('mail.generateDkim', [
            'domain' => $domain,
            'selector' => $request->input('selector', 'default'),
            'bits' => $request->input('bits', 2048),
            'force' => $request->input('force', false),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('mail.generate_dkim', $domain, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'DKIM generated')
            : Response::error($result['error']);
    }

    public function setupDnsRecord(Request $request): Response
    {
        $domain = $request->getParam('domain');
        $validation = $this->validateRequired($request, ['record_type']);
        if ($validation) return $validation;
        
        $result = $this->agent->execute('mail.setupDnsRecord', [
            'domain' => $domain,
            'record_type' => $request->input('record_type'),
            'content' => $request->input('content'),
            'policy' => $request->input('policy'),
            'rua' => $request->input('rua'),
            'ruf' => $request->input('ruf'),
            'selector' => $request->input('selector'),
            'priority' => $request->input('priority'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('mail.setup_dns', "{$domain}:{$request->input('record_type')}", 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'DNS record setup')
            : Response::error($result['error']);
    }
}

