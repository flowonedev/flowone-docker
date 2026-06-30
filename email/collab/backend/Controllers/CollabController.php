<?php

namespace Collab\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\SmtpService;
use Webmail\Services\SessionService;
use Webmail\Services\GoogleOAuthService;
use Webmail\Services\MicrosoftOAuthService;
use Webmail\Services\DriveService;
use Collab\Services\CollabDocumentService;
use Collab\Services\CollabPermissionService;
use Collab\Services\PptxConversionService;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;
use PhpOffice\PhpWord\Writer\HTML as PhpWordHtmlWriter;

/**
 * CollabController - REST API for Collaborative Editing
 * 
 * Handles document CRUD, permissions, and token generation.
 * Prefixed routes: /api/collab/*
 */
class CollabController
{
    private array $config;
    private ?string $userEmail = null;
    private ?string $userPassword = null;
    private bool $hasValidSession = false;
    private bool $isOAuthSession = false;
    private ?string $oauthProvider = null;
    private ?CollabDocumentService $documentService = null;
    private ?CollabPermissionService $permissionService = null;
    private ?SessionService $session = null;
    private ?GoogleOAuthService $googleOAuthService = null;
    private ?MicrosoftOAuthService $microsoftOAuthService = null;
    
    // Configurable prefix for all database tables
    private string $prefix = 'collab_';
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->prefix = $config['collab']['prefix'] ?? 'collab_';
        $this->session = new SessionService($config['jwt'], $config['imap_encryption_key'] ?? '');
        $this->googleOAuthService = new GoogleOAuthService($config);
        $this->microsoftOAuthService = new MicrosoftOAuthService($config);
        $this->extractUserFromToken();
        
        if ($this->userEmail) {
            $this->documentService = new CollabDocumentService($config, $this->prefix);
            $this->permissionService = new CollabPermissionService($config, $this->prefix);
        }
    }
    
    /**
     * Decode a JWT token with RS256 + HS256 fallback support
     */
    private function decodeJwt(string $token): ?object
    {
        $algorithm = $this->config['jwt']['algorithm'] ?? 'RS256';

        // Try primary algorithm
        try {
            $key = $this->getJwtVerificationKey($algorithm);
            return \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($key, $algorithm));
        } catch (\Exception $e) {
            // If RS256 failed, try HS256 fallback
            if ($algorithm === 'RS256' && !empty($this->config['jwt']['secret'])) {
                try {
                    $decoded = \Firebase\JWT\JWT::decode(
                        $token,
                        new \Firebase\JWT\Key($this->config['jwt']['secret'], 'HS256')
                    );
                    error_log('[JWT] Token verified via HS256 fallback — migration still in progress');
                    return $decoded;
                } catch (\Exception $fallback) {
                    // Both failed
                }
            }
            throw $e;
        }
    }

    /**
     * Get the appropriate JWT verification key for the given algorithm
     */
    private function getJwtVerificationKey(string $algorithm): string
    {
        if ($algorithm === 'RS256') {
            $path = $this->config['jwt']['public_key_path'] ?? '';
            if ($path && file_exists($path)) {
                return file_get_contents($path);
            }
            throw new \RuntimeException('RS256 public key not found at: ' . $path);
        }
        return $this->config['jwt']['secret'];
    }

    /**
     * Extract user from JWT token
     */
    private function extractUserFromToken(): void
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            try {
                $decoded = $this->decodeJwt($token);
                $this->userEmail = strtolower($decoded->sub ?? '');
                $this->hasValidSession = !empty($decoded->pwd) || !empty($decoded->oauth);
                
                // Extract password for SMTP
                if (isset($decoded->pwd)) {
                    $this->userPassword = $this->session->decryptPassword($decoded->pwd);
                }
                
                // Check for OAuth session
                if (isset($decoded->oauth) && $decoded->oauth) {
                    $this->isOAuthSession = true;
                    $this->oauthProvider = $decoded->oauth_provider ?? 'google';
                }
            } catch (\Exception $e) {
                error_log("CollabController token error: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Require valid session
     * Only checks for valid user email from JWT - password is not required
     * for read/write operations (same as main app's BaseController)
     */
    private function requireAuth(): ?Response
    {
        if (!$this->userEmail) {
            return Response::error('Authentication required', 401);
        }
        return null;
    }
    
    // ========================================================================
    // DOCUMENT ENDPOINTS
    // ========================================================================
    
    /**
     * List all documents user has access to
     * GET /api/collab/documents
     * Query: type (optional), page, limit
     */
    public function listDocuments(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $type = $request->getQuery('type');
        $page = max(1, (int)$request->getQuery('page', 1));
        $limit = min(100, max(1, (int)$request->getQuery('limit', 50)));
        $offset = ($page - 1) * $limit;
        
        try {
            $result = $this->documentService->listDocuments($this->userEmail, $type, $limit, $offset);
            return Response::success([
                'documents' => $result['documents'],
                'total' => $result['total'],
                'page' => $page,
                'limit' => $limit,
            ]);
        } catch (\Exception $e) {
            error_log("listDocuments error: " . $e->getMessage());
            return Response::error('Failed to list documents', 500);
        }
    }
    
    /**
     * Get a single document by UUID
     * GET /api/collab/documents/{uuid}
     */
    public function getDocument(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $uuid = $request->param('uuid');
        if (!$uuid) {
            return Response::error('Document UUID required', 400);
        }
        
        try {
            // Check permission
            $role = $this->permissionService->getUserRole($uuid, $this->userEmail);
            if (!$role) {
                return Response::error('Document not found or access denied', 404);
            }
            
            $document = $this->documentService->getDocument($uuid);
            if (!$document) {
                return Response::error('Document not found', 404);
            }
            
            return Response::success([
                'document' => $document,
                'role' => $role,
            ]);
        } catch (\Exception $e) {
            error_log("getDocument error: " . $e->getMessage());
            return Response::error('Failed to get document', 500);
        }
    }
    
    /**
     * Create a new document
     * POST /api/collab/documents
     * Body: { title?, type: 'document'|'presentation', folder_id?: int }
     */
    public function createDocument(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $title = $request->input('title', 'Untitled');
        $type = $request->input('type', 'document');
        $folderId = $request->input('folder_id');
        
        if (!in_array($type, ['document', 'presentation'])) {
            return Response::error('Invalid document type', 400);
        }
        
        try {
            $document = $this->documentService->createDocument(
                $this->userEmail, 
                $title, 
                $type, 
                $folderId ? (int)$folderId : null
            );
            
            return Response::success([
                'document' => $document,
                'role' => 'owner',
            ], 'Document created', 201);
        } catch (\Exception $e) {
            error_log("createDocument error: " . $e->getMessage());
            return Response::error('Failed to create document', 500);
        }
    }
    
    /**
     * Update document metadata
     * PATCH /api/collab/documents/{uuid}
     * Body: { title? }
     */
    public function updateDocument(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $uuid = $request->param('uuid');
        if (!$uuid) {
            return Response::error('Document UUID required', 400);
        }
        
        // Check edit permission
        $role = $this->permissionService->getUserRole($uuid, $this->userEmail);
        if (!$role || $role === 'viewer') {
            return Response::error('Edit permission required', 403);
        }
        
        $updates = [];
        if ($request->has('title')) {
            $updates['title'] = $request->input('title');
        }
        
        if (empty($updates)) {
            return Response::error('No valid updates provided', 400);
        }
        
        try {
            $success = $this->documentService->updateDocument($uuid, $updates);
            if ($success) {
                return Response::success(null, 'Document updated');
            }
            return Response::error('Failed to update document', 500);
        } catch (\Exception $e) {
            error_log("updateDocument error: " . $e->getMessage());
            return Response::error('Failed to update document', 500);
        }
    }
    
    /**
     * Delete a document (soft delete)
     * DELETE /api/collab/documents/{uuid}
     */
    public function deleteDocument(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $uuid = $request->param('uuid');
        if (!$uuid) {
            return Response::error('Document UUID required', 400);
        }
        
        // Only owner can delete
        $role = $this->permissionService->getUserRole($uuid, $this->userEmail);
        if ($role !== 'owner') {
            return Response::error('Only the owner can delete this document', 403);
        }
        
        try {
            $success = $this->documentService->deleteDocument($uuid);
            if ($success) {
                return Response::success(null, 'Document deleted');
            }
            return Response::error('Failed to delete document', 500);
        } catch (\Exception $e) {
            error_log("deleteDocument error: " . $e->getMessage());
            return Response::error('Failed to delete document', 500);
        }
    }
    
    /**
     * Duplicate a document
     * POST /api/collab/documents/{uuid}/duplicate
     */
    public function duplicateDocument(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $uuid = $request->param('uuid');
        if (!$uuid) {
            return Response::error('Document UUID required', 400);
        }
        
        // Check at least view permission
        $role = $this->permissionService->getUserRole($uuid, $this->userEmail);
        if (!$role) {
            return Response::error('Document not found or access denied', 404);
        }
        
        try {
            $document = $this->documentService->duplicateDocument($uuid, $this->userEmail);
            if ($document) {
                return Response::success([
                    'document' => $document,
                    'role' => 'owner',
                ], 'Document duplicated', 201);
            }
            return Response::error('Failed to duplicate document', 500);
        } catch (\Exception $e) {
            error_log("duplicateDocument error: " . $e->getMessage());
            return Response::error('Failed to duplicate document', 500);
        }
    }
    
    /**
     * Get collaboration token for WebSocket connection
     * GET /api/collab/documents/{uuid}/collab-token
     */
    public function getCollabToken(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $uuid = $request->param('uuid');
        if (!$uuid) {
            return Response::error('Document UUID required', 400);
        }
        
        // Check permission
        $role = $this->permissionService->getUserRole($uuid, $this->userEmail);
        if (!$role) {
            return Response::error('Document not found or access denied', 404);
        }
        
        try {
            // Generate collab-specific JWT token
            $token = $this->generateCollabToken($uuid, $role);
            
            return Response::success([
                'token' => $token,
                'documentId' => $uuid,
                'role' => $role,
                'expiresIn' => 86400, // 24 hours
            ]);
        } catch (\Exception $e) {
            error_log("getCollabToken error: " . $e->getMessage());
            return Response::error('Failed to generate token', 500);
        }
    }
    
    /**
     * Generate collaboration JWT token
     * Signs with RS256 private key (or HS256 secret as fallback)
     */
    private function generateCollabToken(string $documentId, string $role): string
    {
        $now = time();
        $payload = [
            'sub' => $this->userEmail,
            'name' => explode('@', $this->userEmail)[0],
            'documentId' => $documentId,
            'role' => $role,
            'iat' => $now,
            'exp' => $now + 86400, // 24 hours
        ];

        $algorithm = $this->config['jwt']['algorithm'] ?? 'RS256';

        if ($algorithm === 'RS256') {
            $keyPath = $this->config['jwt']['private_key_path'] ?? '';
            if (!$keyPath || !file_exists($keyPath)) {
                throw new \RuntimeException('RS256 private key not found at: ' . $keyPath);
            }
            $signingKey = file_get_contents($keyPath);
        } else {
            $signingKey = $this->config['jwt']['secret'];
        }

        return \Firebase\JWT\JWT::encode($payload, $signingKey, $algorithm);
    }
    
    // ========================================================================
    // PERMISSION ENDPOINTS
    // ========================================================================
    
    /**
     * List collaborators on a document
     * GET /api/collab/documents/{uuid}/permissions
     */
    public function listPermissions(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $uuid = $request->param('uuid');
        if (!$uuid) {
            return Response::error('Document UUID required', 400);
        }
        
        // Check at least view permission
        $role = $this->permissionService->getUserRole($uuid, $this->userEmail);
        if (!$role) {
            return Response::error('Document not found or access denied', 404);
        }
        
        try {
            $permissions = $this->permissionService->listPermissions($uuid);
            return Response::success(['permissions' => $permissions]);
        } catch (\Exception $e) {
            error_log("listPermissions error: " . $e->getMessage());
            return Response::error('Failed to list permissions', 500);
        }
    }
    
    /**
     * Add a collaborator
     * POST /api/collab/documents/{uuid}/permissions
     * Body: { email, role: 'editor'|'viewer' }
     */
    public function addPermission(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $uuid = $request->param('uuid');
        if (!$uuid) {
            return Response::error('Document UUID required', 400);
        }
        
        // Only owner can share
        $myRole = $this->permissionService->getUserRole($uuid, $this->userEmail);
        if ($myRole !== 'owner') {
            return Response::error('Only the owner can share this document', 403);
        }
        
        $email = strtolower(trim($request->input('email', '')));
        $role = $request->input('role', 'viewer');
        
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::error('Valid email required', 400);
        }
        
        if (!in_array($role, ['editor', 'viewer'])) {
            return Response::error('Invalid role. Must be editor or viewer', 400);
        }
        
        if ($email === $this->userEmail) {
            return Response::error('Cannot share with yourself', 400);
        }
        
        try {
            $permission = $this->permissionService->addPermission($uuid, $email, $role, $this->userEmail);
            if ($permission) {
                // Send email notification to the new collaborator
                $document = $this->documentService->getDocument($uuid);
                if ($document) {
                    $this->sendCollabInviteEmail($email, $document, $role);
                }
                
                return Response::success(['permission' => $permission], 'Collaborator added', 201);
            }
            return Response::error('Failed to add collaborator', 500);
        } catch (\Exception $e) {
            error_log("addPermission error: " . $e->getMessage());
            return Response::error('Failed to add collaborator', 500);
        }
    }
    
    /**
     * Send collaboration invite email (same pattern as BoardController)
     */
    private function sendCollabInviteEmail(string $recipientEmail, array $document, string $role): void
    {
        if (!$this->userEmail) {
            error_log("Cannot send collab invite email - no user email");
            return;
        }
        
        try {
            $smtp = null;
            
            // Check if using OAuth or password authentication
            if ($this->isOAuthSession && $this->oauthProvider) {
                // Get OAuth access token and use appropriate SMTP config
                $accessToken = null;
                $smtpConfig = null;
                
                if ($this->oauthProvider === 'microsoft' && $this->microsoftOAuthService) {
                    $accessToken = $this->microsoftOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
                    $smtpConfig = [
                        'host' => 'smtp.office365.com',
                        'port' => 587,
                        'encryption' => 'tls',
                        'auth' => true,
                    ];
                } elseif ($this->googleOAuthService) {
                    $accessToken = $this->googleOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
                    $smtpConfig = [
                        'host' => 'smtp.gmail.com',
                        'port' => 587,
                        'encryption' => 'tls',
                        'auth' => true,
                    ];
                }
                
                if (!$accessToken) {
                    error_log("Cannot send collab invite email - failed to get OAuth access token");
                    return;
                }
                
                $smtp = new SmtpService($smtpConfig);
                $smtp->setOAuthCredentials($this->userEmail, $accessToken, $this->oauthProvider);
            } elseif ($this->userPassword) {
                // Password-based authentication
                $smtp = new SmtpService($this->config['smtp']);
                $smtp->setCredentials($this->userEmail, $this->userPassword);
            } else {
                error_log("Cannot send collab invite email - session expired, user needs to re-login");
                return;
            }
            
            $roleLabel = $role === 'editor' ? 'edit' : 'view';
            $typeLabel = ($document['type'] ?? 'document') === 'presentation' ? 'presentation' : 'document';
            $docUrl = ($this->config['app']['url'] ?? 'https://email.devcon1.hu') . '/drive?doc=' . $document['uuid'];
            $docTitle = $document['title'] ?? 'Untitled Document';
            
            $htmlBody = $this->buildCollabInviteHtml($docTitle, $this->userEmail, $roleLabel, $typeLabel, $docUrl);
            
            $smtp->send([
                'from_name' => 'Document Collaboration',
                'to' => [$recipientEmail],
                'subject' => "You've been invited to collaborate on \"{$docTitle}\"",
                'body_html' => $htmlBody,
                'body_text' => "{$this->userEmail} has shared a {$typeLabel} with you and given you permission to {$roleLabel} it.\n\nDocument: {$docTitle}\n\nOpen the document: {$docUrl}"
            ]);
            
            error_log("Collab invite email sent to {$recipientEmail} for document {$document['uuid']}");
        } catch (\Exception $e) {
            error_log("Failed to send collab invite email: " . $e->getMessage());
        }
    }
    
    /**
     * Build HTML email for collab invitation
     */
    private function buildCollabInviteHtml(string $docTitle, string $inviterEmail, string $roleLabel, string $typeLabel, string $docUrl): string
    {
        $primaryColor = '#22c55e'; // Green accent
        
        return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; background-color: #f3f4f6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
                    <tr>
                        <td style="background-color: ' . $primaryColor . '; padding: 30px 40px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 600;">FlowOne</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="color: #1f2937; margin: 0 0 20px 0; font-size: 20px; font-weight: 600;">
                                You\'ve been invited to collaborate!
                            </h2>
                            <p style="color: #4b5563; margin: 0 0 20px 0; font-size: 16px; line-height: 1.6;">
                                <strong>' . htmlspecialchars($inviterEmail) . '</strong> has shared a ' . htmlspecialchars($typeLabel) . ' with you and given you permission to <strong>' . htmlspecialchars($roleLabel) . '</strong> it.
                            </p>
                            <div style="background-color: #f9fafb; border-radius: 8px; padding: 20px; margin: 0 0 30px 0;">
                                <p style="color: #6b7280; margin: 0 0 8px 0; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">Document</p>
                                <p style="color: #1f2937; margin: 0; font-size: 18px; font-weight: 600;">' . htmlspecialchars($docTitle) . '</p>
                            </div>
                            <div style="text-align: center;">
                                <a href="' . htmlspecialchars($docUrl) . '" style="display: inline-block; background-color: ' . $primaryColor . '; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-size: 16px; font-weight: 600;">
                                    Open Document
                                </a>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #f9fafb; padding: 20px 40px; text-align: center; border-top: 1px solid #e5e7eb;">
                            <p style="color: #9ca3af; margin: 0; font-size: 12px;">
                                This email was sent because someone shared a document with you.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
    
    /**
     * Update collaborator role
     * PATCH /api/collab/documents/{uuid}/permissions/{email}
     * Body: { role: 'editor'|'viewer' }
     */
    public function updatePermission(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $uuid = $request->param('uuid');
        $email = urldecode($request->param('email'));
        
        if (!$uuid || !$email) {
            return Response::error('Document UUID and email required', 400);
        }
        
        // Only owner can update permissions
        $myRole = $this->permissionService->getUserRole($uuid, $this->userEmail);
        if ($myRole !== 'owner') {
            return Response::error('Only the owner can update permissions', 403);
        }
        
        $role = $request->input('role');
        if (!in_array($role, ['editor', 'viewer'])) {
            return Response::error('Invalid role. Must be editor or viewer', 400);
        }
        
        try {
            $success = $this->permissionService->updatePermission($uuid, strtolower($email), $role);
            if ($success) {
                return Response::success(null, 'Permission updated');
            }
            return Response::error('Permission not found', 404);
        } catch (\Exception $e) {
            error_log("updatePermission error: " . $e->getMessage());
            return Response::error('Failed to update permission', 500);
        }
    }
    
    /**
     * Remove a collaborator
     * DELETE /api/collab/documents/{uuid}/permissions/{email}
     */
    public function removePermission(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $uuid = $request->param('uuid');
        $email = urldecode($request->param('email'));
        
        if (!$uuid || !$email) {
            return Response::error('Document UUID and email required', 400);
        }
        
        // Only owner can remove permissions
        $myRole = $this->permissionService->getUserRole($uuid, $this->userEmail);
        if ($myRole !== 'owner') {
            return Response::error('Only the owner can remove collaborators', 403);
        }
        
        // Cannot remove owner
        if (strtolower($email) === $this->userEmail) {
            return Response::error('Cannot remove yourself as owner', 400);
        }
        
        try {
            $success = $this->permissionService->removePermission($uuid, strtolower($email));
            if ($success) {
                return Response::success(null, 'Collaborator removed');
            }
            return Response::error('Permission not found', 404);
        } catch (\Exception $e) {
            error_log("removePermission error: " . $e->getMessage());
            return Response::error('Failed to remove collaborator', 500);
        }
    }
    
    // ========================================================================
    // VERSION HISTORY ENDPOINTS
    // ========================================================================
    
    /**
     * List version history
     * GET /api/collab/documents/{uuid}/versions
     */
    public function listVersions(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $uuid = $request->param('uuid');
        if (!$uuid) {
            return Response::error('Document UUID required', 400);
        }
        
        // Check at least view permission
        $role = $this->permissionService->getUserRole($uuid, $this->userEmail);
        if (!$role) {
            return Response::error('Document not found or access denied', 404);
        }
        
        $page = max(1, (int)$request->getQuery('page', 1));
        $limit = min(100, max(1, (int)$request->getQuery('limit', 20)));
        
        try {
            $versions = $this->documentService->listVersions($uuid, $limit, ($page - 1) * $limit);
            return Response::success(['versions' => $versions]);
        } catch (\Exception $e) {
            error_log("listVersions error: " . $e->getMessage());
            return Response::error('Failed to list versions', 500);
        }
    }
    
    /**
     * Create a named version
     * POST /api/collab/documents/{uuid}/versions
     * Body: { name? }
     */
    public function createVersion(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $uuid = $request->param('uuid');
        if (!$uuid) {
            return Response::error('Document UUID required', 400);
        }
        
        // Check edit permission
        $role = $this->permissionService->getUserRole($uuid, $this->userEmail);
        if (!$role || $role === 'viewer') {
            return Response::error('Edit permission required', 403);
        }
        
        $nameInput = $request->input('name');
        $name = is_string($nameInput) ? $nameInput : null;
        
        try {
            $version = $this->documentService->createVersion($uuid, $this->userEmail, $name);
            if ($version) {
                return Response::success(['version' => $version], 'Version created', 201);
            }
            return Response::error('Failed to create version', 500);
        } catch (\Exception $e) {
            error_log("createVersion error: " . $e->getMessage());
            return Response::error('Failed to create version', 500);
        }
    }
    
    /**
     * Restore to a specific version
     * POST /api/collab/documents/{uuid}/versions/{version}/restore
     */
    public function restoreVersion(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $uuid = $request->param('uuid');
        $version = (int)$request->param('version');
        
        if (!$uuid || !$version) {
            return Response::error('Document UUID and version number required', 400);
        }
        
        // Check edit permission
        $role = $this->permissionService->getUserRole($uuid, $this->userEmail);
        if (!$role || $role === 'viewer') {
            return Response::error('Edit permission required', 403);
        }
        
        try {
            $success = $this->documentService->restoreVersion($uuid, $version);
            if ($success) {
                return Response::success(null, 'Version restored');
            }
            return Response::error('Version not found', 404);
        } catch (\Exception $e) {
            error_log("restoreVersion error: " . $e->getMessage());
            return Response::error('Failed to restore version', 500);
        }
    }
    
    // ========================================================================
    // AUTH VERIFICATION (called by Hocuspocus server)
    // ========================================================================
    
    /**
     * Verify collaboration token (internal endpoint for Hocuspocus)
     * POST /api/collab/auth/verify
     * Body: { token }
     */
    public function verifyAuthToken(Request $request): Response
    {
        $token = $request->input('token');
        if (!$token) {
            return Response::error('Token required', 400);
        }
        
        try {
            $decoded = $this->decodeJwt($token);
            
            return Response::success([
                'valid' => true,
                'user' => [
                    'email' => $decoded->sub,
                    'name' => $decoded->name ?? null,
                    'role' => $decoded->role,
                ],
                'documentId' => $decoded->documentId,
            ]);
        } catch (\Firebase\JWT\ExpiredException $e) {
            return Response::success(['valid' => false, 'error' => 'Token expired']);
        } catch (\Exception $e) {
            return Response::success(['valid' => false, 'error' => 'Invalid token']);
        }
    }
    
    // ========================================================================
    // DRIVE FILE IMPORT/EXPORT
    // ========================================================================
    
    /**
     * Create a collab document from a Drive file
     * POST /api/collab/documents/from-file
     * Body: { drive_file_id, title?, type? }
     */
    public function createFromFile(Request $request): Response
    {
        error_log("[CollabController::createFromFile] Called. userEmail={$this->userEmail}");
        
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $driveFileId = (int)$request->input('drive_file_id');
        $title = $request->input('title');
        $type = $request->input('type', 'document');
        
        error_log("[CollabController::createFromFile] driveFileId={$driveFileId}, type={$type}, title={$title}");
        
        if (!$driveFileId) {
            return Response::error('Drive file ID is required', 400);
        }
        
        if (!in_array($type, ['document', 'presentation'])) {
            return Response::error('Invalid document type', 400);
        }
        
        try {
            // Check if a collab document already exists for this drive file
            $existingDoc = $this->documentService->findByDriveFileId($driveFileId, $this->userEmail);
            if ($existingDoc) {
                $result = [
                    'document' => $existingDoc,
                    'role' => $existingDoc['owner_email'] === $this->userEmail ? 'owner' : 'editor',
                    'existing' => true,
                ];

                // For presentations, reconvert so the frontend can reimport if Y.js slides are empty
                if (($existingDoc['type'] ?? '') === 'presentation') {
                    $reimportPayload = $this->reconvertPptxForDriveFile($driveFileId);
                    $result['initial_slides'] = $reimportPayload['slides'] ?? null;
                    $result['presentation_meta'] = $reimportPayload['meta'] ?? null;
                }

                return Response::success($result, 'Document already exists');
            }
            
            // Get Drive file info and content
            $driveService = new DriveService($this->config, $this->userEmail);
            $fileInfo = $driveService->getFile($this->userEmail, $driveFileId);
            
            if (!$fileInfo) {
                error_log("[CollabController::createFromFile] Drive file NOT FOUND in DB. email={$this->userEmail}, id={$driveFileId}");
                return Response::error("Drive file not found (email={$this->userEmail}, id={$driveFileId})", 404);
            }
            
            error_log("[CollabController::createFromFile] File found: {$fileInfo['original_name']}, mime={$fileInfo['mime_type']}");
            
            // Get file path
            $filePath = $this->resolveDriveFilePath($driveService, $driveFileId, $fileInfo);

            if (!$filePath || !file_exists($filePath)) {
                $storage = $fileInfo['storage_location'] ?? 'unknown';
                error_log("[CollabController::createFromFile] File path not found. path=" . ($filePath ?? 'null') . ", storage={$storage}, filename={$fileInfo['filename']}, nas_relative_path=" . ($fileInfo['nas_relative_path'] ?? 'null') . ", storage_path=" . ($fileInfo['storage_path'] ?? 'null'));
                return Response::error('File content not found on disk (path=' . ($filePath ?? 'null') . ', storage=' . $storage . ')', 404);
            }
            
            // Use file name as title if not provided
            if (!$title) {
                $title = pathinfo($fileInfo['original_name'], PATHINFO_FILENAME);
            }
            
            // Convert file to initial content based on type
            $initialContent = null;
            $initialSlides = null;
            $presentationMeta = null;
            $mimeType = $fileInfo['mime_type'] ?? '';
            $extension = strtolower(pathinfo($fileInfo['original_name'], PATHINFO_EXTENSION));
            
            // Handle Word documents
            if ($extension === 'docx' || $extension === 'doc' || 
                $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ||
                $mimeType === 'application/msword') {
                
                // Auto-detect type as document
                $type = 'document';
                
                // Try to convert if PHPWord is available
                try {
                    if (class_exists('PhpOffice\\PhpWord\\IOFactory')) {
                        $initialContent = $this->convertWordToHtml($filePath, $extension);
                    } else {
                        error_log("PHPWord not available - document will open empty");
                    }
                } catch (\Throwable $e) {
                    error_log("Word conversion failed: " . $e->getMessage());
                }
            }
            // Handle PowerPoint presentations
            elseif ($extension === 'pptx' || $extension === 'ppt' ||
                $mimeType === 'application/vnd.openxmlformats-officedocument.presentationml.presentation' ||
                $mimeType === 'application/vnd.ms-powerpoint') {
                
                $type = 'presentation';
                
                try {
                    if (class_exists('PhpOffice\\PhpPresentation\\IOFactory')) {
                        $converter = new PptxConversionService();
                        $initialSlides = $converter->convertFile($filePath, $extension);
                        $presentationMeta = $converter->getPresentationMeta();
                    } else {
                        error_log("PHPPresentation not available - presentation will open empty");
                    }
                } catch (\Throwable $e) {
                    error_log("PPTX conversion failed: " . $e->getMessage());
                }
            }
            
            // Create the collab document
            $document = $this->documentService->createFromDriveFile(
                $this->userEmail,
                $title,
                $type,
                $driveFileId,
                $initialContent,
                $initialSlides
            );
            
            return Response::success([
                'document' => $document,
                'role' => 'owner',
                'initial_slides' => $initialSlides,
                'presentation_meta' => $presentationMeta,
            ], 'Document created from Drive file', 201);
            
        } catch (\Throwable $e) {
            error_log("createFromFile error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return Response::error('Failed to create document from file: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Save collab document content back to Drive
     * POST /api/collab/documents/{uuid}/save-to-drive
     * Body: { html_content, create_version? }
     */
    public function saveToDrive(Request $request): Response
    {
        $authError = $this->requireAuth();
        if ($authError) return $authError;
        
        $uuid = $request->param('uuid');
        if (!$uuid) {
            return Response::error('Document UUID required', 400);
        }
        
        // Check edit permission
        $role = $this->permissionService->getUserRole($uuid, $this->userEmail);
        if (!$role || $role === 'viewer') {
            return Response::error('Edit permission required', 403);
        }
        
        $htmlContent = $request->input('html_content');
        $createVersion = (bool)$request->input('create_version', true);
        
        if (!$htmlContent) {
            return Response::error('HTML content is required', 400);
        }
        
        try {
            // Get document with drive file link
            $document = $this->documentService->getDocumentWithDriveLink($uuid);
            if (!$document) {
                return Response::error('Document not found', 404);
            }
            
            if (!$document['drive_file_id']) {
                return Response::error('This document is not linked to a Drive file', 400);
            }
            
            // Get the Drive file info
            $driveService = new DriveService($this->config, $this->userEmail);
            $fileInfo = $driveService->getFile($this->userEmail, $document['drive_file_id']);
            
            if (!$fileInfo) {
                return Response::error('Original Drive file not found', 404);
            }
            
            // Convert HTML to DOCX
            $docxContent = $this->convertHtmlToDocx($htmlContent, $document['title']);
            
            if (!$docxContent) {
                return Response::error('Failed to convert content to DOCX', 500);
            }
            
            // Create a temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'collab_docx_');
            file_put_contents($tempFile, $docxContent);
            
            try {
                // Update the Drive file with new content
                $result = $driveService->updateFileContent(
                    $this->userEmail,
                    $document['drive_file_id'],
                    $tempFile,
                    $createVersion
                );
                
                if (!$result) {
                    return Response::error('Failed to update Drive file', 500);
                }
                
                return Response::success([
                    'drive_file_id' => $document['drive_file_id'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'Document saved to Drive');
                
            } finally {
                // Clean up temp file
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
            
        } catch (\Exception $e) {
            error_log("saveToDrive error: " . $e->getMessage());
            return Response::error('Failed to save to Drive: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Reconvert a PPTX drive file for an existing presentation document.
     * Returns slide data or null on failure.
     */
    private function reconvertPptxForDriveFile(int $driveFileId): ?array
    {
        try {
            if (!class_exists('PhpOffice\\PhpPresentation\\IOFactory')) {
                return null;
            }

            $driveService = new DriveService($this->config, $this->userEmail);
            $fileInfo = $driveService->getFile($this->userEmail, $driveFileId);
            if (!$fileInfo) return null;

            $filePath = $this->resolveDriveFilePath($driveService, $driveFileId, $fileInfo);

            if (!$filePath || !file_exists($filePath)) return null;

            $extension = strtolower(pathinfo($fileInfo['original_name'], PATHINFO_EXTENSION));
            $converter = new PptxConversionService();
            $slides = $converter->convertFile($filePath, $extension);

            return [
                'slides' => $slides,
                'meta' => $converter->getPresentationMeta(),
            ];
        } catch (\Throwable $e) {
            error_log("[CollabController] reconvertPptx failed: " . $e->getMessage());
            return null;
        }
    }

    private function resolveDriveFilePath(DriveService $driveService, int $driveFileId, array $fileInfo): ?string
    {
        $filePath = $driveService->getFilePath($this->userEmail, $driveFileId);
        if ($filePath && file_exists($filePath) && is_readable($filePath)) {
            return $filePath;
        }

        $resolvedFile = $driveService->getFileByIdWithPath($driveFileId);
        $resolvedStoragePath = $resolvedFile['storage_path'] ?? null;
        if ($resolvedStoragePath && file_exists($resolvedStoragePath) && is_readable($resolvedStoragePath)) {
            error_log("[CollabController] Resolved file path via getFileByIdWithPath: {$resolvedStoragePath}");
            return $resolvedStoragePath;
        }

        $email = strtolower((string) $this->userEmail);
        $emailHash = md5($email);
        $filename = $fileInfo['filename'] ?? null;
        $storagePath = $fileInfo['storage_path'] ?? ($resolvedFile['storage_path'] ?? null);
        $nasRelativePath = $fileInfo['nas_relative_path'] ?? ($resolvedFile['nas_relative_path'] ?? null);
        $candidates = [];

        if (!empty($storagePath)) {
            $candidates[] = $storagePath;
        }

        if (!empty($nasRelativePath)) {
            $cleanRelativePath = ltrim(str_replace('\\', '/', (string) $nasRelativePath), '/');
            $candidates[] = '/mnt/nas-drive/' . $cleanRelativePath;
            $candidates[] = '/mnt/nas-drive/' . $email . '/' . $cleanRelativePath;
            $candidates[] = '/mnt/nas-drive/' . $emailHash . '/' . $cleanRelativePath;
        }

        if (!empty($filename)) {
            $candidates[] = '/mnt/nas-drive/' . $emailHash . '/' . $filename;
            $candidates[] = '/mnt/nas-drive/' . $email . '/' . $filename;
        }

        $candidates = array_values(array_unique(array_filter($candidates)));
        foreach ($candidates as $candidate) {
            error_log("[CollabController] Trying fallback path: {$candidate}");
            if (file_exists($candidate) && is_readable($candidate)) {
                error_log("[CollabController] Fallback path worked: {$candidate}");
                return $candidate;
            }
        }

        return $filePath;
    }

    /**
     * Convert Word document to HTML using PHPWord
     */
    private function convertWordToHtml(string $filePath, string $extension): ?string
    {
        try {
            // Load the document
            if ($extension === 'doc') {
                $phpWord = PhpWordIOFactory::load($filePath, 'MsDoc');
            } else {
                $phpWord = PhpWordIOFactory::load($filePath, 'Word2007');
            }
            
            // Create HTML writer
            $htmlWriter = new PhpWordHtmlWriter($phpWord);
            
            // Write to a temporary stream
            $tempFile = tempnam(sys_get_temp_dir(), 'phpword_html_');
            $htmlWriter->save($tempFile);
            
            $html = file_get_contents($tempFile);
            unlink($tempFile);
            
            // Extract just the body content (remove DOCTYPE, html, head tags)
            if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $matches)) {
                $bodyContent = trim($matches[1]);
                // Clean up PHPWord's HTML output
                $bodyContent = $this->cleanPhpWordHtml($bodyContent);
                return $bodyContent;
            }
            
            return $html;
            
        } catch (\Exception $e) {
            error_log("convertWordToHtml error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Clean up PHPWord HTML output for TipTap editor
     */
    private function cleanPhpWordHtml(string $html): string
    {
        // Remove inline styles that might conflict with editor
        $html = preg_replace('/\s+style="[^"]*"/i', '', $html);
        
        // Convert PHPWord's paragraph markers to standard p tags
        $html = preg_replace('/<p[^>]*class="[^"]*MsoNormal[^"]*"[^>]*>/i', '<p>', $html);
        
        // Remove empty paragraphs
        $html = preg_replace('/<p>\s*(&nbsp;)?\s*<\/p>/i', '', $html);
        
        // Clean up multiple whitespace
        $html = preg_replace('/\s+/', ' ', $html);
        
        return trim($html);
    }
    
    /**
     * Convert HTML content to DOCX using PHPWord
     */
    private function convertHtmlToDocx(string $html, string $title): ?string
    {
        try {
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            
            // Set document properties
            $properties = $phpWord->getDocInfo();
            $properties->setTitle($title);
            $properties->setCreator('FlowOne');
            $properties->setLastModifiedBy($this->userEmail);
            
            // Add a section
            $section = $phpWord->addSection();
            
            // Convert HTML to PHPWord elements
            \PhpOffice\PhpWord\Shared\Html::addHtml($section, $html, false, false);
            
            // Save to temp file
            $tempFile = tempnam(sys_get_temp_dir(), 'phpword_docx_');
            $writer = PhpWordIOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tempFile);
            
            $content = file_get_contents($tempFile);
            unlink($tempFile);
            
            return $content;
            
        } catch (\Exception $e) {
            error_log("convertHtmlToDocx error: " . $e->getMessage());
            return null;
        }
    }
    
}

