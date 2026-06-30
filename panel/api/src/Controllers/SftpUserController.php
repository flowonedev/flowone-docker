<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * Additional restricted SFTP users.
 *
 * Per-site endpoints are guarded by canAccessSite(); the global listing
 * is admin-only. All real work happens in the agent's `sftpUser` action;
 * this controller only does auth + shaping, mirroring SiteController's
 * SSH-key endpoints.
 */
class SftpUserController extends BaseController
{
    /**
     * Global (admin) listing across all sites.
     */
    public function indexGlobal(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        return $this->agentAction('sftpUser.list', []);
    }

    /**
     * Per-site listing.
     */
    public function index(Request $request): Response
    {
        $domain = $request->getParam('domain');
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        return $this->agentAction('sftpUser.list', ['domain' => $domain]);
    }

    /**
     * Read-only folder browser scoped to the site home, for picking a
     * target folder in the create form.
     */
    public function browse(Request $request): Response
    {
        $domain = $request->getParam('domain');
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        return $this->agentAction('sftpUser.browse', [
            'domain' => $domain,
            'path' => $request->getQuery('path'),
        ]);
    }

    /**
     * Create a chroot-jailed SFTP user for a site.
     */
    public function create(Request $request): Response
    {
        $domain = $request->getParam('domain');
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        if ($validation = $this->validateRequired($request, ['target_path'])) {
            return $validation;
        }

        $result = $this->agent->execute('sftpUser.create', [
            'domain' => $domain,
            'target_path' => $request->input('target_path'),
            'username' => $request->input('username'),
            'display_name' => $request->input('display_name'),
            'label' => $request->input('label'),
            'auth_type' => $request->input('auth_type', 'password'),
            'password' => $request->input('password'),
            'keys' => $request->input('keys'),
            'key' => $request->input('key'),
        ], $this->getActor());

        return $this->respond($result, $domain, 'sftp_user.create', 'SFTP user created');
    }

    /**
     * Set / rotate the user's password.
     */
    public function setPassword(Request $request): Response
    {
        $domain = $request->getParam('domain');
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        if ($validation = $this->validateRequired($request, ['password'])) {
            return $validation;
        }

        $result = $this->agent->execute('sftpUser.setPassword', [
            'domain' => $domain,
            'id' => (int) $request->getParam('id'),
            'password' => $request->input('password'),
        ], $this->getActor());

        return $this->respond($result, $domain, 'sftp_user.password', 'Password updated');
    }

    /**
     * Add an SSH public key.
     */
    public function addKey(Request $request): Response
    {
        $domain = $request->getParam('domain');
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        if ($validation = $this->validateRequired($request, ['key'])) {
            return $validation;
        }

        $result = $this->agent->execute('sftpUser.addKey', [
            'domain' => $domain,
            'id' => (int) $request->getParam('id'),
            'key' => $request->input('key'),
        ], $this->getActor());

        return $this->respond($result, $domain, 'sftp_user.key.add', 'Key added');
    }

    /**
     * Remove an SSH public key (by index or exact key value).
     */
    public function removeKey(Request $request): Response
    {
        $domain = $request->getParam('domain');
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('sftpUser.removeKey', [
            'domain' => $domain,
            'id' => (int) $request->getParam('id'),
            'index' => $request->input('index'),
            'key' => $request->input('key'),
        ], $this->getActor());

        return $this->respond($result, $domain, 'sftp_user.key.remove', 'Key removed');
    }

    /**
     * Enable / disable the user.
     */
    public function update(Request $request): Response
    {
        $domain = $request->getParam('domain');
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        if ($validation = $this->validateRequired($request, ['status'])) {
            return $validation;
        }

        $result = $this->agent->execute('sftpUser.setStatus', [
            'domain' => $domain,
            'id' => (int) $request->getParam('id'),
            'status' => $request->input('status'),
        ], $this->getActor());

        return $this->respond($result, $domain, 'sftp_user.status', 'Status updated');
    }

    /**
     * Delete the user and tear down its jail.
     */
    public function delete(Request $request): Response
    {
        $domain = $request->getParam('domain');
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('sftpUser.delete', [
            'domain' => $domain,
            'id' => (int) $request->getParam('id'),
        ], $this->getActor());

        return $this->respond($result, $domain, 'sftp_user.delete', 'SFTP user deleted');
    }

    /**
     * Recent sessions + lifetime transfer totals for one SFTP user.
     */
    public function sessions(Request $request): Response
    {
        $domain = $request->getParam('domain');
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }
        return $this->agentAction('sftpSession.list', [
            'domain' => $domain,
            'id' => (int) $request->getParam('id'),
            'limit' => $request->getQuery('limit'),
        ]);
    }

    /**
     * Force an immediate journal -> sftp_sessions sync (admin only). The
     * cron normally drives this every minute; this is for the UI refresh
     * button and diagnostics.
     */
    public function syncSessions(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        return $this->agentAction('sftpSession.sync', []);
    }

    /**
     * Self-heal drift (mount, key file, ACL, group membership, sshd block).
     */
    public function repair(Request $request): Response
    {
        $domain = $request->getParam('domain');
        if (!$this->canAccessSite($domain)) {
            return Response::error('Access denied to this site', 403);
        }

        $result = $this->agent->execute('sftpUser.repair', [
            'domain' => $domain,
            'id' => (int) $request->getParam('id'),
        ], $this->getActor());

        return $this->respond($result, $domain, 'sftp_user.repair', 'Repair complete');
    }

    /**
     * Common result -> Response + audit log mapping.
     */
    private function respond(array $result, string $domain, string $auditAction, string $okMessage): Response
    {
        if ($result['success']) {
            $this->logAction($auditAction, $domain, 'success');
            return Response::success($result['data'] ?? null, $result['message'] ?? $okMessage);
        }
        $this->logAction($auditAction, $domain, 'failed', ['error' => $result['error'] ?? '']);
        return Response::error($result['error'] ?? 'Action failed');
    }
}
