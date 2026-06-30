<?php

namespace Webmail\Services;

use PDO;

class ContactsService
{
    private PDO $db;
    private ?array $config;
    private ?\Webmail\Addons\Contacts\Services\AddressBookService $addressBook = null;

    /**
     * @param PDO        $db
     * @param array|null $config Optional app config. When provided, the service
     *                           reconciles the seen-cache with the real address
     *                           book (threshold auto-add + unified autocomplete).
     *                           When null, it behaves as the legacy cache-only
     *                           service (backward compatible).
     */
    public function __construct(PDO $db, ?array $config = null)
    {
        $this->db = $db;
        $this->config = $config;
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTableExists());
    }

    private function ensureTableExists(): void
    {
        try {
            // Contacts table - stores frequently used email addresses
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS email_contacts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL COMMENT 'Owner/sender email',
                    contact_email VARCHAR(255) NOT NULL,
                    contact_name VARCHAR(255) DEFAULT NULL,
                    use_count INT DEFAULT 1,
                    last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_contact (user_email, contact_email),
                    INDEX idx_user_email (user_email),
                    INDEX idx_use_count (use_count DESC),
                    INDEX idx_last_used (last_used DESC)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Self-heal the link to the canonical address-book contact (migration 185).
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "email_contacts" AND COLUMN_NAME = "contact_id"'
            );
            $stmt->execute();
            if ((int) $stmt->fetchColumn() === 0) {
                $this->db->exec('ALTER TABLE email_contacts ADD COLUMN contact_id INT NULL');
            }
        } catch (\PDOException $e) {
            error_log("ContactsService table creation error: " . $e->getMessage());
        }
    }

    /** Lazily build the address-book service (only when config was supplied). */
    private function addressBook(): ?\Webmail\Addons\Contacts\Services\AddressBookService
    {
        if ($this->config === null) {
            return null;
        }
        if ($this->addressBook === null) {
            try {
                $this->addressBook = new \Webmail\Addons\Contacts\Services\AddressBookService($this->config);
            } catch (\Throwable $e) {
                error_log('ContactsService addressBook init error: ' . $e->getMessage());
                return null;
            }
        }
        return $this->addressBook;
    }

    /** Configured number of sends after which a seen address auto-collects. */
    private function autoAddThreshold(): int
    {
        $t = (int) ($this->config['contacts']['auto_add_threshold'] ?? 3);
        return $t > 0 ? $t : 3;
    }

    /**
     * Add or update a contact when email is sent
     */
    public function recordContact(string $userEmail, string $contactEmail, ?string $contactName = null): bool
    {
        try {
            $contactEmail = strtolower(trim($contactEmail));
            $userEmail = strtolower(trim($userEmail));
            
            if (!$contactEmail || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                return false;
            }

            // Try to insert, on duplicate update use_count and name
            $stmt = $this->db->prepare('
                INSERT INTO email_contacts (user_email, contact_email, contact_name, use_count)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE 
                    use_count = use_count + 1,
                    contact_name = COALESCE(VALUES(contact_name), contact_name),
                    last_used = CURRENT_TIMESTAMP
            ');
            
            $ok = $stmt->execute([$userEmail, $contactEmail, $contactName]);
            $this->maybeAutoCollect($userEmail, $contactEmail, $contactName);
            return $ok;
        } catch (\PDOException $e) {
            error_log("ContactsService recordContact error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Threshold auto-collect: once a seen address crosses the configured send
     * count, create a canonical contact in the NON-synced "Other contacts" pool
     * (never the synced book — keeps phones clean) and link it back. If already
     * linked, just bump the contact's usage counters for ranking.
     */
    private function maybeAutoCollect(string $userEmail, string $contactEmail, ?string $contactName): void
    {
        $ab = $this->addressBook();
        if ($ab === null) {
            return;
        }
        try {
            $stmt = $this->db->prepare('SELECT use_count, contact_id FROM email_contacts WHERE user_email = ? AND contact_email = ?');
            $stmt->execute([$userEmail, $contactEmail]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            if ($row['contact_id'] !== null) {
                $ab->touchUsage((int) $row['contact_id']);
                return;
            }
            if ((int) $row['use_count'] < $this->autoAddThreshold()) {
                return;
            }
            $contact = $ab->findOrCreateByEmail($userEmail, $contactEmail, $contactName, 'auto');
            if ($contact) {
                $ab->touchUsage((int) $contact['id']);
                $this->db->prepare('UPDATE email_contacts SET contact_id = ? WHERE user_email = ? AND contact_email = ?')
                    ->execute([(int) $contact['id'], $userEmail, $contactEmail]);
            }
        } catch (\Throwable $e) {
            error_log('ContactsService maybeAutoCollect error: ' . $e->getMessage());
        }
    }

    /**
     * Record multiple contacts at once (e.g., all recipients of an email)
     */
    public function recordContacts(string $userEmail, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            $email = '';
            $name = null;
            
            if (is_array($recipient)) {
                $email = $recipient['email'] ?? $recipient['address'] ?? '';
                $name = $recipient['name'] ?? $recipient['display'] ?? null;
            } elseif (is_string($recipient)) {
                $email = $recipient;
            }
            
            if ($email) {
                $this->recordContact($userEmail, $email, $name);
            }
        }
    }

    /**
     * Search contacts for autocomplete
     */
    public function searchContacts(string $userEmail, string $query, int $limit = 10): array
    {
        try {
            $userEmail = strtolower(trim($userEmail));
            $like = '%' . strtolower(trim($query)) . '%';
            $limit = (int) $limit;

            $stmt = $this->db->prepare('
                SELECT contact_email, contact_name, use_count, last_used, contact_id
                FROM email_contacts
                WHERE user_email = ? 
                    AND (LOWER(contact_email) LIKE ? OR LOWER(contact_name) LIKE ?)
                ORDER BY use_count DESC, last_used DESC
                LIMIT ' . max(1, $limit * 2) . '
            ');
            $stmt->execute([$userEmail, $like, $like]);
            $seen = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $book = $this->addressBook()?->searchAutocomplete($userEmail, $query, $limit * 2) ?? [];
            return $this->mergePeople($userEmail, $book, $seen, $limit);
        } catch (\PDOException $e) {
            error_log("ContactsService searchContacts error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get recent contacts (most frequently used)
     */
    public function getRecentContacts(string $userEmail, int $limit = 20): array
    {
        try {
            $userEmail = strtolower(trim($userEmail));
            $limit = (int) $limit;

            $stmt = $this->db->prepare('
                SELECT contact_email, contact_name, use_count, last_used, contact_id
                FROM email_contacts
                WHERE user_email = ?
                ORDER BY use_count DESC, last_used DESC
                LIMIT ' . max(1, $limit * 2) . '
            ');
            $stmt->execute([$userEmail]);
            $seen = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $book = $this->addressBook()?->recentAutocomplete($userEmail, $limit * 2) ?? [];
            return $this->mergePeople($userEmail, $book, $seen, $limit);
        } catch (\PDOException $e) {
            error_log("ContactsService getRecentContacts error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Merge address-book rows (saved people) with the seen-cache rows into one
     * de-duped, ranked list carrying the cross-layer flags the UI needs:
     * contact_id, is_saved, is_synced, is_client, client_id.
     *
     * Ranking: synced contacts first, then any saved contact, then by use_count.
     *
     * @param array $bookRows from AddressBookService::searchAutocomplete/recentAutocomplete
     * @param array $seenRows from email_contacts
     */
    private function mergePeople(string $userEmail, array $bookRows, array $seenRows, int $limit): array
    {
        $map = $this->buildPeopleMap($bookRows, $seenRows);
        $this->annotateClients($userEmail, $map);
        return $this->rankPeople($map, $limit);
    }

    /** Pure: build the de-duped email => person map (no DB access). */
    private function buildPeopleMap(array $bookRows, array $seenRows): array
    {
        $map = [];

        foreach ($bookRows as $b) {
            $email = strtolower(trim((string) ($b['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            $map[$email] = [
                'contact_email' => $b['email'],
                'contact_name' => $b['full_name'] ?: null,
                'use_count' => (int) ($b['use_count'] ?? 0),
                'last_used' => $b['last_used'] ?? null,
                'contact_id' => (int) $b['id'],
                'is_saved' => true,
                'is_synced' => !empty($b['is_synced']),
                'origin' => $b['origin'] ?? 'manual',
            ];
        }

        foreach ($seenRows as $s) {
            $email = strtolower(trim((string) ($s['contact_email'] ?? '')));
            if ($email === '') {
                continue;
            }
            if (isset($map[$email])) {
                $map[$email]['use_count'] = max($map[$email]['use_count'], (int) $s['use_count']);
                if (empty($map[$email]['contact_name']) && !empty($s['contact_name'])) {
                    $map[$email]['contact_name'] = $s['contact_name'];
                }
                if (empty($map[$email]['contact_id']) && !empty($s['contact_id'])) {
                    $map[$email]['contact_id'] = (int) $s['contact_id'];
                }
            } else {
                $map[$email] = [
                    'contact_email' => $s['contact_email'],
                    'contact_name' => $s['contact_name'] ?? null,
                    'use_count' => (int) $s['use_count'],
                    'last_used' => $s['last_used'] ?? null,
                    'contact_id' => isset($s['contact_id']) && $s['contact_id'] !== null ? (int) $s['contact_id'] : null,
                    'is_saved' => false,
                    'is_synced' => false,
                    'origin' => null,
                ];
            }
        }

        return $map;
    }

    /** Pure: rank synced-first, then saved, then by use_count; cap to $limit. */
    private function rankPeople(array $map, int $limit): array
    {
        $items = array_values($map);
        usort($items, function ($a, $b) {
            $rank = fn($x) => !empty($x['is_synced']) ? 2 : (!empty($x['is_saved']) ? 1 : 0);
            $ra = $rank($a);
            $rb = $rank($b);
            if ($ra !== $rb) {
                return $rb - $ra;
            }
            return ($b['use_count'] ?? 0) <=> ($a['use_count'] ?? 0);
        });

        return array_slice($items, 0, $limit);
    }

    /** Tag merged people that are contacts of a CRM client (by email). */
    private function annotateClients(string $userEmail, array &$map): void
    {
        foreach ($map as &$m) {
            $m['is_client'] = false;
            $m['client_id'] = null;
        }
        unset($m);

        if (empty($map)) {
            return;
        }
        $emails = array_keys($map);
        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        try {
            $stmt = $this->db->prepare(
                "SELECT LOWER(cc.email) AS email, cc.client_id
                 FROM client_contacts cc JOIN clients c ON c.id = cc.client_id
                 WHERE c.user_email = ? AND LOWER(cc.email) IN ($placeholders)"
            );
            $stmt->execute(array_merge([strtolower($userEmail)], $emails));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $key = $r['email'];
                if (isset($map[$key])) {
                    $map[$key]['is_client'] = true;
                    $map[$key]['client_id'] = (int) $r['client_id'];
                }
            }
        } catch (\PDOException $e) {
            // clients/client_contacts may not exist on a minimal install — ignore.
        }
    }

    /**
     * Promote a seen/recipient address into the real (synced) address book and
     * link the cache row to it. Returns the saved contact, or null on failure.
     */
    public function saveToAddressBook(string $userEmail, string $contactEmail, ?string $name = null): ?array
    {
        $ab = $this->addressBook();
        if ($ab === null) {
            return null;
        }
        $userEmail = strtolower(trim($userEmail));
        $contactEmail = strtolower(trim($contactEmail));
        $contact = $ab->saveToContacts($userEmail, $contactEmail, $name);
        if ($contact) {
            try {
                $this->db->prepare('UPDATE email_contacts SET contact_id = ? WHERE user_email = ? AND contact_email = ?')
                    ->execute([(int) $contact['id'], $userEmail, $contactEmail]);
            } catch (\PDOException $e) {
                // cache link is best-effort
            }
        }
        return $contact;
    }

    /**
     * Delete a contact
     */
    public function deleteContact(string $userEmail, string $contactEmail): bool
    {
        try {
            $stmt = $this->db->prepare('
                DELETE FROM email_contacts 
                WHERE user_email = ? AND contact_email = ?
            ');
            return $stmt->execute([strtolower($userEmail), strtolower($contactEmail)]);
        } catch (\PDOException $e) {
            error_log("ContactsService deleteContact error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Import contacts from email_tracking table (one-time migration)
     */
    public function importFromTrackingHistory(string $userEmail): int
    {
        try {
            $userEmail = strtolower(trim($userEmail));
            
            // Get all recipients from tracking history
            $stmt = $this->db->prepare('
                SELECT recipients FROM email_tracking WHERE user_email = ?
            ');
            $stmt->execute([$userEmail]);
            
            $imported = 0;
            while ($row = $stmt->fetch()) {
                $recipients = json_decode($row['recipients'], true) ?: [];
                foreach ($recipients as $recipient) {
                    $email = is_array($recipient) ? ($recipient['email'] ?? $recipient['address'] ?? '') : $recipient;
                    $name = is_array($recipient) ? ($recipient['name'] ?? null) : null;
                    
                    if ($email && $this->recordContact($userEmail, $email, $name)) {
                        $imported++;
                    }
                }
            }
            
            return $imported;
        } catch (\PDOException $e) {
            error_log("ContactsService importFromTrackingHistory error: " . $e->getMessage());
            return 0;
        }
    }
}

