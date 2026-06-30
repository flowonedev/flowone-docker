<?php

namespace Webmail\Addons\Contacts\Services;

/**
 * AddressBookService
 * ---------------------------------------------------------------
 * A real per-user address book (distinct from the lightweight
 * email_contacts autocomplete cache). Provides CRUD plus loss-aware
 * vCard (.vcf) and CSV import/export.
 *
 * The vCard + CSV parsers are intentionally dependency-free (no
 * sabre/vobject) so the feature works on any deploy without an extra
 * composer install. They cover vCard 2.1 / 3.0 / 4.0 basics
 * (line unfolding, quoted-printable, base64 photos, TYPE params) and
 * the Google / Outlook CSV column conventions, which is what real
 * migrations from cPanel / Gmail / Outlook actually produce.
 */
class AddressBookService
{
    private \PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
        $this->ensureTablesExist();
    }

    private function ensureTablesExist(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS addressbooks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL DEFAULT 'Contacts',
                    description VARCHAR(512) DEFAULT NULL,
                    color VARCHAR(7) DEFAULT '#3b82f6',
                    is_default TINYINT(1) NOT NULL DEFAULT 0,
                    ctag VARCHAR(64) DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_email (user_email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS contacts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    addressbook_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    uid VARCHAR(255) NOT NULL,
                    etag VARCHAR(64) DEFAULT NULL,
                    full_name VARCHAR(512) DEFAULT NULL,
                    first_name VARCHAR(255) DEFAULT NULL,
                    last_name VARCHAR(255) DEFAULT NULL,
                    nickname VARCHAR(255) DEFAULT NULL,
                    organization VARCHAR(512) DEFAULT NULL,
                    job_title VARCHAR(255) DEFAULT NULL,
                    emails TEXT,
                    phones TEXT,
                    addresses TEXT,
                    urls TEXT,
                    birthday DATE DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    photo MEDIUMTEXT DEFAULT NULL,
                    is_favorite TINYINT(1) NOT NULL DEFAULT 0,
                    vcard MEDIUMTEXT DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_addressbook (addressbook_id),
                    INDEX idx_user_email (user_email),
                    INDEX idx_full_name (full_name(191)),
                    UNIQUE KEY unique_book_uid (addressbook_id, uid),
                    FOREIGN KEY (addressbook_id) REFERENCES addressbooks(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Self-heal the people-reconciliation columns (migration 185) so the
            // feature works even before migrations run.
            $this->addColumnIfMissing('addressbooks', 'is_synced', "TINYINT(1) NOT NULL DEFAULT 1");
            $this->addColumnIfMissing('contacts', 'use_count', "INT NOT NULL DEFAULT 0");
            $this->addColumnIfMissing('contacts', 'last_used', "TIMESTAMP NULL DEFAULT NULL");
            $this->addColumnIfMissing('contacts', 'origin', "ENUM('manual','auto','client') NOT NULL DEFAULT 'manual'");
        } catch (\PDOException $e) {
            error_log('AddressBookService table creation error: ' . $e->getMessage());
        }
    }

    /** Add a column only if it doesn't already exist (idempotent self-heal). */
    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            if ((int) $stmt->fetchColumn() === 0) {
                $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        } catch (\PDOException $e) {
            error_log("AddressBookService addColumnIfMissing {$table}.{$column}: " . $e->getMessage());
        }
    }

    // =====================================================================
    // Address books
    // =====================================================================

    public function listAddressBooks(string $email): array
    {
        $email = strtolower($email);
        $stmt = $this->db->prepare(
            'SELECT b.*, (SELECT COUNT(*) FROM contacts c WHERE c.addressbook_id = b.id) AS contact_count
             FROM addressbooks b WHERE b.user_email = ? ORDER BY b.is_default DESC, b.name'
        );
        $stmt->execute([$email]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getAddressBook(string $email, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM addressbooks WHERE user_email = ? AND id = ?');
        $stmt->execute([strtolower($email), $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getOrCreateDefaultAddressBook(string $email): array
    {
        $email = strtolower($email);
        // Prefer a SYNCED book — never hand back the non-synced "Other contacts"
        // pool as the default save target.
        $stmt = $this->db->prepare('SELECT * FROM addressbooks WHERE user_email = ? AND is_synced = 1 ORDER BY is_default DESC, created_at ASC LIMIT 1');
        $stmt->execute([$email]);
        $book = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($book) {
            return $book;
        }
        return $this->createAddressBook($email, 'Contacts', '#3b82f6', true);
    }

    /**
     * The per-user non-synced "Other contacts" pool. Auto-collected (threshold)
     * and client-derived people live here so they NEVER reach phones via
     * CardDAV. Created on demand. Mirrors Google's "Other contacts".
     */
    public function getOrCreateOtherContactsBook(string $email): array
    {
        $email = strtolower($email);
        $stmt = $this->db->prepare('SELECT * FROM addressbooks WHERE user_email = ? AND is_synced = 0 ORDER BY created_at ASC, id ASC LIMIT 1');
        $stmt->execute([$email]);
        $book = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($book) {
            return $book;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO addressbooks (user_email, name, description, color, is_default, is_synced, ctag)
             VALUES (?, ?, ?, ?, 0, 0, ?)'
        );
        $stmt->execute([
            $email,
            'Other contacts',
            'Auto-collected from your email and clients. Not synced to your devices.',
            '#94a3b8',
            bin2hex(random_bytes(8)),
        ]);
        return $this->getAddressBook($email, (int) $this->db->lastInsertId());
    }

    public function createAddressBook(string $email, string $name, string $color = '#3b82f6', bool $isDefault = false, ?string $description = null): array
    {
        $email = strtolower($email);
        if ($isDefault) {
            $this->db->prepare('UPDATE addressbooks SET is_default = 0 WHERE user_email = ?')->execute([$email]);
        }
        $stmt = $this->db->prepare(
            'INSERT INTO addressbooks (user_email, name, description, color, is_default, ctag)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$email, $name ?: 'Contacts', $description, $color ?: '#3b82f6', $isDefault ? 1 : 0, bin2hex(random_bytes(8))]);
        return $this->getAddressBook($email, (int) $this->db->lastInsertId());
    }

    public function updateAddressBook(string $email, int $id, array $data): ?array
    {
        $email = strtolower($email);
        if (!$this->getAddressBook($email, $id)) {
            return null;
        }
        $fields = [];
        $values = [];
        foreach (['name', 'description', 'color'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "$col = ?";
                $values[] = $data[$col];
            }
        }
        if (array_key_exists('is_default', $data) && $data['is_default']) {
            $this->db->prepare('UPDATE addressbooks SET is_default = 0 WHERE user_email = ?')->execute([$email]);
            $fields[] = 'is_default = 1';
        }
        if ($fields) {
            $values[] = $email;
            $values[] = $id;
            $this->db->prepare('UPDATE addressbooks SET ' . implode(', ', $fields) . ' WHERE user_email = ? AND id = ?')
                ->execute($values);
        }
        return $this->getAddressBook($email, $id);
    }

    public function deleteAddressBook(string $email, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM addressbooks WHERE user_email = ? AND id = ?');
        $stmt->execute([strtolower($email), $id]);
        return $stmt->rowCount() > 0;
    }

    // =====================================================================
    // Contacts CRUD
    // =====================================================================

    public function listContacts(string $email, ?int $bookId = null, ?string $search = null, int $limit = 1000, int $offset = 0): array
    {
        $email = strtolower($email);
        $sql = 'SELECT * FROM contacts WHERE user_email = ?';
        $params = [$email];
        if ($bookId) {
            $sql .= ' AND addressbook_id = ?';
            $params[] = $bookId;
        }
        if ($search !== null && $search !== '') {
            $sql .= ' AND (full_name LIKE ? OR organization LIKE ? OR emails LIKE ? OR phones LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sql .= ' ORDER BY (full_name IS NULL OR full_name = ""), full_name ASC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        // Bind manually so LIMIT/OFFSET are integers.
        foreach ($params as $i => $v) {
            $type = is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
            $stmt->bindValue($i + 1, $v, $type);
        }
        $stmt->execute();
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function getContact(string $email, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM contacts WHERE user_email = ? AND id = ?');
        $stmt->execute([strtolower($email), $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Create or update a contact from a structured payload (UI).
     */
    public function createContact(string $email, int $bookId, array $data): ?array
    {
        $email = strtolower($email);
        $book = $this->getAddressBook($email, $bookId);
        if (!$book) {
            return null;
        }
        $contact = $this->normalizeContact($data);
        $contact['uid'] = $contact['uid'] ?: $this->generateUid();
        $contact['vcard'] = $this->buildVcard($contact);
        $id = $this->upsert($email, $bookId, $contact);
        $this->bumpCtag($bookId);
        return $id ? $this->getContact($email, $id) : null;
    }

    public function updateContact(string $email, int $id, array $data): ?array
    {
        $email = strtolower($email);
        $existing = $this->getContact($email, $id);
        if (!$existing) {
            return null;
        }
        $merged = array_merge($existing, $this->normalizeContact($data));
        $merged['uid'] = $existing['uid'];
        $merged['vcard'] = $this->buildVcard($merged);

        $stmt = $this->db->prepare(
            'UPDATE contacts SET full_name=?, first_name=?, last_name=?, nickname=?, organization=?, job_title=?,
                emails=?, phones=?, addresses=?, urls=?, birthday=?, notes=?, photo=?, is_favorite=?, vcard=?, etag=?
             WHERE user_email=? AND id=?'
        );
        $stmt->execute([
            $merged['full_name'], $merged['first_name'], $merged['last_name'], $merged['nickname'],
            $merged['organization'], $merged['job_title'],
            json_encode($merged['emails'] ?? []), json_encode($merged['phones'] ?? []),
            json_encode($merged['addresses'] ?? []), json_encode($merged['urls'] ?? []),
            $merged['birthday'] ?: null, $merged['notes'], $merged['photo'],
            !empty($merged['is_favorite']) ? 1 : 0, $merged['vcard'], bin2hex(random_bytes(16)),
            $email, $id,
        ]);
        $this->bumpCtag((int) $existing['addressbook_id']);
        return $this->getContact($email, $id);
    }

    public function deleteContact(string $email, int $id): bool
    {
        $contact = $this->getContact($email, $id);
        if (!$contact) {
            return false;
        }
        $stmt = $this->db->prepare('DELETE FROM contacts WHERE user_email = ? AND id = ?');
        $stmt->execute([strtolower($email), $id]);
        $this->bumpCtag((int) $contact['addressbook_id']);
        return $stmt->rowCount() > 0;
    }

    // =====================================================================
    // People reconciliation (autocomplete cache <-> address book <-> clients)
    // =====================================================================

    /**
     * Find a contact for this user that owns the given email address. Matches
     * the JSON `emails` column case-insensitively, then verifies by decoding to
     * avoid substring false-positives. Returns the hydrated contact or null.
     */
    public function findByEmail(string $email, string $contactEmail): ?array
    {
        $email = strtolower($email);
        $needle = strtolower(trim($contactEmail));
        if ($needle === '') {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM contacts WHERE user_email = ? AND LOWER(emails) LIKE ? ORDER BY id ASC LIMIT 25'
        );
        $stmt->execute([$email, '%' . $needle . '%']);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $hydrated = $this->hydrate($row);
            foreach ($hydrated['emails'] as $e) {
                if (strtolower(trim((string) ($e['value'] ?? ''))) === $needle) {
                    return $hydrated;
                }
            }
        }
        return null;
    }

    /**
     * Get the canonical contact for an email, creating it if missing.
     * `origin` routes the destination book:
     *   - 'auto' / 'client' -> the non-synced "Other contacts" pool
     *   - 'manual'          -> the default synced book
     * An already-existing contact (in ANY book) is returned untouched, so this
     * never duplicates a person or demotes a manually-saved one.
     */
    public function findOrCreateByEmail(string $email, string $contactEmail, ?string $name = null, string $origin = 'auto'): ?array
    {
        $email = strtolower($email);
        $contactEmail = strtolower(trim($contactEmail));
        if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $existing = $this->findByEmail($email, $contactEmail);
        if ($existing) {
            return $existing;
        }

        $book = in_array($origin, ['auto', 'client'], true)
            ? $this->getOrCreateOtherContactsBook($email)
            : $this->getOrCreateDefaultAddressBook($email);

        $contact = $this->normalizeContact([
            'full_name' => $name ?: '',
            'emails' => [['type' => 'other', 'value' => $contactEmail]],
        ]);
        $contact['uid'] = 'seen-' . md5($contactEmail);
        $contact['vcard'] = $this->buildVcard($contact);

        $id = $this->upsert($email, (int) $book['id'], $contact, $origin);
        if (!$id) {
            return null;
        }
        $this->bumpCtag((int) $book['id']);
        return $this->getContact($email, $id);
    }

    /**
     * Promote a contact into a synced book (default unless $targetBookId given)
     * and mark it origin='manual'. Used by "Save to contacts".
     */
    public function promoteContact(string $email, int $contactId, ?int $targetBookId = null): ?array
    {
        $email = strtolower($email);
        $contact = $this->getContact($email, $contactId);
        if (!$contact) {
            return null;
        }
        $target = $targetBookId ? $this->getAddressBook($email, $targetBookId) : $this->getOrCreateDefaultAddressBook($email);
        if (!$target) {
            return null;
        }
        $fromBook = (int) $contact['addressbook_id'];
        try {
            $stmt = $this->db->prepare('UPDATE contacts SET addressbook_id = ?, origin = "manual" WHERE user_email = ? AND id = ?');
            $stmt->execute([(int) $target['id'], $email, $contactId]);
        } catch (\PDOException $e) {
            // Same uid already present in the target book — just flip origin.
            $this->db->prepare('UPDATE contacts SET origin = "manual" WHERE user_email = ? AND id = ?')->execute([$email, $contactId]);
        }
        $this->bumpCtag($fromBook);
        $this->bumpCtag((int) $target['id']);
        return $this->getContact($email, $contactId);
    }

    /**
     * Idempotently ensure a person is a real, synced contact. Promotes them out
     * of the Other contacts pool if needed, or creates them in the default
     * synced book. This is what "Save to contacts" calls.
     */
    public function saveToContacts(string $email, string $contactEmail, ?string $name = null): ?array
    {
        $email = strtolower($email);
        $existing = $this->findByEmail($email, $contactEmail);
        if ($existing) {
            $book = $this->getAddressBook($email, (int) $existing['addressbook_id']);
            if ($book && (int) ($book['is_synced'] ?? 1) === 0) {
                return $this->promoteContact($email, (int) $existing['id'], null);
            }
            return $existing;
        }
        return $this->findOrCreateByEmail($email, $contactEmail, $name, 'manual');
    }

    /** Bump the behavioral counters used for autocomplete ranking. */
    public function touchUsage(int $contactId): void
    {
        try {
            $this->db->prepare('UPDATE contacts SET use_count = use_count + 1, last_used = NOW() WHERE id = ?')
                ->execute([$contactId]);
        } catch (\PDOException $e) {
            // non-fatal
        }
    }

    /**
     * Address-book rows for compose autocomplete (joined to their book so we
     * know the sync zone). Returns: id, full_name, email, is_synced, origin,
     * use_count, last_used. Synced books rank first.
     */
    public function searchAutocomplete(string $email, string $query, int $limit = 8): array
    {
        $email = strtolower($email);
        $like = '%' . strtolower(trim($query)) . '%';
        $limit = max(1, min($limit, 50));
        $stmt = $this->db->prepare(
            'SELECT c.id, c.full_name, c.emails, c.origin, c.use_count, c.last_used, b.is_synced
             FROM contacts c JOIN addressbooks b ON b.id = c.addressbook_id
             WHERE c.user_email = ? AND (LOWER(c.full_name) LIKE ? OR LOWER(c.emails) LIKE ?)
             ORDER BY b.is_synced DESC, c.use_count DESC, c.full_name ASC
             LIMIT ' . $limit
        );
        $stmt->execute([$email, $like, $like]);
        return $this->shapeAutocomplete($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Top address-book contacts for the empty-query (focus) suggestion list. */
    public function recentAutocomplete(string $email, int $limit = 8): array
    {
        $email = strtolower($email);
        $limit = max(1, min($limit, 50));
        $stmt = $this->db->prepare(
            'SELECT c.id, c.full_name, c.emails, c.origin, c.use_count, c.last_used, b.is_synced
             FROM contacts c JOIN addressbooks b ON b.id = c.addressbook_id
             WHERE c.user_email = ?
             ORDER BY b.is_synced DESC, c.use_count DESC, c.last_used DESC, c.full_name ASC
             LIMIT ' . $limit
        );
        $stmt->execute([$email]);
        return $this->shapeAutocomplete($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Reduce raw autocomplete rows to {id, full_name, email, is_synced, origin, ...}. */
    private function shapeAutocomplete(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $emails = $row['emails'] ? (json_decode($row['emails'], true) ?: []) : [];
            $primary = '';
            foreach ($emails as $e) {
                if (!empty($e['value'])) {
                    $primary = (string) $e['value'];
                    break;
                }
            }
            if ($primary === '') {
                continue;
            }
            $out[] = [
                'id' => (int) $row['id'],
                'full_name' => $row['full_name'] ?: null,
                'email' => $primary,
                'is_synced' => (int) ($row['is_synced'] ?? 1) === 1,
                'origin' => $row['origin'] ?? 'manual',
                'use_count' => (int) ($row['use_count'] ?? 0),
                'last_used' => $row['last_used'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Insert or update by (addressbook_id, uid). Returns the contact id.
     */
    private function upsert(string $email, int $bookId, array $c, string $origin = 'manual'): ?int
    {
        try {
            // `origin` is set on INSERT only — re-importing/updating a contact must
            // not silently demote a manually-saved person back to 'auto'/'client'.
            $stmt = $this->db->prepare(
                'INSERT INTO contacts
                    (addressbook_id, user_email, uid, etag, full_name, first_name, last_name, nickname,
                     organization, job_title, emails, phones, addresses, urls, birthday, notes, photo, is_favorite, vcard, origin)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    etag=VALUES(etag), full_name=VALUES(full_name), first_name=VALUES(first_name),
                    last_name=VALUES(last_name), nickname=VALUES(nickname), organization=VALUES(organization),
                    job_title=VALUES(job_title), emails=VALUES(emails), phones=VALUES(phones),
                    addresses=VALUES(addresses), urls=VALUES(urls), birthday=VALUES(birthday),
                    notes=VALUES(notes), photo=VALUES(photo), vcard=VALUES(vcard)'
            );
            $stmt->execute([
                $bookId, strtolower($email), $c['uid'], bin2hex(random_bytes(16)),
                $c['full_name'] ?? null, $c['first_name'] ?? null, $c['last_name'] ?? null, $c['nickname'] ?? null,
                $c['organization'] ?? null, $c['job_title'] ?? null,
                json_encode($c['emails'] ?? []), json_encode($c['phones'] ?? []),
                json_encode($c['addresses'] ?? []), json_encode($c['urls'] ?? []),
                ($c['birthday'] ?? null) ?: null, $c['notes'] ?? null, $c['photo'] ?? null,
                !empty($c['is_favorite']) ? 1 : 0, $c['vcard'] ?? null,
                in_array($origin, ['manual', 'auto', 'client'], true) ? $origin : 'manual',
            ]);
            $id = (int) $this->db->lastInsertId();
            if ($id === 0) {
                // Updated existing row — look it up by (book, uid).
                $sel = $this->db->prepare('SELECT id FROM contacts WHERE addressbook_id = ? AND uid = ?');
                $sel->execute([$bookId, $c['uid']]);
                $id = (int) ($sel->fetchColumn() ?: 0);
            }
            return $id ?: null;
        } catch (\PDOException $e) {
            error_log('AddressBookService upsert error: ' . $e->getMessage());
            return null;
        }
    }

    // =====================================================================
    // Import / export
    // =====================================================================

    /**
     * Import a .vcf payload (one or many vCards). Idempotent by UID.
     * Returns counts: {imported, updated, total}.
     */
    public function importVcf(string $email, int $bookId, string $vcf): array
    {
        $cards = $this->parseVcards($vcf);
        $imported = 0;
        $updated = 0;
        foreach ($cards as $card) {
            $existsStmt = $this->db->prepare('SELECT id FROM contacts WHERE addressbook_id = ? AND uid = ?');
            $existsStmt->execute([$bookId, $card['uid']]);
            $existed = (bool) $existsStmt->fetchColumn();
            $id = $this->upsert($email, $bookId, $card);
            if ($id) {
                $existed ? $updated++ : $imported++;
            }
        }
        $this->bumpCtag($bookId);
        return ['imported' => $imported, 'updated' => $updated, 'total' => count($cards)];
    }

    /**
     * Import a CSV payload (Google / Outlook conventions). Idempotent by
     * a synthetic UID derived from the primary email or name.
     */
    public function importCsv(string $email, int $bookId, string $csv): array
    {
        $rows = $this->parseCsv($csv);
        if (count($rows) < 2) {
            return ['imported' => 0, 'updated' => 0, 'total' => 0];
        }
        $header = array_map(fn($h) => strtolower(trim($h)), array_shift($rows));
        $idx = fn(array $names) => $this->csvCol($header, $names);

        $col = [
            'first' => $idx(['first name', 'given name', 'firstname']),
            'last' => $idx(['last name', 'family name', 'lastname', 'surname']),
            'name' => $idx(['name', 'display name', 'full name']),
            'org' => $idx(['organization', 'company', 'organization name', 'organization 1 - name']),
            'title' => $idx(['title', 'job title', 'organization 1 - title']),
            'notes' => $idx(['notes', 'note']),
        ];
        // Email/phone columns can repeat (Email 1 - Value, E-mail Address, ...).
        $emailCols = $this->csvMatchingCols($header, ['e-mail address', 'email', 'e-mail', 'email address', 'email 1 - value', 'email 2 - value', 'e-mail 2 address']);
        $phoneCols = $this->csvMatchingCols($header, ['phone', 'mobile phone', 'home phone', 'business phone', 'phone 1 - value', 'phone 2 - value', 'primary phone']);

        $imported = 0;
        $updated = 0;
        $total = 0;
        foreach ($rows as $row) {
            $get = fn($i) => ($i !== null && isset($row[$i])) ? trim((string) $row[$i]) : '';
            $first = $get($col['first']);
            $last = $get($col['last']);
            $name = $get($col['name']);
            $full = $name ?: trim($first . ' ' . $last);

            $emails = [];
            foreach ($emailCols as $i) {
                $v = $get($i);
                if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = ['type' => 'other', 'value' => $v];
                }
            }
            $phones = [];
            foreach ($phoneCols as $i) {
                $v = $get($i);
                if ($v !== '') {
                    $phones[] = ['type' => 'other', 'value' => $v];
                }
            }

            if ($full === '' && empty($emails) && empty($phones)) {
                continue; // empty row
            }
            $total++;

            $primaryEmail = $emails[0]['value'] ?? '';
            $uid = $primaryEmail !== ''
                ? 'csv-' . md5(strtolower($primaryEmail))
                : 'csv-' . md5(strtolower($full) . '|' . microtime(true) . random_int(0, 99999));

            $card = $this->normalizeContact([
                'uid' => $uid,
                'full_name' => $full,
                'first_name' => $first,
                'last_name' => $last,
                'organization' => $get($col['org']),
                'job_title' => $get($col['title']),
                'notes' => $get($col['notes']),
                'emails' => $emails,
                'phones' => $phones,
            ]);
            $card['vcard'] = $this->buildVcard($card);

            $existsStmt = $this->db->prepare('SELECT id FROM contacts WHERE addressbook_id = ? AND uid = ?');
            $existsStmt->execute([$bookId, $uid]);
            $existed = (bool) $existsStmt->fetchColumn();
            if ($this->upsert($email, $bookId, $card)) {
                $existed ? $updated++ : $imported++;
            }
        }
        $this->bumpCtag($bookId);
        return ['imported' => $imported, 'updated' => $updated, 'total' => $total];
    }

    public function exportVcf(string $email, ?int $bookId = null): string
    {
        $contacts = $this->listContacts($email, $bookId, null, 100000, 0);
        $out = [];
        foreach ($contacts as $c) {
            $out[] = !empty($c['vcard']) ? trim($c['vcard']) : trim($this->buildVcard($c));
        }
        return implode("\r\n", $out) . "\r\n";
    }

    // =====================================================================
    // vCard parsing / generation (dependency-free)
    // =====================================================================

    /** @return array<int,array> structured contact arrays */
    public function parseVcards(string $text): array
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        $cards = [];
        $buffer = null;
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^BEGIN:VCARD/i', $line)) {
                $buffer = '';
                continue;
            }
            if (preg_match('/^END:VCARD/i', $line)) {
                if ($buffer !== null) {
                    $parsed = $this->parseSingleVcard($buffer);
                    if ($parsed) {
                        $cards[] = $parsed;
                    }
                }
                $buffer = null;
                continue;
            }
            if ($buffer !== null) {
                $buffer .= $line . "\n";
            }
        }
        return $cards;
    }

    private function parseSingleVcard(string $body): ?array
    {
        // Unfold: lines beginning with space/tab continue the previous line.
        $lines = [];
        foreach (explode("\n", $body) as $raw) {
            if ($raw === '') {
                continue;
            }
            if (($raw[0] === ' ' || $raw[0] === "\t") && !empty($lines)) {
                $lines[count($lines) - 1] .= substr($raw, 1);
            } else {
                $lines[] = $raw;
            }
        }

        $c = $this->emptyContact();
        $rawCard = "BEGIN:VCARD\r\n";
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $left = substr($line, 0, $colon);
            $value = substr($line, $colon + 1);

            $parts = explode(';', $left);
            $prop = strtoupper(array_shift($parts));
            // Strip a group prefix like "item1.EMAIL".
            if (str_contains($prop, '.')) {
                $prop = strtoupper(substr($prop, strrpos($prop, '.') + 1));
            }
            $params = $this->parseParams($parts);

            $value = $this->decodeValue($value, $params);
            $rawCard .= $prop . ($parts ? ';' . implode(';', $parts) : '') . ':' . $this->escapeVcardValue(is_array($value) ? implode(';', $value) : (string) $value) . "\r\n";

            $types = $this->extractTypes($params);
            switch ($prop) {
                case 'FN':
                    $c['full_name'] = is_array($value) ? implode(' ', $value) : $value;
                    break;
                case 'N':
                    $n = is_array($value) ? $value : explode(';', $value);
                    $c['last_name'] = $n[0] ?? '';
                    $c['first_name'] = $n[1] ?? '';
                    break;
                case 'NICKNAME':
                    $c['nickname'] = is_array($value) ? implode(', ', $value) : $value;
                    break;
                case 'ORG':
                    $c['organization'] = is_array($value) ? implode(' - ', array_filter($value)) : $value;
                    break;
                case 'TITLE':
                    $c['job_title'] = is_array($value) ? implode(' ', $value) : $value;
                    break;
                case 'EMAIL':
                    $v = is_array($value) ? ($value[0] ?? '') : $value;
                    if ($v !== '') {
                        $c['emails'][] = ['type' => $types[0] ?? 'other', 'value' => $v];
                    }
                    break;
                case 'TEL':
                    $v = is_array($value) ? ($value[0] ?? '') : $value;
                    if ($v !== '') {
                        $c['phones'][] = ['type' => $types[0] ?? 'other', 'value' => $v];
                    }
                    break;
                case 'ADR':
                    $a = is_array($value) ? $value : explode(';', $value);
                    $c['addresses'][] = [
                        'type' => $types[0] ?? 'other',
                        'street' => $a[2] ?? '',
                        'city' => $a[3] ?? '',
                        'region' => $a[4] ?? '',
                        'postal' => $a[5] ?? '',
                        'country' => $a[6] ?? '',
                    ];
                    break;
                case 'URL':
                    $v = is_array($value) ? ($value[0] ?? '') : $value;
                    if ($v !== '') {
                        $c['urls'][] = ['type' => $types[0] ?? 'other', 'value' => $v];
                    }
                    break;
                case 'BDAY':
                    $c['birthday'] = $this->normalizeBirthday(is_array($value) ? ($value[0] ?? '') : $value);
                    break;
                case 'NOTE':
                    $c['notes'] = is_array($value) ? implode("\n", $value) : $value;
                    break;
                case 'PHOTO':
                    $c['photo'] = $this->buildPhotoDataUrl($value, $params);
                    break;
                case 'UID':
                    $c['uid'] = is_array($value) ? ($value[0] ?? '') : $value;
                    break;
            }
        }
        $rawCard .= "END:VCARD";

        if (($c['full_name'] ?? '') === '') {
            $c['full_name'] = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
        }
        if (($c['full_name'] ?? '') === '' && empty($c['emails']) && empty($c['phones'])) {
            return null; // nothing usable
        }
        if (($c['uid'] ?? '') === '') {
            $seed = ($c['emails'][0]['value'] ?? '') ?: ($c['full_name'] ?? '');
            $c['uid'] = $seed !== '' ? 'vcf-' . md5(strtolower($seed)) : $this->generateUid();
        }
        // Re-store the raw card we reconstructed (keeps params/types).
        $c['vcard'] = $rawCard;
        return $c;
    }

    private function parseParams(array $paramParts): array
    {
        $params = [];
        foreach ($paramParts as $p) {
            if (str_contains($p, '=')) {
                [$k, $v] = explode('=', $p, 2);
                $params[strtoupper($k)][] = $v;
            } else {
                // v2.1 bare param, e.g. ";HOME;VOICE"
                $params['TYPE'][] = $p;
            }
        }
        return $params;
    }

    private function extractTypes(array $params): array
    {
        $types = [];
        foreach (($params['TYPE'] ?? []) as $t) {
            foreach (explode(',', $t) as $piece) {
                $piece = strtolower(trim($piece));
                if ($piece !== '' && !in_array($piece, ['internet', 'pref', 'voice'], true)) {
                    $types[] = $piece;
                }
            }
        }
        return $types;
    }

    private function decodeValue(string $value, array $params)
    {
        $encoding = strtoupper($params['ENCODING'][0] ?? '');
        if ($encoding === 'QUOTED-PRINTABLE') {
            $value = quoted_printable_decode($value);
            $charset = strtoupper($params['CHARSET'][0] ?? '');
            if ($charset && $charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                $value = mb_convert_encoding($value, 'UTF-8', $charset);
            }
            return $value;
        }
        if ($encoding === 'B' || $encoding === 'BASE64') {
            return $value; // handled by photo builder
        }
        // Unescape vCard text escaping and split structured values on unescaped ';'.
        if (preg_match('/(?<!\\\\);/', $value)) {
            $segs = preg_split('/(?<!\\\\);/', $value);
            return array_map([$this, 'unescapeVcardValue'], $segs);
        }
        return $this->unescapeVcardValue($value);
    }

    private function unescapeVcardValue(string $v): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $v);
    }

    private function escapeVcardValue(string $v): string
    {
        return str_replace(['\\', "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], $v);
    }

    private function buildPhotoDataUrl($value, array $params): ?string
    {
        $val = is_array($value) ? implode('', $value) : $value;
        $val = trim($val);
        if ($val === '') {
            return null;
        }
        // Already a URL (vCard 4.0 often uses data: URIs or http URLs).
        if (preg_match('#^(https?:|data:)#i', $val)) {
            return $val;
        }
        $encoding = strtoupper($params['ENCODING'][0] ?? '');
        if ($encoding === 'B' || $encoding === 'BASE64') {
            $type = strtolower($params['TYPE'][0] ?? 'jpeg');
            $type = preg_replace('/[^a-z0-9]/', '', $type) ?: 'jpeg';
            return 'data:image/' . $type . ';base64,' . preg_replace('/\s+/', '', $val);
        }
        return null;
    }

    private function normalizeBirthday(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/(\d{4})-?(\d{2})-?(\d{2})/', $v, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        return null;
    }

    public function buildVcard(array $c): string
    {
        $lines = ['BEGIN:VCARD', 'VERSION:3.0'];
        $lines[] = 'UID:' . ($c['uid'] ?? $this->generateUid());
        $fn = $c['full_name'] ?: trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
        $lines[] = 'FN:' . $this->escapeVcardValue($fn ?: 'Unnamed');
        $lines[] = 'N:' . $this->escapeVcardValue($c['last_name'] ?? '') . ';' . $this->escapeVcardValue($c['first_name'] ?? '') . ';;;';
        if (!empty($c['nickname'])) {
            $lines[] = 'NICKNAME:' . $this->escapeVcardValue($c['nickname']);
        }
        if (!empty($c['organization'])) {
            $lines[] = 'ORG:' . $this->escapeVcardValue($c['organization']);
        }
        if (!empty($c['job_title'])) {
            $lines[] = 'TITLE:' . $this->escapeVcardValue($c['job_title']);
        }
        foreach (($c['emails'] ?? []) as $e) {
            if (!empty($e['value'])) {
                $lines[] = 'EMAIL;TYPE=' . strtoupper($e['type'] ?? 'OTHER') . ':' . $this->escapeVcardValue($e['value']);
            }
        }
        foreach (($c['phones'] ?? []) as $p) {
            if (!empty($p['value'])) {
                $lines[] = 'TEL;TYPE=' . strtoupper($p['type'] ?? 'OTHER') . ':' . $this->escapeVcardValue($p['value']);
            }
        }
        foreach (($c['addresses'] ?? []) as $a) {
            $lines[] = 'ADR;TYPE=' . strtoupper($a['type'] ?? 'OTHER') . ':;;'
                . $this->escapeVcardValue($a['street'] ?? '') . ';'
                . $this->escapeVcardValue($a['city'] ?? '') . ';'
                . $this->escapeVcardValue($a['region'] ?? '') . ';'
                . $this->escapeVcardValue($a['postal'] ?? '') . ';'
                . $this->escapeVcardValue($a['country'] ?? '');
        }
        foreach (($c['urls'] ?? []) as $u) {
            if (!empty($u['value'])) {
                $lines[] = 'URL:' . $this->escapeVcardValue($u['value']);
            }
        }
        if (!empty($c['birthday'])) {
            $lines[] = 'BDAY:' . str_replace('-', '', $c['birthday']);
        }
        if (!empty($c['notes'])) {
            $lines[] = 'NOTE:' . $this->escapeVcardValue($c['notes']);
        }
        $lines[] = 'END:VCARD';
        return implode("\r\n", $lines);
    }

    // =====================================================================
    // CSV parsing
    // =====================================================================

    /** @return array<int,array<int,string>> */
    private function parseCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); // strip BOM
        $rows = [];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($stream);
        return $rows;
    }

    private function csvCol(array $header, array $names): ?int
    {
        foreach ($names as $n) {
            $i = array_search($n, $header, true);
            if ($i !== false) {
                return $i;
            }
        }
        return null;
    }

    private function csvMatchingCols(array $header, array $needles): array
    {
        $cols = [];
        foreach ($header as $i => $h) {
            foreach ($needles as $n) {
                if ($h === $n || str_contains($h, $n)) {
                    $cols[] = $i;
                    break;
                }
            }
        }
        return $cols;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function emptyContact(): array
    {
        return [
            'uid' => '', 'full_name' => '', 'first_name' => '', 'last_name' => '', 'nickname' => '',
            'organization' => '', 'job_title' => '', 'emails' => [], 'phones' => [], 'addresses' => [],
            'urls' => [], 'birthday' => null, 'notes' => '', 'photo' => null, 'is_favorite' => false,
        ];
    }

    private function normalizeContact(array $data): array
    {
        $c = array_merge($this->emptyContact(), [
            'uid' => $data['uid'] ?? '',
            'full_name' => trim((string) ($data['full_name'] ?? '')),
            'first_name' => trim((string) ($data['first_name'] ?? '')),
            'last_name' => trim((string) ($data['last_name'] ?? '')),
            'nickname' => trim((string) ($data['nickname'] ?? '')),
            'organization' => trim((string) ($data['organization'] ?? '')),
            'job_title' => trim((string) ($data['job_title'] ?? '')),
            'emails' => $this->normalizeMulti($data['emails'] ?? []),
            'phones' => $this->normalizeMulti($data['phones'] ?? []),
            'addresses' => is_array($data['addresses'] ?? null) ? $data['addresses'] : [],
            'urls' => $this->normalizeMulti($data['urls'] ?? []),
            'birthday' => $data['birthday'] ?? null,
            'notes' => trim((string) ($data['notes'] ?? '')),
            'photo' => $data['photo'] ?? null,
            'is_favorite' => !empty($data['is_favorite']),
        ]);
        if ($c['full_name'] === '') {
            $c['full_name'] = trim($c['first_name'] . ' ' . $c['last_name']);
        }
        return $c;
    }

    private function normalizeMulti($val): array
    {
        if (!is_array($val)) {
            return [];
        }
        $out = [];
        foreach ($val as $item) {
            if (is_string($item)) {
                if (trim($item) !== '') {
                    $out[] = ['type' => 'other', 'value' => trim($item)];
                }
            } elseif (is_array($item) && !empty($item['value'])) {
                $out[] = ['type' => $item['type'] ?? 'other', 'value' => trim((string) $item['value'])];
            }
        }
        return $out;
    }

    private function hydrate(array $row): array
    {
        foreach (['emails', 'phones', 'addresses', 'urls'] as $f) {
            $row[$f] = $row[$f] ? (json_decode($row[$f], true) ?: []) : [];
        }
        $row['is_favorite'] = (bool) ($row['is_favorite'] ?? false);
        return $row;
    }

    private function bumpCtag(int $bookId): void
    {
        try {
            $this->db->prepare('UPDATE addressbooks SET ctag = ? WHERE id = ?')
                ->execute([bin2hex(random_bytes(8)), $bookId]);
        } catch (\PDOException $e) {
            // non-fatal
        }
    }

    private function generateUid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}
