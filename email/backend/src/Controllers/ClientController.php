<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\ClientService;
use Webmail\Addons\KanbanBoards\Services\BoardService;
use Webmail\Addons\TimeTracker\Services\ClientTimeTrackingService;
use Webmail\Services\DriveService;
use Webmail\Services\SearchIndexerService;

/**
 * ClientController - API endpoints for Client Overview feature
 */
class ClientController extends BaseController
{
    private ClientService $clientService;
    private DriveService $driveService;
    private ?SearchIndexerService $searchIndexer = null;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->clientService = new ClientService($config);
        // userEmail is set in parent constructor via extractUserFromToken()
        $this->driveService = new DriveService($config, $this->userEmail);
    }
    
    /**
     * Get search indexer for indexing clients
     */
    private function getSearchIndexer(): SearchIndexerService
    {
        if (!$this->searchIndexer) {
            $this->searchIndexer = new SearchIndexerService($this->config);
        }
        return $this->searchIndexer;
    }
    
    /**
     * Index a client for search
     */
    private function triggerClientIndex(string $userEmail, array $client): void
    {
        try {
            $this->getSearchIndexer()->indexClient($userEmail, $client);
        } catch (\Exception $e) {
            error_log("ClientController triggerClientIndex error: " . $e->getMessage());
        }
    }
    
    /**
     * GET /clients/init - Combined initial load for clients + all mappings
     * Replaces: GET /clients + GET /clients/board-mapping + GET /clients/folder-mapping + GET /clients/mood-board-mapping
     */
    public function init(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $result = [
            'clients' => [],
            'counts' => [],
            'board_mapping' => [],
            'folder_mapping' => [],
            'mood_board_mapping' => [],
        ];

        // 1. Clients + counts
        try {
            $result['clients'] = $this->clientService->getClients($email);
            $result['counts'] = $this->clientService->getStatusCounts($email);
        } catch (\Exception $e) {
            error_log("[ClientController::init] Failed to get clients: " . $e->getMessage());
        }

        // 2. Board mapping
        try {
            $result['board_mapping'] = $this->clientService->getAllBoardMappings($email);
        } catch (\Exception $e) {
            error_log("[ClientController::init] Failed to get board mappings: " . $e->getMessage());
        }

        // 3. Folder mapping (includes recursive subfolder expansion)
        try {
            $clients = $result['clients'] ?: $this->clientService->getClients($email);
            $mapping = [];
            foreach ($clients as $client) {
                if (!empty($client['drive_folder_id'])) {
                    $folderId = (int)$client['drive_folder_id'];
                    $clientInfo = [
                        'client_id' => $client['id'],
                        'client_name' => $client['display_name'] ?? $client['domain']
                    ];
                    $mapping[(string)$folderId] = $clientInfo;
                    $subfolderIds = $this->driveService->getAllSubfolderIds($email, $folderId);
                    foreach ($subfolderIds as $subId) {
                        $mapping[(string)$subId] = $clientInfo;
                    }
                }
            }
            $result['folder_mapping'] = $mapping;
        } catch (\Exception $e) {
            error_log("[ClientController::init] Failed to get folder mappings: " . $e->getMessage());
        }

        // 4. Mood board mapping
        try {
            $result['mood_board_mapping'] = $this->clientService->getAllMoodBoardMappings($email);
        } catch (\Exception $e) {
            error_log("[ClientController::init] Failed to get mood board mappings: " . $e->getMessage());
        }

        return Response::json(['success' => true, 'data' => $result]);
    }

    /**
     * GET /clients - List all clients with status
     */
    public function list(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $status = $request->getQuery('status');
        $sortBy = $request->getQuery('sort', 'status');
        
        $clients = $this->clientService->getClients($email, $status, $sortBy);
        $counts = $this->clientService->getStatusCounts($email);
        
        return Response::json([
            'success' => true,
            'data' => [
                'clients' => $clients,
                'counts' => $counts
            ]
        ]);
    }
    
    /**
     * GET /clients/{id} - Get client overview snapshot
     */
    public function get(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        
        // First verify client exists
        $client = $this->clientService->getClient($email, $id);
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        // Recalculate task counts from linked boards to ensure fresh data
        $this->recalculateTaskCounts($email, $id);
        
        // Now get the snapshot with updated data
        $snapshot = $this->clientService->getClientSnapshot($email, $id);
        
        if (!$snapshot) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        return Response::json([
            'success' => true,
            'data' => $snapshot
        ]);
    }
    
    /**
     * PUT /clients/{id} - Update client info (name, phone, address, notes, payment_terms)
     */
    public function update(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        
        // Collect all updatable fields
        $data = [];
        
        $displayName = $request->input('display_name');
        if ($displayName !== null) {
            $data['display_name'] = $displayName;
        }
        
        $phone = $request->input('phone');
        if ($phone !== null) {
            $data['phone'] = $phone ?: null;
        }
        
        $address = $request->input('address');
        if ($address !== null) {
            $data['address'] = $address ?: null;
        }
        
        $notes = $request->input('notes');
        if ($notes !== null) {
            $data['notes'] = $notes ?: null;
        }
        
        $paymentTerms = $request->input('payment_terms_days');
        if ($paymentTerms !== null) {
            $data['payment_terms_days'] = (int)$paymentTerms;
        }
        
        $hourlyRate = $request->input('hourly_rate');
        if ($hourlyRate !== null) {
            $data['hourly_rate'] = $hourlyRate === '' ? null : (float)$hourlyRate;
        }
        
        if (empty($data)) {
            return Response::json(['success' => false, 'message' => 'No fields to update'], 400);
        }
        
        $client = $this->clientService->updateClientInfo($email, $id, $data);
        
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        // Index for search
        $this->triggerClientIndex($email, $client);
        
        return Response::json([
            'success' => true,
            'data' => ['client' => $client]
        ]);
    }
    
    /**
     * DELETE /clients/{id} - Delete a client
     */
    public function delete(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        $deleted = $this->clientService->deleteClient($email, $id);
        
        if (!$deleted) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        return Response::json(['success' => true]);
    }
    
    /**
     * GET /clients/{id}/threads - Get email threads for client
     * Note: Frontend should search emails by domain directly via mailbox search
     */
    public function getThreads(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        $client = $this->clientService->getClient($email, $id);
        
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        // Return domain info - frontend will use mailbox search
        return Response::json([
            'success' => true,
            'data' => [
                'threads' => [],
                'domain' => $client['domain'],
                'search_hint' => 'from:@' . $client['domain']
            ]
        ]);
    }
    
    /**
     * GET /clients/{id}/mindmap - Get client data formatted for mind map visualization
     */
    public function getMindMap(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        
        // Get full client snapshot
        $snapshot = $this->clientService->getClientSnapshot($email, $id);
        
        if (!$snapshot) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        $client = $snapshot['client'];
        
        // Build mind map structure
        $root = [
            'id' => 'client-' . $client['id'],
            'type' => 'client',
            'label' => $client['display_name'] ?? $client['name'] ?? $client['email'],
            'sublabel' => ($client['total_emails'] ?? 0) . ' emails',
            'icon' => 'person',
            'meta' => [
                'clientId' => $client['id'],
                'email' => $client['email'],
                'domain' => $client['domain'],
                'status' => $client['status'],
                'emailCount' => $client['total_emails'] ?? 0,
                'lastActivity' => $client['last_activity'] ?? null,
            ],
            'children' => [],
            'linkedTo' => [],
        ];
        
        // Add conversation threads as children
        $threads = $snapshot['threads'] ?? [];
        foreach ($threads as $i => $thread) {
            $convNode = [
                'id' => 'conv-' . ($thread['conversation_id'] ?? $i),
                'type' => 'conversation',
                'label' => $thread['subject'] ?? 'No Subject',
                'icon' => 'forum',
                'meta' => [
                    'conversationId' => $thread['conversation_id'] ?? null,
                    'messageCount' => $thread['message_count'] ?? count($thread['emails'] ?? []),
                    'unreadCount' => $thread['unread_count'] ?? 0,
                    'lastDate' => $thread['last_date'] ?? null,
                    'participants' => $thread['participants'] ?? [],
                ],
                'children' => [],
            ];
            
            // Add individual emails
            $emails = $thread['emails'] ?? $thread['messages'] ?? [];
            foreach ($emails as $email) {
                $convNode['children'][] = [
                    'id' => 'email-' . ($email['message_id'] ?? $email['uid'] ?? uniqid()),
                    'type' => 'email',
                    'label' => $email['subject'] ?? 'No Subject',
                    'icon' => isset($email['seen']) && !$email['seen'] ? 'mark_email_unread' : 'mail',
                    'meta' => [
                        'messageId' => $email['message_id'] ?? null,
                        'uid' => $email['uid'] ?? null,
                        'folder' => $email['folder'] ?? 'INBOX',
                        'from' => $email['from_name'] ?? $email['from_email'] ?? null,
                        'timestamp' => $email['timestamp'] ?? $email['date'] ?? null,
                        'unread' => !($email['seen'] ?? true),
                        'flagged' => $email['flagged'] ?? false,
                        'hasAttachment' => $email['has_attachment'] ?? false,
                        'preview' => isset($email['text_body']) ? substr($email['text_body'], 0, 100) : null,
                    ],
                    'children' => [],
                    'linkedTo' => [],
                ];
            }
            
            $root['children'][] = $convNode;
        }
        
        // Add linked boards
        $linkedBoards = $client['linked_boards'] ?? [];
        foreach ($linkedBoards as $board) {
            $root['linkedTo'][] = [
                'id' => 'board-' . ($board['board_id'] ?? $board['id']),
                'type' => 'board',
                'label' => $board['name'] ?? $board['title'] ?? 'Board',
                'icon' => 'dashboard',
                'meta' => [
                    'boardId' => $board['board_id'] ?? $board['id'],
                    'cardCount' => $board['card_count'] ?? 0,
                ],
            ];
        }
        
        // Add linked calendar events (if any in snapshot)
        $calendarEvents = $snapshot['calendar_events'] ?? [];
        foreach ($calendarEvents as $event) {
            $root['linkedTo'][] = [
                'id' => 'cal-' . $event['id'],
                'type' => 'calendar',
                'label' => $event['title'] ?? 'Event',
                'icon' => 'event',
                'meta' => [
                    'eventId' => $event['id'],
                    'eventDate' => $event['start_date'] ?? null,
                    'allDay' => $event['all_day'] ?? false,
                ],
            ];
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'root' => $root,
            ]
        ]);
    }
    
    /**
     * GET /clients/by-email/{email}/mindmap - Get client mind map by email address
     */
    public function getMindMapByEmail(Request $request): Response
    {
        $userEmail = $this->getUser($request);
        if (!$userEmail) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientEmail = urldecode($request->getParam('email'));
        
        // Find client by email
        $client = $this->clientService->findClientByEmail($userEmail, $clientEmail);
        
        // Auto-create client if not found
        if (!$client) {
            // Extract name from email (before @)
            $namePart = strstr($clientEmail, '@', true) ?: $clientEmail;
            $displayName = ucwords(str_replace(['.', '_', '-'], ' ', $namePart));
            
            $client = $this->clientService->getOrCreateClient($userEmail, $clientEmail, $displayName);
            
            if (!$client) {
                return Response::json(['success' => false, 'message' => 'Failed to create client for email: ' . $clientEmail], 500);
            }
        }
        
        // Get FULL client overview with all related data
        $overview = $this->clientService->getClientFullOverview($userEmail, $client['id']);
        
        if (!$overview) {
            return Response::json(['success' => false, 'message' => 'Client data not found'], 404);
        }
        
        $clientData = $overview['client'];
        $emailBoardLinks = $overview['email_board_links'] ?? [];
        
        // Create lookup for emails linked to boards (by message_id and uid+folder)
        $emailBoardMap = [];
        $emailBoardMapByUid = [];
        foreach ($emailBoardLinks as $link) {
            if (!empty($link['message_id'])) {
                $emailBoardMap[$link['message_id']] = $link;
            }
            if (!empty($link['uid']) && !empty($link['folder'])) {
                $emailBoardMapByUid[$link['folder'] . ':' . $link['uid']] = $link;
            }
        }
        
        // Build comprehensive mind map structure
        $root = [
            'id' => 'client-' . $clientData['id'],
            'type' => 'client',
            'label' => $clientData['display_name'] ?? $clientData['name'] ?? $clientData['email'],
            'sublabel' => ($clientData['total_emails'] ?? 0) . ' emails',
            'icon' => 'person',
            'meta' => [
                'clientId' => $clientData['id'],
                'email' => $clientData['email'],
                'domain' => $clientData['domain'],
                'status' => $clientData['status'],
                'emailCount' => $clientData['total_emails'] ?? 0,
                'lastActivity' => $clientData['last_activity'] ?? null,
            ],
            'children' => [],
            'linkedTo' => [],
        ];
        
        // === SECTION 1: Email Conversations with Reply Chains ===
        $threads = $overview['threads'] ?? [];
        foreach ($threads as $i => $thread) {
            $convNode = [
                'id' => 'conv-' . ($thread['conversation_id'] ?? $i),
                'type' => 'conversation',
                'label' => $this->truncateLabel($thread['subject'] ?? 'No Subject', 40),
                'sublabel' => ($thread['message_count'] ?? 0) . ' emails',
                'icon' => 'forum',
                'meta' => [
                    'conversationId' => $thread['conversation_id'] ?? null,
                    'messageCount' => $thread['message_count'] ?? count($thread['emails'] ?? []),
                    'unreadCount' => $thread['unread_count'] ?? 0,
                    'lastDate' => $thread['last_date'] ?? null,
                    'hasAttachment' => $thread['has_attachment'] ?? false,
                ],
                'children' => [],
                'linkedTo' => [],
            ];
            
            // Build email reply tree
            $emails = $thread['emails'] ?? [];
            $emailNodeMap = [];
            
            foreach ($emails as $idx => $emailData) {
                $isFromClient = $emailData['is_from_client'] ?? false;
                $messageId = $emailData['message_id'] ?? $emailData['uid'] ?? uniqid();
                
                $emailNode = [
                    'id' => 'email-' . $messageId,
                    'type' => 'email',
                    'label' => $this->truncateLabel($emailData['from_name'] ?? $emailData['from_email'] ?? 'Unknown', 25),
                    'sublabel' => $this->formatEmailDate($emailData['date'] ?? null),
                    'icon' => $isFromClient ? 'call_received' : 'call_made',
                    'meta' => [
                        'messageId' => $emailData['message_id'] ?? null,
                        'uid' => $emailData['uid'] ?? null,
                        'folder' => $emailData['folder'] ?? 'INBOX',
                        'from' => $emailData['from_name'] ?? $emailData['from_email'] ?? null,
                        'fromEmail' => $emailData['from_email'] ?? null,
                        'timestamp' => $emailData['date'] ?? null,
                        'isFromClient' => $isFromClient,
                        'subject' => $emailData['subject'] ?? null,
                    ],
                    'children' => [],
                    'linkedTo' => [],
                ];
                
                // Check if this email has a board linked (by message_id or uid+folder)
                $boardLink = null;
                if (isset($emailBoardMap[$messageId])) {
                    $boardLink = $emailBoardMap[$messageId];
                } else {
                    $uidKey = ($emailData['folder'] ?? '') . ':' . ($emailData['uid'] ?? '');
                    if (isset($emailBoardMapByUid[$uidKey])) {
                        $boardLink = $emailBoardMapByUid[$uidKey];
                    }
                }
                
                if ($boardLink) {
                    $emailNode['linkedTo'][] = [
                        'id' => 'board-link-' . $boardLink['id'],
                        'type' => 'board',
                        'label' => $boardLink['board_name'],
                        'icon' => 'dashboard',
                        'meta' => [
                            'boardId' => $boardLink['board_id'],
                            'linkId' => $boardLink['id'],
                        ],
                    ];
                }
                
                $emailNodeMap[$messageId] = $emailNode;
            }
            
            // For now, attach all emails as flat children of conversation
            // (in_reply_to parsing would be needed for true tree structure)
            foreach ($emailNodeMap as $emailNode) {
                $convNode['children'][] = $emailNode;
            }
            
            $root['children'][] = $convNode;
        }
        
        // === SECTION 2: Boards with Tasks, Milestones, and Drive Folders ===
        $boards = $overview['boards'] ?? [];
        $clientDriveInfo = $overview['drive']; // Client's main drive folder
        $clientDriveUsedByBoard = false; // Track if client's drive was matched to a board
        
        foreach ($boards as $board) {
            // Get drive folder info for this board (by board_id link)
            $boardDriveInfo = $this->getBoardDriveFolder($userEmail, $board['id']);
            
            // If no board-specific drive, check if client's drive folder matches this board name
            if (!$boardDriveInfo && $clientDriveInfo) {
                $boardName = strtolower(trim($board['name'] ?? ''));
                $folderName = strtolower(trim($clientDriveInfo['folder_name'] ?? ''));
                if ($boardName && $folderName && ($boardName === $folderName || strpos($folderName, $boardName) !== false || strpos($boardName, $folderName) !== false)) {
                    // Match found - use client's drive folder for this board
                    $boardDriveInfo = $clientDriveInfo;
                    $clientDriveUsedByBoard = true;
                }
            }
            
            $boardNode = [
                'id' => 'board-' . $board['id'],
                'type' => 'board',
                'label' => $board['name'] ?? 'Board',
                'sublabel' => $board['completed_cards'] . '/' . $board['total_cards'] . ' tasks (' . $board['progress'] . '%)',
                'icon' => 'dashboard',
                'meta' => [
                    'boardId' => $board['id'],
                    'totalCards' => $board['total_cards'],
                    'completedCards' => $board['completed_cards'],
                    'progress' => $board['progress'],
                    'financials' => $board['financials'],
                    'hasDrive' => $boardDriveInfo !== null,
                ],
                'children' => [],
                'linkedTo' => [],
            ];
            
            // Add drive folder as first child if exists
            if ($boardDriveInfo) {
                $driveNode = [
                    'id' => 'drive-board-' . $board['id'],
                    'type' => 'drive',
                    'label' => $boardDriveInfo['folder_name'] ?? 'Files',
                    'sublabel' => $boardDriveInfo['total_files'] . ' files' . 
                        ($boardDriveInfo['subfolder_count'] > 0 ? ', ' . $boardDriveInfo['subfolder_count'] . ' folders' : ''),
                    'icon' => 'folder',
                    'meta' => [
                        'folderId' => $boardDriveInfo['folder_id'],
                        'totalFiles' => $boardDriveInfo['total_files'],
                        'totalSize' => $boardDriveInfo['total_size'],
                        'totalSizeFormatted' => $boardDriveInfo['total_size_formatted'],
                        'subfolderCount' => $boardDriveInfo['subfolder_count'],
                        'boardId' => $board['id'],
                    ],
                    'children' => [],
                ];
                
                // Add subfolders as children of drive node
                foreach ($boardDriveInfo['subfolders'] ?? [] as $subfolder) {
                    $driveNode['children'][] = [
                        'id' => 'folder-' . $subfolder['id'],
                        'type' => 'drive',
                        'label' => $subfolder['name'],
                        'sublabel' => $subfolder['file_count'] . ' files',
                        'icon' => 'folder',
                        'meta' => [
                            'folderId' => $subfolder['id'],
                            'fileCount' => $subfolder['file_count'],
                        ],
                        'children' => [],
                    ];
                }
                
                $boardNode['children'][] = $driveNode;
            }
            
            // Add milestones and lists with financial data
            foreach ($board['lists'] ?? [] as $list) {
                $isMilestone = !empty($list['is_milestone']) || !empty($list['expected_amount']);
                
                $listNode = [
                    'id' => 'list-' . $list['id'],
                    'type' => $isMilestone ? 'milestone' : 'list',
                    'label' => $list['name'],
                    'sublabel' => $isMilestone && !empty($list['expected_amount']) 
                        ? number_format($list['expected_amount'], 0) . ' ' . ($list['currency'] ?? 'HUF')
                        : $list['completed_cards'] . '/' . $list['total_cards'] . ' done',
                    'icon' => $isMilestone ? 'flag' : 'view_list',
                    'meta' => [
                        'listId' => $list['id'],
                        'isMilestone' => $isMilestone,
                        'expectedAmount' => $list['expected_amount'] ?? null,
                        'currency' => $list['currency'] ?? null,
                        'invoiceDate' => $list['invoice_date'] ?? null,
                        'progress' => $list['progress'],
                        'totalCards' => $list['total_cards'],
                        'completedCards' => $list['completed_cards'],
                    ],
                    'children' => [],
                ];
                
                // Add individual tasks/cards
                foreach ($list['cards'] ?? [] as $card) {
                    $listNode['children'][] = [
                        'id' => 'card-' . $card['id'],
                        'type' => 'task',
                        'label' => $this->truncateLabel($card['title'], 30),
                        'sublabel' => $card['due_date'] ? 'Due: ' . date('M j', strtotime($card['due_date'])) : '',
                        'icon' => $card['is_complete'] ? 'task_alt' : 'radio_button_unchecked',
                        'meta' => [
                            'cardId' => $card['id'],
                            'isComplete' => (bool)$card['is_complete'],
                            'dueDate' => $card['due_date'],
                        ],
                        'children' => [],
                    ];
                }
                
                $boardNode['children'][] = $listNode;
            }
            
            $root['linkedTo'][] = $boardNode;
        }
        
        // === SECTION 3: Drive Folder with Stats ===
        // Only show as separate item if NOT already matched to a board
        $driveInfo = $overview['drive'];
        if ($driveInfo && !$clientDriveUsedByBoard) {
            $root['linkedTo'][] = [
                'id' => 'drive-' . $driveInfo['folder_id'],
                'type' => 'drive',
                'label' => $driveInfo['folder_name'] ?? 'Files',
                'sublabel' => $driveInfo['total_files'] . ' files, ' . $driveInfo['total_size_formatted'],
                'icon' => 'folder',
                'meta' => [
                    'folderId' => $driveInfo['folder_id'],
                    'totalFiles' => $driveInfo['total_files'],
                    'totalSize' => $driveInfo['total_size'],
                    'totalSizeFormatted' => $driveInfo['total_size_formatted'],
                    'subfolderCount' => $driveInfo['subfolder_count'],
                    'isLinked' => true,
                ],
                'children' => [],
            ];
        } elseif (!$driveInfo && !$clientDriveUsedByBoard) {
            // Show "no folder" only if no drive folder at all
            $root['linkedTo'][] = [
                'id' => 'drive-unlinked',
                'type' => 'drive',
                'label' => 'Drive Folder',
                'sublabel' => 'No folder linked',
                'icon' => 'folder_off',
                'meta' => [
                    'folderId' => null,
                    'totalFiles' => 0,
                    'isLinked' => false,
                ],
                'children' => [],
            ];
        }
        // If clientDriveUsedByBoard is true, the drive folder is already shown under the board
        
        // === SECTION 4: Calendar Events ===
        $calendarEvents = $overview['calendar_events'] ?? [];
        if (count($calendarEvents) > 0) {
            $calendarNode = [
                'id' => 'calendar-section',
                'type' => 'calendar-group',
                'label' => 'Calendar',
                'sublabel' => count($calendarEvents) . ' events',
                'icon' => 'calendar_month',
                'meta' => [
                    'eventCount' => count($calendarEvents),
                ],
                'children' => [],
            ];
            
            foreach ($calendarEvents as $event) {
                $calendarNode['children'][] = [
                    'id' => 'cal-' . $event['id'],
                    'type' => 'calendar',
                    'label' => $this->truncateLabel($event['title'] ?? 'Event', 30),
                    'sublabel' => $event['start_date'] ? date('M j, Y', strtotime($event['start_date'])) : '',
                    'icon' => 'event',
                    'meta' => [
                        'eventId' => $event['id'],
                        'calendarId' => $event['calendar_id'] ?? null,
                        'startDate' => $event['start_date'] ?? null,
                        'endDate' => $event['end_date'] ?? null,
                        'allDay' => $event['all_day'] ?? false,
                        'calendarName' => $event['calendar_name'] ?? null,
                        'color' => $event['color'] ?? null,
                    ],
                    'children' => [],
                ];
            }
            
            $root['linkedTo'][] = $calendarNode;
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'root' => $root,
            ]
        ]);
    }
    
    /**
     * Truncate label for display
     */
    private function truncateLabel(string $text, int $maxLength = 30): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, 0, $maxLength - 3) . '...';
    }
    
    /**
     * Format email date for display
     */
    private function formatEmailDate(?string $date): string
    {
        if (!$date) return '';
        
        try {
            $timestamp = strtotime($date);
            $now = time();
            $diff = $now - $timestamp;
            
            if ($diff < 86400) { // Today
                return date('g:i A', $timestamp);
            } elseif ($diff < 604800) { // This week
                return date('D g:i A', $timestamp);
            } else {
                return date('M j', $timestamp);
            }
        } catch (\Exception $e) {
            return '';
        }
    }
    
    /**
     * Get drive folder info for a board
     * Returns folder details with file counts and subfolders
     */
    private function getBoardDriveFolder(string $userEmail, int $boardId): ?array
    {
        try {
            // Get the drive folder associated with this board
            $folder = $this->driveService->getBoardFolder($userEmail, $boardId);
            if (!$folder) {
                return null;
            }
            
            $folderId = $folder['id'];
            
            // Get files in the folder
            $files = $this->driveService->getFiles($userEmail, $folderId);
            $totalFiles = count($files);
            $totalSize = 0;
            foreach ($files as $file) {
                $totalSize += $file['size'] ?? 0;
            }
            
            // Get subfolders
            $subfolders = $this->driveService->getFolders($userEmail, $folderId);
            $subfolderData = [];
            foreach ($subfolders as $subfolder) {
                $subfolderFiles = $this->driveService->getFiles($userEmail, $subfolder['id']);
                $subfolderData[] = [
                    'id' => $subfolder['id'],
                    'name' => $subfolder['name'],
                    'file_count' => count($subfolderFiles),
                ];
                // Add subfolder files to total count
                $totalFiles += count($subfolderFiles);
                foreach ($subfolderFiles as $sf) {
                    $totalSize += $sf['size'] ?? 0;
                }
            }
            
            return [
                'folder_id' => $folderId,
                'folder_name' => $folder['name'],
                'total_files' => $totalFiles,
                'total_size' => $totalSize,
                'total_size_formatted' => $this->formatBytes($totalSize),
                'subfolder_count' => count($subfolders),
                'subfolders' => $subfolderData,
            ];
        } catch (\Exception $e) {
            error_log("getBoardDriveFolder error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Format bytes to human readable string
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1073741824, 1) . ' GB';
    }
    
    /**
     * GET /clients/{id}/tasks - Get open tasks for client
     */
    public function getTasks(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        $client = $this->clientService->getClient($email, $id);
        
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        $tasks = [];
        $boardIds = $this->clientService->getLinkedBoardIds($id);
        
        if (!empty($boardIds)) {
            try {
                $boardService = new BoardService($this->config);
                
                foreach ($boardIds as $boardId) {
                    $board = $boardService->getBoard($email, $boardId);
                    if ($board && !empty($board['lists'])) {
                        foreach ($board['lists'] as $list) {
                            if (!empty($list['cards'])) {
                                foreach ($list['cards'] as $card) {
                                    // Only include incomplete cards
                                    if (empty($card['completed'])) {
                                        $card['board_name'] = $board['name'];
                                        $card['list_name'] = $list['name'];
                                        $tasks[] = $card;
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Sort by due date
                usort($tasks, function($a, $b) {
                    if (empty($a['due_date']) && empty($b['due_date'])) return 0;
                    if (empty($a['due_date'])) return 1;
                    if (empty($b['due_date'])) return -1;
                    return strtotime($a['due_date']) - strtotime($b['due_date']);
                });
                
            } catch (\Exception $e) {
                error_log("ClientController getTasks error: " . $e->getMessage());
            }
        }
        
        return Response::json([
            'success' => true,
            'data' => ['tasks' => $tasks]
        ]);
    }
    
    /**
     * GET /clients/{id}/files - Get files related to client
     */
    public function getFiles(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        $client = $this->clientService->getClient($email, $id);
        
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        // Files are collected from:
        // 1. Board attachments from linked boards
        // 2. Drive files in folders matching client domain
        $files = [];
        
        // Get files from linked boards
        $boardIds = $this->clientService->getLinkedBoardIds($id);
        if (!empty($boardIds)) {
            try {
                $boardService = new BoardService($this->config);
                
                foreach ($boardIds as $boardId) {
                    $board = $boardService->getBoard($email, $boardId);
                    if ($board && !empty($board['lists'])) {
                        foreach ($board['lists'] as $list) {
                            if (!empty($list['cards'])) {
                                foreach ($list['cards'] as $card) {
                                    if (!empty($card['attachments'])) {
                                        foreach ($card['attachments'] as $attachment) {
                                            $attachment['source'] = 'board';
                                            $attachment['board_name'] = $board['name'];
                                            $attachment['card_title'] = $card['title'];
                                            $files[] = $attachment;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log("ClientController getFiles error: " . $e->getMessage());
            }
        }
        
        // Sort by date descending
        usort($files, function($a, $b) {
            $dateA = $a['created_at'] ?? $a['uploaded_at'] ?? '0';
            $dateB = $b['created_at'] ?? $b['uploaded_at'] ?? '0';
            return strtotime($dateB) - strtotime($dateA);
        });
        
        return Response::json([
            'success' => true,
            'data' => ['files' => $files]
        ]);
    }
    
    /**
     * POST /clients/{id}/boards - Link a board to client
     * Also auto-links board's drive folder to client if client has no folder
     */
    public function linkBoard(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        $boardId = (int)$request->input('board_id');
        
        if (!$boardId) {
            return Response::json(['success' => false, 'message' => 'Board ID is required'], 400);
        }
        
        $client = $this->clientService->getClient($email, $clientId);
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        $linked = $this->clientService->linkBoard($clientId, $boardId, $email, $this->config);

        if ($linked) {
            $this->syncBoardToProjectHub($clientId, $boardId, $email);
        }

        return Response::json([
            'success' => $linked,
            'message' => $linked ? 'Board linked' : 'Failed to link board'
        ]);
    }
    
    /**
     * DELETE /clients/{id}/boards/{board_id} - Unlink a board from client
     */
    public function unlinkBoard(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        $boardId = (int)$request->getParam('board_id');
        
        $unlinked = $this->clientService->unlinkBoard($clientId, $boardId);

        if ($unlinked) {
            $this->unsyncBoardFromProjectHub($clientId, $boardId, $email);
        }

        return Response::json([
            'success' => $unlinked,
            'message' => $unlinked ? 'Board unlinked' : 'Board link not found'
        ]);
    }
    
    /**
     * When a board is linked to a client from the Client view,
     * auto-add it to the PH space that has this client assigned.
     */
    private function syncBoardToProjectHub(int $clientId, int $boardId, string $email): void
    {
        try {
            $addonService = new \Webmail\Services\AddonService($this->config);
            if (!$addonService->isProjectHubEnabled()) return;

            $db = \Webmail\Core\Database::getConnection($this->config);
            $userEmail = strtolower($email);

            $stmt = $db->prepare("
                SELECT s.id AS space_id, f.id AS folder_id
                FROM projecthub_spaces s
                LEFT JOIN projecthub_folders f ON f.space_id = s.id AND f.archived = 0
                WHERE s.client_id = ? AND s.user_email = ?
                ORDER BY f.sort_order ASC
                LIMIT 1
            ");
            $stmt->execute([$clientId, $userEmail]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) return;

            $folderId = $row['folder_id'];
            if (!$folderId) {
                $db->prepare("
                    INSERT INTO projecthub_folders (space_id, name, sort_order) VALUES (?, 'General', 0)
                ")->execute([$row['space_id']]);
                $folderId = (int)$db->lastInsertId();
            }

            $db->prepare("
                INSERT IGNORE INTO projecthub_folder_boards (folder_id, board_id, sort_order)
                VALUES (?, ?, 999)
            ")->execute([$folderId, $boardId]);
        } catch (\Throwable $e) {
            error_log("syncBoardToProjectHub error: " . $e->getMessage());
        }
    }

    /**
     * When a board is unlinked from a client, remove it from PH
     * folder_boards if the space has this client.
     */
    private function unsyncBoardFromProjectHub(int $clientId, int $boardId, string $email): void
    {
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);

            $stmt = $db->prepare("
                DELETE fb FROM projecthub_folder_boards fb
                JOIN projecthub_folders f ON f.id = fb.folder_id
                JOIN projecthub_spaces s ON s.id = f.space_id
                WHERE fb.board_id = ? AND s.client_id = ? AND s.user_email = ?
            ");
            $stmt->execute([$boardId, $clientId, strtolower($email)]);
        } catch (\Throwable $e) {
            error_log("unsyncBoardFromProjectHub error: " . $e->getMessage());
        }
    }

    /**
     * POST /clients/{id}/drive-folder - Link a Drive folder to client
     */
    public function linkDriveFolder(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        $folderId = $request->input('folder_id');
        
        // Allow null to unlink
        $folderId = $folderId !== null ? (int)$folderId : null;
        
        $client = $this->clientService->linkDriveFolder($email, $clientId, $folderId);
        
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        return Response::json([
            'success' => true,
            'message' => $folderId ? 'Drive folder linked' : 'Drive folder unlinked',
            'data' => $client
        ]);
    }
    
    /**
     * DELETE /clients/{id}/drive-folder - Unlink Drive folder from client
     */
    public function unlinkDriveFolder(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        
        $client = $this->clientService->unlinkDriveFolder($email, $clientId);
        
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        return Response::json([
            'success' => true,
            'message' => 'Drive folder unlinked',
            'data' => $client
        ]);
    }
    
    /**
     * POST /clients/{id}/sync-drive-folder - Sync drive folder from linked boards
     * Used to fix existing clients that don't have their board's folder linked
     */
    public function syncDriveFolder(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        
        $result = $this->clientService->syncDriveFolderFromBoards($email, $clientId, $this->config);
        
        if (!$result) {
            return Response::json(['success' => false, 'message' => 'Failed to sync'], 500);
        }
        
        return Response::json($result);
    }
    
    /**
     * POST /clients/sync - Sync clients from board-linked emails
     */
    public function sync(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $synced = 0;
        
        try {
            $boardService = new BoardService($this->config);
            
            // Get all boards for this user
            $boards = $boardService->getBoards($email, false);
            
            foreach ($boards as $board) {
                // Get emails linked to this board
                $linkedEmails = $boardService->getBoardEmails($email, $board['id']);
                
                foreach ($linkedEmails as $linkedEmail) {
                    // Extract email address and name from "from" field
                    $fromEmail = $linkedEmail['email_from'] ?? null;
                    if (!$fromEmail) continue;
                    
                    // Parse "Name <email@domain.com>" format
                    $emailAddress = $fromEmail;
                    $senderName = null;
                    
                    if (preg_match('/^(.+?)\s*<([^>]+)>$/', $fromEmail, $matches)) {
                        $senderName = trim($matches[1], ' "\'');
                        $emailAddress = $matches[2];
                    } elseif (preg_match('/<([^>]+)>/', $fromEmail, $matches)) {
                        $emailAddress = $matches[1];
                    }
                    
                    // Get or create the client with sender name
                    $client = $this->clientService->getOrCreateClient($email, $emailAddress, $senderName);
                    
                    if ($client) {
                        $synced++;
                        // Link the board to this client
                        $this->clientService->linkBoard($client['id'], $board['id']);
                    }
                }
            }
            
        } catch (\Exception $e) {
            error_log("ClientController sync error: " . $e->getMessage());
        }
        
        // Recalculate all statuses after sync
        $this->clientService->recalculateAllStatuses($email);
        
        $counts = $this->clientService->getStatusCounts($email);
        
        return Response::json([
            'success' => true,
            'data' => [
                'synced' => $synced,
                'counts' => $counts
            ]
        ]);
    }
    
    /**
     * POST /clients/manual - Manually create a new client
     */
    public function createManual(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $displayName = trim($request->input('display_name') ?? '');
        $domain = strtolower(trim($request->input('domain') ?? ''));
        $contactEmail = strtolower(trim($request->input('contact_email') ?? ''));

        if (!$displayName || !$domain) {
            return Response::json(['success' => false, 'message' => 'Name and domain are required'], 400);
        }

        try {
            // Check if client with this domain already exists
            $existing = $this->clientService->getClientByDomain($email, $domain);
            if ($existing) {
                return Response::json(['success' => false, 'message' => "Client with domain '{$domain}' already exists"], 409);
            }

            // Create the client
            $client = $this->clientService->getOrCreateClient($email, $contactEmail ?: "info@{$domain}", $displayName);
            if (!$client) {
                return Response::json(['success' => false, 'message' => 'Failed to create client'], 500);
            }

            // Update display name explicitly
            $this->clientService->updateClientInfo($email, $client['id'], ['display_name' => $displayName]);

            // If contact email provided, add it as a contact
            if ($contactEmail && $contactEmail !== "info@{$domain}") {
                $this->clientService->addContact($client['id'], $contactEmail, $displayName);
            }

            return Response::json([
                'success' => true,
                'data' => ['id' => $client['id'], 'display_name' => $displayName, 'domain' => $domain],
                'message' => "Client '{$displayName}' created successfully"
            ]);
        } catch (\Exception $e) {
            error_log("ClientController createManual error: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Failed to create client: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /clients/{id}/recalculate - Recalculate client status
     */
    public function recalculate(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        $client = $this->clientService->getClient($email, $id);
        
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        // Recalculate task counts from linked boards
        $this->recalculateTaskCounts($email, $id);
        
        // Recalculate status
        $newStatus = $this->clientService->recalculateStatus($id);
        
        $updatedClient = $this->clientService->getClient($email, $id);
        
        return Response::json([
            'success' => true,
            'data' => ['client' => $updatedClient]
        ]);
    }
    
    /**
     * GET /clients/{id}/email-stats - Get email statistics for a client
     */
    public function getEmailStats(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        $client = $this->clientService->getClient($email, $id);
        
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        // Try to get IMAP connection - if it fails, return empty stats
        try {
            $imap = $this->getImap($request);
            if ($imap) {
                $stats = $this->clientService->getEmailStatsWithImap($imap, $email, $client['domain']);
            } else {
                // Return empty stats if IMAP connection fails
                $stats = $this->clientService->getEmptyEmailStats();
            }
        } catch (\Exception $e) {
            error_log("ClientController getEmailStats IMAP error: " . $e->getMessage());
            $stats = $this->clientService->getEmptyEmailStats();
        }
        
        return Response::json([
            'success' => true,
            'data' => $stats
        ]);
    }
    
    /**
     * Recalculate task counts for a client from linked boards
     */
    private function recalculateTaskCounts(string $email, int $clientId): void
    {
        $boardIds = $this->clientService->getLinkedBoardIds($clientId);
        
        $openTasks = 0;
        $overdueTasks = 0;
        $nextDeadline = null;
        $today = date('Y-m-d');
        
        if (!empty($boardIds)) {
            try {
                $boardService = new BoardService($this->config);
                
                foreach ($boardIds as $boardId) {
                    $board = $boardService->getBoard($email, $boardId);
                    if ($board && !empty($board['lists'])) {
                        foreach ($board['lists'] as $list) {
                            if (!empty($list['cards'])) {
                                foreach ($list['cards'] as $card) {
                                    if (empty($card['completed'])) {
                                        $openTasks++;
                                        
                                        if (!empty($card['due_date'])) {
                                            $dueDate = date('Y-m-d', strtotime($card['due_date']));
                                            
                                            if ($dueDate < $today) {
                                                $overdueTasks++;
                                            }
                                            
                                            if ($dueDate >= $today && (!$nextDeadline || $dueDate < $nextDeadline)) {
                                                $nextDeadline = $dueDate;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log("ClientController recalculateTaskCounts error: " . $e->getMessage());
            }
        }
        
        $this->clientService->updateTaskCounts($clientId, $openTasks, $overdueTasks, $nextDeadline);
    }
    
    // =========================================================================
    // CLIENT MERGE
    // =========================================================================
    
    /**
     * POST /clients/merge - Merge two clients
     */
    public function merge(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $primaryId = (int)$request->input('primary_id');
        $secondaryId = (int)$request->input('secondary_id');
        
        if (!$primaryId || !$secondaryId) {
            return Response::json(['success' => false, 'message' => 'Both primary_id and secondary_id are required'], 400);
        }
        
        if ($primaryId === $secondaryId) {
            return Response::json(['success' => false, 'message' => 'Cannot merge a client with itself'], 400);
        }
        
        $mergedClient = $this->clientService->mergeClients($email, $primaryId, $secondaryId);
        
        if (!$mergedClient) {
            return Response::json(['success' => false, 'message' => 'Failed to merge clients. Make sure both clients exist.'], 400);
        }
        
        return Response::json([
            'success' => true,
            'data' => ['client' => $mergedClient],
            'message' => 'Clients merged successfully'
        ]);
    }
    
    // =========================================================================
    // DOMAIN ALIASES (MERGE TRACKING)
    // =========================================================================
    
    /**
     * GET /clients/{id}/aliases - Get domain aliases for a client
     */
    public function getAliases(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        $aliases = $this->clientService->getAliases($email, $clientId);
        
        return Response::json([
            'success' => true,
            'data' => ['aliases' => $aliases]
        ]);
    }
    
    /**
     * DELETE /clients/{id}/aliases/{aliasId} - Remove a domain alias
     */
    public function removeAlias(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $aliasId = (int)$request->getParam('aliasId');
        
        if (!$aliasId) {
            return Response::json(['success' => false, 'message' => 'Alias ID is required'], 400);
        }
        
        $removed = $this->clientService->removeAlias($email, $aliasId);
        
        if (!$removed) {
            return Response::json(['success' => false, 'message' => 'Failed to remove alias'], 400);
        }
        
        return Response::json([
            'success' => true,
            'message' => 'Domain alias removed. This domain will create its own client on next sync.'
        ]);
    }
    
    /**
     * POST /clients/backfill-aliases - Retroactively create domain aliases from contact data
     */
    public function backfillAliases(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $result = $this->clientService->backfillAliases($email);
        
        return Response::json([
            'success' => true,
            'data' => $result,
            'message' => "Backfill complete: {$result['created']} aliases created, {$result['skipped']} skipped"
        ]);
    }
    
    // =========================================================================
    // ASSOCIATED ACCOUNTS
    // =========================================================================
    
    /**
     * GET /clients/{id}/associated - Get associated accounts for a client
     */
    public function getAssociated(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        $client = $this->clientService->getClient($email, $id);
        
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        $associated = $this->clientService->getAssociatedAccounts($email, $id);
        
        return Response::json([
            'success' => true,
            'data' => ['associated' => $associated]
        ]);
    }
    
    /**
     * POST /clients/{id}/promote - Promote associated account to full client
     */
    public function promote(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        $client = $this->clientService->promoteToClient($email, $id);
        
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        return Response::json([
            'success' => true,
            'data' => ['client' => $client],
            'message' => 'Associated account promoted to client'
        ]);
    }
    
    /**
     * POST /clients/{id}/mark-associated - Mark a client as associated with another
     */
    public function markAssociated(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        $primaryClientId = (int)$request->input('primary_client_id');
        
        if (!$primaryClientId) {
            return Response::json(['success' => false, 'message' => 'primary_client_id is required'], 400);
        }
        
        if ($id === $primaryClientId) {
            return Response::json(['success' => false, 'message' => 'Cannot associate a client with itself'], 400);
        }
        
        $client = $this->clientService->markAsAssociated($email, $id, $primaryClientId);
        
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        return Response::json([
            'success' => true,
            'data' => ['client' => $client],
            'message' => 'Client marked as associated'
        ]);
    }
    
    // =========================================================================
    // SIGNATURE EXTRACTION
    // =========================================================================
    
    /**
     * POST /clients/{id}/extract-signature - Extract contact info from email signature
     */
    public function extractSignature(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        $emailBody = $request->input('email_body');
        
        if (empty($emailBody)) {
            return Response::json(['success' => false, 'message' => 'email_body is required'], 400);
        }
        
        $client = $this->clientService->getClient($email, $id);
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        // Extract signature info
        $signature = $this->clientService->extractSignatureFromEmail($emailBody);
        $info = $this->clientService->extractSignatureInfo($signature);
        
        return Response::json([
            'success' => true,
            'data' => [
                'extracted' => $info,
                'signature_preview' => substr($signature, 0, 500)
            ]
        ]);
    }
    
    /**
     * POST /clients/{id}/extract-contacts - Extract phone/address from multiple emails for all contacts
     * Scans recent emails from the client's domain, uses pattern frequency analysis
     */
    public function extractContacts(Request $request): Response
    {
        $debug = []; // Collect debug info to return in response
        $logFile = '/tmp/extract_contacts_debug.log';
        $logMsg = function($msg) use (&$debug, $logFile) {
            $debug[] = $msg;
            $line = date('Y-m-d H:i:s') . ' ' . $msg . "\n";
            @file_put_contents($logFile, $line, FILE_APPEND);
            error_log("extractContacts: " . $msg);
        };
        
        $logMsg("=== extractContacts START ===");
        
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        $logMsg("user={$email}");
        
        $id = (int)$request->getParam('id');
        $client = $this->clientService->getClient($email, $id);
        
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        $domain = $client['domain'];
        $autoApply = (bool)$request->input('auto_apply', false);
        $logMsg("domain={$domain}, clientId={$id}");
        
        // For generic domains (gmail.com, yahoo.com etc.) without a full email, skip scanning
        $isFullEmail = strpos($domain, '@') !== false;
        if (!$isFullEmail && $this->clientService->isGenericDomain($domain)) {
            return Response::json([
                'success' => true,
                'data' => [
                    'extractions' => [],
                    'applied' => [],
                    'message' => 'Cannot scan emails for generic email providers (e.g. Gmail, Yahoo). Only business domain clients can be scanned.'
                ]
            ]);
        }
        
        // Collect contact emails for searching (more reliable than domain-only search)
        $contactEmails = [];
        if (!empty($client['contacts'])) {
            foreach ($client['contacts'] as $contact) {
                $ce = strtolower(trim($contact['email'] ?? ''));
                if ($ce && strpos($ce, '@') !== false && empty($contact['is_placeholder'])) {
                    $contactEmails[] = $ce;
                }
            }
        }
        
        // Strategy: Try DB-indexed emails first (fast), then fallback to IMAP search across ALL folders
        $emailRefs = $this->clientService->getRecentEmailRefsForDomain($email, $domain, 30);
        $logMsg("DB refs=" . count($emailRefs) . ", contact_emails=" . implode(',', $contactEmails));
        
        $emailBodies = [];
        $imapConnected = false;
        $searchDomain = $isFullEmail ? $domain : '@' . $domain;
        
        try {
            $imap = $this->getImap($request);
            $logMsg("IMAP object: " . ($imap ? 'OK' : 'NULL'));
            if ($imap) {
                $imapConnected = true;
                
                // --- Path A: Use DB-indexed references (fast, direct UID fetch) ---
                if (!empty($emailRefs)) {
                    $logMsg("Path A - fetching " . count($emailRefs) . " emails from DB refs");
                    $currentFolder = null;
                    foreach ($emailRefs as $ref) {
                        $folder = $ref['folder'] ?? '';
                        $uid = (int)($ref['uid'] ?? 0);
                        if (empty($folder) || $uid <= 0) continue;
                        
                        try {
                            if ($currentFolder !== $folder) {
                                if (!$imap->selectFolder($folder)) {
                                    $logMsg("Path A - selectFolder FAILED: {$folder}");
                                    continue;
                                }
                                $currentFolder = $folder;
                            }
                            $this->fetchEmailBody($imap, $folder, $uid, $emailBodies, $ref);
                        } catch (\Exception $e) {
                            $logMsg("Path A error folder={$folder} uid={$uid}: " . $e->getMessage());
                        }
                    }
                    $logMsg("Path A result: " . count($emailBodies) . " bodies");
                }
                
                // --- Path B: If DB had nothing, search ALL IMAP folders ---
                if (empty($emailBodies)) {
                    $skipFolders = ['trash', 'spam', 'junk', 'drafts', '[gmail]/trash', '[gmail]/spam', '[gmail]/drafts'];
                    $folders = $imap->listFolders();
                    $folderNames = array_map(fn($f) => $f['name'] ?? $f, $folders);
                    $logMsg("Path B - " . count($folders) . " folders: " . implode(', ', $folderNames));
                    
                    // Build per-contact search queries
                    $perContactQueries = [];
                    foreach ($contactEmails as $ce) {
                        $perContactQueries[$ce] = 'FROM "' . addslashes($ce) . '"';
                    }
                    $domainQuery = 'FROM "' . addslashes($searchDomain) . '"';
                    $logMsg("Path B - per-contact queries: " . implode(' | ', $perContactQueries) . " + domain: " . $domainQuery);
                    
                    // Collect UIDs per contact across all folders
                    $uidsByContact = []; // email => [{folder, uid}, ...]
                    $domainUids = [];    // [{folder, uid}, ...]
                    
                    foreach ($folders as $folder) {
                        $folderName = $folder['name'] ?? $folder;
                        $folderLower = strtolower($folderName);
                        
                        $shouldSkip = false;
                        foreach ($skipFolders as $skip) {
                            if (strpos($folderLower, $skip) !== false) {
                                $shouldSkip = true;
                                break;
                            }
                        }
                        if ($shouldSkip) continue;
                        
                        try {
                            if (!$imap->selectFolder($folderName)) continue;
                            
                            // Search per contact
                            foreach ($perContactQueries as $ce => $query) {
                                $uids = $imap->searchMessages($query);
                                if (!empty($uids)) {
                                    $logMsg("Path B - '{$query}' in '{$folderName}' => " . count($uids) . " UIDs");
                                    foreach ($uids as $uid) {
                                        $uidsByContact[$ce][] = ['folder' => $folderName, 'uid' => $uid];
                                    }
                                }
                            }
                            
                            // Domain search for contacts not in the contact list
                            $domainResults = $imap->searchMessages($domainQuery);
                            if (!empty($domainResults)) {
                                $logMsg("Path B - domain '{$domainQuery}' in '{$folderName}' => " . count($domainResults) . " UIDs");
                                foreach ($domainResults as $uid) {
                                    $domainUids[] = ['folder' => $folderName, 'uid' => $uid];
                                }
                            }
                        } catch (\Exception $e) {
                            $logMsg("Path B folder {$folderName} error: " . $e->getMessage());
                        }
                    }
                    
                    // Fetch 3 most recent emails per known contact
                    $fetchedUidKeys = [];
                    foreach ($uidsByContact as $ce => $entries) {
                        $recent = array_slice($entries, -3);
                        foreach ($recent as $entry) {
                            $key = $entry['folder'] . ':' . $entry['uid'];
                            if (isset($fetchedUidKeys[$key])) continue;
                            $fetchedUidKeys[$key] = true;
                            try {
                                $this->fetchEmailBody($imap, $entry['folder'], $entry['uid'], $emailBodies);
                            } catch (\Exception $e) {}
                        }
                    }
                    $logMsg("Path B - fetched " . count($emailBodies) . " bodies from known contacts");
                    
                    // Fill remaining slots from domain search (other contacts not in the list)
                    if (count($emailBodies) < 30) {
                        $remaining = array_slice($domainUids, -15);
                        foreach ($remaining as $entry) {
                            if (count($emailBodies) >= 30) break;
                            $key = $entry['folder'] . ':' . $entry['uid'];
                            if (isset($fetchedUidKeys[$key])) continue;
                            $fetchedUidKeys[$key] = true;
                            try {
                                $this->fetchEmailBody($imap, $entry['folder'], $entry['uid'], $emailBodies);
                            } catch (\Exception $e) {}
                        }
                    }
                    $logMsg("Path B result: " . count($emailBodies) . " bodies total");
                }
            } else {
                $logMsg("IMAP connection is NULL");
            }
        } catch (\Exception $e) {
            $logMsg("IMAP error: " . $e->getMessage());
        }
        
        // Also check if email bodies were passed directly in the request
        $providedBodies = $request->input('email_bodies');
        if (is_array($providedBodies)) {
            foreach ($providedBodies as $entry) {
                if (!empty($entry['from_email']) && !empty($entry['body'])) {
                    $emailBodies[] = $entry;
                }
            }
        }
        
        if (empty($emailBodies)) {
            // Return a specific message depending on why we found nothing
            if (!$imapConnected) {
                $reason = 'Could not connect to email server. Please try again or check your connection.';
            } else {
                $reason = 'No emails from ' . $domain . ' could be read from the mail server. The messages may have been moved or deleted.';
            }
            $logMsg("FINAL: no email bodies found. Reason: {$reason}");
            
            return Response::json([
                'success' => true,
                'data' => [
                    'extractions' => [],
                    'applied' => [],
                    'message' => $reason,
                    'debug' => $debug
                ]
            ]);
        }
        
        // Run extraction with pattern analysis (pass contacts for name-aware matching)
        try {
            $clientContacts = $client['contacts'] ?? [];
            $logMsg("Running extractContactInfoFromEmails on " . count($emailBodies) . " bodies with " . count($clientContacts) . " contacts");
            $extractions = $this->clientService->extractContactInfoFromEmails($emailBodies, $clientContacts, $email);
            $logMsg("Extraction done: " . count($extractions) . " results");
        } catch (\Throwable $e) {
            $logMsg("extractContactInfoFromEmails CRASHED: " . get_class($e) . ": " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            return Response::json([
                'success' => false,
                'message' => 'Extraction failed: ' . $e->getMessage(),
                'data' => ['debug' => $debug]
            ], 500);
        }
        
        // Auto-apply: user explicitly clicked "Extract Info" so apply all found data
        $applied = [];
        try {
            if ($autoApply) {
                $withData = array_filter($extractions, function($e) {
                    return !empty($e['phone']) || !empty($e['position']);
                });
                $applied = $this->clientService->applyExtractedContactInfo($id, $withData);
                $logMsg("Auto-applied to " . count($applied) . " contacts");
                
                // Also update client-level address from the best extraction
                $bestAddr = null;
                $bestAddrConf = 0;
                foreach ($extractions as $ext) {
                    if (!empty($ext['address']) && ($ext['address_confidence'] ?? 0) > $bestAddrConf) {
                        $bestAddr = $ext['address'];
                        $bestAddrConf = $ext['address_confidence'] ?? 0;
                    }
                }
                if ($bestAddr && empty($client['address'])) {
                    $this->clientService->updateClientInfo($email, $id, ['address' => $bestAddr]);
                    $logMsg("Applied client-level address: " . $bestAddr);
                }
            }
        } catch (\Throwable $e) {
            $logMsg("Auto-apply CRASHED: " . get_class($e) . ": " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
        }
        
        $logMsg("FINAL: success - " . count($extractions) . " extractions, " . count($applied) . " applied");
        
        // Build per-sender debug: pick the longest body per sender and show stripped version
        $perSenderSamples = [];
        $senderBest = [];
        foreach ($emailBodies as $b) {
            $sender = $b['from_email'];
            if (!isset($senderBest[$sender]) || strlen($b['body']) > strlen($senderBest[$sender]['body'])) {
                $senderBest[$sender] = $b;
            }
        }
        foreach ($senderBest as $sender => $b) {
            $stripped = $this->clientService->stripQuotedReplies($b['body']);
            $perSenderSamples[] = [
                'from' => $sender,
                'body_length' => strlen($b['body']),
                'stripped_length' => strlen($stripped),
                'body_tail' => mb_substr($b['body'], -500),
                'stripped_tail' => mb_substr($stripped, -500),
            ];
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'extractions' => $extractions,
                'emails_scanned' => count($emailBodies),
                'applied' => $applied,
                'debug' => $debug,
                'body_samples' => $perSenderSamples,
            ]
        ]);
    }
    
    /**
     * Helper: Fetch a single email body from IMAP and add to the bodies array
     * Uses direct IMAP functions to avoid getMessage's folder re-selection overhead
     */
    private function fetchEmailBody($imap, string $folder, int $uid, array &$emailBodies, ?array $dbRef = null): void
    {
        $msg = $imap->getMessage($folder, $uid);
        if (!$msg) {
            return;
        }
        
        $fromData = $msg['from'][0] ?? [];
        $fromEmail = $fromData['email'] ?? '';
        $fromName = $fromData['name'] ?? '';
        
        $body = '';
        
        // Always prefer HTML conversion: signatures live in the HTML body,
        // while body_text is often a simplified fallback without the signature.
        if (!empty($msg['body_html'])) {
            $html = $msg['body_html'];
            // Insert newlines before ALL block-level and table cell elements
            $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
            $html = preg_replace('/<\s*\/?\s*(?:p|div|tr|td|th|li|h[1-6]|table|blockquote|hr|dt|dd)\b[^>]*>/i', "\n", $html);
            // Remove invisible elements entirely
            $html = preg_replace('/<\s*(?:style|script|head)\b[^>]*>.*?<\s*\/\s*(?:style|script|head)\s*>/is', '', $html);
            // Remove base64 image data that would leak into text
            $html = preg_replace('/[A-Za-z0-9+\/=]{50,}/', '', $html);
            $body = strip_tags($html);
            // Decode HTML entities (&nbsp;, &amp;, etc.) to real characters
            $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Strip invisible Unicode characters that break regex matching:
            // zero-width spaces, joiners, directional marks, BOM, soft hyphens
            $body = preg_replace('/[\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{FEFF}\x{00AD}]/u', '', $body);
            // Convert non-breaking spaces to regular spaces
            $body = str_replace("\xc2\xa0", ' ', $body);
            // Normalize whitespace: collapse runs of spaces/tabs on each line
            $body = preg_replace('/[^\S\n]+/u', ' ', $body);
            // Remove lines that are only whitespace (e.g. "\n \n" -> "\n\n")
            $body = preg_replace('/\n[ \t]+\n/', "\n\n", $body);
            // Collapse excessive blank lines
            $body = preg_replace('/\n{3,}/', "\n\n", $body);
            $body = trim($body);
        }
        
        // Fall back to plain text body if HTML conversion produced nothing
        if (empty($body)) {
            $body = $msg['body_text'] ?? '';
        }
        
        if ($dbRef) {
            if (empty($fromEmail)) $fromEmail = $dbRef['from_email'] ?? '';
            if (empty($fromName)) $fromName = $dbRef['from_name'] ?? '';
        }
        
        if ($fromEmail && !empty(trim($body))) {
            $emailBodies[] = [
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'body' => $body
            ];
        }
    }
    
    /**
     * POST /clients/{id}/apply-signature - Apply extracted signature info to client
     */
    public function applySignature(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        $emailBody = $request->input('email_body');
        
        if (empty($emailBody)) {
            return Response::json(['success' => false, 'message' => 'email_body is required'], 400);
        }
        
        $client = $this->clientService->updateClientFromSignature($email, $id, $emailBody);
        
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        return Response::json([
            'success' => true,
            'data' => ['client' => $client],
            'message' => 'Signature info applied to client'
        ]);
    }
    
    // =========================================================================
    // CLIENT FINANCIALS
    // =========================================================================
    
    /**
     * GET /clients/{id}/financials - Get financial summary across all linked boards
     */
    public function getFinancials(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $id = (int)$request->getParam('id');
        $client = $this->clientService->getClient($email, $id);
        
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        // Get payment terms from client
        $paymentTerms = $client['payment_terms_days'] ?? 30;
        
        // Get financials from all linked boards
        $boardIds = $this->clientService->getLinkedBoardIds($id);
        $boardFinancials = [];
        $totalsByCurrency = [];
        $allMilestones = [];
        
        if (!empty($boardIds)) {
            try {
                $boardService = new BoardService($this->config);
                
                foreach ($boardIds as $boardId) {
                    $financials = $boardService->getBoardFinancials($email, $boardId, $paymentTerms);
                    if (!empty($financials['milestones'])) {
                        $boardFinancials[] = $financials;
                        
                        // Sum totals by currency
                        foreach ($financials['totals_by_currency'] ?? [] as $currency => $amount) {
                            if (!isset($totalsByCurrency[$currency])) {
                                $totalsByCurrency[$currency] = 0;
                            }
                            $totalsByCurrency[$currency] += $amount;
                        }
                        
                        foreach ($financials['milestones'] as $milestone) {
                            $milestone['board_id'] = $boardId;
                            $milestone['board_name'] = $financials['board_name'];
                            $allMilestones[] = $milestone;
                        }
                    }
                }
                
                // Sort milestones by invoice date
                usort($allMilestones, function($a, $b) {
                    $dateA = $a['invoice_date'] ?? '9999-12-31';
                    $dateB = $b['invoice_date'] ?? '9999-12-31';
                    return strcmp($dateA, $dateB);
                });
                
            } catch (\Exception $e) {
                error_log("ClientController getFinancials error: " . $e->getMessage());
            }
        }
        
        // Calculate monthly projections by currency
        $monthlyProjections = [];
        foreach ($allMilestones as $milestone) {
            if ($milestone['payment_date']) {
                $month = date('Y-m', strtotime($milestone['payment_date']));
                $currency = $milestone['currency'] ?? 'HUF';
                if (!isset($monthlyProjections[$month])) {
                    $monthlyProjections[$month] = [];
                }
                if (!isset($monthlyProjections[$month][$currency])) {
                    $monthlyProjections[$month][$currency] = 0;
                }
                $monthlyProjections[$month][$currency] += $milestone['expected_amount'];
            }
        }
        ksort($monthlyProjections);
        
        // Filter out zero totals
        $totalsByCurrency = array_filter($totalsByCurrency, fn($amount) => $amount > 0);
        
        return Response::json([
            'success' => true,
            'data' => [
                'client_id' => $id,
                'client_name' => $client['display_name'] ?? $client['domain'],
                'payment_terms_days' => $paymentTerms,
                'totals_by_currency' => $totalsByCurrency,
                'boards' => $boardFinancials,
                'all_milestones' => $allMilestones,
                'monthly_projections' => $monthlyProjections
            ]
        ]);
    }
    
    // =========================================================================
    // CONTACT MANAGEMENT
    // =========================================================================
    
    /**
     * POST /clients/{id}/contacts - Add a new contact to a client
     */
    public function addContact(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        $contactEmail = $request->input('email');
        $name = $request->input('name');
        $phone = $request->input('phone');
        $position = $request->input('position');
        
        // Convert empty strings to null
        $name = !empty($name) ? $name : null;
        $phone = !empty($phone) ? $phone : null;
        $position = !empty($position) ? $position : null;
        
        if (!$contactEmail) {
            return Response::json(['success' => false, 'message' => 'Email is required'], 400);
        }
        
        try {
            $contact = $this->clientService->addContact($clientId, $contactEmail, $name, $phone, $position);
            
            if ($contact) {
                return Response::json([
                    'success' => true,
                    'message' => 'Contact added',
                    'data' => ['contact' => $contact]
                ]);
            }
            
            return Response::json(['success' => false, 'message' => 'Failed to add contact'], 500);
        } catch (\Exception $e) {
            error_log("ClientController addContact error: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * PUT /clients/{id}/contacts/{contactId} - Update a contact
     */
    public function updateContact(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        $contactId = (int)$request->getParam('contactId');
        
        // Log the raw request for debugging
        error_log("ClientController updateContact: clientId=$clientId, contactId=$contactId, raw input=" . json_encode($request->input()));
        
        if ($contactId <= 0) {
            return Response::json(['success' => false, 'message' => 'Invalid contact ID'], 400);
        }
        
        // Collect all fields that are present in the request (even if empty)
        $data = [];
        $rawInput = $request->input();
        
        if (array_key_exists('name', $rawInput)) {
            $data['name'] = $rawInput['name'];
        }
        if (array_key_exists('phone', $rawInput)) {
            $data['phone'] = $rawInput['phone'];
        }
        if (array_key_exists('position', $rawInput)) {
            $data['position'] = $rawInput['position'];
        }
        
        error_log("ClientController updateContact: data to update=" . json_encode($data));
        
        try {
            $contact = $this->clientService->updateContact($clientId, $contactId, $data, $email);
            
            if ($contact) {
                error_log("ClientController updateContact: success, returning contact=" . json_encode($contact));
                return Response::json([
                    'success' => true,
                    'message' => 'Contact updated',
                    'data' => ['contact' => $contact]
                ]);
            }
            
            return Response::json(['success' => false, 'message' => 'Contact not found or failed to update'], 404);
        } catch (\Exception $e) {
            error_log("ClientController updateContact error: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * DELETE /clients/{id}/contacts/{contactId} - Delete a contact
     */
    public function deleteContact(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        $contactId = (int)$request->getParam('contactId');
        
        if ($contactId <= 0) {
            return Response::json(['success' => false, 'message' => 'Invalid contact ID'], 400);
        }
        
        // Verify client belongs to user
        $client = $this->clientService->getClient($email, $clientId);
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        try {
            $deleted = $this->clientService->deleteContact($clientId, $contactId);
            
            if ($deleted) {
                return Response::json([
                    'success' => true,
                    'message' => 'Contact deleted'
                ]);
            }
            
            return Response::json(['success' => false, 'message' => 'Contact not found or failed to delete'], 404);
        } catch (\Exception $e) {
            error_log("ClientController deleteContact error: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * GET /clients/all-contacts - Fetch all contacts from all clients
     * Used by mailing list creation feature to populate a list from existing clients.
     */
    public function allContacts(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        try {
            $contacts = $this->clientService->getAllContacts($email);
            return Response::json(['success' => true, 'data' => ['contacts' => $contacts]]);
        } catch (\Exception $e) {
            error_log("ClientController allContacts error: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Server error'], 500);
        }
    }

    /**
     * GET /clients/{id}/activity - Get activity log for a client
     */
    public function getActivity(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        $limit = (int)($request->getQuery('limit') ?? 50);
        $offset = (int)($request->getQuery('offset') ?? 0);
        
        // Verify client belongs to user
        $client = $this->clientService->getClient($email, $clientId);
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        // Get activity from BoardService (which has the activity_log table)
        $boardService = new \Webmail\Addons\KanbanBoards\Services\BoardService($this->config);
        $activity = $boardService->getClientActivityLog($clientId, $limit, $offset);
        
        return Response::json([
            'success' => true,
            'data' => ['activity' => $activity]
        ]);
    }
    
    // =========================================================================
    // TEAM MEMBERSHIP ENDPOINTS
    // =========================================================================
    
    /**
     * GET /clients/{id}/members - Get all team members for a client
     */
    public function getMembers(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        
        // Verify user has access to this client
        if (!$this->clientService->isMember($clientId, $email)) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        $members = $this->clientService->getMembers($clientId);
        
        // Get the client owner
        $client = $this->clientService->getClient($email, $clientId);
        if (!$client) {
            // Try to get via shared access
            $shared = $this->clientService->getSharedClients($email);
            $client = array_filter($shared, fn($c) => $c['id'] == $clientId);
            $client = !empty($client) ? array_values($client)[0] : null;
        }
        
        $ownerEmail = $client['user_email'] ?? $email;
        
        return Response::json([
            'success' => true,
            'data' => [
                'members' => $members,
                'owner_email' => $ownerEmail,
                'is_owner' => strtolower($email) === strtolower($ownerEmail)
            ]
        ]);
    }
    
    /**
     * POST /clients/{id}/members - Add a team member to a client
     */
    public function addMember(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        $memberEmail = $request->input('email');
        $role = $request->input('role', 'member');
        
        if (!$memberEmail || !filter_var($memberEmail, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['success' => false, 'message' => 'Valid email is required'], 400);
        }
        
        $result = $this->clientService->addMember($email, $clientId, $memberEmail, $role);
        
        if ($result['success']) {
            return Response::json([
                'success' => true,
                'message' => 'Member added',
                'data' => ['member' => $result['member']]
            ]);
        }
        
        return Response::json(['success' => false, 'message' => $result['error'] ?? 'Failed to add member'], 400);
    }
    
    /**
     * DELETE /clients/{id}/members/{email} - Remove a team member from a client
     */
    public function removeMember(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        $memberEmail = $request->getParam('memberEmail');
        
        if (!$memberEmail) {
            return Response::json(['success' => false, 'message' => 'Member email is required'], 400);
        }
        
        $removed = $this->clientService->removeMember($email, $clientId, $memberEmail);
        
        if ($removed) {
            return Response::json(['success' => true, 'message' => 'Member removed']);
        }
        
        return Response::json(['success' => false, 'message' => 'Failed to remove member'], 400);
    }
    
    /**
     * PUT /clients/{id}/members/{email} - Update a member's role
     */
    public function updateMember(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        $memberEmail = $request->getParam('memberEmail');
        $newRole = $request->input('role');
        
        if (!$memberEmail || !$newRole) {
            return Response::json(['success' => false, 'message' => 'Member email and role are required'], 400);
        }
        
        $updated = $this->clientService->updateMemberRole($email, $clientId, $memberEmail, $newRole);
        
        if ($updated) {
            return Response::json(['success' => true, 'message' => 'Member role updated']);
        }
        
        return Response::json(['success' => false, 'message' => 'Failed to update member role'], 400);
    }
    
    // =========================================================================
    // TIME TRACKING ENDPOINTS
    // =========================================================================
    
    /**
     * POST /clients/{id}/time - Track time for an activity
     */
    public function trackTime(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        $activityType = $request->input('activity_type');
        $durationSeconds = (int)$request->input('duration_seconds', 0);
        $entityId = $request->input('entity_id');
        $entityName = $request->input('entity_name');
        $source = $request->input('source', 'cloud');
        
        if (!$activityType) {
            return Response::json(['success' => false, 'message' => 'Activity type is required'], 400);
        }
        
        if ($durationSeconds <= 0) {
            return Response::json(['success' => false, 'message' => 'Duration must be positive'], 400);
        }
        
        // Verify client exists (lightweight check - time tracking can come from
        // auto-detected clients via email domain/board/folder mapping, so we
        // only require the client to exist, not strict ownership/membership)
        if (!$this->clientService->clientExists($clientId)) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        $timeService = new ClientTimeTrackingService($this->config);
        $tracked = $timeService->trackActivity(
            $email,
            $clientId,
            $activityType,
            $durationSeconds,
            $entityId,
            $entityName,
            $source
        );
        
        if (!$tracked) {
            return Response::json(['success' => false, 'message' => 'Failed to track time'], 500);
        }

        $this->bridgeToProjectHub($request, $email, $activityType, $durationSeconds, $entityName);

        return Response::json(['success' => true, 'message' => 'Time tracked']);
    }
    
    private function bridgeToProjectHub(Request $request, string $email, string $activityType, int $durationSeconds, ?string $entityName): void
    {
        try {
            $addonService = new \Webmail\Services\AddonService($this->config);
            if (!$addonService->isProjectHubEnabled()) return;

            $service = new \Webmail\Addons\ProjectHub\Services\ProjectHubWorkTrackingService($this->config);

            if ($activityType === 'document_edit') {
                $driveFileId = (int)$request->input('drive_file_id', 0);
                $boardId = (int)$request->input('board_id', 0);
                if ($driveFileId > 0) {
                    $service->bridgeDriveActivity($driveFileId, $email, $durationSeconds, $entityName);
                } elseif ($boardId > 0) {
                    $cardId = (int)$request->input('card_id', 0);
                    $targetCardId = $cardId > 0 ? $cardId : null;
                    if ($targetCardId) {
                        $service->logWorkSession($targetCardId, $email, [
                            'source' => 'drive_edit',
                            'entity_type' => 'local_file',
                            'entity_name' => $entityName,
                            'duration_seconds' => $durationSeconds,
                        ]);
                    }
                }
            } elseif ($activityType === 'website_work') {
                $cardId = (int)$request->input('card_id', 0);
                if ($cardId > 0) {
                    $service->logWorkSession($cardId, $email, [
                        'source' => 'website_work',
                        'entity_type' => 'url',
                        'entity_id' => $request->input('entity_id'),
                        'entity_name' => $entityName,
                        'duration_seconds' => $durationSeconds,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log("ClientController::bridgeToProjectHub error: " . $e->getMessage());
        }
    }

    /**
     * GET /clients/{id}/time-stats - Get time statistics for a client
     */
    public function getTimeStats(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        $period = $request->getQuery('period', 'week');
        
        // Verify user has access to this client
        if (!$this->clientService->isMember($clientId, $email)) {
            error_log("getTimeStats: isMember check failed for client={$clientId}, email={$email}");
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        try {
            $timeService = new ClientTimeTrackingService($this->config);
            $stats = $timeService->getCompleteTimeStats($email, $clientId, $period);
        } catch (\Exception $e) {
            error_log("getTimeStats FATAL error for client={$clientId}: " . $e->getMessage());
            // Return zeroed structure but still success=true so frontend renders what it can
            $stats = [
                'client_id' => $clientId,
                'period' => $period,
                'my_time' => ['total_seconds' => 0, 'by_activity' => []],
                'team_time' => ['total_seconds' => 0, 'by_member' => [], 'by_activity' => []],
                'daily_breakdown' => [],
                'cumulative' => ['my_total' => 0, 'team_total' => 0],
                '_errors' => ['fatal: ' . $e->getMessage()]
            ];
        }
        
        // Add raw data count for diagnostics (separate try-catch so it can't crash the endpoint)
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $stmt = $db->prepare('SELECT COUNT(*) as cnt, SUM(duration_seconds) as total FROM webmail_client_time_tracking WHERE client_id = ?');
            $stmt->execute([$clientId]);
            $raw = $stmt->fetch();
            $stats['_debug'] = [
                'raw_rows' => (int)($raw['cnt'] ?? 0),
                'raw_total_seconds' => (int)($raw['total'] ?? 0),
                'user_email' => $email,
                'client_id' => $clientId,
                'period' => $period
            ];
        } catch (\Exception $e) {
            error_log("getTimeStats debug query failed for client={$clientId}: " . $e->getMessage());
            $stats['_debug'] = ['error' => $e->getMessage(), 'user_email' => $email, 'client_id' => $clientId, 'period' => $period];
        }
        
        return Response::json([
            'success' => true,
            'data' => $stats
        ]);
    }
    
    /**
     * GET /clients/{id}/time-breakdown - Get daily time breakdown for a client
     */
    public function getTimeBreakdown(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        $startDate = $request->getQuery('start_date');
        $endDate = $request->getQuery('end_date');
        
        // Verify user has access to this client
        if (!$this->clientService->isMember($clientId, $email)) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        $timeService = new ClientTimeTrackingService($this->config);
        $breakdown = $timeService->getDailyBreakdown($clientId, $email, $startDate, $endDate);
        
        return Response::json([
            'success' => true,
            'data' => ['breakdown' => $breakdown]
        ]);
    }
    
    /**
     * GET /clients/time-totals - Get time totals for all clients (for compact list)
     */
    public function getTimeTotals(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $period = $request->getQuery('period', 'week');
        
        $timeService = new ClientTimeTrackingService($this->config);
        $totals = $timeService->getClientTimeTotals($email, $period);
        
        return Response::json([
            'success' => true,
            'data' => ['totals' => $totals]
        ]);
    }
    
    /**
     * GET /clients/folder-mapping - Get drive folder to client mapping
     * Used by Electron app to track document editing time
     * 
     * Returns a mapping of folder_id -> client for:
     * - Direct client folders (drive_folder_id)
     * - ALL subfolders recursively (inherit client from parent)
     */
    public function getFolderMapping(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        // Get all clients with their drive_folder_id
        $clients = $this->clientService->getClients($email);
        
        $mapping = [];
        foreach ($clients as $client) {
            if (!empty($client['drive_folder_id'])) {
                $folderId = (int)$client['drive_folder_id'];
                $clientInfo = [
                    'client_id' => $client['id'],
                    'client_name' => $client['display_name'] ?? $client['domain']
                ];
                
                // Add the main folder
                $mapping[(string)$folderId] = $clientInfo;
                
                // Add all subfolders recursively - they inherit the client
                $subfolderIds = $this->driveService->getAllSubfolderIds($email, $folderId);
                foreach ($subfolderIds as $subId) {
                    $mapping[(string)$subId] = $clientInfo;
                }
            }
        }
        
        return Response::json([
            'success' => true,
            'data' => ['mapping' => $mapping]
        ]);
    }
    
    /**
     * GET /clients/board-mapping - Get board_id to client_id mapping
     * Used for time tracking board activities
     */
    public function getBoardMapping(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        // Get all board-client links
        $mapping = $this->clientService->getAllBoardMappings($email);
        
        return Response::json([
            'success' => true,
            'data' => ['mapping' => $mapping]
        ]);
    }
    
    /**
     * GET /clients/mood-board-mapping - Get mood_board_id to client_id mapping
     * Used for time tracking mood board activities
     */
    public function getMoodBoardMapping(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $mapping = $this->clientService->getAllMoodBoardMappings($email);
        
        return Response::json([
            'success' => true,
            'data' => ['mapping' => $mapping]
        ]);
    }
    
    // =========================================================================
    // DRIVE INDEX ENDPOINT
    // =========================================================================
    
    /**
     * GET /clients/{id}/drive-index - Get all indexed files and folders for a client
     * Returns the folder tree structure with file counts and details
     */
    public function getDriveIndex(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        
        // Get client data
        $client = $this->clientService->getClient($email, $clientId);
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        // Check if client has a linked drive folder
        if (empty($client['drive_folder_id'])) {
            return Response::json([
                'success' => true,
                'data' => [
                    'has_folder' => false,
                    'folder' => null,
                    'tree' => [],
                    'stats' => [
                        'total_folders' => 0,
                        'total_files' => 0,
                        'total_size' => 0
                    ]
                ]
            ]);
        }
        
        try {
            $driveService = new \Webmail\Services\DriveService($this->config);
            
            // Get the root folder
            $rootFolder = $driveService->getFolder($email, $client['drive_folder_id']);
            if (!$rootFolder) {
                return Response::json([
                    'success' => true,
                    'data' => [
                        'has_folder' => false,
                        'folder' => null,
                        'tree' => [],
                        'stats' => ['total_folders' => 0, 'total_files' => 0, 'total_size' => 0],
                        'error' => 'Linked folder not found'
                    ]
                ]);
            }
            
            // Build the folder tree recursively
            $tree = $this->buildFolderTree($driveService, $email, $client['drive_folder_id']);
            
            // Calculate stats
            $stats = $this->calculateTreeStats($tree);
            
            return Response::json([
                'success' => true,
                'data' => [
                    'has_folder' => true,
                    'folder' => [
                        'id' => $rootFolder['id'],
                        'name' => $rootFolder['name'],
                        'created_at' => $rootFolder['created_at'] ?? null
                    ],
                    'tree' => $tree,
                    'stats' => $stats
                ]
            ]);
        } catch (\Exception $e) {
            error_log("ClientController getDriveIndex error: " . $e->getMessage());
            return Response::json([
                'success' => false,
                'message' => 'Failed to load drive index: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Build folder tree recursively
     */
    private function buildFolderTree(\Webmail\Services\DriveService $driveService, string $email, int $folderId, int $depth = 0): array
    {
        // Limit depth to prevent infinite recursion
        if ($depth > 10) {
            return [];
        }
        
        $result = [];
        
        // Get subfolders
        $subfolders = $driveService->getFolders($email, $folderId);
        foreach ($subfolders as $folder) {
            $folderFiles = $driveService->getFiles($email, $folder['id']);
            $children = $this->buildFolderTree($driveService, $email, $folder['id'], $depth + 1);
            
            $result[] = [
                'type' => 'folder',
                'id' => $folder['id'],
                'name' => $folder['name'],
                'color' => $folder['color'] ?? null,
                'file_count' => count($folderFiles),
                'files' => array_map(function($f) {
                    return [
                        'id' => $f['id'],
                        'name' => $f['original_name'],
                        'size' => $f['size'],
                        'mime_type' => $f['mime_type'],
                        'updated_at' => $f['updated_at'] ?? $f['created_at'] ?? null
                    ];
                }, $folderFiles),
                'children' => $children,
                'created_at' => $folder['created_at'] ?? null
            ];
        }
        
        // Get files in this folder (root level)
        if ($depth === 0) {
            $rootFiles = $driveService->getFiles($email, $folderId);
            foreach ($rootFiles as $file) {
                $result[] = [
                    'type' => 'file',
                    'id' => $file['id'],
                    'name' => $file['original_name'],
                    'size' => $file['size'],
                    'mime_type' => $file['mime_type'],
                    'updated_at' => $file['updated_at'] ?? $file['created_at'] ?? null
                ];
            }
        }
        
        return $result;
    }
    
    /**
     * Calculate stats from folder tree
     */
    private function calculateTreeStats(array $tree): array
    {
        $totalFolders = 0;
        $totalFiles = 0;
        $totalSize = 0;
        
        foreach ($tree as $item) {
            if ($item['type'] === 'folder') {
                $totalFolders++;
                $totalFiles += $item['file_count'];
                
                // Sum file sizes
                foreach ($item['files'] as $file) {
                    $totalSize += $file['size'] ?? 0;
                }
                
                // Recurse into children
                if (!empty($item['children'])) {
                    $childStats = $this->calculateTreeStats($item['children']);
                    $totalFolders += $childStats['total_folders'];
                    $totalFiles += $childStats['total_files'];
                    $totalSize += $childStats['total_size'];
                }
            } else {
                // Root level file
                $totalFiles++;
                $totalSize += $item['size'] ?? 0;
            }
        }
        
        return [
            'total_folders' => $totalFolders,
            'total_files' => $totalFiles,
            'total_size' => $totalSize
        ];
    }
    
    // =========================================================================
    // EXPORT ENDPOINT
    // =========================================================================
    
    /**
     * GET /clients/export - Export all clients data as CSV (Excel-compatible)
     */
    public function export(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clients = $this->clientService->getClients($email);
        
        // Build CSV data
        $rows = [];
        
        // Header
        $rows[] = [
            'Client Name', 'Domain', 'Status', 'Phone', 'Address', 'Notes',
            'Payment Terms (days)', 'Hourly Rate',
            'Billing Name', 'Tax Number', 'EU Tax Number',
            'Billing Address', 'Billing City', 'Billing ZIP', 'Billing Country', 'Bank Account',
            'Open Tasks', 'Overdue Tasks',
            'Contacts', 'Contact Emails', 'Contact Phones',
            'Linked Boards', 'Last Activity', 'Created'
        ];
        
        foreach ($clients as $client) {
            // Get contacts
            $contacts = [];
            $contactEmails = [];
            $contactPhones = [];
            try {
                $clientContacts = $this->clientService->getClientContacts($client['id']);
                foreach ($clientContacts as $contact) {
                    $contacts[] = $contact['name'] ?: $contact['email'];
                    $contactEmails[] = $contact['email'];
                    if (!empty($contact['phone'])) {
                        $contactPhones[] = $contact['phone'];
                    }
                }
            } catch (\Exception $e) {
                // Skip
            }
            
            // Get linked board names
            $boardNames = [];
            $boardIds = $this->clientService->getLinkedBoardIds($client['id']);
            if (!empty($boardIds)) {
                try {
                    $boardService = new BoardService($this->config);
                    foreach ($boardIds as $boardId) {
                        $board = $boardService->getBoard($email, $boardId);
                        if ($board) {
                            $boardNames[] = $board['name'];
                        }
                    }
                } catch (\Exception $e) {
                    // Skip
                }
            }
            
            $rows[] = [
                $client['display_name'] ?? $client['domain'],
                $client['domain'],
                $client['status'] ?? 'active',
                $client['phone'] ?? '',
                $client['address'] ?? '',
                $client['notes'] ?? '',
                $client['payment_terms_days'] ?? 30,
                $client['hourly_rate'] ?? '',
                $client['billing_name'] ?? '',
                $client['billing_tax_id'] ?? '',
                $client['billing_eu_tax_id'] ?? '',
                $client['billing_address'] ?? '',
                $client['billing_city'] ?? '',
                $client['billing_zip'] ?? '',
                $client['billing_country'] ?? '',
                $client['billing_bank_account'] ?? '',
                $client['open_task_count'] ?? 0,
                $client['overdue_task_count'] ?? 0,
                implode('; ', $contacts),
                implode('; ', $contactEmails),
                implode('; ', $contactPhones),
                implode('; ', $boardNames),
                $client['last_activity_at'] ?? '',
                $client['created_at'] ?? ''
            ];
        }
        
        // Generate CSV with UTF-8 BOM for Excel compatibility
        $output = "\xEF\xBB\xBF"; // UTF-8 BOM
        foreach ($rows as $row) {
            $escaped = array_map(function($cell) {
                $cell = str_replace('"', '""', (string)$cell);
                return '"' . $cell . '"';
            }, $row);
            $output .= implode(',', $escaped) . "\r\n";
        }
        
        $filename = 'clients_export_' . date('Y-m-d') . '.csv';
        
        return Response::raw($output, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($output)
        ]);
    }
    
    // =========================================================================
    // CSV IMPORT
    // =========================================================================

    /**
     * POST /clients/import - Import clients from CSV data
     * 
     * Accepts JSON body with csv_data (base64-encoded CSV) or rows (pre-parsed array).
     * Creates new clients and contacts, skipping duplicates by domain.
     * 
     * CSV columns (header row required, order-independent):
     *   Client Name, Domain, Phone, Address, Notes, Payment Terms, Hourly Rate,
     *   Billing Name, Tax Number, EU Tax Number, Billing Address, Billing City, Billing ZIP, Billing Country, Bank Account,
     *   Contact Name, Contact Email, Contact Phone, Contact Position
     */
    public function import(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $csvData = $request->input('csv_data'); // base64 CSV
        $rows = $request->input('rows');         // pre-parsed array

        if (!$csvData && !$rows) {
            return Response::json(['success' => false, 'message' => 'csv_data or rows is required'], 400);
        }

        try {
            $parsedRows = [];

            if ($csvData) {
                // Decode base64
                $raw = base64_decode($csvData, true);
                if ($raw === false) {
                    return Response::json(['success' => false, 'message' => 'Invalid base64 CSV data'], 400);
                }

                // Strip UTF-8 BOM if present
                if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
                    $raw = substr($raw, 3);
                }

                // Parse CSV
                $lines = str_getcsv($raw, "\n");
                if (count($lines) < 2) {
                    return Response::json(['success' => false, 'message' => 'CSV must have a header row and at least one data row'], 400);
                }

                // Parse header
                $headerLine = array_shift($lines);
                $headers = str_getcsv($headerLine);
                $headers = array_map(fn($h) => strtolower(trim($h)), $headers);

                // Map column names to indices
                $colMap = $this->buildImportColumnMap($headers);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;

                    $cols = str_getcsv($line);
                    $parsedRows[] = $this->mapCsvRow($cols, $colMap);
                }
            } elseif (is_array($rows)) {
                $parsedRows = $rows;
            }

            if (empty($parsedRows)) {
                return Response::json(['success' => false, 'message' => 'No valid rows to import'], 400);
            }

            // Process imports
            $results = [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'contacts_added' => 0,
                'errors' => [],
            ];

            foreach ($parsedRows as $i => $row) {
                $rowNum = $i + 2; // Account for 0-index + header row
                try {
                    $this->importSingleRow($email, $row, $results, $rowNum);
                } catch (\Throwable $e) {
                    $results['errors'][] = "Row {$rowNum}: " . $e->getMessage();
                    $results['skipped']++;
                }
            }

            return Response::json([
                'success' => true,
                'message' => sprintf(
                    'Import complete: %d created, %d updated, %d skipped, %d contacts added',
                    $results['created'], $results['updated'], $results['skipped'], $results['contacts_added']
                ),
                'data' => $results,
            ]);
        } catch (\Throwable $e) {
            error_log("ClientController::import error: " . $e->getMessage());
            return Response::json(['success' => false, 'message' => 'Import failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Map CSV header names to known field positions
     */
    private function buildImportColumnMap(array $headers): array
    {
        $map = [];
        $aliases = [
            'client_name' => ['client name', 'name', 'company', 'client', 'display_name', 'display name'],
            'domain' => ['domain', 'email domain', 'website'],
            'phone' => ['phone', 'telephone', 'tel', 'mobile'],
            'address' => ['address', 'company address', 'billing address'],
            'notes' => ['notes', 'description', 'comment', 'comments'],
            'payment_terms' => ['payment terms', 'payment terms (days)', 'payment_terms_days', 'net days'],
            'hourly_rate' => ['hourly rate', 'hourly_rate', 'rate', 'rate/hr'],
            'billing_name' => ['billing name', 'billing_name', 'company name', 'legal name', 'invoice name'],
            'billing_tax_id' => ['tax number', 'tax_number', 'billing_tax_id', 'tax id', 'adószám', 'adoszam'],
            'billing_eu_tax_id' => ['eu tax number', 'eu_tax_number', 'billing_eu_tax_id', 'eu vat', 'eu tax', 'vat number', 'eu adószám'],
            'billing_address' => ['billing address', 'billing_address', 'invoice address', 'company address'],
            'billing_city' => ['billing city', 'billing_city', 'city', 'város'],
            'billing_zip' => ['billing zip', 'billing_zip', 'zip', 'zip code', 'postal code', 'irányítószám'],
            'billing_country' => ['billing country', 'billing_country', 'country', 'country code', 'ország'],
            'billing_bank_account' => ['bank account', 'billing_bank_account', 'bank_account', 'bankszámlaszám', 'iban'],
            'contact_name' => ['contact name', 'contact', 'contact person', 'person'],
            'contact_email' => ['contact email', 'contact_email', 'email', 'contact e-mail'],
            'contact_phone' => ['contact phone', 'contact_phone', 'contact tel', 'contact mobile'],
            'contact_position' => ['contact position', 'position', 'title', 'job title', 'role'],
        ];

        foreach ($aliases as $field => $names) {
            foreach ($headers as $idx => $header) {
                if (in_array($header, $names, true)) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Map a CSV row array to a named associative array using the column map
     */
    private function mapCsvRow(array $cols, array $colMap): array
    {
        $row = [];
        foreach ($colMap as $field => $idx) {
            $row[$field] = isset($cols[$idx]) ? trim($cols[$idx]) : '';
        }
        return $row;
    }

    /**
     * Import a single client row
     */
    private function importSingleRow(string $userEmail, array $row, array &$results, int $rowNum): void
    {
        $clientName = $row['client_name'] ?? '';
        $domain = $row['domain'] ?? '';
        $contactEmail = $row['contact_email'] ?? '';

        // We need at least a domain OR a contact email to create a client
        if (empty($domain) && empty($contactEmail)) {
            $results['errors'][] = "Row {$rowNum}: Missing both domain and contact email, cannot create client";
            $results['skipped']++;
            return;
        }

        // If domain is empty but we have a contact email, derive domain from it
        if (empty($domain) && !empty($contactEmail)) {
            $parts = explode('@', $contactEmail);
            if (count($parts) === 2) {
                $domain = strtolower($parts[1]);
            }
        }

        // Check if client already exists by domain
        $existingClient = $this->clientService->getClientByDomain($userEmail, $domain);

        if ($existingClient) {
            // Update existing client with any new info
            $updateData = [];
            if (!empty($clientName) && empty($existingClient['display_name'])) {
                $updateData['display_name'] = $clientName;
            }
            if (!empty($row['phone'] ?? '') && empty($existingClient['phone'])) {
                $updateData['phone'] = $row['phone'];
            }
            if (!empty($row['address'] ?? '') && empty($existingClient['address'])) {
                $updateData['address'] = $row['address'];
            }
            if (!empty($row['notes'] ?? '') && empty($existingClient['notes'])) {
                $updateData['notes'] = $row['notes'];
            }
            if (!empty($row['payment_terms'] ?? '') && empty($existingClient['payment_terms_days'])) {
                $updateData['payment_terms_days'] = (int)$row['payment_terms'];
            }
            if (!empty($row['hourly_rate'] ?? '') && $existingClient['hourly_rate'] === null) {
                $updateData['hourly_rate'] = (float)$row['hourly_rate'];
            }
            // Billing/company fields - only fill if primary is empty
            $billingImportFields = [
                'billing_name', 'billing_tax_id', 'billing_eu_tax_id',
                'billing_address', 'billing_city', 'billing_zip', 'billing_country', 'billing_bank_account',
            ];
            foreach ($billingImportFields as $bf) {
                if (!empty($row[$bf] ?? '') && empty($existingClient[$bf])) {
                    $updateData[$bf] = $row[$bf];
                }
            }

            if (!empty($updateData)) {
                $this->clientService->updateClientInfo($userEmail, $existingClient['id'], $updateData);
                $results['updated']++;
            } else {
                $results['skipped']++;
            }

            $clientId = $existingClient['id'];
        } else {
            // Create new client using getOrCreateClient (handles generic vs company domains)
            $contactForCreate = !empty($contactEmail) ? $contactEmail : ('info@' . $domain);
            $client = $this->clientService->getOrCreateClient($userEmail, $contactForCreate, $clientName ?: null);

            if (!$client) {
                $results['errors'][] = "Row {$rowNum}: Failed to create client for domain '{$domain}'";
                $results['skipped']++;
                return;
            }

            $clientId = $client['id'];

            // Update with additional fields
            $updateData = [];
            if (!empty($clientName) && ($client['display_name'] ?? '') !== $clientName) {
                $updateData['display_name'] = $clientName;
            }
            if (!empty($row['phone'] ?? '')) $updateData['phone'] = $row['phone'];
            if (!empty($row['address'] ?? '')) $updateData['address'] = $row['address'];
            if (!empty($row['notes'] ?? '')) $updateData['notes'] = $row['notes'];
            if (!empty($row['payment_terms'] ?? '')) $updateData['payment_terms_days'] = (int)$row['payment_terms'];
            if (!empty($row['hourly_rate'] ?? '')) $updateData['hourly_rate'] = (float)$row['hourly_rate'];
            // Billing/company fields
            foreach (['billing_name', 'billing_tax_id', 'billing_eu_tax_id', 'billing_address', 'billing_city', 'billing_zip', 'billing_country', 'billing_bank_account'] as $bf) {
                if (!empty($row[$bf] ?? '')) $updateData[$bf] = $row[$bf];
            }

            if (!empty($updateData)) {
                $this->clientService->updateClientInfo($userEmail, $clientId, $updateData);
            }

            $results['created']++;
        }

        // Add contact if contact email is provided
        if (!empty($contactEmail) && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $contactName = $row['contact_name'] ?? null;
            $contactPhone = $row['contact_phone'] ?? null;
            $contactPosition = $row['contact_position'] ?? null;

            $contact = $this->clientService->addContact(
                $clientId,
                $contactEmail,
                $contactName ?: null,
                $contactPhone ?: null,
                $contactPosition ?: null
            );

            if ($contact) {
                $results['contacts_added']++;
            }
        }
    }

    // =========================================================================
    // OVERVIEW ENDPOINT
    // =========================================================================
    
    /**
     * GET /clients/overview - Get comprehensive overview of all clients with contacts, time, tasks
     */
    public function overview(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $period = $request->getQuery('period', 'all');
        
        $clients = $this->clientService->getClients($email);
        $boardService = new BoardService($this->config);
        $timeService = new ClientTimeTrackingService($this->config);
        
        // Pre-load colleague display names for all emails
        $nameCache = [];
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $stmt = $db->query('SELECT LOWER(email) as email, display_name FROM organization_colleagues WHERE display_name IS NOT NULL AND display_name != ""');
            foreach ($stmt->fetchAll() as $row) {
                $nameCache[$row['email']] = $row['display_name'];
            }
        } catch (\Exception $e) {
            // Non-critical, names will fall back to email prefix
        }
        
        $overview = [];
        
        foreach ($clients as $client) {
            $clientId = $client['id'];
            
            // Get contacts
            $contacts = $this->clientService->getClientContacts($clientId);
            
            // Get linked boards with task counts + financial data
            $boardIds = $this->clientService->getLinkedBoardIds($clientId);
            $boards = [];
            $totalTasks = 0;
            $completedTasks = 0;
            $overdueTasks = 0;
            $today = date('Y-m-d');
            $financialsByCurrency = [];
            $hasActiveWork = false;
            
            foreach ($boardIds as $boardId) {
                try {
                    $board = $boardService->getBoard($email, $boardId);
                    if ($board) {
                        $boardTotal = 0;
                        $boardCompleted = 0;
                        $boardOverdue = 0;
                        $boardFinancials = [];
                        
                        foreach ($board['lists'] ?? [] as $list) {
                            foreach ($list['cards'] ?? [] as $card) {
                                $boardTotal++;
                                if (!empty($card['completed'])) {
                                    $boardCompleted++;
                                } elseif (!empty($card['due_date']) && date('Y-m-d', strtotime($card['due_date'])) < $today) {
                                    $boardOverdue++;
                                }
                            }
                            
                            // Collect financial data from lists with expected_amount
                            if (!empty($list['expected_amount']) && (float)$list['expected_amount'] > 0) {
                                $curr = $list['currency'] ?? 'HUF';
                                $amt = (float)$list['expected_amount'];
                                $boardFinancials[$curr] = ($boardFinancials[$curr] ?? 0) + $amt;
                                $financialsByCurrency[$curr] = ($financialsByCurrency[$curr] ?? 0) + $amt;
                            }
                        }
                        
                        // Active work = has open (non-completed) tasks
                        $openTasks = $boardTotal - $boardCompleted;
                        if ($openTasks > 0) {
                            $hasActiveWork = true;
                        }
                        
                        $boards[] = [
                            'id' => $board['id'],
                            'name' => $board['name'],
                            'total_tasks' => $boardTotal,
                            'completed_tasks' => $boardCompleted,
                            'overdue_tasks' => $boardOverdue,
                            'progress' => $boardTotal > 0 ? round(($boardCompleted / $boardTotal) * 100) : 0,
                            'financials' => $boardFinancials
                        ];
                        
                        $totalTasks += $boardTotal;
                        $completedTasks += $boardCompleted;
                        $overdueTasks += $boardOverdue;
                    }
                } catch (\Exception $e) {
                    // Skip
                }
            }
            
            // Get time stats - use lightweight queries (no daily breakdown)
            $myTimeData = ['total_seconds' => 0, 'by_activity' => []];
            $teamTimeData = ['total_seconds' => 0, 'by_member' => [], 'by_activity' => []];
            try {
                $myTimeData = $timeService->getMyTimeStats($email, $clientId, $period);
                $teamTimeData = $timeService->getTeamTimeStats($clientId, $period);
            } catch (\Exception $e) {
                error_log("Overview: Failed to get time stats for client {$clientId}: " . $e->getMessage());
            }
            
            // Get team members and merge with time data
            $members = $this->clientService->getMembers($clientId);
            $byMemberFromTime = $teamTimeData['by_member'] ?? [];
            
            // Build a lookup of time by email
            $timeByEmail = [];
            foreach ($byMemberFromTime as $m) {
                $timeByEmail[strtolower($m['email'])] = $m;
            }
            
            // Build merged team list: owner + members, with time data
            $teamWithTime = [];
            $seenEmails = [];
            
            // Add owner first
            $ownerEmail = strtolower($client['user_email']);
            $ownerTime = $timeByEmail[$ownerEmail] ?? null;
            $ownerName = $nameCache[$ownerEmail] ?? $ownerTime['name'] ?? explode('@', $client['user_email'])[0];
            $teamWithTime[] = [
                'email' => $client['user_email'],
                'name' => $ownerName,
                'role' => 'owner',
                'total_seconds' => $ownerTime['total_seconds'] ?? 0,
                'seconds' => $ownerTime['seconds'] ?? 0
            ];
            $seenEmails[$ownerEmail] = true;
            
            // Add team members
            foreach ($members as $member) {
                $memberEmailLower = strtolower($member['user_email']);
                if (isset($seenEmails[$memberEmailLower])) continue;
                $memberTime = $timeByEmail[$memberEmailLower] ?? null;
                $memberName = $nameCache[$memberEmailLower] ?? $memberTime['name'] ?? explode('@', $member['user_email'])[0];
                $teamWithTime[] = [
                    'email' => $member['user_email'],
                    'name' => $memberName,
                    'role' => $member['role'] ?? 'member',
                    'total_seconds' => $memberTime['total_seconds'] ?? 0,
                    'seconds' => $memberTime['seconds'] ?? 0
                ];
                $seenEmails[$memberEmailLower] = true;
            }
            
            // Add anyone who tracked time but isn't a formal member
            foreach ($byMemberFromTime as $m) {
                $mEmail = strtolower($m['email']);
                if (!isset($seenEmails[$mEmail])) {
                    $teamWithTime[] = [
                        'email' => $m['email'],
                        'name' => $m['name'],
                        'role' => 'contributor',
                        'total_seconds' => $m['total_seconds'],
                        'seconds' => $m['seconds']
                    ];
                }
            }
            
            // Board Pro card-level estimates (adds to financials if table exists)
            $cardEstimates = [];
            try {
                $db = \Webmail\Core\Database::getConnection($this->config);
                $boardIdList = implode(',', array_map('intval', $boardIds));
                if ($boardIdList) {
                    $stmt = $db->query("
                        SELECT cf.currency, SUM(cf.estimated_revenue) AS total_revenue, SUM(cf.estimated_cost) AS total_cost
                        FROM boardpro_card_financials cf
                        JOIN webmail_board_cards bc ON bc.id = cf.card_id AND bc.archived = 0
                        JOIN webmail_board_lists bl ON bl.id = bc.list_id AND bl.archived = 0
                        WHERE bl.board_id IN ({$boardIdList})
                        GROUP BY cf.currency
                    ");
                    foreach ($stmt->fetchAll() as $row) {
                        $cur = $row['currency'] ?? 'HUF';
                        $cardEstimates[$cur] = [
                            'revenue' => (float) $row['total_revenue'],
                            'cost' => (float) $row['total_cost'],
                        ];
                    }
                }
            } catch (\Exception $e) {
                // boardpro_card_financials may not exist — skip
            }

            // CRM invoice summary per client
            $invoiceInfo = ['total_invoiced' => [], 'total_paid' => [], 'overdue' => []];
            try {
                $db = \Webmail\Core\Database::getConnection($this->config);
                $stmt = $db->prepare("
                    SELECT currency,
                           SUM(total) AS total_invoiced,
                           SUM(paid_amount) AS total_paid,
                           SUM(CASE WHEN status = 'overdue' THEN total - COALESCE(paid_amount, 0) ELSE 0 END) AS overdue_amount,
                           COUNT(*) AS invoice_count
                    FROM crm_invoices
                    WHERE client_id = ? AND LOWER(user_email) = LOWER(?)
                    GROUP BY currency
                ");
                $stmt->execute([$clientId, $email]);
                foreach ($stmt->fetchAll() as $row) {
                    $cur = $row['currency'] ?? 'HUF';
                    $invoiceInfo['total_invoiced'][$cur] = (float) $row['total_invoiced'];
                    $invoiceInfo['total_paid'][$cur] = (float) $row['total_paid'];
                    if ((float)$row['overdue_amount'] > 0) {
                        $invoiceInfo['overdue'][$cur] = (float) $row['overdue_amount'];
                    }
                }
            } catch (\Exception $e) {
                // crm_invoices may not exist — skip
            }

            $overview[] = [
                'id' => $client['id'],
                'display_name' => $client['display_name'] ?? $client['domain'],
                'domain' => $client['domain'],
                'status' => $client['status'] ?? 'active',
                'phone' => $client['phone'] ?? null,
                'address' => $client['address'] ?? null,
                'hourly_rate' => $client['hourly_rate'] ?? null,
                'payment_terms_days' => $client['payment_terms_days'] ?? 30,
                'last_activity_at' => $client['last_activity_at'],
                'created_at' => $client['created_at'],
                'contacts' => $contacts,
                'boards' => $boards,
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'overdue_tasks' => $overdueTasks,
                'open_tasks' => $totalTasks - $completedTasks,
                'progress' => $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0,
                'has_active_work' => $hasActiveWork,
                'financials' => $financialsByCurrency,
                'card_estimates' => $cardEstimates,
                'invoice_summary' => $invoiceInfo,
                'team' => $teamWithTime,
                'time' => [
                    'my_total_seconds' => $myTimeData['total_seconds'] ?? 0,
                    'team_total_seconds' => $teamTimeData['total_seconds'] ?? 0,
                    'by_member' => $teamWithTime,
                    'by_activity' => array_map(
                        fn($type, $secs) => ['type' => $type, 'total_seconds' => $secs],
                        array_keys($teamTimeData['by_activity'] ?? []),
                        array_values($teamTimeData['by_activity'] ?? [])
                    ),
                ]
            ];
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'clients' => $overview,
                'total_clients' => count($overview)
            ]
        ]);
    }
    
    // =========================================================================
    // DEBUG ENDPOINT
    // =========================================================================
    
    /**
     * GET /clients/{id}/debug - Get comprehensive debug info for a client
     * Shows all connections: owner, team members, boards, calendar events, drive folder
     */
    public function getDebugInfo(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        $clientId = (int)$request->getParam('id');
        
        // Get client data
        $client = $this->clientService->getClient($email, $clientId);
        if (!$client) {
            return Response::json(['success' => false, 'message' => 'Client not found'], 404);
        }
        
        // Get team members
        $members = $this->clientService->getMembers($clientId);
        
        // Get linked boards
        $boardIds = $this->clientService->getLinkedBoardIds($clientId);
        $linkedBoards = [];
        if (!empty($boardIds)) {
            $boardService = new BoardService($this->config);
            foreach ($boardIds as $boardId) {
                $board = $boardService->getBoard($email, $boardId);
                if ($board) {
                    $linkedBoards[] = [
                        'id' => $board['id'],
                        'name' => $board['name'],
                        'list_count' => count($board['lists'] ?? []),
                        'card_count' => array_sum(array_map(fn($l) => count($l['cards'] ?? []), $board['lists'] ?? []))
                    ];
                }
            }
        }
        
        // Get calendar events linked to this client
        $calendarEvents = [];
        $hasClientIdColumn = false;
        try {
            // Use shared DB connection
            $db = \Webmail\Core\Database::getConnection($this->config);
            
            // Check if client_id column exists
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'calendar_events' 
                AND COLUMN_NAME = 'client_id'
            ");
            $stmt->execute();
            $hasClientIdColumn = $stmt->fetchColumn() > 0;
            
            if ($hasClientIdColumn) {
                $stmt = $db->prepare("
                    SELECT e.id, e.title, e.start_time, e.end_time, e.client_id, c.name as calendar_name
                    FROM calendar_events e
                    JOIN calendars c ON e.calendar_id = c.id
                    WHERE e.client_id = ? AND c.user_email = ?
                    ORDER BY e.start_time DESC
                    LIMIT 20
                ");
                $stmt->execute([$clientId, strtolower($email)]);
                $calendarEvents = $stmt->fetchAll();
            }
        } catch (\Exception $e) {
            error_log("Debug getCalendarEvents error: " . $e->getMessage());
        }
        
        // Get Drive folder info
        $driveFolder = null;
        if (!empty($client['drive_folder_id'])) {
            try {
                $driveService = new \Webmail\Services\DriveService($this->config);
                $folder = $driveService->getFolder($email, $client['drive_folder_id']);
                if ($folder) {
                    // Count items in the folder
                    $files = $driveService->getFiles($email, $client['drive_folder_id']);
                    $subfolders = $driveService->getFolders($email, $client['drive_folder_id']);
                    
                    $driveFolder = [
                        'id' => $folder['id'],
                        'name' => $folder['name'],
                        'file_count' => count($files),
                        'subfolder_count' => count($subfolders)
                    ];
                }
            } catch (\Exception $e) {
                error_log("Debug getDriveFolder error: " . $e->getMessage());
                $driveFolder = [
                    'id' => $client['drive_folder_id'],
                    'name' => '(folder not found or error)',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // Get time tracking data
        $timeTracking = [
            'my_time' => [],
            'team_time' => [],
            'activity_log' => [],
            'by_activity_type' => []
        ];
        try {
            $timeService = new ClientTimeTrackingService($this->config);
            
            // Get time stats using lightweight queries (avoid getDailyBreakdown crash with 'all')
            $timeTracking['my_time'] = $timeService->getMyTimeStats($email, $clientId, 'all');
            $timeTracking['team_time'] = $timeService->getTeamTimeStats($clientId, 'all');
            
            // Get activity log (recent entries)
            $timeTracking['activity_log'] = $timeService->getActivityLog($clientId, null, 'all', 20);
            
            // Get breakdown by activity type using direct query
            $stmt = $db->prepare("
                SELECT 
                    activity_type,
                    user_email,
                    SUM(duration_seconds) as total_seconds,
                    COUNT(*) as entry_count,
                    MAX(updated_at) as last_activity
                FROM webmail_client_time_tracking
                WHERE client_id = ?
                GROUP BY activity_type, user_email
                ORDER BY total_seconds DESC
            ");
            $stmt->execute([$clientId]);
            $timeTracking['by_activity_type'] = $stmt->fetchAll();
            
            // Get total time
            $cumulative = $timeService->getCumulativeTime($clientId);
            $timeTracking['total_seconds'] = $cumulative['total_seconds'] ?? 0;
            
            // Get calendar event durations (actual meeting/event time)
            $eventDurations = $timeService->getCalendarEventDurations($clientId, 'all');
            $timeTracking['calendar_event_durations'] = $eventDurations;
            
            // Get portal call durations (completed calls)
            $portalCallDurations = $timeService->getPortalCallDurations($clientId, 'all');
            $timeTracking['portal_call_durations'] = $portalCallDurations;
            
            // Calculate combined total (tracked time + event durations)
            $timeTracking['combined_total_seconds'] = $timeTracking['total_seconds'] + ($eventDurations['total_seconds'] ?? 0);
            
        } catch (\Exception $e) {
            error_log("Debug getTimeTracking error: " . $e->getMessage());
            $timeTracking['error'] = $e->getMessage();
        }
        
        return Response::json([
            'success' => true,
            'data' => [
                'client' => [
                    'id' => $client['id'],
                    'domain' => $client['domain'],
                    'display_name' => $client['display_name'],
                    'owner_email' => $client['user_email'],
                    'status' => $client['status'],
                    'drive_folder_id' => $client['drive_folder_id'] ?? null,
                    'created_at' => $client['created_at'],
                    'last_activity_at' => $client['last_activity_at']
                ],
                'current_user' => [
                    'email' => $email,
                    'is_owner' => strtolower($email) === strtolower($client['user_email'])
                ],
                'team_members' => $members,
                'linked_boards' => $linkedBoards,
                'calendar_events' => $calendarEvents,
                'drive_folder' => $driveFolder,
                'time_tracking' => $timeTracking,
                'summary' => [
                    'team_member_count' => count($members),
                    'board_count' => count($linkedBoards),
                    'calendar_event_count' => count($calendarEvents),
                    'has_drive_folder' => !empty($driveFolder),
                    'calendar_has_client_id_column' => $hasClientIdColumn,
                    'total_time_seconds' => $timeTracking['total_seconds'] ?? 0,
                    'event_duration_seconds' => $timeTracking['calendar_event_durations']['total_seconds'] ?? 0,
                    'portal_call_seconds' => $timeTracking['portal_call_durations']['total_seconds'] ?? 0,
                    'portal_call_count' => $timeTracking['portal_call_durations']['call_count'] ?? 0,
                    'combined_time_seconds' => $timeTracking['combined_total_seconds'] ?? 0
                ]
            ]
        ]);
    }
    
    /**
     * GET /clients/time-debug - Diagnostic: show all time tracking data summary
     * Temporary endpoint for debugging time tracking issues
     */
    public function timeDebug(Request $request): Response
    {
        $email = $this->getUser($request);
        if (!$email) {
            return Response::json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            
            // Check if table exists
            $stmt = $db->query("SHOW TABLES LIKE 'webmail_client_time_tracking'");
            $tableExists = $stmt->fetch() !== false;
            
            if (!$tableExists) {
                return Response::json([
                    'success' => true,
                    'data' => [
                        'table_exists' => false,
                        'message' => 'webmail_client_time_tracking table does not exist!'
                    ]
                ]);
            }
            
            // Get total row count and sum
            $stmt = $db->query('SELECT COUNT(*) as total_rows, SUM(duration_seconds) as total_seconds FROM webmail_client_time_tracking');
            $totals = $stmt->fetch();
            
            // Get data grouped by client_id with client names
            $stmt = $db->query('
                SELECT t.client_id, c.display_name, c.domain, 
                       COUNT(*) as row_count, 
                       SUM(t.duration_seconds) as total_seconds,
                       MIN(t.tracked_date) as first_tracked,
                       MAX(t.tracked_date) as last_tracked
                FROM webmail_client_time_tracking t
                LEFT JOIN clients c ON c.id = t.client_id
                GROUP BY t.client_id, c.display_name, c.domain
                ORDER BY total_seconds DESC
            ');
            $byClient = $stmt->fetchAll();
            
            // Get data grouped by user_email
            $stmt = $db->query('
                SELECT user_email, COUNT(*) as row_count, SUM(duration_seconds) as total_seconds
                FROM webmail_client_time_tracking
                GROUP BY user_email
                ORDER BY total_seconds DESC
            ');
            $byUser = $stmt->fetchAll();
            
            // Get recent 10 entries
            $stmt = $db->query('
                SELECT t.*, c.display_name, c.domain as client_domain
                FROM webmail_client_time_tracking t
                LEFT JOIN clients c ON c.id = t.client_id
                ORDER BY t.updated_at DESC
                LIMIT 10
            ');
            $recentEntries = $stmt->fetchAll();
            
            // Get database name being used
            $stmt = $db->query('SELECT DATABASE() as db_name');
            $dbName = $stmt->fetch()['db_name'];
            
            return Response::json([
                'success' => true,
                'data' => [
                    'table_exists' => true,
                    'database' => $dbName,
                    'total_rows' => (int)($totals['total_rows'] ?? 0),
                    'total_seconds' => (int)($totals['total_seconds'] ?? 0),
                    'current_user' => $email,
                    'by_client' => $byClient,
                    'by_user' => $byUser,
                    'recent_entries' => $recentEntries
                ]
            ]);
        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Debug query failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
}

