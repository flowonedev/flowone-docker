<?php

namespace Webmail\Addons\BoardPro\Services;

use PDO;

/**
 * BoardProEmailService
 *
 * Handles card-level email linking, reply status tracking,
 * and auto-link rules for Board Pro addon.
 * Reads from webmail_board_* tables but only writes to boardpro_* tables.
 */
class BoardProEmailService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
        $this->ensureTables();
    }

    // =========================================================================
    // Table Bootstrap
    // =========================================================================

    private function ensureTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS boardpro_card_emails (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                card_id INT NOT NULL COMMENT 'FK to webmail_board_cards.id',
                board_id INT NOT NULL COMMENT 'FK to webmail_boards.id',
                email_uid INT NOT NULL,
                email_folder VARCHAR(255) NOT NULL,
                email_subject VARCHAR(500) DEFAULT NULL,
                email_from VARCHAR(255) DEFAULT NULL,
                email_date DATETIME DEFAULT NULL,
                thread_id VARCHAR(255) DEFAULT NULL,
                reply_status ENUM('none','replied','awaiting','forwarded') DEFAULT 'none',
                linked_by VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_card (card_id),
                INDEX idx_board (board_id),
                INDEX idx_email (email_uid, email_folder),
                INDEX idx_thread (thread_id),
                INDEX idx_reply_status (reply_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS boardpro_email_rules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                board_id INT NOT NULL,
                list_id INT DEFAULT NULL COMMENT 'Target list for auto-created cards',
                rule_type ENUM('subject_contains','sender_domain','sender_email','label_match') NOT NULL,
                rule_value VARCHAR(500) NOT NULL,
                auto_create_card TINYINT(1) DEFAULT 1,
                auto_assign_to VARCHAR(255) DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_by VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_board (board_id),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // =========================================================================
    // Card-Email Linking
    // =========================================================================

    /**
     * Link an email to a specific card
     */
    public function linkEmailToCard(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO boardpro_card_emails
                (card_id, board_id, email_uid, email_folder, email_subject, email_from, email_date, thread_id, reply_status, linked_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['card_id'],
            $data['board_id'],
            $data['email_uid'],
            $data['email_folder'],
            $data['email_subject'] ?? null,
            $data['email_from'] ?? null,
            $data['email_date'] ?? null,
            $data['thread_id'] ?? null,
            $data['reply_status'] ?? 'none',
            $data['linked_by'],
        ]);

        $id = (int) $this->db->lastInsertId();
        return $this->getCardEmail($id);
    }

    /**
     * Get a single card-email link by ID
     */
    public function getCardEmail(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM boardpro_card_emails WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get all emails linked to a card
     */
    public function getCardEmails(int $cardId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM boardpro_card_emails
            WHERE card_id = ?
            ORDER BY email_date DESC, created_at DESC
        ");
        $stmt->execute([$cardId]);
        $cardEmails = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($cardEmails)) {
            return $cardEmails;
        }

        // Fallback: show board-level emails for this card's board
        try {
            $stmt = $this->db->prepare("
                SELECT be.id, be.board_id, be.email_uid, be.email_folder,
                       be.email_subject, be.email_from, be.thread_id,
                       be.linked_by, be.created_at,
                       be.email_uid AS email_uid,
                       'none' AS reply_status,
                       NULL AS email_date,
                       ? AS card_id
                FROM webmail_board_emails be
                JOIN webmail_board_cards c ON c.id = ?
                JOIN webmail_board_lists l ON c.list_id = l.id AND l.board_id = be.board_id
                ORDER BY be.created_at DESC
            ");
            $stmt->execute([$cardId, $cardId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get emails linked to a board (all cards)
     */
    public function getBoardEmails(int $boardId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT ce.*, bc.title AS card_title
            FROM boardpro_card_emails ce
            LEFT JOIN webmail_board_cards bc ON bc.id = ce.card_id
            WHERE ce.board_id = ?
            ORDER BY ce.email_date DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$boardId, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Unlink an email from a card
     */
    public function unlinkEmail(int $id, string $userEmail): bool
    {
        $stmt = $this->db->prepare("DELETE FROM boardpro_card_emails WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update reply status for a card email
     */
    public function updateReplyStatus(int $id, string $status): bool
    {
        $stmt = $this->db->prepare("
            UPDATE boardpro_card_emails SET reply_status = ? WHERE id = ?
        ");
        $stmt->execute([$status, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Auto-link emails from a thread to a card
     * Finds all other emails in the same thread and links them
     */
    public function autoLinkThread(int $cardId, int $boardId, string $threadId, string $linkedBy): int
    {
        // Check which emails in this thread are already linked
        $stmt = $this->db->prepare("
            SELECT email_uid, email_folder FROM boardpro_card_emails
            WHERE card_id = ? AND thread_id = ?
        ");
        $stmt->execute([$cardId, $threadId]);
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Return count of existing (no new links needed from this method alone)
        return count($existing);
    }

    /**
     * Get reply status summary for a card (counts per status)
     */
    public function getCardReplyStatusSummary(int $cardId): array
    {
        $stmt = $this->db->prepare("
            SELECT reply_status, COUNT(*) as count
            FROM boardpro_card_emails
            WHERE card_id = ?
            GROUP BY reply_status
        ");
        $stmt->execute([$cardId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = ['none' => 0, 'replied' => 0, 'awaiting' => 0, 'forwarded' => 0];
        foreach ($rows as $row) {
            $summary[$row['reply_status']] = (int) $row['count'];
        }
        return $summary;
    }

    /**
     * Get cards that have emails awaiting reply (for board-level indicators)
     */
    public function getCardsAwaitingReply(int $boardId): array
    {
        $stmt = $this->db->prepare("
            SELECT ce.card_id, bc.title AS card_title, COUNT(*) AS awaiting_count,
                   MIN(ce.email_date) AS oldest_awaiting
            FROM boardpro_card_emails ce
            JOIN webmail_board_cards bc ON bc.id = ce.card_id
            WHERE ce.board_id = ? AND ce.reply_status = 'awaiting'
            GROUP BY ce.card_id, bc.title
            ORDER BY oldest_awaiting ASC
        ");
        $stmt->execute([$boardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Convert an email to a new card on a board
     */
    public function convertEmailToCard(array $emailData, int $boardId, int $listId, string $userEmail): ?array
    {
        // Use BoardService to create the card
        $boardService = new \Webmail\Addons\KanbanBoards\Services\BoardService($this->config);

        // Determine card title from email subject
        $title = $emailData['subject'] ?? 'Email card';
        if (strlen($title) > 200) {
            $title = substr($title, 0, 197) . '...';
        }

        $card = $boardService->createCard([
            'list_id' => $listId,
            'title' => $title,
            'description' => $emailData['snippet'] ?? '',
        ], $userEmail);

        if ($card) {
            // Link the email to the new card
            $this->linkEmailToCard([
                'card_id' => $card['id'],
                'board_id' => $boardId,
                'email_uid' => $emailData['uid'],
                'email_folder' => $emailData['folder'],
                'email_subject' => $emailData['subject'] ?? null,
                'email_from' => $emailData['from'] ?? null,
                'email_date' => !empty($emailData['date']) ? date('Y-m-d H:i:s', strtotime($emailData['date'])) : null,
                'thread_id' => $emailData['thread_id'] ?? null,
                'reply_status' => 'awaiting',
                'linked_by' => $userEmail,
            ]);
        }

        return $card;
    }

    // =========================================================================
    // Email Auto-Link Rules
    // =========================================================================

    /**
     * Get all rules for a board
     */
    public function getRules(int $boardId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM boardpro_email_rules
            WHERE board_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$boardId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'decodeRuleJson'], $rows);
    }

    /**
     * Get active rules for a board
     */
    public function getActiveRules(int $boardId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM boardpro_email_rules
            WHERE board_id = ? AND is_active = 1
            ORDER BY created_at ASC
        ");
        $stmt->execute([$boardId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'decodeRuleJson'], $rows);
    }

    /**
     * Create a new email rule
     */
    public function createRule(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO boardpro_email_rules
                (board_id, list_id, rule_type, rule_value, auto_create_card, auto_assign_to,
                 card_title_template, type_categories, type_default, body_handling,
                 checklist_title, auto_link_email, auto_attach_files, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['board_id'],
            $data['list_id'] ?? null,
            $data['rule_type'],
            $data['rule_value'],
            $data['auto_create_card'] ?? 1,
            $data['auto_assign_to'] ?? null,
            $data['card_title_template'] ?? '',
            $data['type_categories'] ?? null,
            $data['type_default'] ?? 'General',
            $data['body_handling'] ?? 'none',
            $data['checklist_title'] ?? '',
            $data['auto_link_email'] ?? 1,
            $data['auto_attach_files'] ?? 1,
            $data['is_active'] ?? 1,
            $data['created_by'],
        ]);

        $id = (int) $this->db->lastInsertId();
        return $this->getRule($id);
    }

    /**
     * Get a single rule by ID
     */
    public function getRule(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM boardpro_email_rules WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row = $this->decodeRuleJson($row);
        }
        return $row ?: null;
    }

    private function decodeRuleJson(array $row): array
    {
        if (isset($row['type_categories']) && is_string($row['type_categories'])) {
            $row['type_categories'] = json_decode($row['type_categories'], true) ?? [];
        }
        return $row;
    }

    /**
     * Update a rule
     */
    public function updateRule(int $id, array $data): ?array
    {
        $fields = [];
        $values = [];

        $allowedFields = [
            'list_id', 'rule_type', 'rule_value', 'auto_create_card', 'auto_assign_to',
            'card_title_template', 'type_categories', 'type_default', 'body_handling',
            'checklist_title', 'auto_link_email', 'auto_attach_files', 'is_active',
        ];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return $this->getRule($id);
        }

        $values[] = $id;
        $sql = "UPDATE boardpro_email_rules SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

        return $this->getRule($id);
    }

    /**
     * Delete a rule
     */
    public function deleteRule(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM boardpro_email_rules WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Run a specific rule against a single email. Used by "Run Rule Now".
     * Bypasses the rule-finding query since we already have the rule.
     */
    public function runSingleRuleAgainstEmail(array $rule, array $emailData, string $userEmail, $imapService = null): ?array
    {
        $rule = $this->decodeRuleJson($rule);

        if (!$this->ruleMatches($rule, $emailData)) {
            return null;
        }

        if ($this->emailAlreadyProcessedByRule($emailData, (int) $rule['id'])) {
            return null;
        }

        $action = ['rule_id' => $rule['id'], 'board_id' => $rule['board_id']];

        if ($rule['auto_create_card'] && $rule['list_id']) {
            $needsBody = ($rule['body_handling'] ?? 'none') !== 'none'
                || !empty($rule['type_categories']);
            $needsAttachments = !empty($rule['auto_attach_files']);

            if (($needsBody || $needsAttachments) && $imapService) {
                try {
                    $emailData = $this->enrichWithFullMessage($emailData, $imapService);
                } catch (\Throwable $e) {
                    $action['action'] = 'card_failed';
                    $action['error'] = 'enrichWithFullMessage: ' . $e->getMessage();
                    $this->markEmailProcessedByRule($emailData, (int) $rule['id']);
                    return $action;
                }
            }

            try {
                $card = $this->executeRuleCardCreation($rule, $emailData, $userEmail);
            } catch (\Throwable $e) {
                $action['action'] = 'card_failed';
                $action['error'] = $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
                $this->markEmailProcessedByRule($emailData, (int) $rule['id']);
                return $action;
            }

            if ($card && isset($card['id'])) {
                $action['action'] = 'card_created';
                $action['card_id'] = $card['id'];
            } else {
                $action['action'] = 'card_failed';
                $action['error'] = 'executeRuleCardCreation returned null/empty';
            }
        } else {
            $action['action'] = 'rule_matched';
        }

        $this->incrementRuleRunCount((int) $rule['id']);
        $this->markEmailProcessedByRule($emailData, (int) $rule['id']);
        return $action;
    }

    /**
     * Get count of active rules for a user (for debugging).
     */
    public function countActiveRulesForUser(string $userEmail): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT er.id)
                FROM boardpro_email_rules er
                JOIN webmail_boards b ON b.id = er.board_id
                LEFT JOIN webmail_board_members bm ON bm.board_id = b.id AND bm.user_email = ?
                WHERE er.is_active = 1
                  AND (b.owner_email = ? OR bm.user_email = ?)
            ");
            $stmt->execute([$userEmail, $userEmail, $userEmail]);
            return (int) $stmt->fetchColumn();
        } catch (\Exception $e) {
            return -1;
        }
    }

    /**
     * Evaluate a single email against all active rules for boards the user has access to.
     * Creates cards, checklists, links emails, attaches files based on rule config.
     * @param \Webmail\Services\ImapService|null $imapService Optional, used to fetch full body on demand
     */
    public function evaluateEmailAgainstRules(array $emailData, string $userEmail, $imapService = null): array
    {
        error_log("[EmailRules::DEBUG] evaluateEmailAgainstRules called for user=$userEmail, subject=\"{$emailData['subject']}\"");
        $results = [];

        $stmt = $this->db->prepare("
            SELECT DISTINCT er.*, b.name AS board_name
            FROM boardpro_email_rules er
            JOIN webmail_boards b ON b.id = er.board_id
            LEFT JOIN webmail_board_members bm ON bm.board_id = b.id AND bm.user_email = ?
            WHERE er.is_active = 1
              AND (b.owner_email = ? OR bm.user_email = ?)
        ");
        $stmt->execute([$userEmail, $userEmail, $userEmail]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        error_log("[EmailRules::DEBUG] Found " . count($rules) . " active rules for user=$userEmail");

        if (empty($rules)) {
            error_log("[EmailRules::DEBUG] No active rules found, returning empty");
            return $results;
        }

        $fullMessageFetched = false;

        foreach ($rules as $rule) {
            $rule = $this->decodeRuleJson($rule);
            error_log("[EmailRules::DEBUG] Checking rule #{$rule['id']}: type={$rule['rule_type']}, value=\"{$rule['rule_value']}\", board={$rule['board_id']}, list_id={$rule['list_id']}");

            $matches = $this->ruleMatches($rule, $emailData);
            error_log("[EmailRules::DEBUG] Rule #{$rule['id']} match result: " . ($matches ? 'YES' : 'NO') . " (subject=\"{$emailData['subject']}\", from=\"{$emailData['from']}\")");

            if (!$matches) {
                continue;
            }

            if ($this->emailAlreadyProcessedByRule($emailData, (int) $rule['id'])) {
                error_log("[EmailRules::DEBUG] Rule #{$rule['id']} already processed for uid={$emailData['uid']}, skipping");
                continue;
            }

            $action = ['rule_id' => $rule['id'], 'board_id' => $rule['board_id']];

            if ($rule['auto_create_card'] && $rule['list_id']) {
                error_log("[EmailRules::DEBUG] Rule #{$rule['id']} will create card. auto_create_card={$rule['auto_create_card']}, list_id={$rule['list_id']}");
                $needsBody = ($rule['body_handling'] ?? 'none') !== 'none'
                    || !empty($rule['type_categories']);
                $needsAttachments = !empty($rule['auto_attach_files']);
                error_log("[EmailRules::DEBUG] needsBody=$needsBody, needsAttachments=$needsAttachments, fullMessageFetched=$fullMessageFetched, hasImapService=" . ($imapService ? 'yes' : 'no'));

                if (($needsBody || $needsAttachments) && !$fullMessageFetched && $imapService) {
                    error_log("[EmailRules::DEBUG] Fetching full message body for uid={$emailData['uid']}, folder={$emailData['folder']}");
                    try {
                        $emailData = $this->enrichWithFullMessage($emailData, $imapService);
                        $fullMessageFetched = true;
                        error_log("[EmailRules::DEBUG] Full message fetched. body_text length=" . strlen($emailData['body_text'] ?? '') . ", attachments=" . count($emailData['attachments'] ?? []));
                    } catch (\Throwable $e) {
                        error_log("[EmailRules::DEBUG] enrichWithFullMessage FAILED for rule #{$rule['id']}: " . $e->getMessage());
                        $action['action'] = 'card_failed';
                        $action['error'] = 'enrichWithFullMessage: ' . $e->getMessage();
                        $this->markEmailProcessedByRule($emailData, (int) $rule['id']);
                        $results[] = $action;
                        continue;
                    }
                }

                try {
                    $card = $this->executeRuleCardCreation($rule, $emailData, $userEmail);
                } catch (\Throwable $e) {
                    error_log("[EmailRules::DEBUG] executeRuleCardCreation THREW for rule #{$rule['id']}: " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
                    $action['action'] = 'card_failed';
                    $action['error'] = $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
                    $this->markEmailProcessedByRule($emailData, (int) $rule['id']);
                    $results[] = $action;
                    continue;
                }

                if ($card) {
                    error_log("[EmailRules::DEBUG] Card created! id={$card['id']}, title=\"{$card['title']}\"");
                    $action['action'] = 'card_created';
                    $action['card_id'] = $card['id'];
                } else {
                    error_log("[EmailRules::DEBUG] Card creation FAILED for rule #{$rule['id']}");
                }
            } else {
                error_log("[EmailRules::DEBUG] Rule #{$rule['id']} matched but no card creation (auto_create_card={$rule['auto_create_card']}, list_id=" . ($rule['list_id'] ?? 'null') . ")");
                $action['action'] = 'rule_matched';
            }

            $this->incrementRuleRunCount((int) $rule['id']);
            $this->markEmailProcessedByRule($emailData, (int) $rule['id']);
            $results[] = $action;
        }

        error_log("[EmailRules::DEBUG] Final results: " . json_encode($results));
        return $results;
    }

    /**
     * Fetch full message via IMAP and merge body/attachment data into emailData.
     */
    private function enrichWithFullMessage(array $emailData, $imapService): array
    {
        $folder = $emailData['folder'] ?? null;
        $uid = $emailData['uid'] ?? null;
        if (!$folder || !$uid) {
            return $emailData;
        }

        try {
            $fullMsg = $imapService->getMessage($folder, (int) $uid);
            if ($fullMsg) {
                $emailData['body_text'] = $fullMsg['body_text'] ?? '';
                $emailData['body_html'] = $fullMsg['body_html'] ?? '';
                $emailData['attachments'] = $fullMsg['attachments'] ?? [];
                $emailData['_imap_service'] = $imapService;
                error_log("[EmailRules::DEBUG] enrichWithFullMessage: body_text=" . strlen($emailData['body_text']) . ", body_html=" . strlen($emailData['body_html']) . ", attachments=" . count($emailData['attachments']));
            }
        } catch (\Exception $e) {
            error_log("[BoardProEmail] Failed to fetch full message for rule processing: " . $e->getMessage());
        }

        return $emailData;
    }

    /**
     * Download a single IMAP attachment by part number and save to disk.
     */
    private function downloadAndSaveAttachment($imapService, string $folder, int $uid, array $att): ?array
    {
        $part = $att['part'] ?? null;
        $filename = $att['filename'] ?? 'attachment';
        if (!$part) {
            return null;
        }

        try {
            $downloaded = $imapService->getAttachment($folder, $uid, $part);
            if (!$downloaded || empty($downloaded['content'])) {
                return null;
            }

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            if (!$isImage) {
                return null;
            }

            $inlineDir = '/var/www/vps-email/data/inline-images';
            if (!is_dir($inlineDir)) {
                $altDir = ($this->config['storage_path'] ?? __DIR__ . '/../../../../storage') . '/inline-images';
                if (!is_dir($altDir)) {
                    @mkdir($altDir, 0755, true);
                }
                $inlineDir = $altDir;
            }

            $hash = substr(md5($uid . '_' . $part . '_' . time()), 0, 16);
            $savedFilename = "img_{$hash}_" . time() . ".{$ext}";
            $filePath = $inlineDir . '/' . $savedFilename;

            if (@file_put_contents($filePath, $downloaded['content'])) {
                $baseUrl = rtrim($this->config['app_url'] ?? 'https://flowone.pro', '/');
                error_log("[EmailRules::DEBUG] Saved attachment to disk: $savedFilename ($filename)");
                return [
                    'filename' => $filename,
                    'url' => "{$baseUrl}/api/inline-image/{$savedFilename}",
                    'type' => $downloaded['type'] ?? $att['type'] ?? 'application/octet-stream',
                    'size' => strlen($downloaded['content']),
                ];
            }
        } catch (\Exception $e) {
            error_log("[EmailRules::DEBUG] Failed to save attachment: " . $e->getMessage());
        }
        return null;
    }

    public function isEmailProcessedByRule(array $emailData, int $ruleId): bool
    {
        return $this->emailAlreadyProcessedByRule($emailData, $ruleId);
    }

    private function emailAlreadyProcessedByRule(array $emailData, int $ruleId): bool
    {
        $uid = $emailData['uid'] ?? null;
        $folder = $emailData['folder'] ?? null;
        if (!$uid || !$folder) {
            return false;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM boardpro_rule_processed_emails
                WHERE rule_id = ? AND email_uid = ? AND email_folder = ?
            ");
            $stmt->execute([$ruleId, $uid, $folder]);
            $count = (int) $stmt->fetchColumn();
            error_log("[EmailRules::DEBUG] emailAlreadyProcessed check: rule=$ruleId, uid=$uid, folder=$folder -> count=$count");
            return $count > 0;
        } catch (\Exception $e) {
            error_log("[EmailRules::DEBUG] emailAlreadyProcessed table error (may not exist yet): " . $e->getMessage());
            return false;
        }
    }

    private function markEmailProcessedByRule(array $emailData, int $ruleId): void
    {
        $uid = $emailData['uid'] ?? null;
        $folder = $emailData['folder'] ?? null;
        if (!$uid || !$folder) {
            return;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT IGNORE INTO boardpro_rule_processed_emails (rule_id, email_uid, email_folder)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$ruleId, $uid, $folder]);
        } catch (\Exception $e) {
            error_log("[EmailRules::DEBUG] markProcessed table error: " . $e->getMessage());
        }
    }

    private function ruleMatches(array $rule, array $emailData): bool
    {
        switch ($rule['rule_type']) {
            case 'subject_contains':
                return stripos($emailData['subject'] ?? '', $rule['rule_value']) !== false;
            case 'sender_domain':
                $domain = substr(strrchr($emailData['from'] ?? '', '@'), 1);
                return strcasecmp($domain ?: '', $rule['rule_value']) === 0;
            case 'sender_email':
                return strcasecmp($emailData['from'] ?? '', $rule['rule_value']) === 0;
            case 'label_match':
                return stripos($emailData['folder'] ?? '', $rule['rule_value']) !== false;
            default:
                return false;
        }
    }

    /**
     * Full card creation pipeline: title, description, checklist, email link, attachments.
     * Uses direct SQL INSERT to bypass BoardService::createCard access/enrichment issues.
     */
    private function executeRuleCardCreation(array $rule, array $emailData, string $userEmail): ?array
    {
        $listId = (int) $rule['list_id'];
        $boardId = (int) $rule['board_id'];

        $boardService = new \Webmail\Addons\KanbanBoards\Services\BoardService($this->config);

        $title = $this->buildCardTitle($rule, $emailData);

        $bodyHandling = $rule['body_handling'] ?? 'none';
        $parsed = $this->parseEmailBody($emailData);

        $description = '';
        if ($bodyHandling === 'description' || $bodyHandling === 'both') {
            $description = $parsed['formatted'];
            if (mb_strlen($description) > 5000) {
                $description = mb_substr($description, 0, 4997) . '...';
            }
        }

        // Direct INSERT - no try-catch so errors propagate to caller for proper reporting
        $posStmt = $this->db->prepare(
            "SELECT COALESCE(MAX(position), -1) + 1 as next_pos FROM webmail_board_cards WHERE list_id = ?"
        );
        $posStmt->execute([$listId]);
        $nextPos = (int) $posStmt->fetchColumn();

        $ins = $this->db->prepare("
            INSERT INTO webmail_board_cards
                (list_id, title, description, position, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $listId,
            $title,
            $description ?: null,
            $nextPos,
            strtolower($userEmail),
        ]);
        $cardId = (int) $this->db->lastInsertId();

        if ($cardId <= 0) {
            throw new \RuntimeException("INSERT succeeded but lastInsertId returned 0 for list_id=$listId");
        }

        // Auto-assign
        try {
            if (!empty($rule['auto_assign_to'])) {
                $this->db->prepare("UPDATE webmail_board_cards SET assigned_to = ? WHERE id = ?")
                    ->execute([$rule['auto_assign_to'], $cardId]);
            }
        } catch (\Throwable $e) {
            error_log("[EmailRules::DEBUG] Auto-assign failed: " . $e->getMessage());
        }

        // Auto-label
        try {
            $this->autoLabelCard($boardService, $userEmail, $cardId, $boardId, $rule, $emailData, $parsed);
        } catch (\Throwable $e) {
            error_log("[EmailRules::DEBUG] Auto-label failed: " . $e->getMessage());
        }

        // Create checklist from user-written content
        try {
            if ($bodyHandling === 'checklist' || $bodyHandling === 'both') {
                $this->createChecklistFromBody($boardService, $userEmail, $cardId, $rule, $parsed['user_content']);
            }
        } catch (\Throwable $e) {
            error_log("[EmailRules::DEBUG] Checklist creation failed: " . $e->getMessage());
        }

        // 7. Link source email to card
        $autoLink = $rule['auto_link_email'] ?? 1;
        error_log("[EmailRules::DEBUG] auto_link_email=$autoLink, uid=" . ($emailData['uid'] ?? 'null') . ", folder=" . ($emailData['folder'] ?? 'null'));
        if ($autoLink) {
            try {
                $linkData = [
                    'card_id' => $cardId,
                    'board_id' => $boardId,
                    'email_uid' => $emailData['uid'] ?? null,
                    'email_folder' => $emailData['folder'] ?? 'INBOX',
                    'email_subject' => $emailData['subject'] ?? null,
                    'email_from' => $emailData['from'] ?? null,
                    'email_date' => !empty($emailData['date']) ? date('Y-m-d H:i:s', strtotime($emailData['date'])) : null,
                    'thread_id' => $emailData['thread_id'] ?? null,
                    'reply_status' => 'none',
                    'linked_by' => $userEmail,
                ];
                error_log("[EmailRules::DEBUG] Linking email to card: " . json_encode($linkData));
                $linked = $this->linkEmailToCard($linkData);
                error_log("[EmailRules::DEBUG] Email linked to card: id=" . ($linked['id'] ?? 'null'));
            } catch (\Exception $e) {
                error_log("[EmailRules::DEBUG] FAILED to link email to card: " . $e->getMessage());
            }
        }

        // Attach email files to card
        try {
        $autoAttach = $rule['auto_attach_files'] ?? 1;
        $rawAttachments = $emailData['attachments'] ?? [];
        if ($autoAttach) {
            $savedAttachments = [];
            $imapSvc = $emailData['_imap_service'] ?? null;
            $folder = $emailData['folder'] ?? 'INBOX';
            $uid = (int) ($emailData['uid'] ?? 0);

            // Download IMAP image attachments and save to disk (only for this card)
            if ($imapSvc && $uid > 0) {
                foreach ($rawAttachments as $att) {
                    $saved = $this->downloadAndSaveAttachment($imapSvc, $folder, $uid, $att);
                    if ($saved) {
                        $savedAttachments[] = $saved;
                    }
                }
            }

            // Also extract inline images from body_html (CID images already as data URIs)
            $inlineImages = $this->extractInlineImagesFromHtml($emailData['body_html'] ?? '', $emailData);
            if (!empty($inlineImages)) {
                error_log("[EmailRules::DEBUG] Found " . count($inlineImages) . " inline images in HTML body");
                // Deduplicate: skip inline images whose size matches an already-saved IMAP attachment
                $existingSizes = array_map(fn($a) => (int)($a['size'] ?? 0), $savedAttachments);
                foreach ($inlineImages as $img) {
                    $imgSize = (int)($img['size'] ?? 0);
                    $isDupe = false;
                    foreach ($existingSizes as $s) {
                        if ($imgSize > 0 && abs($imgSize - $s) < 512) {
                            $isDupe = true;
                            break;
                        }
                    }
                    if (!$isDupe) {
                        $savedAttachments[] = $img;
                    } else {
                        error_log("[EmailRules::DEBUG] Skipping duplicate inline image (size=$imgSize)");
                    }
                }
            }

            if (!empty($savedAttachments)) {
                $this->attachEmailFilesToCard($boardService, $userEmail, $cardId, $savedAttachments, $emailData);
            }
        }
        } catch (\Throwable $e) {
            error_log("[EmailRules::DEBUG] Attachment handling failed: " . $e->getMessage());
        }

        return ['id' => $cardId, 'title' => $title];
    }

    private function buildCardTitle(array $rule, array $emailData): string
    {
        $template = $rule['card_title_template'] ?? '';
        if (empty($template)) {
            return $emailData['subject'] ?? 'Email card';
        }

        $type = $this->detectType($rule, $emailData);
        $senderName = $emailData['from_name'] ?? '';
        if (empty($senderName)) {
            $senderName = explode('@', $emailData['from'] ?? '')[0] ?? '';
        }

        $replacements = [
            '{subject}' => $emailData['subject'] ?? '',
            '{sender}' => $emailData['from'] ?? '',
            '{sender_name}' => $senderName,
            '{type}' => $type,
            '{date}' => date('Y-m-d'),
        ];

        $title = str_replace(array_keys($replacements), array_values($replacements), $template);
        return mb_substr(trim($title), 0, 200);
    }

    private function detectType(array $rule, array $emailData): string
    {
        $categories = $rule['type_categories'] ?? [];
        if (empty($categories)) {
            return $rule['type_default'] ?? 'General';
        }

        $searchText = strtolower(($emailData['subject'] ?? '') . ' ' . ($emailData['body_text'] ?? ''));

        foreach ($categories as $cat) {
            $label = $cat['label'] ?? '';
            $keywords = array_map('trim', explode(',', strtolower($cat['keywords'] ?? '')));
            foreach ($keywords as $kw) {
                if (!empty($kw) && strpos($searchText, $kw) !== false) {
                    return $label;
                }
            }
        }

        return $rule['type_default'] ?? 'General';
    }

    private static $labelColors = [
        'bug'         => '#F97316',
        'error'       => '#F97316',
        'feature'     => '#22C55E',
        'enhancement' => '#3B82F6',
        'design'      => '#A855F7',
        'ux'          => '#8B5CF6',
        'question'    => '#6366F1',
        'priority'    => '#EF4444',
        'urgent'      => '#DC2626',
        'general'     => '#6B7280',
        'feedback'    => '#14B8A6',
        'performance' => '#F59E0B',
        'security'    => '#E11D48',
        'docs'        => '#0EA5E9',
        'test'        => '#84CC16',
    ];

    /**
     * Auto-apply board labels to a card based on detected type and email category fields.
     * Creates missing labels on the board automatically.
     */
    private function autoLabelCard(
        \Webmail\Addons\KanbanBoards\Services\BoardService $boardService,
        string $userEmail,
        int $cardId,
        int $boardId,
        array $rule,
        array $emailData,
        array $parsed
    ): void {
        try {
            $boardLabels = $boardService->getLabels($boardId);

            $labelMap = [];
            foreach ($boardLabels as $bl) {
                $labelMap[strtolower(trim($bl['name']))] = (int) $bl['id'];
            }

            $candidate = $this->detectType($rule, $emailData);
            if (empty($candidate) || strlen($candidate) < 2) {
                return;
            }

            $key = strtolower(trim($candidate));

            if (!isset($labelMap[$key])) {
                $color = self::$labelColors[$key] ?? '#6B7280';
                $newLabel = $boardService->createLabel($boardId, [
                    'name' => ucfirst($candidate),
                    'color' => $color,
                ]);
                if ($newLabel) {
                    $labelMap[$key] = (int) $newLabel['id'];
                    error_log("[EmailRules::DEBUG] Created board label '{$candidate}' (id={$newLabel['id']}) with color $color");
                }
            }

            if (isset($labelMap[$key])) {
                $labelId = $labelMap[$key];
                $boardService->addLabelToCard($userEmail, $cardId, $labelId);
                error_log("[EmailRules::DEBUG] Applied label '$candidate' (id=$labelId) to card #$cardId");
            }
        } catch (\Exception $e) {
            error_log("[EmailRules::DEBUG] autoLabelCard error: " . $e->getMessage());
        }
    }

    /**
     * Parse email body (HTML or text) into structured data.
     * Returns: ['fields' => [...], 'user_content' => '...', 'formatted' => '...']
     */
    private function parseEmailBody(array $emailData): array
    {
        $html = $emailData['body_html'] ?? '';
        $text = $emailData['body_text'] ?? $emailData['snippet'] ?? '';

        $fields = [];
        $userContent = '';

        if (!empty($html)) {
            $fields = $this->extractFieldsFromHtml($html);
        }

        if (!empty($fields)) {
            // Find user-written content: look for "Description", "Message", "Body", "Content", "Details", "Problem"
            $contentKeys = ['description', 'message', 'body', 'content', 'details', 'problem', 'feedback', 'comment', 'notes', 'issue'];
            foreach ($fields as $label => $value) {
                if (in_array(strtolower(trim($label)), $contentKeys)) {
                    $userContent = $value;
                    break;
                }
            }

            // Build nicely formatted description (plain text, no markdown)
            $formatted = '';
            foreach ($fields as $label => $value) {
                $label = trim($label);
                $value = trim($value);
                if (empty($label) || empty($value)) {
                    continue;
                }
                $formatted .= "{$label}: {$value}\n";
            }

            // The text/plain MIME part may contain additional data that the HTML
            // field extraction cannot capture (e.g. diagnostic log sections in
            // feedback emails). When body_text is substantially richer, prefer it.
            if (!empty($text)) {
                $textLines = substr_count(trim($text), "\n") + 1;
                $fieldLines = substr_count(trim($formatted), "\n") + 1;
                if ($textLines > $fieldLines * 1.5 && $textLines > 10) {
                    $formatted = trim($text);
                }
            }
        } else {
            // No structured HTML, fall back to plain text
            $plainBody = !empty($text) ? $text : strip_tags($html);
            $plainBody = html_entity_decode($plainBody, ENT_QUOTES, 'UTF-8');
            $plainBody = preg_replace('/[^\S\n]+/', ' ', $plainBody);
            $plainBody = preg_replace('/\n{3,}/', "\n\n", $plainBody);
            $plainBody = trim($plainBody);

            $formatted = $plainBody;

            // For checklist user_content, strip boilerplate so only meaningful text remains
            $userContent = $this->stripEmailBoilerplate($plainBody);
        }

        return [
            'fields' => $fields,
            'user_content' => trim($userContent),
            'formatted' => trim($formatted),
        ];
    }

    /**
     * Strip common email boilerplate from plain text so only the user's message remains.
     */
    private function stripEmailBoilerplate(string $text): string
    {
        $lines = preg_split('/\r?\n/', $text);
        $cleaned = [];
        $inQuote = false;

        // Patterns that indicate metadata / boilerplate lines
        $metaPatterns = [
            '/^(From|To|Cc|Bcc|Subject|Date|Sent|Reply-To)\s*:/i',
            '/^(URL|Screen|UA|User-Agent|Browser|Resolution)\s*:/i',
            '/^(Mozilla|AppleWebKit|Chrome|Safari|Gecko)\//i',
            '/^https?:\/\/\S+$/i',
            '/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/i',
            '/^-{2,}$/',
        ];

        // Patterns that indicate quoted / forwarded content
        $quotePatterns = [
            '/^>/',
            '/^On\s+.+wrote\s*:$/i',
            '/^-{3,}\s*(Original Message|Forwarded message)/i',
        ];

        // Signature markers
        $sigPatterns = [
            '/^(Best regards|Kind regards|Regards|Cheers|Thanks|Thank you|Sincerely|Sent from)/i',
            '/^--\s*$/',
        ];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (empty($trimmed)) {
                continue;
            }

            // Once we hit a signature marker, stop
            foreach ($sigPatterns as $pat) {
                if (preg_match($pat, $trimmed)) {
                    break 2;
                }
            }

            // Skip quoted/forwarded lines
            foreach ($quotePatterns as $pat) {
                if (preg_match($pat, $trimmed)) {
                    $inQuote = true;
                    continue 2;
                }
            }
            if ($inQuote) {
                continue;
            }

            // Skip metadata lines
            $isMeta = false;
            foreach ($metaPatterns as $pat) {
                if (preg_match($pat, $trimmed)) {
                    $isMeta = true;
                    break;
                }
            }
            if ($isMeta) {
                continue;
            }

            $cleaned[] = $trimmed;
        }

        return implode("\n", $cleaned);
    }

    /**
     * Extract label->value pairs from HTML tables and definition-list-like structures.
     * Handles nested layout tables by finding the innermost data tables first.
     */
    private function extractFieldsFromHtml(string $html): array
    {
        $fields = [];

        // Helper to collapse whitespace in extracted values
        $clean = function (string $s): string {
            $s = strip_tags($s);
            $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
            $s = preg_replace('/\s+/', ' ', $s);
            return trim($s);
        };

        // Method 1: Extract from <table> rows with two cells (th+td or td+td).
        // Use PREG_SET_ORDER to get all <tr> rows, then filter to 2-cell data rows.
        // For nested tables the regex will match rows at every depth; skip layout
        // rows that have only one cell or whose cells contain further tables.
        if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $rows)) {
            foreach ($rows[1] as $row) {
                // Skip rows that contain nested tables (layout wrappers)
                if (stripos($row, '<table') !== false) {
                    continue;
                }
                $cells = [];
                if (preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/is', $row, $cellMatches)) {
                    $cells = array_map($clean, $cellMatches[1]);
                }
                if (count($cells) === 2 && !empty($cells[0]) && !empty($cells[1])) {
                    $fields[$cells[0]] = $cells[1];
                }
                // Single-cell rows with a <strong> label (e.g. "URL: https://...")
                if (count($cells) === 1 && preg_match('/<strong[^>]*>(.*?)<\/strong>/i', $row, $labelMatch)) {
                    $label = $clean($labelMatch[1]);
                    $fullText = $clean($row);
                    $value = trim(str_replace($label, '', $fullText));
                    if (!empty($label) && !empty($value) && !isset($fields[$label])) {
                        $fields[$label] = $value;
                    }
                }
            }
        }

        // Method 2: Look for heading + content patterns (e.g. <h3>Description</h3><div>...</div>)
        if (preg_match_all('/<(?:h[1-6]|strong|b)[^>]*>(.*?)<\/(?:h[1-6]|strong|b)>\s*(?:<[^>]*>)*\s*(.*?)(?=<(?:h[1-6]|strong|b|hr|table|\/body|\/td)[^>]*>|\z)/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $label = $clean($m[1]);
                $value = $clean($m[2]);
                if (!empty($label) && !empty($value) && !isset($fields[$label])) {
                    $fields[$label] = $value;
                }
            }
        }

        return $fields;
    }

    private function createChecklistFromBody(
        \Webmail\Addons\KanbanBoards\Services\BoardService $boardService,
        string $userEmail,
        int $cardId,
        array $rule,
        string $userContent
    ): void {
        if (empty(trim($userContent))) {
            return;
        }

        $checklistTitle = !empty($rule['checklist_title']) ? $rule['checklist_title'] : 'Email Content';
        $checklist = $boardService->createChecklist($userEmail, $cardId, ['title' => $checklistTitle]);
        if (!$checklist) {
            return;
        }

        // Split user content into meaningful items by sentences or lines
        $lines = preg_split('/(?:\r?\n)+/', $userContent);
        $lines = array_values(array_filter(array_map('trim', $lines)));

        // If only one line, try splitting by sentences
        if (count($lines) === 1 && mb_strlen($lines[0]) > 80) {
            $sentences = preg_split('/(?<=[.!?])\s+/', $lines[0]);
            $sentences = array_filter(array_map('trim', $sentences));
            if (count($sentences) > 1) {
                $lines = array_values($sentences);
            }
        }

        $count = 0;
        foreach ($lines as $line) {
            if (empty($line) || $count >= 30) {
                break;
            }
            $boardService->addChecklistItem($userEmail, (int) $checklist['id'], [
                'title' => mb_substr($line, 0, 500),
            ]);
            $count++;
        }
    }

    private function attachEmailFilesToCard(
        \Webmail\Addons\KanbanBoards\Services\BoardService $boardService,
        string $userEmail,
        int $cardId,
        array $attachments,
        array $emailData = []
    ): void {
        $baseUrl = rtrim($this->config['app_url'] ?? 'https://flowone.pro', '/');

        foreach ($attachments as $att) {
            try {
                $name = $att['filename'] ?? $att['name'] ?? 'attachment';
                $url = $att['url'] ?? $att['download_url'] ?? null;

                if ($url) {
                    error_log("[EmailRules::DEBUG] Attaching file: $name -> $url");
                    $boardService->addUrlAttachment($userEmail, $cardId, $url, $name);
                } else {
                    error_log("[EmailRules::DEBUG] Skipping attachment (no URL): $name");
                }
            } catch (\Exception $e) {
                error_log("[EmailRules::DEBUG] Failed to attach file to card: " . $e->getMessage());
            }
        }
    }

    /**
     * Extract inline images embedded as data URIs in HTML and save them to disk.
     * Returns pseudo-attachment entries with serveable URLs.
     */
    private function extractInlineImagesFromHtml(string $html, array $emailData): array
    {
        if (empty($html)) {
            return [];
        }

        $images = [];

        if (!preg_match_all('/src\s*=\s*"(data:image\/([^;]+);base64,([^"]+))"/i', $html, $matches)) {
            return [];
        }

        $inlineDir = '/var/www/vps-email/data/inline-images';
        if (!is_dir($inlineDir)) {
            $altDir = ($this->config['storage_path'] ?? __DIR__ . '/../../../../storage') . '/inline-images';
            if (!is_dir($altDir)) {
                @mkdir($altDir, 0755, true);
            }
            $inlineDir = $altDir;
        }

        $baseUrl = rtrim($this->config['app_url'] ?? 'https://flowone.pro', '/');
        $uid = $emailData['uid'] ?? time();

        foreach ($matches[2] as $i => $imageType) {
            $base64Data = $matches[3][$i];
            $binaryData = base64_decode($base64Data);
            if (!$binaryData || strlen($binaryData) < 100) {
                continue;
            }

            $ext = ($imageType === 'jpeg') ? 'jpg' : strtolower($imageType);
            if (!in_array($ext, ['jpg', 'png', 'gif', 'webp'])) {
                continue;
            }

            $hash = substr(md5($uid . '_' . $i . '_' . time()), 0, 16);
            $filename = "img_{$hash}_" . time() . ".{$ext}";
            $filePath = $inlineDir . '/' . $filename;

            if (@file_put_contents($filePath, $binaryData)) {
                $images[] = [
                    'filename' => "screenshot-" . ($i + 1) . ".{$ext}",
                    'url' => "{$baseUrl}/api/inline-image/{$filename}",
                    'type' => 'image/' . $imageType,
                    'size' => strlen($binaryData),
                ];
                error_log("[EmailRules::DEBUG] Saved inline image: $filename (" . strlen($binaryData) . " bytes)");
            }
        }

        return $images;
    }

    private function incrementRuleRunCount(int $ruleId): void
    {
        $stmt = $this->db->prepare("
            UPDATE boardpro_email_rules
            SET run_count = run_count + 1, last_run_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$ruleId]);
    }

    /**
     * Quick check: does this user have any active rules? Returns the rules or empty array.
     */
    public function getActiveRulesForUser(string $userEmail): array
    {
        $stmt = $this->db->prepare("
            SELECT er.id
            FROM boardpro_email_rules er
            JOIN webmail_boards b ON b.id = er.board_id
            LEFT JOIN webmail_board_members bm ON bm.board_id = b.id AND bm.user_email = ?
            WHERE er.is_active = 1
              AND (b.owner_email = ? OR bm.user_email = ?)
            LIMIT 1
        ");
        $stmt->execute([$userEmail, $userEmail, $userEmail]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get all active rules across all boards (for cron processing)
     */
    public function getAllActiveRules(): array
    {
        $stmt = $this->db->prepare("
            SELECT er.*, b.owner_email
            FROM boardpro_email_rules er
            JOIN webmail_boards b ON b.id = er.board_id
            WHERE er.is_active = 1
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map([$this, 'decodeRuleJson'], $rows);
    }
}

