<?php

namespace Webmail\Services;

/**
 * LinkedAccountSyncService - Syncs emails from linked accounts to main inbox
 * 
 * This service handles:
 * - Fetching new emails from linked accounts
 * - Copying them to the primary account's inbox
 * - Adding custom headers to identify source
 * - Syncing deletions back to source
 */
class LinkedAccountSyncService
{
    private AccountService $accountService;
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->accountService = new AccountService($config);
    }
    
    /**
     * Sync all linked accounts that need syncing
     * Returns summary of sync results
     */
    public function syncAll(): array
    {
        $results = [
            'accounts_synced' => 0,
            'emails_fetched' => 0,
            'emails_deleted' => 0,
            'errors' => [],
        ];
        
        $accounts = $this->accountService->getAccountsNeedingSync();
        
        foreach ($accounts as $account) {
            try {
                $result = $this->syncAccount($account);
                $results['accounts_synced']++;
                $results['emails_fetched'] += $result['fetched'];
                $results['emails_deleted'] += $result['deleted'];
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'account' => $account['account_email'],
                    'error' => $e->getMessage(),
                ];
                error_log("LinkedAccountSync error for {$account['account_email']}: " . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Sync a specific linked account
     */
    public function syncAccount(array $account): array
    {
        $result = [
            'fetched' => 0,
            'deleted' => 0,
        ];
        
        // Connect to linked account
        $linkedImap = new ImapService([
            'host' => $account['imap_host'],
            'port' => (int)$account['imap_port'],
            'encryption' => $account['imap_encryption'],
            'validate_cert' => false,
        ]);
        
        if (!$linkedImap->connect($account['account_email'], $account['password'])) {
            throw new \Exception('Failed to connect to linked account');
        }
        
        // Connect to primary account (main mailbox)
        $primaryImap = new ImapService($this->config['imap']);
        
        // Get primary credentials from the token/session - we'll need this passed in
        // For now, we'll use a workaround - store fetched emails in a temp location
        // and append them to the primary inbox
        
        try {
            // Fetch new emails from linked account
            $result['fetched'] = $this->fetchNewEmails($linkedImap, $account);
            
            // Sync deletions back if not leaving on server
            if (!$account['leave_on_server']) {
                $result['deleted'] = $this->syncDeletions($linkedImap, $account);
            }
            
            // Update last sync time
            $this->accountService->updateLastSync($account['primary_email'], $account['id']);
            
        } finally {
            $linkedImap->disconnect();
        }
        
        return $result;
    }
    
    /**
     * Fetch new emails from linked account
     */
    private function fetchNewEmails(ImapService $linkedImap, array $account): int
    {
        $fetched = 0;
        $folder = 'INBOX';
        
        if (!$linkedImap->selectFolder($folder)) {
            throw new \Exception("Failed to select folder: $folder");
        }
        
        // Get already synced UIDs
        $syncedUids = $this->accountService->getSyncedMessageUids($account['id'], $folder);
        
        // Get all UIDs from linked account
        $allUids = $linkedImap->searchMessages('ALL');
        
        // Find new UIDs
        $newUids = array_diff($allUids, $syncedUids);
        
        if (empty($newUids)) {
            return 0;
        }
        
        // Limit to prevent overwhelming - sync max 50 at a time
        $newUids = array_slice($newUids, 0, 50);
        
        foreach ($newUids as $uid) {
            try {
                // Get message details
                $message = $linkedImap->getMessage($folder, $uid);
                if (!$message) continue;
                
                // Get the raw message source
                $rawMessage = $linkedImap->getRawMessage($uid);
                if (!$rawMessage) continue;
                
                // Add custom header to identify source account
                $rawMessage = $this->addLinkedAccountHeader($rawMessage, $account);
                
                // Store the message for the primary account
                // We'll save it to a temp file and let the primary account append it
                $stored = $this->storeForPrimaryAccount($rawMessage, $account, $message);
                
                if ($stored) {
                    // Record synced message
                    $messageId = $message['message_id'] ?? $this->generateMessageId($message);
                    $this->accountService->recordSyncedMessage(
                        $account['id'],
                        $folder,
                        $uid,
                        $messageId
                    );
                    $fetched++;
                }
                
            } catch (\Exception $e) {
                error_log("Error syncing message UID $uid from {$account['account_email']}: " . $e->getMessage());
            }
        }
        
        return $fetched;
    }
    
    /**
     * Add X-Linked-Account header to identify source
     */
    private function addLinkedAccountHeader(string $rawMessage, array $account): string
    {
        // Find the end of headers (first empty line)
        $headerEnd = strpos($rawMessage, "\r\n\r\n");
        if ($headerEnd === false) {
            $headerEnd = strpos($rawMessage, "\n\n");
        }
        
        if ($headerEnd === false) {
            return $rawMessage;
        }
        
        $headers = substr($rawMessage, 0, $headerEnd);
        $body = substr($rawMessage, $headerEnd);
        
        // Add custom headers
        $customHeaders = "X-Linked-Account: {$account['account_email']}\r\n";
        $customHeaders .= "X-Linked-Account-Id: {$account['id']}\r\n";
        
        if ($account['auto_label']) {
            $customHeaders .= "X-Auto-Label: {$account['auto_label']}\r\n";
        }
        
        return $headers . "\r\n" . $customHeaders . $body;
    }
    
    /**
     * Store message for primary account to pick up
     * Uses file-based queue that primary account can append to IMAP
     */
    private function storeForPrimaryAccount(string $rawMessage, array $account, array $messageInfo): bool
    {
        // Create queue directory
        $queueDir = '/var/www/vps-email/data/sync-queue/' . md5($account['primary_email']);
        if (!is_dir($queueDir)) {
            mkdir($queueDir, 0755, true);
        }
        
        // Save message to queue
        $filename = $queueDir . '/' . time() . '_' . uniqid() . '.eml';
        $metadata = [
            'from_account' => $account['account_email'],
            'account_id' => $account['id'],
            'auto_label' => $account['auto_label'],
            'subject' => $messageInfo['subject'] ?? 'No Subject',
            'from' => $messageInfo['from'] ?? '',
            'date' => $messageInfo['date'] ?? date('Y-m-d H:i:s'),
        ];
        
        // Save metadata alongside
        file_put_contents($filename . '.meta', json_encode($metadata));
        
        return file_put_contents($filename, $rawMessage) !== false;
    }
    
    /**
     * Process queued messages for a primary account
     * Called when the primary user is online
     */
    public function processQueue(string $primaryEmail, string $primaryPassword): array
    {
        $result = [
            'processed' => 0,
            'labeled' => 0,
            'errors' => [],
        ];
        
        $queueDir = '/var/www/vps-email/data/sync-queue/' . md5($primaryEmail);
        if (!is_dir($queueDir)) {
            return $result;
        }
        
        // Connect to primary account's IMAP
        $primaryImap = new ImapService($this->config['imap']);
        if (!$primaryImap->connect($primaryEmail, $primaryPassword)) {
            throw new \Exception('Failed to connect to primary account');
        }
        
        $labelService = new LabelService($this->config);
        $labelCache = [];
        
        try {
            $files = glob($queueDir . '/*.eml');
            
            foreach ($files as $file) {
                try {
                    $rawMessage = file_get_contents($file);
                    $metaFile = $file . '.meta';
                    $metadata = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];
                    
                    if ($primaryImap->appendMessage('INBOX', $rawMessage)) {
                        // Auto-label the imported message
                        $autoLabel = $metadata['auto_label'] ?? ($metadata['from_account'] ?? null);
                        if ($autoLabel) {
                            $messageId = $this->extractMessageIdFromRaw($rawMessage);
                            if ($messageId) {
                                $labelId = $this->getOrCreateAutoLabel($labelService, $primaryEmail, $autoLabel, $labelCache);
                                if ($labelId) {
                                    $labelService->addLabelToMessage($primaryEmail, $messageId, $labelId);
                                    $result['labeled']++;
                                }
                            }
                        }
                        
                        unlink($file);
                        if (file_exists($metaFile)) {
                            unlink($metaFile);
                        }
                        $result['processed']++;
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = $e->getMessage();
                }
            }
        } finally {
            $primaryImap->disconnect();
        }
        
        return $result;
    }
    
    /**
     * Extract Message-ID from raw email source
     */
    private function extractMessageIdFromRaw(string $rawMessage): ?string
    {
        $headerEnd = strpos($rawMessage, "\r\n\r\n");
        if ($headerEnd === false) {
            $headerEnd = strpos($rawMessage, "\n\n");
        }
        if ($headerEnd === false) {
            return null;
        }
        
        $headers = substr($rawMessage, 0, $headerEnd);
        
        if (preg_match('/^Message-ID:\s*<?([^>\s]+)>?\s*$/mi', $headers, $matches)) {
            return trim($matches[1], '<>');
        }
        
        return null;
    }
    
    /**
     * Get or create an auto-label for a linked account, with caching
     */
    private function getOrCreateAutoLabel(LabelService $labelService, string $primaryEmail, string $labelName, array &$cache): ?int
    {
        $labelName = strtolower(trim($labelName));
        if (empty($labelName)) {
            return null;
        }
        
        if (isset($cache[$labelName])) {
            return $cache[$labelName];
        }
        
        $labels = $labelService->getLabels($primaryEmail);
        foreach ($labels as $label) {
            if (strtolower($label['name']) === $labelName) {
                $cache[$labelName] = (int)$label['id'];
                return $cache[$labelName];
            }
        }
        
        // Create the label with a distinct color (cyan for linked accounts)
        $result = $labelService->createLabel($primaryEmail, $labelName, '#06b6d4');
        if ($result) {
            $cache[$labelName] = (int)$result['id'];
            return $cache[$labelName];
        }
        
        return null;
    }
    
    /**
     * Sync deletions back to source account
     */
    private function syncDeletions(ImapService $linkedImap, array $account): int
    {
        $deleted = 0;
        
        $deletedMessages = $this->accountService->getDeletedSyncedMessages($account['id']);
        
        foreach ($deletedMessages as $msg) {
            try {
                if ($linkedImap->selectFolder($msg['source_folder'])) {
                    if ($linkedImap->deleteMessage($msg['source_uid'])) {
                        $this->accountService->removeSyncedMessage(
                            $account['id'],
                            $msg['source_uid'],
                            $msg['source_folder']
                        );
                        $deleted++;
                    }
                }
            } catch (\Exception $e) {
                error_log("Error deleting message from source: " . $e->getMessage());
            }
        }
        
        return $deleted;
    }
    
    /**
     * Generate a message ID if not present
     */
    private function generateMessageId(array $message): string
    {
        $data = ($message['from'] ?? '') . ($message['date'] ?? '') . ($message['subject'] ?? '');
        return '<' . md5($data) . '@linked>';
    }
    
    /**
     * Get sync status for all linked accounts of a user
     */
    public function getSyncStatus(string $primaryEmail): array
    {
        $accounts = $this->accountService->getLinkedAccounts($primaryEmail);
        
        $status = [];
        foreach ($accounts as $account) {
            $status[] = [
                'id' => $account['id'],
                'email' => $account['account_email'],
                'last_sync' => $account['last_sync'],
                'sync_enabled' => $account['sync_enabled'],
                'sync_frequency' => $account['sync_frequency'],
            ];
        }
        
        return $status;
    }
    
    /**
     * Manually trigger sync for a specific account
     */
    public function triggerSync(string $primaryEmail, int $accountId): array
    {
        $account = $this->accountService->getAccountWithCredentials($primaryEmail, $accountId);
        
        if (!$account || $account['account_type'] !== 'linked') {
            throw new \Exception('Account not found or not a linked account');
        }
        
        // Auto-fill auto_label for existing accounts that don't have one
        if (empty($account['auto_label'])) {
            $autoLabel = strtolower($account['account_email']);
            $this->accountService->updateAccount($primaryEmail, $accountId, ['auto_label' => $autoLabel]);
            $account['auto_label'] = $autoLabel;
        }
        
        return $this->syncAccount($account);
    }
}

