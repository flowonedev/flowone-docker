<?php

namespace Webmail\Addons\KanbanBoards\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Controllers\BaseController;
use Webmail\Addons\KanbanBoards\Services\BoardService;
use Webmail\Services\DriveService;
use Webmail\Services\SmtpService;
use Webmail\Services\SearchIndexerService;
use Webmail\Services\RedisCacheService;

class BoardController extends BaseController
{
    private ?BoardService $boardService = null;
    private ?DriveService $driveService = null;
    private ?SearchIndexerService $searchIndexer = null;
    private ?RedisCacheService $redisCache = null;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->boardService = new BoardService($config);
    }
    
    /**
     * Get BoardService (lazy init after auth)
     */
    private function getBoardService(): BoardService
    {
        if (!$this->boardService) {
            $this->boardService = new BoardService($this->config);
        }
        return $this->boardService;
    }
    
    /**
     * Get RedisCacheService for publishing real-time events
     */
    private function getRedisCache(): RedisCacheService
    {
        if (!$this->redisCache) {
            $this->redisCache = new RedisCacheService($this->config);
        }
        return $this->redisCache;
    }
    
    /**
     * Get search indexer for indexing board items
     */
    private function getSearchIndexer(): SearchIndexerService
    {
        if (!$this->searchIndexer) {
            $this->searchIndexer = new SearchIndexerService($this->config);
        }
        return $this->searchIndexer;
    }
    
    /**
     * Index a card for search (call after create/update)
     */
    private function triggerCardIndex(array $card): void
    {
        try {
            $email = $this->getActiveEmail();
            $board = $this->boardService->getBoard($email, $card['board_id'] ?? $card['list_board_id'] ?? null);
            $list = isset($card['list_id']) ? ['id' => $card['list_id'], 'name' => $card['list_name'] ?? null] : null;
            $this->getSearchIndexer()->indexCard($email, $card, $list, $board);
        } catch (\Exception $e) {
            error_log("BoardController triggerCardIndex error: " . $e->getMessage());
        }
    }
    
    /**
     * Index a board for search (call after create/update)
     */
    private function triggerBoardIndex(array $board): void
    {
        try {
            $this->getSearchIndexer()->indexBoard($this->getActiveEmail(), $board);
        } catch (\Exception $e) {
            error_log("BoardController triggerBoardIndex error: " . $e->getMessage());
        }
    }
    
    // getActiveEmail() and getSecondaryAccountEmail() inherited from BaseController
    
    private function getDriveService(): DriveService
    {
        if (!$this->driveService) {
            $this->driveService = new DriveService($this->config, $this->userEmail);
        }
        return $this->driveService;
    }
    
    // ========================================
    // BOARD ENDPOINTS
    // ========================================
    
    /**
     * List all boards for the user
     */
    public function listBoards(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $includeArchived = $request->getQuery('include_archived', 'false') === 'true';
        $boards = $this->boardService->getBoards($this->getActiveEmail(), $includeArchived);
        
        return Response::success(['boards' => $boards]);
    }
    
    /**
     * Get a single board with full data
     */
    public function getBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        $board = $this->boardService->getBoard($this->getActiveEmail(), $id);
        
        if (!$board) {
            return Response::error('Board not found or access denied', 404);
        }
        
        return Response::success(['board' => $board]);
    }

    /**
     * Batched board fetch: load many boards in one HTTP call.
     * Used by ProjectHub FolderTaskView when opening a folder that
     * contains multiple boards. Replaces N sequential GET /boards/{id}.
     *
     * Body: { board_ids: int[] }
     * Returns: { boards: { [boardId]: board }, errors: [...] }
     *
     * POST /boards/batch-fetch
     */
    public function batchFetch(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $ids = (array)$request->input('board_ids', []);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));
        if (empty($ids)) {
            return Response::error('board_ids[] is required', 400);
        }
        // Cap to keep response sizes sane.
        $ids = array_slice($ids, 0, 50);

        $email = $this->getActiveEmail();
        $boards = [];
        $errors = [];

        foreach ($ids as $id) {
            try {
                $b = $this->boardService->getBoard($email, $id);
                if ($b) {
                    $boards[$id] = $b;
                } else {
                    $errors[] = ['board_id' => $id, 'error' => 'not_found_or_access_denied'];
                }
            } catch (\Throwable $e) {
                $errors[] = ['board_id' => $id, 'error' => $e->getMessage()];
                error_log("[BoardController::batchFetch] {$id}: " . $e->getMessage());
            }
        }

        return Response::success([
            'boards' => $boards,
            'errors' => $errors,
        ]);
    }
    
    /**
     * Create a new board
     */
    public function createBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $data = [
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'background_color' => $request->input('background_color'),
            'default_lists' => $request->input('default_lists'),
        ];
        
        if (empty($data['name'])) {
            return Response::error('Board name is required');
        }
        
        $board = $this->boardService->createBoard($this->getActiveEmail(), $data);
        
        if (!$board) {
            return Response::error('Failed to create board');
        }
        
        // Index for search
        $this->triggerBoardIndex($board);
        
        return Response::success(['board' => $board], 'Board created');
    }
    
    /**
     * Update a board
     */
    public function updateBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        
        $data = [];
        if ($request->has('name')) $data['name'] = $request->input('name');
        if ($request->has('description')) $data['description'] = $request->input('description');
        if ($request->has('background_color')) $data['background_color'] = $request->input('background_color');
        if ($request->has('background_image')) $data['background_image'] = $request->input('background_image');
        if ($request->has('background_blur')) $data['background_blur'] = (int)$request->input('background_blur');
        if ($request->has('background_overlay_color')) $data['background_overlay_color'] = $request->input('background_overlay_color');
        if ($request->has('background_overlay_opacity')) $data['background_overlay_opacity'] = (int)$request->input('background_overlay_opacity');
        if ($request->has('archived')) $data['archived'] = $request->input('archived');
        
        $board = $this->boardService->updateBoard($this->getActiveEmail(), $id, $data);
        
        if (!$board) {
            return Response::error('Board not found or access denied', 404);
        }
        
        // Index for search
        $this->triggerBoardIndex($board);
        
        // Publish real-time event for WebSocket sync
        try {
            $cache = $this->getRedisCache();
            $cache->publishBoardUpdated($this->getActiveEmail(), $id, $data);
        } catch (\Exception $e) {
            error_log("[BoardController] Failed to publish board update: " . $e->getMessage());
            // Non-fatal - board was saved, just sync failed
        }
        
        return Response::success(['board' => $board], 'Board updated');
    }
    
    /**
     * Delete a board
     */
    public function deleteBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $id = (int)$request->getParam('id');
        
        if (!$this->boardService->deleteBoard($this->getActiveEmail(), $id)) {
            return Response::error('Board not found or access denied', 404);
        }
        
        return Response::success(null, 'Board deleted');
    }
    
    /**
     * Close a board
     * POST /boards/{id}/close
     */
    public function closeBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $id = (int)$request->getParam('id');
        $board = $this->boardService->closeBoard($this->getActiveEmail(), $id);

        if (!$board) {
            return Response::error('Board not found or access denied', 404);
        }

        return Response::success(['board' => $board], 'Board closed');
    }

    /**
     * Reopen a closed board
     * POST /boards/{id}/reopen
     */
    public function reopenBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $id = (int)$request->getParam('id');
        $board = $this->boardService->reopenBoard($this->getActiveEmail(), $id);

        if (!$board) {
            return Response::error('Board not found or access denied', 404);
        }

        return Response::success(['board' => $board], 'Board reopened');
    }

    // ========================================
    // MEMBER ENDPOINTS
    // ========================================
    
    /**
     * Get board members
     */
    public function getMembers(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId)) {
            return Response::error('Board not found or access denied', 404);
        }
        
        $members = $this->boardService->getMembers($boardId);
        
        return Response::success(['members' => $members]);
    }
    
    /**
     * Add a member to a board
     */
    public function addMember(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        try {
            $boardId = (int)$request->getParam('id');
            $memberEmail = $request->input('email');
            $role = $request->input('role', 'editor');
            
            // Get permissions from request
            $permissions = [
                'can_view_financials' => (bool)$request->input('can_view_financials', false),
                'can_view_client' => (bool)$request->input('can_view_client', false),
                'can_view_contacts' => (bool)$request->input('can_view_contacts', false),
                'can_view_emails' => (bool)$request->input('can_view_emails', false),
                'can_access_drive' => (bool)$request->input('can_access_drive', false),
                'drive_folder_id' => $request->input('drive_folder_id'),
                'drive_permission' => $request->input('drive_permission', 'viewer'),
            ];
            
            if (empty($memberEmail)) {
                $this->boardService->log("addMember FAILED: empty email");
                return Response::error('Member email is required');
            }
            
            if (!in_array($role, ['editor', 'viewer'])) {
                $this->boardService->log("addMember FAILED: invalid role '{$role}'");
                return Response::error('Invalid role. Must be editor or viewer');
            }
            
            // Get board info before adding member
            $board = $this->boardService->getBoard($this->getActiveEmail(), $boardId);
            if (!$board) {
                return Response::error('Board not found or access denied', 404);
            }
            
            if (!$this->boardService->addMember($this->getActiveEmail(), $boardId, $memberEmail, $role, $permissions)) {
                return Response::error('Failed to add member. You may not have permission.');
            }
            
            // Send invitation email
            $this->sendBoardInviteEmail($memberEmail, $board, $role);
            
            $members = $this->boardService->getMembers($boardId);
            
            return Response::success(['members' => $members], 'Member added and invitation sent');
        } catch (\Exception $e) {
            error_log("BoardController addMember error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            return Response::error('An error occurred while adding the member. Please try again.', 500);
        }
    }
    
    /**
     * Batched add-members in a single HTTP call. Mirrors per-call
     * addMember semantics (guards, permissions, invitation email,
     * automation events) but fetches the board ONCE, computes the
     * member roster ONCE at the end, and shares the auth check.
     *
     * Body: { members: [{ email, role?, can_view_financials?, ... }, ...] }
     *
     * POST /boards/{id}/members/batch
     */
    public function addMembersBatch(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        try {
            $boardId = (int)$request->getParam('id');
            $members = (array)$request->input('members', []);
            if (empty($members)) {
                return Response::error('members[] is required', 400);
            }
            if (count($members) > 100) {
                $members = array_slice($members, 0, 100);
            }

            $board = $this->boardService->getBoard($this->getActiveEmail(), $boardId);
            if (!$board) {
                return Response::error('Board not found or access denied', 404);
            }

            $added = 0;
            $failed = 0;
            $errors = [];

            foreach ($members as $m) {
                $memberEmail = trim((string)($m['email'] ?? ''));
                $role = (string)($m['role'] ?? 'editor');
                if ($memberEmail === '') {
                    $failed++;
                    $errors[] = 'Missing email';
                    continue;
                }
                if (!in_array($role, ['editor', 'viewer'], true)) {
                    $failed++;
                    $errors[] = "{$memberEmail}: invalid role";
                    continue;
                }

                $permissions = [
                    'can_view_financials' => (bool)($m['can_view_financials'] ?? false),
                    'can_view_client' => (bool)($m['can_view_client'] ?? false),
                    'can_view_contacts' => (bool)($m['can_view_contacts'] ?? false),
                    'can_view_emails' => (bool)($m['can_view_emails'] ?? false),
                    'can_access_drive' => (bool)($m['can_access_drive'] ?? false),
                    'drive_folder_id' => $m['drive_folder_id'] ?? null,
                    'drive_permission' => $m['drive_permission'] ?? 'viewer',
                ];

                $ok = $this->boardService->addMember($this->getActiveEmail(), $boardId, $memberEmail, $role, $permissions);
                if ($ok) {
                    $added++;
                    try {
                        $this->sendBoardInviteEmail($memberEmail, $board, $role);
                    } catch (\Throwable $e) {
                        error_log("[addMembersBatch] invite email failed for {$memberEmail}: " . $e->getMessage());
                    }
                } else {
                    $failed++;
                    $errors[] = "{$memberEmail}: failed";
                }
            }

            // ONE getMembers call at the end, not per-add.
            $roster = $this->boardService->getMembers($boardId);

            return Response::success([
                'added' => $added,
                'failed' => $failed,
                'errors' => $errors,
                'members' => $roster,
            ], "Added {$added} member(s), {$failed} failed");
        } catch (\Exception $e) {
            error_log("BoardController addMembersBatch error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            return Response::error('An error occurred while adding members. Please try again.', 500);
        }
    }

    /**
     * Update member permissions
     */
    public function updateMemberPermissions(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        try {
            $boardId = (int)$request->getParam('id');
            $memberEmail = $request->input('member_email');
            
            if (empty($memberEmail)) {
                return Response::error('Member email is required');
            }
            
            // Get all possible permissions from request
            $permissions = [];
            
            if ($request->input('role') !== null) {
                $permissions['role'] = $request->input('role');
            }
            if ($request->input('can_view_financials') !== null) {
                $permissions['can_view_financials'] = (bool)$request->input('can_view_financials');
            }
            if ($request->input('can_view_client') !== null) {
                $permissions['can_view_client'] = (bool)$request->input('can_view_client');
            }
            if ($request->input('can_view_contacts') !== null) {
                $permissions['can_view_contacts'] = (bool)$request->input('can_view_contacts');
            }
            if ($request->input('can_view_emails') !== null) {
                $permissions['can_view_emails'] = (bool)$request->input('can_view_emails');
            }
            if ($request->input('can_access_drive') !== null) {
                $permissions['can_access_drive'] = (bool)$request->input('can_access_drive');
            }
            
            if (!$this->boardService->updateMemberPermissions($this->getActiveEmail(), $boardId, $memberEmail, $permissions)) {
                return Response::error('Failed to update permissions');
            }
            
            $members = $this->boardService->getMembers($boardId);
            
            return Response::success(['members' => $members], 'Permissions updated');
        } catch (\Exception $e) {
            error_log("BoardController updateMemberPermissions error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            return Response::error('An error occurred while updating permissions. Please try again.', 500);
        }
    }
    
    /**
     * Get activity log for a board
     */
    public function getBoardActivityLog(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $limit = (int)($request->getQuery('limit') ?? 50);
        $offset = (int)($request->getQuery('offset') ?? 0);
        
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId)) {
            return Response::error('Board not found or access denied', 404);
        }
        
        $activity = $this->boardService->getBoardActivityLog($boardId, $limit, $offset);
        
        return Response::success(['activity' => $activity]);
    }
    
    /**
     * Get company users (users with the same domain as the current user)
     * Used for quick board sharing within the same organization
     */
    public function getCompanyUsers(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $domain = substr(strrchr($activeEmail, "@"), 1);
        
        if (!$domain) {
            return Response::error('Invalid email domain', 400);
        }
        
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            
            // Get unique emails with same domain from multiple sources
            // Excluding the current user
            $likeDomain = '%@' . $domain;
            
            $sql = "
                SELECT DISTINCT email FROM (
                    -- Mail server accounts (primary source)
                    SELECT email COLLATE utf8mb4_unicode_ci as email FROM mail_accounts WHERE domain = ? AND status = 'active'
                    UNION
                    -- Board owners
                    SELECT owner_email COLLATE utf8mb4_unicode_ci as email FROM webmail_boards WHERE owner_email LIKE ?
                    UNION
                    -- Board members  
                    SELECT user_email COLLATE utf8mb4_unicode_ci as email FROM webmail_board_members WHERE user_email LIKE ?
                    UNION
                    -- Webmail accounts
                    SELECT account_email COLLATE utf8mb4_unicode_ci as email FROM webmail_accounts WHERE account_email LIKE ?
                    UNION
                    SELECT primary_email COLLATE utf8mb4_unicode_ci as email FROM webmail_accounts WHERE primary_email LIKE ?
                ) AS all_emails
                WHERE email != ? AND email IS NOT NULL AND email != ''
                ORDER BY email
            ";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([$domain, $likeDomain, $likeDomain, $likeDomain, $likeDomain, strtolower($activeEmail)]);
            $users = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            return Response::success(['users' => $users, 'domain' => $domain]);
        } catch (\PDOException $e) {
            error_log("BoardController getCompanyUsers error: " . $e->getMessage());
            return Response::error('Failed to fetch company users', 500);
        }
    }
    
    /**
     * Send board invitation email to new member
     */
    private function sendBoardInviteEmail(string $recipientEmail, array $board, string $role): void
    {
        if (!$this->userEmail) {
            error_log("Cannot send board invite email - no user email");
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
                    error_log("Cannot send board invite email - failed to get OAuth access token");
                    return;
                }
                
                $smtp = new SmtpService($smtpConfig);
                $smtp->setOAuthCredentials($this->userEmail, $accessToken, $this->oauthProvider);
            } elseif ($this->userPassword) {
                // Password-based authentication
                $smtp = new SmtpService($this->config['smtp']);
                $smtp->setCredentials($this->userEmail, $this->userPassword);
            } else {
                error_log("Cannot send board invite email - session expired, user needs to re-login (not OAuth, no password)");
                return;
            }
            
            $roleLabel = $role === 'editor' ? 'edit, create, and move cards' : 'view the board and cards';
            $boardColor = $board['background_color'] ?? '#6366f1';
            $boardUrl = ($this->config['app']['url'] ?? 'https://flowone.pro') . '/boards/' . $board['id'];
            
            $htmlBody = $this->buildInviteEmailHtml($board['name'], $this->userEmail, $roleLabel, $boardUrl, $boardColor);
            
            $smtp->send([
                'from_name' => 'Board Collaboration',
                'to' => [$recipientEmail],
                'subject' => "You've been invited to collaborate on \"{$board['name']}\"",
                'body_html' => $htmlBody,
                'body_text' => "You've been invited to collaborate on the board \"{$board['name']}\" by {$this->userEmail}. You can {$roleLabel}. Open the board: {$boardUrl}"
            ]);
            
            error_log("Board invite email sent to {$recipientEmail} for board {$board['id']}");
        } catch (\Exception $e) {
            error_log("Failed to send board invite email: " . $e->getMessage());
        }
    }
    
    /**
     * Build HTML email for board invitation
     */
    private function buildInviteEmailHtml(string $boardName, string $inviterEmail, string $roleLabel, string $boardUrl, string $boardColor): string
    {
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; background-color: #f3f4f6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 520px; border-radius: 20px; overflow: hidden; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #1f2937; padding: 40px 32px; text-align: center;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <h1 style="color: white; margin: 0 0 8px 0; font-size: 28px; font-weight: 700; letter-spacing: -0.5px;">' . htmlspecialchars($boardName) . '</h1>
                                        <p style="color: rgba(255,255,255,0.7); margin: 0; font-size: 14px;">Board Invitation</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="background: white; padding: 36px 32px;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding-bottom: 24px;">
                                        <p style="font-size: 16px; color: #374151; margin: 0; line-height: 1.6;">
                                            <strong style="color: #111827;">' . htmlspecialchars($inviterEmail) . '</strong> has invited you to collaborate on this board.
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-bottom: 28px;">
                                        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f8fafc; border-radius: 12px; border-left: 4px solid ' . htmlspecialchars($boardColor) . ';">
                                            <tr>
                                                <td style="padding: 20px 24px;">
                                                    <p style="margin: 0; font-size: 14px; color: #6b7280; line-height: 1.5;">
                                                        Your role: <strong style="color: #111827;">' . ucfirst($roleLabel === 'edit, create, and move cards' ? 'Editor' : 'Viewer') . '</strong>
                                                    </p>
                                                    <p style="margin: 8px 0 0 0; font-size: 13px; color: #9ca3af;">
                                                        You can ' . htmlspecialchars($roleLabel) . '
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding-bottom: 24px;">
                                        <table cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="background: #1f2937; border-radius: 10px;">
                                                    <a href="' . htmlspecialchars($boardUrl) . '" style="display: inline-block; color: white; text-decoration: none; padding: 14px 40px; font-weight: 600; font-size: 15px;">
                                                        Open Board
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center">
                                        <p style="font-size: 13px; color: #9ca3af; margin: 0;">
                                            If you don\'t have an account, you\'ll be prompted to sign in first.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td align="center" style="background: #f9fafb; padding: 20px 32px; border-top: 1px solid #e5e7eb;">
                            <p style="color: #9ca3af; font-size: 12px; margin: 0;">
                                This invitation was sent from the Webmail Board System by <strong style="color: #6b7280;">Pixel Ranger Studio</strong>
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
     * Update member role
     */
    public function updateMember(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $memberEmail = $request->getParam('email');
        $role = $request->input('role');
        
        if (!in_array($role, ['editor', 'viewer'])) {
            return Response::error('Invalid role. Must be editor or viewer');
        }
        
        if (!$this->boardService->updateMemberRole($this->getActiveEmail(), $boardId, $memberEmail, $role)) {
            return Response::error('Failed to update member role');
        }
        
        return Response::success(null, 'Member role updated');
    }
    
    /**
     * Remove a member from a board
     */
    public function removeMember(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $memberEmail = $request->getParam('email');
        
        if (!$this->boardService->removeMember($this->getActiveEmail(), $boardId, $memberEmail)) {
            return Response::error('Failed to remove member');
        }
        
        return Response::success(null, 'Member removed');
    }
    
    // ========================================
    // LIST ENDPOINTS
    // ========================================
    
    /**
     * Get lists for a board
     */
    public function getLists(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId)) {
            return Response::error('Board not found or access denied', 404);
        }
        
        $includeArchived = $request->getQuery('include_archived', 'false') === 'true';
        $lists = $this->boardService->getLists($boardId, $includeArchived);
        
        return Response::success(['lists' => $lists]);
    }
    
    /**
     * Create a list
     */
    public function createList(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        
        $data = [
            'name' => $request->input('name'),
            'position' => $request->input('position'),
            'expected_amount' => $request->input('expected_amount'),
            'invoice_date' => $request->input('invoice_date'),
            'is_milestone' => $request->input('is_milestone'),
            'currency' => $request->input('currency'),
        ];
        
        $list = $this->boardService->createList($this->getActiveEmail(), $boardId, $data);
        
        if (!$list) {
            return Response::error('Failed to create list');
        }
        
        // Publish real-time event for WebSocket sync
        try {
            $cache = $this->getRedisCache();
            $cache->publishListUpdated($this->getActiveEmail(), $boardId, $list['id'] ?? 0, 'created');
        } catch (\Exception $e) {
            // Non-fatal
        }
        
        return Response::success(['list' => $list], 'List created');
    }
    
    /**
     * Update a list
     */
    public function updateList(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $listId = (int)$request->getParam('id');
        
        $data = [];
        if ($request->has('name')) $data['name'] = $request->input('name');
        if ($request->has('position')) $data['position'] = $request->input('position');
        if ($request->has('archived')) $data['archived'] = $request->input('archived');
        if ($request->has('expected_amount')) $data['expected_amount'] = $request->input('expected_amount');
        if ($request->has('invoice_date')) $data['invoice_date'] = $request->input('invoice_date');
        if ($request->has('is_milestone')) $data['is_milestone'] = $request->input('is_milestone');
        if ($request->has('currency')) $data['currency'] = $request->input('currency');
        if ($request->has('payment_status')) $data['payment_status'] = $request->input('payment_status');
        if ($request->has('collapsed')) $data['collapsed'] = $request->input('collapsed');
        if ($request->has('list_color')) $data['list_color'] = $request->input('list_color');
        
        $list = $this->boardService->updateList($this->getActiveEmail(), $listId, $data);
        
        if (!$list) {
            return Response::error('List not found or access denied', 404);
        }
        
        // Publish real-time event for WebSocket sync
        try {
            $cache = $this->getRedisCache();
            $cache->publishListUpdated($this->getActiveEmail(), $list['board_id'] ?? 0, $listId, 'updated');
        } catch (\Exception $e) {
            // Non-fatal
        }
        
        return Response::success(['list' => $list], 'List updated');
    }
    
    /**
     * Delete a list
     */
    public function deleteList(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $listId = (int)$request->getParam('id');
        
        // Get list info first for WebSocket notification
        $list = $this->boardService->getList($listId);
        $boardId = $list['board_id'] ?? 0;
        
        if (!$this->boardService->deleteList($this->getActiveEmail(), $listId)) {
            return Response::error('List not found or access denied', 404);
        }
        
        // Publish real-time event for WebSocket sync
        try {
            $cache = $this->getRedisCache();
            $cache->publishListUpdated($this->getActiveEmail(), $boardId, $listId, 'deleted');
        } catch (\Exception $e) {
            // Non-fatal
        }
        
        return Response::success(null, 'List deleted');
    }
    
    /**
     * Reorder lists
     */
    public function reorderLists(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->input('board_id');
        $listIds = $request->input('list_ids', []);
        
        if (empty($listIds) || !is_array($listIds)) {
            return Response::error('Invalid list_ids');
        }
        
        if (!$this->boardService->reorderLists($this->getActiveEmail(), $boardId, $listIds)) {
            return Response::error('Failed to reorder lists');
        }
        
        // Publish real-time event for WebSocket sync
        try {
            $cache = $this->getRedisCache();
            $cache->publishBoardUpdated($this->getActiveEmail(), $boardId, ['action' => 'lists_reordered']);
        } catch (\Exception $e) {
            // Non-fatal
        }
        
        return Response::success(null, 'Lists reordered');
    }
    
    // ========================================
    // CARD ENDPOINTS
    // ========================================
    
    /**
     * Get cards for a list
     */
    public function getCards(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $listId = (int)$request->getParam('list_id');
        
        // Access check is done via list -> board
        $includeArchived = $request->getQuery('include_archived', 'false') === 'true';
        $cards = $this->boardService->getCards($listId, $includeArchived);
        
        return Response::success(['cards' => $cards]);
    }
    
    /**
     * Get a single card
     */
    public function getCard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        try {
            $cardId = (int)$request->getParam('id');
            $card = $this->boardService->getCard($this->getActiveEmail(), $cardId);
            
            if (!$card) {
                return Response::error('Card not found or access denied', 404);
            }
            
            return Response::success(['card' => $card]);
        } catch (\Exception $e) {
            error_log("BoardController getCard error: " . $e->getMessage());
            return Response::error('Failed to get card: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Create a card
     */
    public function createCard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $listId = (int)$request->getParam('list_id');
        
        $data = [
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'position' => $request->input('position'),
            'due_date' => $request->input('due_date'),
            'start_date' => $request->input('start_date'),
            'assigned_to' => $request->input('assigned_to'),
        ];
        
        if (empty($data['title'])) {
            return Response::error('Card title is required');
        }
        
        $card = $this->boardService->createCard($this->getActiveEmail(), $listId, $data);
        
        if (!$card) {
            return Response::error('Failed to create card');
        }
        
        // Index for search
        $this->triggerCardIndex($card);
        
        return Response::success(['card' => $card], 'Card created');
    }
    
    /**
     * Update a card
     */
    public function updateCard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $cardId = (int)$request->getParam('id');
        
        $data = [];
        if ($request->has('title')) $data['title'] = $request->input('title');
        if ($request->has('description')) $data['description'] = $request->input('description');
        if ($request->has('position')) $data['position'] = $request->input('position');
        if ($request->has('due_date')) $data['due_date'] = $request->input('due_date');
        if ($request->has('start_date')) $data['start_date'] = $request->input('start_date');
        if ($request->has('completed')) $data['completed'] = $request->input('completed');
        if ($request->has('cover_color')) $data['cover_color'] = $request->input('cover_color');
        if ($request->has('card_color')) $data['card_color'] = $request->input('card_color');
        if ($request->has('assigned_to')) $data['assigned_to'] = $request->input('assigned_to');
        if ($request->has('archived')) $data['archived'] = $request->input('archived');
        
        try {
            $card = $this->boardService->updateCard($this->getActiveEmail(), $cardId, $data);
        } catch (\Throwable $e) {
            error_log("BoardController updateCard exception: " . $e->getMessage());
            return Response::error('Failed to update card: ' . $e->getMessage(), 500);
        }
        
        if (!$card) {
            return Response::error('Card not found or access denied', 404);
        }
        
        // Index for search
        $this->triggerCardIndex($card);
        
        // Publish real-time event for WebSocket sync
        try {
            $cache = $this->getRedisCache();
            $cache->publishCardUpdated($this->getActiveEmail(), $card['board_id'] ?? 0, $cardId, 'updated');
        } catch (\Throwable $e) {
            // Non-fatal
        }
        
        return Response::success(['card' => $card], 'Card updated');
    }
    
    /**
     * Move a card to a different list
     */
    public function moveCard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $cardId = (int)$request->getParam('id');
        $newListId = (int)$request->input('list_id');
        $position = $request->input('position');
        
        $card = $this->boardService->moveCard(
            $this->getActiveEmail(),
            $cardId,
            $newListId,
            $position !== null ? (int)$position : null
        );
        
        if (!$card) {
            return Response::error('Card not found or access denied', 404);
        }
        
        // Publish real-time event for WebSocket sync
        try {
            $cache = $this->getRedisCache();
            $cache->publishCardUpdated($this->getActiveEmail(), $card['board_id'] ?? 0, $cardId, 'moved');
        } catch (\Exception $e) {
            // Non-fatal
        }
        
        return Response::success(['card' => $card], 'Card moved');
    }
    
    /**
     * Delete a card
     */
    public function deleteCard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $cardId = (int)$request->getParam('id');
        
        // Get card info first for WebSocket notification
        $card = $this->boardService->getCard($this->getActiveEmail(), $cardId);
        $boardId = $card['board_id'] ?? 0;
        
        if (!$this->boardService->deleteCard($this->getActiveEmail(), $cardId)) {
            return Response::error('Card not found or access denied', 404);
        }
        
        // Publish real-time event for WebSocket sync
        try {
            $cache = $this->getRedisCache();
            $cache->publishCardUpdated($this->getActiveEmail(), $boardId, $cardId, 'deleted');
        } catch (\Exception $e) {
            // Non-fatal
        }
        
        return Response::success(null, 'Card deleted');
    }
    
    /**
     * Reorder cards
     */
    public function reorderCards(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $listId = (int)$request->input('list_id');
        $cardIds = $request->input('card_ids', []);
        
        if (empty($cardIds) || !is_array($cardIds)) {
            return Response::error('Invalid card_ids');
        }
        
        if (!$this->boardService->reorderCards($this->getActiveEmail(), $listId, $cardIds)) {
            return Response::error('Failed to reorder cards');
        }
        
        // Publish real-time event for WebSocket sync
        try {
            $list = $this->boardService->getList($listId);
            $boardId = $list['board_id'] ?? 0;
            $cache = $this->getRedisCache();
            $cache->publishBoardUpdated($this->getActiveEmail(), $boardId, ['action' => 'cards_reordered', 'list_id' => $listId]);
        } catch (\Exception $e) {
            // Non-fatal
        }
        
        return Response::success(null, 'Cards reordered');
    }
    
    // ========================================
    // SUBTASK ENDPOINTS
    // ========================================

    /**
     * GET /boards/cards/{id}/subtasks
     */
    public function getSubtasks(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $parentCardId = (int)$request->getParam('id');
        $subtasks = $this->boardService->getSubtasks($this->getActiveEmail(), $parentCardId);
        return Response::json(['subtasks' => $subtasks]);
    }

    /**
     * POST /boards/cards/{id}/subtasks
     */
    public function createSubtask(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $parentCardId = (int)$request->getParam('id');
        $parentCard = $this->boardService->getCard($this->getActiveEmail(), $parentCardId);
        if (!$parentCard) {
            return Response::error('Parent card not found or access denied', 404);
        }

        $subtask = $this->boardService->createSubtask($this->getActiveEmail(), $parentCardId, [
            'title' => $request->input('title') ?? 'New Subtask',
            'description' => $request->input('description'),
            'assigned_to' => $request->input('assigned_to'),
            'due_date' => $request->input('due_date'),
        ]);

        if (!$subtask) {
            return Response::error('Failed to create subtask', 500);
        }

        try {
            $boardId = $parentCard['board_id'] ?? 0;
            $cache = $this->getRedisCache();
            $cache->publishCardUpdated($this->getActiveEmail(), $boardId, $parentCardId, 'subtask_created');
        } catch (\Exception $e) {
            // Non-fatal
        }

        return Response::json($subtask, 201);
    }

    /**
     * Batched subtask create. Accepts up to 100 rows in a single
     * request -- one parent lookup, one INSERT, one cache publish.
     * Replaces the per-line POST loop in SubtasksList::handlePaste.
     *
     * Body: { rows: [{ title, description?, due_date?, assigned_to? }, ...] }
     * POST /boards/cards/{id}/subtasks/batch
     */
    public function createSubtasksBatch(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $parentCardId = (int)$request->getParam('id');
        $rows = (array)$request->input('rows', []);
        if (empty($rows)) {
            return Response::error('rows array required', 400);
        }
        if (count($rows) > 100) {
            $rows = array_slice($rows, 0, 100);
        }

        $parentCard = $this->boardService->getCard($this->getActiveEmail(), $parentCardId);
        if (!$parentCard) {
            return Response::error('Parent card not found or access denied', 404);
        }

        $r = $this->boardService->createSubtasksBatch($this->getActiveEmail(), $parentCardId, $rows);

        // ONE pubsub event per batch, not per subtask.
        if ($r['success'] > 0) {
            try {
                $boardId = $parentCard['board_id'] ?? 0;
                $cache = $this->getRedisCache();
                $cache->publishCardUpdated($this->getActiveEmail(), $boardId, $parentCardId, 'subtask_created');
            } catch (\Exception $e) {
                // Non-fatal
            }
        }

        return Response::json([
            'success' => $r['success'],
            'failed' => $r['failed'],
            'subtasks' => $r['subtasks'],
        ], 201);
    }

    // ========================================
    // LABEL ENDPOINTS
    // ========================================
    
    /**
     * Get labels for a board
     */
    public function getLabels(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('board_id');
        
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId)) {
            return Response::error('Board not found or access denied', 404);
        }
        
        $labels = $this->boardService->getLabels($boardId);
        
        return Response::success(['labels' => $labels]);
    }
    
    /**
     * Create a label
     */
    public function createLabel(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('board_id');
        
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId, 'editor')) {
            return Response::error('Board not found or access denied', 404);
        }
        
        $data = [
            'name' => $request->input('name'),
            'color' => $request->input('color'),
        ];
        
        $label = $this->boardService->createLabel($boardId, $data);
        
        if (!$label) {
            return Response::error('Failed to create label');
        }
        
        return Response::success(['label' => $label], 'Label created');
    }
    
    /**
     * Update a label
     */
    public function updateLabel(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $labelId = (int)$request->getParam('id');
        
        $data = [];
        if ($request->has('name')) $data['name'] = $request->input('name');
        if ($request->has('color')) $data['color'] = $request->input('color');
        if ($request->has('is_type')) $data['is_type'] = (int)$request->input('is_type');

        $label = $this->boardService->updateLabel($this->getActiveEmail(), $labelId, $data);
        
        if (!$label) {
            return Response::error('Label not found or access denied', 404);
        }
        
        return Response::success(['label' => $label], 'Label updated');
    }
    
    /**
     * Delete a label
     */
    public function deleteLabel(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $labelId = (int)$request->getParam('id');
        
        if (!$this->boardService->deleteLabel($this->getActiveEmail(), $labelId)) {
            return Response::error('Label not found or access denied', 404);
        }
        
        return Response::success(null, 'Label deleted');
    }
    
    /**
     * Add label to card
     */
    public function addCardLabel(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $cardId = (int)$request->getParam('card_id');
        $labelId = (int)$request->input('label_id');
        
        if (!$this->boardService->addLabelToCard($this->getActiveEmail(), $cardId, $labelId)) {
            return Response::error('Failed to add label');
        }
        
        return Response::success(null, 'Label added');
    }
    
    /**
     * Remove label from card
     */
    public function removeCardLabel(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $cardId = (int)$request->getParam('card_id');
        $labelId = (int)$request->getParam('label_id');
        
        if (!$this->boardService->removeLabelFromCard($this->getActiveEmail(), $cardId, $labelId)) {
            return Response::error('Failed to remove label');
        }
        
        return Response::success(null, 'Label removed');
    }
    
    // ========================================
    // CHECKLIST ENDPOINTS
    // ========================================
    
    /**
     * Get checklists for a card
     */
    public function getChecklists(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $cardId = (int)$request->getParam('card_id');
        $checklists = $this->boardService->getChecklists($cardId);
        
        return Response::success(['checklists' => $checklists]);
    }
    
    /**
     * Create a checklist
     */
    public function createChecklist(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $cardId = (int)$request->getParam('card_id');
        
        $data = [
            'title' => $request->input('title', 'Checklist'),
        ];
        
        $checklist = $this->boardService->createChecklist($this->getActiveEmail(), $cardId, $data);
        
        if (!$checklist) {
            return Response::error('Failed to create checklist');
        }
        
        return Response::success(['checklist' => $checklist], 'Checklist created');
    }
    
    /**
     * Update a checklist
     */
    public function updateChecklist(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $checklistId = (int)$request->getParam('id');
        
        $data = [];
        if ($request->has('title')) $data['title'] = $request->input('title');
        if ($request->has('position')) $data['position'] = $request->input('position');
        
        $checklist = $this->boardService->updateChecklist($this->getActiveEmail(), $checklistId, $data);
        
        if (!$checklist) {
            return Response::error('Checklist not found or access denied', 404);
        }
        
        return Response::success(['checklist' => $checklist], 'Checklist updated');
    }
    
    /**
     * Delete a checklist
     */
    public function deleteChecklist(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $checklistId = (int)$request->getParam('id');
        
        if (!$this->boardService->deleteChecklist($this->getActiveEmail(), $checklistId)) {
            return Response::error('Checklist not found or access denied', 404);
        }
        
        return Response::success(null, 'Checklist deleted');
    }
    
    /**
     * Add item to checklist
     */
    public function addChecklistItem(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $checklistId = (int)$request->getParam('checklist_id');
        
        $data = [
            'title' => $request->input('title'),
        ];
        
        if (empty($data['title'])) {
            return Response::error('Item title is required');
        }
        
        $item = $this->boardService->addChecklistItem($this->getActiveEmail(), $checklistId, $data);
        
        if (!$item) {
            return Response::error('Failed to add item');
        }
        
        return Response::success(['item' => $item], 'Item added');
    }
    
    /**
     * Update checklist item
     */
    public function updateChecklistItem(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $itemId = (int)$request->getParam('id');
        
        $data = [];
        if ($request->has('title')) $data['title'] = $request->input('title');
        if ($request->has('completed')) $data['completed'] = $request->input('completed');
        if ($request->has('position')) $data['position'] = $request->input('position');
        if ($request->has('drive_file_id')) $data['drive_file_id'] = $request->input('drive_file_id');
        if ($request->has('assigned_to')) $data['assigned_to'] = $request->input('assigned_to');

        $item = $this->boardService->updateChecklistItem($this->getActiveEmail(), $itemId, $data);
        
        if (!$item) {
            return Response::error('Item not found or access denied', 404);
        }
        
        // Publish real-time event for WebSocket sync
        try {
            $cache = $this->getRedisCache();
            $completed = isset($data['completed']) ? (bool)$data['completed'] : null;
            $cache->publishChecklistUpdated($this->getActiveEmail(), $item['card_id'] ?? 0, $itemId, 'updated', $completed);
        } catch (\Exception $e) {
            // Non-fatal
        }
        
        return Response::success(['item' => $item], 'Item updated');
    }
    
    /**
     * Delete checklist item
     */
    public function deleteChecklistItem(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $itemId = (int)$request->getParam('id');
        
        if (!$this->boardService->deleteChecklistItem($this->getActiveEmail(), $itemId)) {
            return Response::error('Item not found or access denied', 404);
        }
        
        return Response::success(null, 'Item deleted');
    }
    
    // ========================================
    // ATTACHMENT ENDPOINTS
    // ========================================
    
    /**
     * Get attachments for a card
     */
    public function getAttachments(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $cardId = (int)$request->getParam('card_id');
        $attachments = $this->boardService->getAttachments($cardId);
        
        return Response::success(['attachments' => $attachments]);
    }
    
    /**
     * Upload attachment to card
     */
    public function uploadAttachment(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $cardId = (int)$request->getParam('card_id');
        
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return Response::error('No file uploaded or upload error');
        }
        
        // Get card's board to find/create the drive folder
        $card = $this->boardService->getCard($this->getActiveEmail(), $cardId);
        if (!$card) {
            return Response::error('Card not found or access denied', 404);
        }
        
        // Get board name for folder structure
        $board = $this->boardService->getBoard($this->getActiveEmail(), $card['board_id']);
        if (!$board) {
            return Response::error('Board not found', 404);
        }
        
        // Upload to Drive
        $drive = $this->getDriveService();
        
        // Get or create board folder: Boards / [Board Name]
        $boardFolder = $drive->getOrCreateBoardFolder($this->getActiveEmail(), $board['name']);
        if (!$boardFolder) {
            return Response::error('Failed to create board folder');
        }
        
        try {
            $file = $drive->uploadFile(
                $this->getActiveEmail(),
                $_FILES['file'],
                $boardFolder['id']
            );
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }
        
        if (!$file) {
            return Response::error('Failed to upload file');
        }
        
        // Link to card
        $folderId = $request->input('folder_id') ? (int)$request->input('folder_id') : null;
        $attachment = $this->boardService->addAttachment(
            $this->getActiveEmail(),
            $cardId,
            $file['id'],
            $_FILES['file']['name'],
            $folderId
        );
        
        if (!$attachment) {
            return Response::error('Failed to create attachment');
        }
        
        return Response::success(['attachment' => $attachment], 'Attachment uploaded');
    }
    
    /**
     * Add URL attachment to card
     */
    public function addUrlAttachment(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $cardId = (int)$request->getParam('card_id');
        $url = $request->input('url');
        $name = $request->input('name', $url);
        
        if (empty($url)) {
            return Response::error('URL is required');
        }
        
        $attachment = $this->boardService->addUrlAttachment(
            $this->getActiveEmail(),
            $cardId,
            $url,
            $name
        );
        
        if (!$attachment) {
            return Response::error('Failed to add attachment');
        }
        
        return Response::success(['attachment' => $attachment], 'Attachment added');
    }
    
    /**
     * Add Drive file as attachment to card
     */
    public function addDriveAttachment(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $cardId = (int)$request->getParam('card_id');
        $driveFileId = (int)$request->input('drive_file_id');
        $fileName = $request->input('file_name', 'Drive file');
        $folderId = $request->input('folder_id') ? (int)$request->input('folder_id') : null;

        if (empty($driveFileId)) {
            return Response::error('Drive file ID is required');
        }

        try {
            $attachment = $this->boardService->addDriveAttachment(
                $this->getActiveEmail(),
                $cardId,
                $driveFileId,
                $fileName,
                $folderId
            );

            if (!$attachment) {
                return Response::error('Failed to add Drive attachment');
            }

            return Response::success(['attachment' => $attachment], 'Attachment added from Drive');
        } catch (\Throwable $e) {
            error_log('addDriveAttachment error: ' . $e->getMessage());
            return Response::error('Failed to add Drive attachment: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Delete attachment
     */
    public function deleteAttachment(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $attachmentId = (int)$request->getParam('id');
        
        if (!$this->boardService->deleteAttachment($this->getActiveEmail(), $attachmentId)) {
            return Response::error('Attachment not found or access denied', 404);
        }
        
        return Response::success(null, 'Attachment deleted');
    }
    
    /**
     * Set attachment as card cover
     */
    public function setAttachmentCover(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $cardId = (int)$request->getParam('card_id');
        $attachmentId = (int)$request->input('attachment_id');
        
        if (!$this->boardService->setAttachmentAsCover($this->getActiveEmail(), $cardId, $attachmentId)) {
            return Response::error('Failed to set cover');
        }
        
        return Response::success(null, 'Cover set');
    }
    
    // ========================================
    // CARD ASSET FOLDER ENDPOINTS
    // ========================================

    public function getAssetFolders(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $cardId = (int)$request->getParam('card_id');
        $service = new \Webmail\Addons\KanbanBoards\Services\CardAssetFolderService($this->config);
        $folders = $service->getFolders($cardId);

        return Response::success(['folders' => $folders]);
    }

    public function createAssetFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $cardId = (int)$request->getParam('card_id');
        $name = trim($request->input('name', ''));
        $parentId = $request->input('parent_id') ? (int)$request->input('parent_id') : null;
        $syncDrive = (bool)$request->input('sync_drive', false);

        if (!$name) return Response::error('Folder name is required');

        $service = new \Webmail\Addons\KanbanBoards\Services\CardAssetFolderService($this->config);

        if (!$parentId) {
            $folder = $service->getOrCreateByName($cardId, $name, $this->getActiveEmail());
        } else {
            $folder = $service->createFolder($cardId, $name, $parentId, $this->getActiveEmail());
        }

        if (!$folder) return Response::error('Failed to create folder');

        if ($syncDrive) {
            $driveFolderId = $service->ensureDriveFolder($folder['id'], $this->getActiveEmail());
            if ($driveFolderId) $folder['drive_folder_id'] = $driveFolderId;
        }

        return Response::success(['folder' => $folder]);
    }

    public function renameAssetFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $folderId = (int)$request->getParam('id');
        $name = trim($request->input('name', ''));
        if (!$name) return Response::error('Folder name is required');

        $service = new \Webmail\Addons\KanbanBoards\Services\CardAssetFolderService($this->config);
        $service->renameFolder($folderId, $name);

        return Response::success(null, 'Folder renamed');
    }

    public function deleteAssetFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $folderId = (int)$request->getParam('id');
        $service = new \Webmail\Addons\KanbanBoards\Services\CardAssetFolderService($this->config);
        $service->deleteFolder($folderId);

        return Response::success(null, 'Folder deleted');
    }

    public function moveAttachmentToFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $attachmentId = (int)$request->getParam('id');
        $folderId = $request->input('folder_id') !== null ? (int)$request->input('folder_id') : null;

        $service = new \Webmail\Addons\KanbanBoards\Services\CardAssetFolderService($this->config);
        $service->moveAttachment($attachmentId, $folderId ?: null);

        return Response::success(null, 'Attachment moved');
    }

    // ========================================
    // COMMENT ENDPOINTS
    // ========================================
    
    /**
     * Get comments for a card
     */
    public function getComments(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $cardId = (int)$request->getParam('card_id');
        $comments = $this->boardService->getComments($cardId);
        
        return Response::success(['comments' => $comments]);
    }
    
    /**
     * Add comment to card
     */
    public function addComment(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $cardId = (int)$request->getParam('card_id');
        $content = $request->input('content') ?? '';
        $parentCommentId = $request->input('parent_comment_id') ? (int)$request->input('parent_comment_id') : null;
        
        if (empty(trim(strip_tags($content)))) {
            $content = $content ?: '(attachment)';
        }
        
        $mentions = $request->input('mentions');
        $structured = null;
        if (is_array($mentions)) {
            $structured = $mentions;
        } elseif (is_string($mentions) && $mentions !== '') {
            $decoded = json_decode($mentions, true);
            $structured = is_array($decoded) ? $decoded : null;
        }

        $comment = $this->boardService->addComment($this->getActiveEmail(), $cardId, $content, $parentCommentId, $structured);
        
        if (!$comment) {
            return Response::error('Failed to add comment');
        }
        
        return Response::success(['comment' => $comment], 'Comment added');
    }
    
    /**
     * Update comment
     */
    public function updateComment(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $commentId = (int)$request->getParam('id');
        $content = $request->input('content');
        $parentCommentId = $request->input('parent_comment_id') ? (int)$request->input('parent_comment_id') : null;
        $mentions = $request->input('mentions');
        
        if (empty($content)) {
            return Response::error('Comment content is required');
        }
        
        $comment = $this->boardService->updateComment($this->getActiveEmail(), $commentId, $content, $parentCommentId, $mentions);
        
        if (!$comment) {
            return Response::error('Comment not found or not authorized', 404);
        }
        
        return Response::success(['comment' => $comment], 'Comment updated');
    }
    
    /**
     * Delete comment
     */
    public function deleteComment(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $commentId = (int)$request->getParam('id');
        
        if (!$this->boardService->deleteComment($this->getActiveEmail(), $commentId)) {
            return Response::error('Comment not found or not authorized', 404);
        }
        
        return Response::success(null, 'Comment deleted');
    }
    
    // ========================================
    // SEARCH & FILTER ENDPOINTS
    // ========================================
    
    /**
     * Search cards
     */
    public function searchCards(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $query = $request->getQuery('q', '');
        $boardId = $request->getQuery('board_id') ? (int)$request->getQuery('board_id') : null;
        
        if (empty($query)) {
            return Response::success(['cards' => []]);
        }
        
        $cards = $this->boardService->searchCards($this->getActiveEmail(), $query, $boardId);
        
        return Response::success(['cards' => $cards]);
    }
    
    /**
     * Get cards by due date
     */
    public function getCardsByDueDate(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $startDate = $request->getQuery('start_date');
        $endDate = $request->getQuery('end_date');
        $boardId = $request->getQuery('board_id') ? (int)$request->getQuery('board_id') : null;
        
        if (empty($startDate) || empty($endDate)) {
            return Response::error('start_date and end_date are required');
        }
        
        $cards = $this->boardService->getCardsByDueDate(
            $this->getActiveEmail(),
            $startDate,
            $endDate,
            $boardId
        );
        
        return Response::success(['cards' => $cards]);
    }
    
    /**
     * Get cards assigned to me
     */
    public function getAssignedCards(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = $request->getQuery('board_id') ? (int)$request->getQuery('board_id') : null;
        
        $cards = $this->boardService->getAssignedCards($this->getActiveEmail(), $boardId);
        
        return Response::success(['cards' => $cards]);
    }
    
    /**
     * Get card activity
     */
    public function getActivity(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $cardId = (int)$request->getParam('card_id');
        $limit = (int)$request->getQuery('limit', 50);
        
        $activity = $this->boardService->getActivity($cardId, $limit);
        
        return Response::success(['activity' => $activity]);
    }
    
    // ========================================
    // EMAIL-BOARD LINKING ENDPOINTS
    // ========================================
    
    /**
     * Link an email to a board
     */
    public function linkEmail(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('board_id');
        
        // Get raw input for debugging
        $rawUid = $request->input('email_uid');
        $rawFolder = $request->input('email_folder');
        
        $emailData = [
            'uid' => $rawUid !== null ? (int)$rawUid : 0,
            'folder' => $rawFolder ?? '',
            'subject' => $request->input('email_subject'),
            'from' => $request->input('email_from'),
            'thread_id' => $request->input('thread_id')
        ];
        
        // Validate required fields
        if ($emailData['uid'] <= 0) {
            return Response::error('email_uid is required and must be a positive number (received: ' . json_encode($rawUid) . ')');
        }
        
        if (empty($emailData['folder'])) {
            return Response::error('email_folder is required (received: ' . json_encode($rawFolder) . ')');
        }
        
        try {
            $link = $this->boardService->linkEmailToBoard($this->getActiveEmail(), $boardId, $emailData);
            
            if (!$link) {
                // Check specific failure reason
                $logFile = dirname(__DIR__, 4) . '/storage/boards.log';
                $lastLines = file_exists($logFile) ? array_slice(file($logFile), -5) : [];
                $debugInfo = implode(' | ', array_map('trim', $lastLines));
                return Response::error('Failed to link email. Debug: ' . $debugInfo);
            }
            
            // Also link the client (by email domain) to this board
            if (!empty($emailData['from'])) {
                $this->linkClientToBoard($this->getActiveEmail(), $boardId, $emailData['from']);
            }
            
            return Response::success(['link' => $link], 'Email linked to board');
        } catch (\Exception $e) {
            return Response::error('Exception: ' . $e->getMessage());
        }
    }
    
    /**
     * Link a client (by email domain) to a board
     */
    private function linkClientToBoard(string $userEmail, int $boardId, string $fromEmail): void
    {
        try {
            $clientService = new \Webmail\Services\ClientService($this->config);
            
            // Extract the email address from "Name <email@domain.com>" format
            $emailAddress = $fromEmail;
            if (preg_match('/<([^>]+)>/', $fromEmail, $matches)) {
                $emailAddress = $matches[1];
            }
            
            // Get or create the client for this email
            $client = $clientService->getOrCreateClient($userEmail, $emailAddress);
            
            if ($client) {
                // Link the board to the client
                $clientService->linkBoard($client['id'], $boardId);
            }
        } catch (\Exception $e) {
            // Non-critical error, just log it
            error_log("BoardController linkClientToBoard error: " . $e->getMessage());
        }
    }
    
    /**
     * Unlink an email from a board
     */
    public function unlinkEmail(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $linkId = (int)$request->getParam('link_id');
        
        if (!$this->boardService->unlinkEmailFromBoard($this->getActiveEmail(), $linkId)) {
            return Response::error('Link not found or access denied', 404);
        }
        
        return Response::success(null, 'Email unlinked from board');
    }
    
    /**
     * Get emails linked to a board
     */
    public function getBoardEmails(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('board_id');
        
        $emails = $this->boardService->getBoardEmails($this->getActiveEmail(), $boardId);
        
        return Response::success(['emails' => $emails]);
    }
    
    /**
     * Update email link location (when email is moved to different folder)
     * PUT /boards/{board_id}/email-link
     */
    public function updateEmailLinkLocation(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('board_id');
        $oldUid = (int)$request->input('old_uid');
        $oldFolder = $request->input('old_folder');
        $newUid = (int)$request->input('new_uid');
        $newFolder = $request->input('new_folder');
        
        if ($oldUid <= 0 || empty($oldFolder) || $newUid <= 0 || empty($newFolder)) {
            return Response::error('old_uid, old_folder, new_uid, and new_folder are required');
        }
        
        $updated = $this->boardService->updateEmailLinkLocation(
            $this->getActiveEmail(),
            $boardId,
            $oldUid,
            $oldFolder,
            $newUid,
            $newFolder
        );
        
        if (!$updated) {
            return Response::error('Email link not found or access denied', 404);
        }
        
        return Response::success(null, 'Email link location updated');
    }
    
    /**
     * Check if an email is linked to any board
     */
    public function getEmailBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $emailUid = (int)$request->getQuery('uid');
        $folder = $request->getQuery('folder');
        
        if (empty($emailUid) || empty($folder)) {
            return Response::error('uid and folder are required');
        }
        
        $board = $this->boardService->getBoardByEmail($this->getActiveEmail(), $emailUid, $folder);
        
        return Response::success(['board' => $board]);
    }
    
    /**
     * Batch fetch board links for multiple emails
     */
    public function getEmailBoardsBatch(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $emails = $request->input('emails', []);
        
        error_log("getEmailBoardsBatch: user=" . $this->getActiveEmail() . ", emails=" . json_encode($emails));
        
        if (empty($emails)) {
            return Response::success(['links' => []]);
        }
        
        $links = $this->boardService->getBoardsByEmailsBatch($this->getActiveEmail(), $emails);
        
        error_log("getEmailBoardsBatch: found " . count($links) . " links: " . json_encode($links));
        
        return Response::success(['links' => $links]);
    }
    
    /**
     * Get boards by thread ID
     */
    public function getBoardsByThread(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $threadId = $request->getQuery('thread_id');
        
        if (empty($threadId)) {
            return Response::success(['boards' => []]);
        }
        
        $boards = $this->boardService->getBoardsByThread($this->getActiveEmail(), $threadId);
        
        return Response::success(['boards' => $boards]);
    }
    
    // ========================================
    // PROGRESS REPORT ENDPOINTS
    // ========================================
    
    /**
     * Get progress since last report
     */
    public function getProgress(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('board_id');
        
        $progress = $this->boardService->getProgressSinceLastReport($this->getActiveEmail(), $boardId);
        
        return Response::success(['progress' => $progress]);
    }
    
    /**
     * Generate progress report preview (HTML)
     */
    public function generateProgressReport(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('board_id');
        $userEmail = $this->getActiveEmail();
        
        try {
            $html = $this->boardService->generateProgressReportHtml($userEmail, $boardId);
            
            // Always return HTML, even if empty (the HTML itself contains the "no updates" message)
            return Response::success(['html' => $html]);
        } catch (\Exception $e) {
            error_log("generateProgressReport ERROR: " . $e->getMessage());
            return Response::error('Failed to generate report: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Send progress report email
     */
    public function sendProgressReport(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('board_id');
        $recipients = $request->input('recipients'); // Comma-separated emails
        $subject = $request->input('subject');
        
        if (empty($recipients)) {
            return Response::error('Recipients are required');
        }
        
        // Generate HTML content
        $html = $this->boardService->generateProgressReportHtml($this->getActiveEmail(), $boardId);
        
        if (empty($html)) {
            return Response::error('Board not found or access denied', 404);
        }
        
        // Get board for default subject
        $board = $this->boardService->getBoard($this->getActiveEmail(), $boardId);
        if (!$subject) {
            $subject = 'Progress Update: ' . $board['name'];
        }
        
        // Get progress to save card IDs
        $progress = $this->boardService->getProgressSinceLastReport($this->getActiveEmail(), $boardId);
        $cardIds = array_column($progress['cards'], 'id');
        
        // Send email via SMTP service
        $recipientList = array_map('trim', explode(',', $recipients));
        
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
                    return Response::error('Failed to get OAuth access token for sending email.');
                }
                
                $smtp = new SmtpService($smtpConfig);
                $smtp->setOAuthCredentials($this->userEmail, $accessToken, $this->oauthProvider);
            } elseif ($this->userPassword && $this->userEmail) {
                // Password-based authentication
                $smtp = new SmtpService($this->config['smtp']);
                $smtp->setCredentials($this->userEmail, $this->userPassword);
            } else {
                error_log("sendProgressReport: No SMTP credentials - user needs to re-login");
                return Response::error('Your session has expired. Please log out and log back in to send emails.', 401);
            }
            
            $smtp->send([
                'from_name' => 'Progress Report',
                'to' => $recipientList,
                'subject' => $subject,
                'body_html' => $html,
                'body_text' => strip_tags($html)
            ]);
            
            // Save report record
            $this->boardService->saveProgressReport(
                $this->getActiveEmail(),
                $boardId,
                $recipients,
                $subject,
                $html,
                $cardIds
            );
            
            // Update client activity for progress report recipients (status becomes "waiting")
            try {
                $clientService = new \Webmail\Services\ClientService($this->config);
                foreach ($recipientList as $recipientEmail) {
                    if (!empty($recipientEmail)) {
                        $clientService->updateActivity($this->getActiveEmail(), $recipientEmail, 'outbound');
                    }
                }
            } catch (\Exception $e) {
                error_log("Client activity update error (progress report): " . $e->getMessage());
            }
            
            return Response::success(null, 'Progress report sent');
        } catch (\Exception $e) {
            error_log("sendProgressReport error: " . $e->getMessage());
            return Response::error('Failed to send email: ' . $e->getMessage());
        }
    }
    
    /**
     * Get progress report history
     */
    public function getProgressReportHistory(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('board_id');
        
        $history = $this->boardService->getProgressReportHistory($this->getActiveEmail(), $boardId);
        
        return Response::success(['history' => $history]);
    }
    
    /**
     * Get financial summary for a board (milestones with expected amounts)
     */
    public function getFinancials(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('board_id');
        $paymentTerms = $request->getQuery('payment_terms_days');
        
        $financials = $this->boardService->getBoardFinancials(
            $this->getActiveEmail(), 
            $boardId,
            $paymentTerms ? (int)$paymentTerms : null
        );
        
        if (empty($financials)) {
            return Response::error('Board not found or access denied', 404);
        }
        
        return Response::success(['financials' => $financials]);
    }
    
    /**
     * Get all financials across all boards (Global Financial Overview)
     */
    public function getAllFinancials(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $dateFrom = $request->getQuery('date_from');
        $dateTo = $request->getQuery('date_to');
        
        $financials = $this->boardService->getAllFinancials(
            $this->getActiveEmail(),
            $dateFrom,
            $dateTo
        );
        
        return Response::success(['financials' => $financials]);
    }
    
    /**
     * Update member financial permission
     */
    public function updateMemberFinancialPermission(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $memberEmail = $request->input('member_email');
        $canViewFinancials = (bool)$request->input('can_view_financials');
        
        if (!$memberEmail) {
            return Response::error('Member email is required', 400);
        }
        
        $success = $this->boardService->updateMemberFinancialPermission(
            $this->getActiveEmail(),
            $boardId,
            $memberEmail,
            $canViewFinancials
        );
        
        if (!$success) {
            return Response::error('Failed to update permission. Only board owner can change financial permissions.', 403);
        }
        
        return Response::success([], 'Permission updated');
    }
    
    /**
     * Check if current user can view financials for a board
     */
    public function canViewFinancials(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        
        $canView = $this->boardService->canViewFinancials(
            $this->getActiveEmail(),
            $boardId
        );
        
        return Response::success(['can_view_financials' => $canView]);
    }
    
    /**
     * Check board database info (debug/admin tool)
     * GET /api/boards/{id}/check
     */
    public function checkBoard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        
        // Only owner can check board
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId, 'owner')) {
            return Response::error('Only board owner can run board check', 403);
        }
        
        try {
            $db = $this->boardService->getDb();
            $result = [];
            
            // 1. Board basic info
            $stmt = $db->prepare("SELECT * FROM webmail_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $board = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$board) {
                return Response::error('Board not found', 404);
            }
            
            // Check for drive folder via drive_folders.board_id if not set directly
            $driveFolderId = $board['drive_folder_id'];
            if (!$driveFolderId) {
                $stmt = $db->prepare("SELECT id FROM drive_folders WHERE board_id = ?");
                $stmt->execute([$boardId]);
                $linkedFolder = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($linkedFolder) {
                    $driveFolderId = $linkedFolder['id'];
                }
            }
            
            $result['board'] = [
                'id' => $board['id'],
                'name' => $board['name'],
                'owner_email' => $board['owner_email'],
                'client_id' => $board['client_id'],
                'drive_folder_id' => $driveFolderId,
                'drive_folder_id_direct' => $board['drive_folder_id'], // Original from webmail_boards
                'created_at' => $board['created_at'],
            ];
            
            // 2. Board members
            $stmt = $db->prepare("
                SELECT user_email, role, can_view_financials, can_view_client, can_view_contacts, 
                       can_view_emails, can_access_drive, drive_folder_id, drive_permission, created_at
                FROM webmail_board_members 
                WHERE board_id = ?
            ");
            $stmt->execute([$boardId]);
            $result['members'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // 3. Linked clients (via client_boards)
            $stmt = $db->prepare("
                SELECT c.id, c.domain, c.display_name, c.status, cb.linked_at
                FROM client_boards cb
                JOIN clients c ON c.id = cb.client_id
                WHERE cb.board_id = ?
            ");
            $stmt->execute([$boardId]);
            $result['linked_clients'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // 4. Direct client association (board.client_id)
            if ($board['client_id']) {
                $stmt = $db->prepare("SELECT id, domain, display_name, status FROM clients WHERE id = ?");
                $stmt->execute([$board['client_id']]);
                $result['direct_client'] = $stmt->fetch(\PDO::FETCH_ASSOC);
            }
            
            // 5. Tracked URLs
            $stmt = $db->prepare("
                SELECT id, url_domain, display_name, is_active, client_id, created_at
                FROM board_tracked_urls 
                WHERE board_id = ?
                ORDER BY url_domain
            ");
            $stmt->execute([$boardId]);
            $result['tracked_urls'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // 6. Drive folder info
            // Check both webmail_boards.drive_folder_id AND drive_folders.board_id
            $folder = null;
            $folderSource = null;
            
            if ($board['drive_folder_id']) {
                // Try direct link from board table
                $stmt = $db->prepare("
                    SELECT id, name, parent_id, user_email, board_id, created_at
                    FROM drive_folders 
                    WHERE id = ?
                ");
                $stmt->execute([$board['drive_folder_id']]);
                $folder = $stmt->fetch(\PDO::FETCH_ASSOC);
                $folderSource = 'webmail_boards.drive_folder_id';
            }
            
            if (!$folder) {
                // Fallback: check drive_folders.board_id
                $stmt = $db->prepare("
                    SELECT id, name, parent_id, user_email, board_id, created_at
                    FROM drive_folders 
                    WHERE board_id = ?
                ");
                $stmt->execute([$boardId]);
                $folder = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($folder) {
                    $folderSource = 'drive_folders.board_id';
                }
            }
            
            if ($folder) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM drive_folders WHERE parent_id = ?");
                $stmt->execute([$folder['id']]);
                $subfolderCount = $stmt->fetchColumn();
                
                $stmt = $db->prepare("SELECT COUNT(*) FROM drive_files WHERE folder_id = ?");
                $stmt->execute([$folder['id']]);
                $fileCount = $stmt->fetchColumn();
                
                $result['drive_folder'] = [
                    'id' => $folder['id'],
                    'name' => $folder['name'],
                    'parent_id' => $folder['parent_id'],
                    'owner' => $folder['user_email'],
                    'board_id_on_folder' => $folder['board_id'],
                    'subfolders' => (int)$subfolderCount,
                    'files' => (int)$fileCount,
                    'link_source' => $folderSource,
                ];
            }
            
            // 7. Linked emails count
            $stmt = $db->prepare("SELECT COUNT(*) FROM webmail_board_emails WHERE board_id = ?");
            $stmt->execute([$boardId]);
            $result['linked_emails_count'] = (int)$stmt->fetchColumn();
            
            return Response::success($result);
            
        } catch (\Exception $e) {
            error_log("BoardController checkBoard error: " . $e->getMessage());
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Get milestone progress (for timeline visualization)
     */
    public function getMilestoneProgress(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $listId = (int)$request->getParam('list_id');
        
        $progress = $this->boardService->getMilestoneProgress($listId);
        
        return Response::success(['progress' => $progress]);
    }
    
    // ===== BOARD DRIVE INTEGRATION =====
    
    /**
     * Get, create, or link a Drive folder for a board
     * POST /api/boards/{id}/drive-folder
     * Optional body: { folder_id: int } - link existing folder instead of creating
     */
    public function getOrCreateDriveFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $folderId = $request->input('folder_id'); // Check if linking existing folder
        
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId, 'owner')) {
            return Response::error('Only board owner can manage Drive folder', 403);
        }
        
        // If folder_id provided, link that existing folder to the board
        if ($folderId) {
            $folder = $this->boardService->linkExistingFolderToBoard($this->getActiveEmail(), $boardId, (int)$folderId);
            
            if (!$folder) {
                return Response::error('Failed to link folder - folder not found or access denied');
            }
            
            return Response::success(['folder' => $folder], 'Folder linked to board');
        }
        
        // Otherwise create a new folder
        $folder = $this->boardService->getOrCreateBoardDriveFolder($this->getActiveEmail(), $boardId);
        
        if (!$folder) {
            return Response::error('Failed to create Drive folder');
        }
        
        return Response::success(['folder' => $folder], 'Board Drive folder ready');
    }
    
    /**
     * Get board's Drive folder info
     * GET /api/boards/{id}/drive-folder
     */
    public function getDriveFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId)) {
            return Response::error('Board not found or access denied', 404);
        }
        
        $folder = $this->boardService->getBoardDriveFolder($boardId);
        
        // If folder link exists, verify folder still exists in drive_folders table
        if ($folder) {
            $drive = $this->getDriveService();
            $actualFolder = $drive->getFolder($this->getActiveEmail(), $folder['id']);
            
            if (!$actualFolder) {
                // Folder link exists but folder was deleted - return missing status
                return Response::success([
                    'folder' => null,
                    'folder_missing' => true,
                    'missing_folder_id' => $folder['id'],
                    'missing_folder_name' => $folder['name']
                ]);
            }
            
            // Include actual folder info with the link
            $folder = array_merge($folder, $actualFolder);
        }
        
        return Response::success(['folder' => $folder, 'folder_missing' => false]);
    }
    
    /**
     * Set a member's Drive access
     * POST /api/boards/{id}/members/{email}/drive-access
     */
    public function setMemberDriveAccess(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $memberEmail = $request->getParam('email');
        $permission = $request->input('permission', 'viewer');
        $folderId = $request->input('folder_id');
        
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId, 'owner')) {
            return Response::error('Only board owner can manage member Drive access', 403);
        }
        
        if (!in_array($permission, ['viewer', 'editor'])) {
            return Response::error('Permission must be viewer or editor');
        }
        
        if (!$this->boardService->setMemberDriveAccess($this->getActiveEmail(), $boardId, $memberEmail, $permission, $folderId ? (int)$folderId : null)) {
            return Response::error('Failed to set Drive access');
        }
        
        return Response::success(null, 'Drive access granted');
    }
    
    /**
     * Revoke a member's Drive access
     * DELETE /api/boards/{id}/members/{email}/drive-access
     */
    public function revokeMemberDriveAccess(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $memberEmail = $request->getParam('email');
        
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId, 'owner')) {
            return Response::error('Only board owner can manage member Drive access', 403);
        }
        
        if (!$this->boardService->revokeMemberDriveAccess($this->getActiveEmail(), $boardId, $memberEmail)) {
            return Response::error('Failed to revoke Drive access');
        }
        
        return Response::success(null, 'Drive access revoked');
    }
    
    /**
     * Get members with Drive access
     * GET /api/boards/{id}/drive-members
     */
    public function getDriveMembers(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId)) {
            return Response::error('Board not found or access denied', 404);
        }
        
        $members = $this->boardService->getMembersWithDriveAccess($boardId);
        
        return Response::success(['members' => $members]);
    }
    
    // =========================================================================
    // TRACKED URLS (Website Time Tracking)
    // =========================================================================
    
    /**
     * Get tracked URLs for a board
     * GET /api/boards/{id}/tracked-urls
     */
    public function getTrackedUrls(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId)) {
            return Response::error('Board not found or access denied', 404);
        }
        
        $urls = $this->boardService->getTrackedUrls($boardId);
        
        return Response::success(['urls' => $urls]);
    }
    
    /**
     * Add tracked URL to a board
     * POST /api/boards/{id}/tracked-urls
     */
    public function addTrackedUrl(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $urlDomain = trim($request->input('url_domain', ''));
        $displayName = trim($request->input('display_name', ''));
        $titleMatch = trim($request->input('title_match', ''));
        
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId, 'owner')) {
            return Response::error('Only board owner can manage tracked URLs', 403);
        }
        
        if (empty($urlDomain)) {
            return Response::error('URL domain is required', 400);
        }
        
        // Validate domain format
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-_.]+\.[a-zA-Z]{2,}$/', $urlDomain)) {
            return Response::error('Invalid domain format', 400);
        }
        
        $urlId = $this->boardService->addTrackedUrl($boardId, $urlDomain, $displayName, $titleMatch ?: null);
        
        if (!$urlId) {
            return Response::error('Failed to add tracked URL. Make sure the board is linked to a client, or the URL may already exist.');
        }
        
        return Response::success(['id' => $urlId], 'Tracked URL added');
    }
    
    /**
     * Update tracked URL
     * PUT /api/boards/{id}/tracked-urls/{urlId}
     */
    public function updateTrackedUrl(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $urlId = (int)$request->getParam('urlId');
        $urlDomain = trim($request->input('url_domain', ''));
        $displayName = trim($request->input('display_name', ''));
        $titleMatch = trim($request->input('title_match', ''));
        $isActive = $request->input('is_active', null);
        
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId, 'owner')) {
            return Response::error('Only board owner can manage tracked URLs', 403);
        }
        
        if (empty($urlDomain)) {
            return Response::error('URL domain is required', 400);
        }
        
        // Validate domain format
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-_.]+\.[a-zA-Z]{2,}$/', $urlDomain)) {
            return Response::error('Invalid domain format', 400);
        }
        
        $success = $this->boardService->updateTrackedUrl($urlId, $boardId, $urlDomain, $displayName, $titleMatch ?: null, $isActive);
        
        if (!$success) {
            return Response::error('Failed to update tracked URL');
        }
        
        return Response::success(null, 'Tracked URL updated');
    }
    
    /**
     * Delete tracked URL
     * DELETE /api/boards/{id}/tracked-urls/{urlId}
     */
    public function deleteTrackedUrl(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $boardId = (int)$request->getParam('id');
        $urlId = (int)$request->getParam('urlId');
        
        if (!$this->boardService->hasAccess($this->getActiveEmail(), $boardId, 'owner')) {
            return Response::error('Only board owner can manage tracked URLs', 403);
        }
        
        $success = $this->boardService->deleteTrackedUrl($urlId, $boardId);
        
        if (!$success) {
            return Response::error('Failed to delete tracked URL');
        }
        
        return Response::success(null, 'Tracked URL deleted');
    }
    
    /**
     * Get all URL mappings (for FlowOneDrive sync)
     * GET /api/boards/url-mappings
     */
    public function getUrlMappings(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $mappings = $this->boardService->getAllUrlMappings($this->getActiveEmail());
        
        return Response::success(['mappings' => $mappings]);
    }

    // =========================================================================
    // TRELLO IMPORT
    // =========================================================================

    private const TRELLO_COLOR_MAP = [
        'green' => '#22c55e', 'yellow' => '#eab308', 'orange' => '#f97316',
        'red' => '#ef4444', 'purple' => '#a855f7', 'blue' => '#3b82f6',
        'sky' => '#0ea5e9', 'lime' => '#84cc16', 'pink' => '#ec4899',
        'black' => '#1e293b',
        'green_dark' => '#166534', 'yellow_dark' => '#854d0e', 'orange_dark' => '#9a3412',
        'red_dark' => '#991b1b', 'purple_dark' => '#7e22ce', 'blue_dark' => '#1e40af',
        'sky_dark' => '#075985', 'lime_dark' => '#3f6212', 'pink_dark' => '#9d174d',
        'black_dark' => '#0f172a',
        'green_light' => '#86efac', 'yellow_light' => '#fde047', 'orange_light' => '#fdba74',
        'red_light' => '#fca5a5', 'purple_light' => '#d8b4fe', 'blue_light' => '#93c5fd',
        'sky_light' => '#7dd3fc', 'lime_light' => '#bef264', 'pink_light' => '#f9a8d4',
        'black_light' => '#64748b',
    ];

    /**
     * Preview a Trello export ZIP
     * POST /boards/import-trello/preview
     */
    public function previewTrelloImport(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return Response::error('No file uploaded', 400);
        }

        if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'zip') {
            return Response::error('File must be a ZIP archive', 400);
        }

        $zip = new \ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            return Response::error('Failed to open ZIP file', 400);
        }

        try {
            $boards = [];
            $workspaceName = '';

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);

                // Workspace-level JSON
                if (preg_match('#^[^/]+/[^/]+\.json$#', $entryName) && !str_contains($entryName, '/boards/')) {
                    $wsData = json_decode($zip->getFromIndex($i), true);
                    if ($wsData && isset($wsData['displayName'])) {
                        $workspaceName = $wsData['displayName'];
                    }
                }

                // Board JSON files
                if (preg_match('#/boards/([^/]+)/\1\.json$#', $entryName)) {
                    $boardJson = json_decode($zip->getFromIndex($i), true);
                    if (!$boardJson || !isset($boardJson['name'])) continue;

                    $openCards = array_filter($boardJson['cards'] ?? [], fn($c) => !($c['closed'] ?? false));
                    $openLists = array_filter($boardJson['lists'] ?? [], fn($l) => !($l['closed'] ?? false));
                    $attachmentCount = 0;
                    foreach ($openCards as $card) {
                        $attachmentCount += count($card['attachments'] ?? []);
                    }

                    $boards[] = [
                        'id' => $boardJson['id'],
                        'name' => $boardJson['name'],
                        'desc' => $boardJson['desc'] ?? '',
                        'list_count' => count($openLists),
                        'card_count' => count($openCards),
                        'label_count' => count($boardJson['labels'] ?? []),
                        'attachment_count' => $attachmentCount,
                    ];
                }
            }

            $zip->close();

            return Response::success([
                'workspace_name' => $workspaceName,
                'boards' => $boards,
            ]);
        } catch (\Throwable $e) {
            $zip->close();
            error_log("Trello preview error: " . $e->getMessage());
            return Response::error('Failed to parse Trello export: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Import boards from a Trello export ZIP
     * POST /boards/import-trello
     */
    public function importTrello(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getActiveEmail();
        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return Response::error('No file uploaded', 400);
        }

        $selectedIds = json_decode($request->input('board_ids', '[]'), true);
        if (empty($selectedIds)) {
            return Response::error('No boards selected', 400);
        }

        $zip = new \ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            return Response::error('Failed to open ZIP file', 400);
        }

        $importedCount = 0;
        $importedBoards = [];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);

                if (!preg_match('#/boards/([^/]+)/\1\.json$#', $entryName)) continue;

                $boardJson = json_decode($zip->getFromIndex($i), true);
                if (!$boardJson || !isset($boardJson['id'])) continue;
                if (!in_array($boardJson['id'], $selectedIds)) continue;

                $result = $this->importSingleTrelloBoard($email, $boardJson, $zip, dirname($entryName));
                if ($result) {
                    $importedBoards[] = $result;
                    $importedCount++;
                }
            }

            $zip->close();

            return Response::success([
                'imported_count' => $importedCount,
                'boards' => $importedBoards,
            ], "Imported $importedCount board(s)");
        } catch (\Throwable $e) {
            $zip->close();
            error_log("Trello import error: " . $e->getMessage());
            return Response::error('Import failed: ' . $e->getMessage(), 500);
        }
    }

    private function importSingleTrelloBoard(string $email, array $boardJson, \ZipArchive $zip, string $boardZipDir): ?array
    {
        $bs = $this->getBoardService();

        // Build index of attachment files in the ZIP for this board (handles encoded filenames)
        $attachDir = $boardZipDir . '/attachments/';
        $zipAttachments = [];
        for ($zi = 0; $zi < $zip->numFiles; $zi++) {
            $name = $zip->getNameIndex($zi);
            if (str_starts_with($name, $attachDir) && $name !== $attachDir) {
                $basename = substr($name, strlen($attachDir));
                $zipAttachments[$basename] = $name;
            }
        }

        // Prepare Drive for attachment storage
        $drive = $this->getDriveService();
        $boardFolder = $drive->getOrCreateBoardFolder($email, $boardJson['name']);
        $boardFolderId = $boardFolder ? (int)$boardFolder['id'] : null;

        $bgColor = $boardJson['prefs']['backgroundColor'] ?? '#1e1e26';

        // Create the board without default lists/labels
        $stmt = $bs->getDb()->prepare("
            INSERT INTO webmail_boards (owner_email, name, description, background_color)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$email, $boardJson['name'], $boardJson['desc'] ?? null, $bgColor]);
        $boardId = (int)$bs->getDb()->lastInsertId();

        // Add owner as member
        $stmt = $bs->getDb()->prepare("
            INSERT INTO webmail_board_members (board_id, user_email, role, invited_by)
            VALUES (?, ?, 'owner', ?)
        ");
        $stmt->execute([$boardId, $email, $email]);

        // Import labels (Trello uses color names, map to hex)
        $labelMap = []; // trello_label_id => our_label_id
        foreach ($boardJson['labels'] ?? [] as $tLabel) {
            $color = self::TRELLO_COLOR_MAP[$tLabel['color'] ?? ''] ?? '#808080';
            $name = $tLabel['name'] ?? '';

            $stmt = $bs->getDb()->prepare("INSERT INTO webmail_board_labels (board_id, name, color) VALUES (?, ?, ?)");
            $stmt->execute([$boardId, $name, $color]);
            $labelMap[$tLabel['id']] = (int)$bs->getDb()->lastInsertId();
        }

        // Import lists (only open ones)
        $listMap = []; // trello_list_id => our_list_id
        $openLists = array_filter($boardJson['lists'] ?? [], fn($l) => !($l['closed'] ?? false));
        usort($openLists, fn($a, $b) => ($a['pos'] ?? 0) - ($b['pos'] ?? 0));

        foreach ($openLists as $pos => $tList) {
            $stmt = $bs->getDb()->prepare("
                INSERT INTO webmail_board_lists (board_id, name, position)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$boardId, $tList['name'], $pos]);
            $listMap[$tList['id']] = (int)$bs->getDb()->lastInsertId();
        }

        // Build checklist lookup by card ID (Trello stores checklists at top level with idCard reference)
        $checklistsByCard = [];
        foreach ($boardJson['checklists'] ?? [] as $cl) {
            $cardRef = $cl['idCard'] ?? '';
            if ($cardRef) {
                $checklistsByCard[$cardRef][] = $cl;
            }
        }

        // Import cards (only open ones)
        $openCards = array_filter($boardJson['cards'] ?? [], fn($c) => !($c['closed'] ?? false));

        // Group cards by list and sort by position
        $cardsByList = [];
        foreach ($openCards as $tCard) {
            $listId = $tCard['idList'] ?? '';
            if (!isset($listMap[$listId])) continue;
            $cardsByList[$listId][] = $tCard;
        }

        foreach ($cardsByList as $tListId => $cards) {
            usort($cards, fn($a, $b) => ($a['pos'] ?? 0) - ($b['pos'] ?? 0));
            $ourListId = $listMap[$tListId];

            foreach ($cards as $cardPos => $tCard) {
                $dueDate = null;
                if (!empty($tCard['due'])) {
                    $dueDate = date('Y-m-d H:i:s', strtotime($tCard['due']));
                }
                $startDate = null;
                if (!empty($tCard['start'])) {
                    $startDate = date('Y-m-d H:i:s', strtotime($tCard['start']));
                }
                $completed = !empty($tCard['dueComplete']) ? 1 : 0;

                $stmt = $bs->getDb()->prepare("
                    INSERT INTO webmail_board_cards (list_id, title, description, position, due_date, start_date, completed, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $ourListId,
                    $tCard['name'] ?? 'Untitled',
                    $tCard['desc'] ?? null,
                    $cardPos,
                    $dueDate,
                    $startDate,
                    $completed,
                    $email,
                ]);
                $cardId = (int)$bs->getDb()->lastInsertId();

                // Assign labels
                foreach ($tCard['idLabels'] ?? [] as $tLabelId) {
                    if (isset($labelMap[$tLabelId])) {
                        $stmt = $bs->getDb()->prepare("INSERT IGNORE INTO webmail_card_labels (card_id, label_id) VALUES (?, ?)");
                        $stmt->execute([$cardId, $labelMap[$tLabelId]]);
                    }
                }

                // Import checklists (from top-level board checklists matched by card ID)
                $cardChecklists = $checklistsByCard[$tCard['id'] ?? ''] ?? [];
                foreach ($cardChecklists as $tChecklist) {
                    $stmt = $bs->getDb()->prepare("
                        INSERT INTO webmail_card_checklists (card_id, title, position)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$cardId, $tChecklist['name'] ?? 'Checklist', $tChecklist['pos'] ?? 0]);
                    $checklistId = (int)$bs->getDb()->lastInsertId();

                    $items = $tChecklist['checkItems'] ?? [];
                    usort($items, fn($a, $b) => ($a['pos'] ?? 0) - ($b['pos'] ?? 0));
                    foreach ($items as $itemPos => $tItem) {
                        $stmt = $bs->getDb()->prepare("
                            INSERT INTO webmail_checklist_items (checklist_id, text, completed, position)
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $checklistId,
                            $tItem['name'] ?? '',
                            ($tItem['state'] ?? '') === 'complete' ? 1 : 0,
                            $itemPos,
                        ]);
                    }
                }

                // Import attachments from ZIP
                foreach ($tCard['attachments'] ?? [] as $tAttach) {
                    $fileName = $tAttach['fileName'] ?? '';
                    if (!$fileName) continue;

                    $content = false;
                    $matchedZipFile = $fileName;

                    if (isset($zipAttachments[$fileName])) {
                        $content = $zip->getFromName($zipAttachments[$fileName]);
                        $matchedZipFile = basename($zipAttachments[$fileName]);
                    } else {
                        foreach ($zipAttachments as $zipBasename => $zipFullPath) {
                            $decoded = rawurldecode(str_replace('_', '%', $zipBasename));
                            if ($decoded === $fileName || str_contains($zipBasename, pathinfo($fileName, PATHINFO_FILENAME))) {
                                $content = $zip->getFromName($zipFullPath);
                                $matchedZipFile = $zipBasename;
                                break;
                            }
                        }
                    }

                    if ($content === false) continue;

                    $mimeType = $tAttach['mimeType'] ?? 'application/octet-stream';
                    if (!$mimeType || $mimeType === '') $mimeType = 'application/octet-stream';
                    $originalName = $tAttach['name'] ?? $fileName;

                    $driveFile = $drive->uploadFileContent($email, $originalName, $content, $mimeType, $boardFolderId);
                    if (!$driveFile) continue;

                    $stmt = $bs->getDb()->prepare("
                        INSERT INTO webmail_card_attachments (card_id, drive_file_id, name, created_by)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$cardId, (int)$driveFile['id'], $originalName, $email]);
                }
            }
        }

        return ['id' => $boardId, 'name' => $boardJson['name']];
    }
}

