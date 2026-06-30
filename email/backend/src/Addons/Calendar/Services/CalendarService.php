<?php

namespace Webmail\Addons\Calendar\Services;

/**
 * CalendarService - Calendar events management
 * 
 * Stores events in database and provides CalDAV-compatible data format
 * for syncing with iOS/Android calendar apps
 */
class CalendarService
{
    private \PDO $db;
    
    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTablesExist());
    }
    
    private function ensureTablesExist(): void
    {
        try {
            // Calendars table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS calendars (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL DEFAULT 'My Calendar',
                    color VARCHAR(7) DEFAULT '#3b82f6',
                    timezone VARCHAR(50) DEFAULT 'UTC',
                    is_default TINYINT(1) DEFAULT 0,
                    ctag VARCHAR(64) DEFAULT NULL COMMENT 'Sync token for CalDAV',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_email (user_email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Events table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS calendar_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    calendar_id INT NOT NULL,
                    uid VARCHAR(255) NOT NULL COMMENT 'Unique identifier for CalDAV',
                    title VARCHAR(255) NOT NULL,
                    description TEXT,
                    location VARCHAR(255),
                    start_time DATETIME NOT NULL,
                    end_time DATETIME NOT NULL,
                    all_day TINYINT(1) DEFAULT 0,
                    timezone VARCHAR(50) DEFAULT 'UTC',
                    recurrence TEXT COMMENT 'iCal RRULE format',
                    reminders TEXT COMMENT 'JSON array of reminders',
                    color VARCHAR(7) DEFAULT NULL,
                    etag VARCHAR(64) DEFAULT NULL COMMENT 'Version tag for CalDAV',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_calendar_id (calendar_id),
                    INDEX idx_start_time (start_time),
                    INDEX idx_uid (uid),
                    FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Calendar shares table (individual user + group sharing)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS calendar_shares (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    calendar_id INT NOT NULL,
                    owner_email VARCHAR(255) NOT NULL COMMENT 'Calendar owner email',
                    shared_with_email VARCHAR(255) DEFAULT NULL COMMENT 'Individual user email',
                    shared_with_group_id INT UNSIGNED DEFAULT NULL COMMENT 'Group ID from colleague_groups',
                    permission ENUM('view', 'edit') DEFAULT 'view',
                    can_see_details TINYINT(1) DEFAULT 1 COMMENT 'Can see event details or just busy/free',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_share (calendar_id, shared_with_email),
                    UNIQUE KEY unique_group_share (calendar_id, shared_with_group_id),
                    INDEX idx_shared_email (shared_with_email),
                    INDEX idx_shared_group (shared_with_group_id),
                    INDEX idx_owner (owner_email),
                    FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
        } catch (\PDOException $e) {
            error_log("CalendarService table creation error: " . $e->getMessage());
        }
    }
    
    // ===== CALENDAR OPERATIONS =====
    
    /**
     * Get all calendars for a user
     */
    public function getCalendars(string $email): array
    {
        $email = strtolower($email);
        
        $stmt = $this->db->prepare('SELECT * FROM calendars WHERE user_email = ? ORDER BY is_default DESC, name');
        $stmt->execute([$email]);
        
        $calendars = $stmt->fetchAll();
        
        // Create default calendar if none exists
        if (empty($calendars)) {
            $calendar = $this->createCalendar($email, 'My Calendar', '#3b82f6', true);
            return $calendar ? [$calendar] : [];
        }
        
        return array_map(function($cal) {
            $cal['is_default'] = (bool)$cal['is_default'];
            return $cal;
        }, $calendars);
    }
    
    /**
     * Get a single calendar
     */
    public function getCalendar(string $email, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM calendars WHERE user_email = ? AND id = ?');
        $stmt->execute([strtolower($email), $id]);
        $calendar = $stmt->fetch();
        
        if ($calendar) {
            $calendar['is_default'] = (bool)$calendar['is_default'];
        }
        
        return $calendar ?: null;
    }
    
    /**
     * Get the owner email of a calendar by its ID
     */
    public function getCalendarOwner(int $calendarId): ?string
    {
        $stmt = $this->db->prepare('SELECT user_email FROM calendars WHERE id = ?');
        $stmt->execute([$calendarId]);
        $row = $stmt->fetch();
        return $row ? strtolower($row['user_email']) : null;
    }
    
    /**
     * Get the default calendar for a user, or the first one if no default is set
     */
    public function getDefaultCalendar(string $email): ?array
    {
        $email = strtolower($email);
        
        // First try to get the explicitly set default
        $stmt = $this->db->prepare('SELECT * FROM calendars WHERE user_email = ? AND is_default = 1 LIMIT 1');
        $stmt->execute([$email]);
        $calendar = $stmt->fetch();
        
        // If no default, get the first calendar
        if (!$calendar) {
            $stmt = $this->db->prepare('SELECT * FROM calendars WHERE user_email = ? ORDER BY created_at ASC LIMIT 1');
            $stmt->execute([$email]);
            $calendar = $stmt->fetch();
        }
        
        if ($calendar) {
            $calendar['is_default'] = (bool)$calendar['is_default'];
        }
        
        return $calendar ?: null;
    }
    
    /**
     * Create a new calendar
     */
    public function createCalendar(string $email, string $name, string $color = '#3b82f6', bool $isDefault = false): ?array
    {
        $email = strtolower($email);
        
        try {
            if ($isDefault) {
                $this->db->prepare('UPDATE calendars SET is_default = 0 WHERE user_email = ?')
                    ->execute([$email]);
            }
            
            $stmt = $this->db->prepare('
                INSERT INTO calendars (user_email, name, color, is_default, ctag)
                VALUES (?, ?, ?, ?, ?)
            ');
            
            $ctag = bin2hex(random_bytes(16));
            $stmt->execute([$email, $name, $color, $isDefault ? 1 : 0, $ctag]);
            
            return $this->getCalendar($email, (int)$this->db->lastInsertId());
        } catch (\PDOException $e) {
            error_log("CalendarService createCalendar error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update a calendar
     */
    public function updateCalendar(string $email, int $id, array $data): ?array
    {
        $email = strtolower($email);
        
        $fields = [];
        $values = [];
        
        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $values[] = $data['name'];
        }
        if (isset($data['color'])) {
            $fields[] = 'color = ?';
            $values[] = $data['color'];
        }
        if (isset($data['timezone'])) {
            $fields[] = 'timezone = ?';
            $values[] = $data['timezone'];
        }
        if (isset($data['is_default']) && $data['is_default']) {
            $this->db->prepare('UPDATE calendars SET is_default = 0 WHERE user_email = ?')
                ->execute([$email]);
            $fields[] = 'is_default = 1';
        }
        
        if (empty($fields)) {
            return $this->getCalendar($email, $id);
        }
        
        // Update ctag
        $fields[] = 'ctag = ?';
        $values[] = bin2hex(random_bytes(16));
        
        $values[] = $email;
        $values[] = $id;
        
        $stmt = $this->db->prepare('UPDATE calendars SET ' . implode(', ', $fields) . ' WHERE user_email = ? AND id = ?');
        $stmt->execute($values);
        
        return $this->getCalendar($email, $id);
    }
    
    /**
     * Delete a calendar
     */
    public function deleteCalendar(string $email, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM calendars WHERE user_email = ? AND id = ?');
        $stmt->execute([strtolower($email), $id]);
        return $stmt->rowCount() > 0;
    }
    
    // ===== CALENDAR SHARING =====
    
    /**
     * Share a calendar with an individual user
     */
    public function shareWithUser(string $ownerEmail, int $calendarId, string $targetEmail, string $permission = 'view', bool $canSeeDetails = true): array
    {
        $ownerEmail = strtolower($ownerEmail);
        $targetEmail = strtolower($targetEmail);
        
        // Verify the calendar belongs to the owner
        $calendar = $this->getCalendar($ownerEmail, $calendarId);
        if (!$calendar) {
            return ['success' => false, 'error' => 'Calendar not found'];
        }
        
        // Can't share with yourself
        if ($ownerEmail === $targetEmail) {
            return ['success' => false, 'error' => 'Cannot share calendar with yourself'];
        }
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO calendar_shares (calendar_id, owner_email, shared_with_email, permission, can_see_details)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE permission = VALUES(permission), can_see_details = VALUES(can_see_details)
            ');
            $stmt->execute([$calendarId, $ownerEmail, $targetEmail, $permission, $canSeeDetails ? 1 : 0]);
            
            return ['success' => true];
        } catch (\PDOException $e) {
            error_log("CalendarService shareWithUser error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to share calendar'];
        }
    }
    
    /**
     * Share a calendar with a colleague group
     */
    public function shareWithGroup(string $ownerEmail, int $calendarId, int $groupId, string $permission = 'view', bool $canSeeDetails = true): array
    {
        $ownerEmail = strtolower($ownerEmail);
        
        // Verify the calendar belongs to the owner
        $calendar = $this->getCalendar($ownerEmail, $calendarId);
        if (!$calendar) {
            return ['success' => false, 'error' => 'Calendar not found'];
        }
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO calendar_shares (calendar_id, owner_email, shared_with_group_id, permission, can_see_details)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE permission = VALUES(permission), can_see_details = VALUES(can_see_details)
            ');
            $stmt->execute([$calendarId, $ownerEmail, $groupId, $permission, $canSeeDetails ? 1 : 0]);
            
            return ['success' => true];
        } catch (\PDOException $e) {
            error_log("CalendarService shareWithGroup error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to share calendar'];
        }
    }
    
    /**
     * Remove a share (individual user)
     */
    public function unshareWithUser(string $ownerEmail, int $calendarId, string $targetEmail): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM calendar_shares 
            WHERE calendar_id = ? AND owner_email = ? AND shared_with_email = ?
        ');
        $stmt->execute([$calendarId, strtolower($ownerEmail), strtolower($targetEmail)]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Remove a share (group)
     */
    public function unshareWithGroup(string $ownerEmail, int $calendarId, int $groupId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM calendar_shares 
            WHERE calendar_id = ? AND owner_email = ? AND shared_with_group_id = ?
        ');
        $stmt->execute([$calendarId, strtolower($ownerEmail), $groupId]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get all shares for a calendar (owner perspective)
     */
    public function getCalendarShares(string $ownerEmail, int $calendarId): array
    {
        $ownerEmail = strtolower($ownerEmail);
        
        // Verify ownership
        $calendar = $this->getCalendar($ownerEmail, $calendarId);
        if (!$calendar) return [];
        
        $stmt = $this->db->prepare('
            SELECT cs.*, 
                   cg.name as group_name, cg.color as group_color, cg.icon as group_icon
            FROM calendar_shares cs
            LEFT JOIN colleague_groups cg ON cs.shared_with_group_id = cg.id
            WHERE cs.calendar_id = ? AND cs.owner_email = ?
            ORDER BY cs.created_at DESC
        ');
        $stmt->execute([$calendarId, $ownerEmail]);
        
        return array_map(function($share) {
            $share['can_see_details'] = (bool)$share['can_see_details'];
            $share['type'] = $share['shared_with_email'] ? 'user' : 'group';
            return $share;
        }, $stmt->fetchAll());
    }
    
    /**
     * Get calendars shared with a specific user (including via groups)
     * Returns calendars shared directly + via group membership
     */
    public function getSharedCalendars(string $userEmail, array $userGroupIds = []): array
    {
        $userEmail = strtolower($userEmail);
        $sharedCalendars = [];
        
        // 1. Calendars shared directly with this user
        $stmt = $this->db->prepare('
            SELECT c.*, cs.permission, cs.can_see_details, cs.owner_email as shared_by,
                   \'user\' as share_type
            FROM calendar_shares cs
            JOIN calendars c ON cs.calendar_id = c.id
            WHERE cs.shared_with_email = ?
        ');
        $stmt->execute([$userEmail]);
        $directShares = $stmt->fetchAll();
        
        foreach ($directShares as $cal) {
            $cal['is_default'] = (bool)$cal['is_default'];
            $cal['is_shared'] = true;
            $cal['can_edit'] = $cal['permission'] === 'edit';
            $cal['can_see_details'] = (bool)$cal['can_see_details'];
            $sharedCalendars[$cal['id']] = $cal;
        }
        
        // 2. Calendars shared via group membership
        if (!empty($userGroupIds)) {
            $placeholders = implode(',', array_fill(0, count($userGroupIds), '?'));
            $stmt = $this->db->prepare("
                SELECT c.*, cs.permission, cs.can_see_details, cs.owner_email as shared_by,
                       'group' as share_type, cs.shared_with_group_id as via_group_id
                FROM calendar_shares cs
                JOIN calendars c ON cs.calendar_id = c.id
                WHERE cs.shared_with_group_id IN ($placeholders)
                  AND c.user_email != ?
            ");
            $params = array_merge($userGroupIds, [$userEmail]);
            $stmt->execute($params);
            $groupShares = $stmt->fetchAll();
            
            foreach ($groupShares as $cal) {
                // Don't override direct share (direct share takes priority)
                if (isset($sharedCalendars[$cal['id']])) continue;
                
                $cal['is_default'] = (bool)$cal['is_default'];
                $cal['is_shared'] = true;
                $cal['can_edit'] = $cal['permission'] === 'edit';
                $cal['can_see_details'] = (bool)$cal['can_see_details'];
                $sharedCalendars[$cal['id']] = $cal;
            }
        }
        
        return array_values($sharedCalendars);
    }
    
    /**
     * Get all events from calendars shared with a user (within date range)
     */
    public function getSharedEvents(string $userEmail, array $userGroupIds = [], ?string $startDate = null, ?string $endDate = null): array
    {
        $sharedCalendars = $this->getSharedCalendars($userEmail, $userGroupIds);
        if (empty($sharedCalendars)) return [];

        // Index shared-calendar metadata by id so we can stitch onto
        // each event row without a per-calendar query.
        $calMeta = [];
        $calIds = [];
        foreach ($sharedCalendars as $cal) {
            $cid = (int)$cal['id'];
            $calMeta[$cid] = $cal;
            $calIds[] = $cid;
        }

        // ONE IN-clause query for all events across all shared calendars,
        // vs one query per calendar in the legacy loop.
        $placeholders = implode(',', array_fill(0, count($calIds), '?'));
        $sql = "SELECT * FROM calendar_events WHERE calendar_id IN ({$placeholders})";
        $params = $calIds;

        if ($startDate && $endDate) {
            $sql .= " AND ((end_time >= ? AND start_time <= ?) OR (recurrence IS NOT NULL AND recurrence != '' AND start_time <= ?))";
            $params[] = $startDate;
            $params[] = $endDate;
            $params[] = $endDate;
        } else {
            if ($startDate) {
                $sql .= " AND (end_time >= ? OR (recurrence IS NOT NULL AND recurrence != ''))";
                $params[] = $startDate;
            }
            if ($endDate) {
                $sql .= " AND start_time <= ?";
                $params[] = $endDate;
            }
        }
        $sql .= ' ORDER BY start_time';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $events = $stmt->fetchAll();

        // ONE batched participant lookup, vs nothing in the legacy path
        // (it skipped participants entirely for shared events).
        $participantMap = [];
        if ($events) {
            $eventIds = array_map(fn($e) => (int)$e['id'], $events);
            $participantMap = $this->getParticipantsForEvents($eventIds);
        }

        foreach ($events as &$event) {
            $cal = $calMeta[(int)$event['calendar_id']] ?? null;
            $canSeeDetails = $cal ? (bool)$cal['can_see_details'] : false;

            $event['all_day'] = (bool)$event['all_day'];
            $event['reminders'] = json_decode($event['reminders'] ?? '[]', true) ?: [];
            $event['is_shared_event'] = true;
            $event['participants'] = $participantMap[(int)$event['id']] ?? [];

            if ($cal) {
                $event['shared_by'] = $cal['shared_by'];
                $event['shared_calendar_name'] = $cal['name'];
                $event['calendar_name'] = $cal['name'];
                $event['calendar_color'] = $cal['color'];
                $event['shared_permission'] = $cal['permission'] ?? 'view';
            }

            if (!$canSeeDetails) {
                $event['title'] = 'Busy';
                $event['description'] = null;
                $event['location'] = null;
                $event['is_private'] = true;
            }
        }
        unset($event);

        if ($startDate && $endDate) {
            $events = $this->expandRecurringEvents($events, $startDate, $endDate);
        }

        usort($events, function($a, $b) {
            return strcmp($a['start_time'], $b['start_time']);
        });

        return $events;
    }
    
    // ===== EVENT OPERATIONS =====
    
    /**
     * Get events for a calendar within a date range
     */
    public function getEvents(string $email, int $calendarId, ?string $startDate = null, ?string $endDate = null): array
    {
        $calendar = $this->getCalendar($email, $calendarId);
        if (!$calendar) return [];
        
        // For recurring events, we need to also include events that START before the range
        // but could have occurrences within the range
        $sql = 'SELECT * FROM calendar_events WHERE calendar_id = ?';
        $params = [$calendarId];
        
        if ($startDate && $endDate) {
            // Include events that either:
            // 1. Fall within the range normally (non-recurring or first occurrence)
            // 2. Have recurrence and started before the range end (may have occurrences in range)
            $sql .= ' AND ((end_time >= ? AND start_time <= ?) OR (recurrence IS NOT NULL AND recurrence != \'\' AND start_time <= ?))';
            $params[] = $startDate;
            $params[] = $endDate;
            $params[] = $endDate;
        } else {
            if ($startDate) {
                $sql .= ' AND (end_time >= ? OR (recurrence IS NOT NULL AND recurrence != \'\'))';
                $params[] = $startDate;
            }
            if ($endDate) {
                $sql .= ' AND start_time <= ?';
                $params[] = $endDate;
            }
        }
        
        $sql .= ' ORDER BY start_time';
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $events = array_map(function($event) {
            $event['all_day'] = (bool)$event['all_day'];
            $event['reminders'] = json_decode($event['reminders'] ?? '[]', true) ?: [];
            return $event;
        }, $stmt->fetchAll());
        
        // Batch-load participants for all events
        if ($events) {
            $eventIds = array_column($events, 'id');
            $participantMap = $this->getParticipantsForEvents($eventIds);
            foreach ($events as &$evt) {
                $evt['participants'] = $participantMap[(int)$evt['id']] ?? [];
            }
            unset($evt);
        }
        
        // Expand recurring events into occurrences
        if ($startDate && $endDate) {
            $events = $this->expandRecurringEvents($events, $startDate, $endDate);
        }
        
        return $events;
    }
    
    /**
     * Get all events for user within date range (all calendars)
     * Includes events from the user's own calendars AND events where the user is a participant
     */
    public function getAllEvents(string $email, ?string $startDate = null, ?string $endDate = null): array
    {
        $email = strtolower($email);
        
        // Own events - include recurring events that may have occurrences in range
        $sql = '
            SELECT e.*, c.name as calendar_name, c.color as calendar_color,
                   CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_synced,
                   sm.google_calendar_id as sync_source,
                   0 as is_participant_event
            FROM calendar_events e
            JOIN calendars c ON e.calendar_id = c.id
            LEFT JOIN calendar_sync_map sm ON e.id = sm.local_event_id
            WHERE c.user_email = ?
        ';
        $params = [$email];
        
        if ($startDate && $endDate) {
            // Include events that fall in range OR have recurrence (may have future occurrences)
            $sql .= ' AND ((e.end_time >= ? AND e.start_time <= ?) OR (e.recurrence IS NOT NULL AND e.recurrence != \'\' AND e.start_time <= ?))';
            $params[] = $startDate;
            $params[] = $endDate;
            $params[] = $endDate;
        } else {
            if ($startDate) {
                $sql .= ' AND (e.end_time >= ? OR (e.recurrence IS NOT NULL AND e.recurrence != \'\'))';
                $params[] = $startDate;
            }
            if ($endDate) {
                $sql .= ' AND e.start_time <= ?';
                $params[] = $endDate;
            }
        }
        
        // Also include events where the user is a participant (shared via chat, accepted invitations)
        $sql .= '
            UNION
            SELECT e.*, c.name as calendar_name, c.color as calendar_color,
                   0 as is_synced,
                   NULL as sync_source,
                   1 as is_participant_event
            FROM calendar_events e
            JOIN calendars c ON e.calendar_id = c.id
            JOIN calendar_event_participants p ON p.event_id = e.id
            WHERE LOWER(p.user_email) = ? AND p.status = \'accepted\'
              AND c.user_email != ?
        ';
        $params[] = $email;
        $params[] = $email;
        
        if ($startDate && $endDate) {
            $sql .= ' AND ((e.end_time >= ? AND e.start_time <= ?) OR (e.recurrence IS NOT NULL AND e.recurrence != \'\' AND e.start_time <= ?))';
            $params[] = $startDate;
            $params[] = $endDate;
            $params[] = $endDate;
        } else {
            if ($startDate) {
                $sql .= ' AND (e.end_time >= ? OR (e.recurrence IS NOT NULL AND e.recurrence != \'\'))';
                $params[] = $startDate;
            }
            if ($endDate) {
                $sql .= ' AND e.start_time <= ?';
                $params[] = $endDate;
            }
        }
        
        $sql .= ' ORDER BY start_time';
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $events = array_map(function($event) {
            $event['all_day'] = (bool)$event['all_day'];
            $event['is_synced'] = (bool)($event['is_synced'] ?? false);
            $event['is_participant_event'] = (bool)($event['is_participant_event'] ?? false);
            $event['reminders'] = json_decode($event['reminders'] ?? '[]', true) ?: [];
            return $event;
        }, $stmt->fetchAll());
        
        // Batch-load participants
        if ($events) {
            $eventIds = array_column($events, 'id');
            $participantMap = $this->getParticipantsForEvents($eventIds);
            foreach ($events as &$evt) {
                $evt['participants'] = $participantMap[(int)$evt['id']] ?? [];
            }
            unset($evt);
        }
        
        // Expand recurring events into occurrences
        if ($startDate && $endDate) {
            $events = $this->expandRecurringEvents($events, $startDate, $endDate);
        }
        
        return $events;
    }
    
    /**
     * Expand recurring events into individual occurrences within a date range.
     * 
     * Parses RRULE format (e.g., RRULE:FREQ=WEEKLY;INTERVAL=2) and generates
     * virtual event instances. The original event becomes the first occurrence,
     * and additional occurrences are added with adjusted start/end times.
     */
    private function expandRecurringEvents(array $events, string $rangeStart, string $rangeEnd): array
    {
        $expanded = [];
        $rangeStartDT = new \DateTime($rangeStart);
        $rangeEndDT = new \DateTime($rangeEnd);
        
        // For recurring events, always expand at least 1 year ahead from today
        $oneYearFromNow = new \DateTime('+1 year');
        $recurrenceEndDT = $rangeEndDT > $oneYearFromNow ? $rangeEndDT : $oneYearFromNow;
        
        $maxOccurrences = 500; // Safety limit (1 year of daily = 365)
        
        error_log("[expandRecurringEvents] Called with rangeStart={$rangeStart} rangeEnd={$rangeEnd} recurrenceEnd=" . $recurrenceEndDT->format('Y-m-d') . " eventCount=" . count($events));
        
        foreach ($events as $event) {
            $rrule = $event['recurrence'] ?? '';
            
            // Non-recurring events pass through unchanged
            if (empty($rrule)) {
                $expanded[] = $event;
                continue;
            }
            
            error_log("[expandRecurringEvents] Event ID={$event['id']} title={$event['title']} recurrence={$rrule} start={$event['start_time']} end={$event['end_time']}");
            
            // Parse the RRULE
            $parsed = $this->parseRRule($rrule);
            if (!$parsed) {
                error_log("[expandRecurringEvents] Failed to parse RRULE: {$rrule}");
                // Couldn't parse, just include the original
                $expanded[] = $event;
                continue;
            }
            
            $freq = $parsed['freq'];
            $interval = $parsed['interval'];
            $count = $parsed['count'];     // null or int
            $until = $parsed['until'];     // null or DateTime
            
            $eventStart = new \DateTime($event['start_time']);
            $eventEnd = new \DateTime($event['end_time']);
            $duration = $eventStart->diff($eventEnd);
            
            // Generate occurrences
            $occurrenceCount = 0;
            $currentStart = clone $eventStart;
            $generatedCount = 0;
            
            error_log("[expandRecurringEvents] Parsed: freq={$freq} interval={$interval} count=" . var_export($count, true) . " until=" . ($until ? $until->format('Y-m-d H:i:s') : 'null'));
            
            while ($occurrenceCount < $maxOccurrences) {
                // Stop if we've exceeded the UNTIL date
                if ($until && $currentStart > $until) {
                    break;
                }
                
                // Stop if we've exceeded the COUNT
                if ($count !== null && $occurrenceCount >= $count) {
                    break;
                }
                
                // Stop if this occurrence is past the 1-year recurrence range
                if ($currentStart > $recurrenceEndDT) {
                    break;
                }
                
                $currentEnd = clone $currentStart;
                $currentEnd->add($duration);
                
                // Include occurrences that overlap with the extended recurrence range
                if ($currentEnd >= $rangeStartDT && $currentStart <= $recurrenceEndDT) {
                    $occurrence = $event;
                    $occurrence['start_time'] = $currentStart->format('Y-m-d H:i:s');
                    $occurrence['end_time'] = $currentEnd->format('Y-m-d H:i:s');
                    
                    // Mark virtual occurrences (not the first one)
                    if ($occurrenceCount > 0) {
                        $occurrence['is_recurrence_instance'] = true;
                        $occurrence['recurrence_parent_id'] = (int)$event['id'];
                        // Give virtual occurrences a unique virtual ID so frontend can distinguish them
                        $occurrence['virtual_id'] = $event['id'] . '_' . $currentStart->format('Ymd');
                    } else {
                        $occurrence['is_recurrence_instance'] = false;
                        $occurrence['recurrence_parent_id'] = null;
                        $occurrence['virtual_id'] = null;
                    }
                    
                    $expanded[] = $occurrence;
                    $generatedCount++;
                }
                
                // Advance to next occurrence
                $this->advanceDate($currentStart, $freq, $interval);
                $occurrenceCount++;
            }
            
            error_log("[expandRecurringEvents] Event ID={$event['id']}: generated {$generatedCount} occurrences (iterated {$occurrenceCount} times)");
        }
        
        // Sort by start_time
        usort($expanded, function($a, $b) {
            return strcmp($a['start_time'], $b['start_time']);
        });
        
        error_log("[expandRecurringEvents] Total expanded events: " . count($expanded) . " (from " . count($events) . " base events)");
        
        return $expanded;
    }
    
    /**
     * Parse an iCal RRULE string into components.
     * Supports: FREQ, INTERVAL, COUNT, UNTIL
     * Examples:
     *   RRULE:FREQ=DAILY
     *   RRULE:FREQ=WEEKLY;INTERVAL=2
     *   RRULE:FREQ=MONTHLY;COUNT=12
     *   RRULE:FREQ=YEARLY;UNTIL=20271231T235959Z
     */
    private function parseRRule(string $rrule): ?array
    {
        // Strip "RRULE:" prefix if present
        $rule = preg_replace('/^RRULE:/i', '', trim($rrule));
        if (empty($rule)) return null;
        
        $parts = explode(';', $rule);
        $result = [
            'freq' => null,
            'interval' => 1,
            'count' => null,
            'until' => null,
        ];
        
        foreach ($parts as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) continue;
            
            $key = strtoupper(trim($kv[0]));
            $val = trim($kv[1]);
            
            switch ($key) {
                case 'FREQ':
                    $result['freq'] = strtoupper($val);
                    break;
                case 'INTERVAL':
                    $result['interval'] = max(1, (int)$val);
                    break;
                case 'COUNT':
                    $result['count'] = max(1, (int)$val);
                    break;
                case 'UNTIL':
                    try {
                        // UNTIL can be like 20271231T235959Z or 20271231
                        $result['until'] = new \DateTime($val);
                    } catch (\Exception $e) {
                        // Ignore invalid UNTIL
                    }
                    break;
            }
        }
        
        if (!$result['freq']) return null;
        
        // Validate frequency
        $validFreqs = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];
        if (!in_array($result['freq'], $validFreqs)) return null;
        
        return $result;
    }
    
    /**
     * Advance a DateTime by the recurrence frequency and interval.
     */
    private function advanceDate(\DateTime &$date, string $freq, int $interval): void
    {
        switch ($freq) {
            case 'DAILY':
                $date->modify("+{$interval} day");
                break;
            case 'WEEKLY':
                $date->modify("+{$interval} week");
                break;
            case 'MONTHLY':
                // Store original day for month-end handling
                $origDay = (int)$date->format('j');
                $date->modify("+{$interval} month");
                // If month doesn't have enough days, adjust to last day of month
                $newDay = (int)$date->format('j');
                if ($newDay < $origDay) {
                    // We overflowed - go back to last day of target month
                    $date->modify('-' . $newDay . ' days');
                }
                break;
            case 'YEARLY':
                $date->modify("+{$interval} year");
                break;
        }
    }
    
    /**
     * Find calendar reminders that are due to fire right now.
     *
     * Used by the process-calendar-reminders cron. Each (recurring) occurrence is
     * evaluated in the EVENT'S OWN timezone so a "09:00 Europe/Budapest" event
     * keeps firing at 09:00 local across DST transitions instead of drifting.
     * Returns one entry per (event, occurrence, reminder-minute) whose fire time
     * falls in the half-open window (now - windowSeconds, now].
     *
     * @return array<int,array{event_id:int,user_email:string,title:string,occurrence_start:string,minutes:int,all_day:bool}>
     */
    public function getDueReminders(\DateTimeImmutable $now, int $windowSeconds = 120, int $maxLeadMinutes = 1440): array
    {
        $utc = new \DateTimeZone('UTC');
        $now = $now->setTimezone($utc);
        $windowSeconds = max(1, $windowSeconds);
        $maxLeadMinutes = max(1, $maxLeadMinutes);

        $fireFloor = $now->modify('-' . $windowSeconds . ' seconds');

        // fire = start - minutes; minutes in [0, maxLead]; fire in (fireFloor, now]
        //   => occurrence start in (fireFloor, now + maxLead].
        $winStart = $fireFloor;
        $winEnd = $now->modify('+' . $maxLeadMinutes . ' minutes');

        // Coarse, timezone-agnostic SQL pre-filter with a generous +/-14h buffer to
        // cover any event timezone offset. Precise matching happens in PHP below.
        $sqlLow = $winStart->modify('-14 hours')->format('Y-m-d H:i:s');
        $sqlHigh = $winEnd->modify('+14 hours')->format('Y-m-d H:i:s');

        try {
            $stmt = $this->db->prepare("
                SELECT e.id, e.calendar_id, e.title, e.start_time, e.end_time, e.all_day,
                       e.timezone, e.recurrence, e.reminders, c.user_email
                FROM calendar_events e
                JOIN calendars c ON e.calendar_id = c.id
                WHERE e.reminders IS NOT NULL AND e.reminders != '' AND e.reminders != '[]'
                  AND (
                        ((e.recurrence IS NULL OR e.recurrence = '') AND e.start_time BETWEEN ? AND ?)
                     OR (e.recurrence IS NOT NULL AND e.recurrence != '' AND e.start_time <= ?)
                  )
            ");
            $stmt->execute([$sqlLow, $sqlHigh, $sqlHigh]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('CalendarService::getDueReminders query failed: ' . $e->getMessage());
            return [];
        }

        $due = [];
        foreach ($rows as $row) {
            $minutesList = $this->normalizeReminderMinutes($row['reminders']);
            if (empty($minutesList)) {
                continue;
            }

            try {
                $eventTz = new \DateTimeZone($row['timezone'] ?: 'UTC');
            } catch (\Throwable $e) {
                $eventTz = $utc;
            }

            $rrule = !empty($row['recurrence']) ? $this->parseRRule($row['recurrence']) : null;
            $occurrences = $this->occurrenceStartsInWindow(
                (string)$row['start_time'],
                $rrule,
                $eventTz,
                $winStart,
                $winEnd
            );

            foreach ($occurrences as $occUtc) {
                foreach ($minutesList as $m) {
                    $fire = $occUtc->modify('-' . $m . ' minutes');
                    if ($fire > $fireFloor && $fire <= $now) {
                        $due[] = [
                            'event_id' => (int)$row['id'],
                            'user_email' => strtolower((string)$row['user_email']),
                            'title' => (string)$row['title'],
                            'occurrence_start' => $occUtc->format('Y-m-d H:i:s'),
                            'minutes' => (int)$m,
                            'all_day' => (bool)$row['all_day'],
                        ];
                    }
                }
            }
        }

        return $due;
    }

    /**
     * Normalize a reminders JSON blob into a list of distinct "minutes before"
     * integers. Accepts both plain ints and {minutes, method} objects.
     *
     * @param mixed $raw
     * @return int[]
     */
    private function normalizeReminderMinutes($raw): array
    {
        $decoded = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                $out[(int)$item] = true;
            } elseif (is_array($item) && isset($item['minutes']) && is_numeric($item['minutes'])) {
                $out[(int)$item['minutes']] = true;
            }
        }

        return array_map('intval', array_keys($out));
    }

    /**
     * Compute occurrence start instants (UTC) within [winStart, winEnd] for a
     * single event, stepping wall-clock in the event timezone (DST-correct).
     *
     * @return \DateTimeImmutable[]
     */
    private function occurrenceStartsInWindow(
        string $startTime,
        ?array $rrule,
        \DateTimeZone $tz,
        \DateTimeImmutable $winStart,
        \DateTimeImmutable $winEnd
    ): array {
        $utc = new \DateTimeZone('UTC');
        $out = [];

        try {
            $start = new \DateTime($startTime, $tz);
        } catch (\Throwable $e) {
            return [];
        }

        // Non-recurring: a single occurrence at the event start.
        if (!$rrule) {
            $occUtc = \DateTimeImmutable::createFromMutable((clone $start)->setTimezone($utc));
            if ($occUtc >= $winStart && $occUtc <= $winEnd) {
                $out[] = $occUtc;
            }
            return $out;
        }

        $freq = $rrule['freq'];
        $interval = max(1, (int)$rrule['interval']);
        $count = $rrule['count'];   // null|int
        $until = $rrule['until'];   // null|\DateTime

        $cur = clone $start;
        $index = 0;

        // Fast-forward toward the window (only when no COUNT cap, which needs exact
        // iteration). Bulk-jump by whole intervals to avoid scanning years of dailies.
        if ($count === null) {
            $guard = 0;
            while ($cur < $winStart && $guard < 100000) {
                $diffSecs = $winStart->getTimestamp() - $cur->getTimestamp();
                $jumped = false;
                if ($freq === 'DAILY') {
                    $n = intdiv((int)floor($diffSecs / 86400), $interval) * $interval;
                    if ($n >= $interval) { $cur->modify("+{$n} day"); $jumped = true; }
                } elseif ($freq === 'WEEKLY') {
                    $n = intdiv((int)floor($diffSecs / (7 * 86400)), $interval) * $interval;
                    if ($n >= $interval) { $cur->modify("+{$n} week"); $jumped = true; }
                } elseif ($freq === 'YEARLY') {
                    $n = intdiv((int)floor($diffSecs / (365 * 86400)), $interval) * $interval;
                    if ($n >= $interval) { $cur->modify("+{$n} year"); $jumped = true; }
                }
                if (!$jumped) {
                    $this->advanceDate($cur, $freq, $interval);
                }
                if ($until && $cur > $until) {
                    return $out;
                }
                $guard++;
            }
        }

        // Fine-step through the window, collecting in-range occurrences.
        $guard = 0;
        while ($cur <= $winEnd && $guard < 20000) {
            if ($until && $cur > $until) {
                break;
            }
            if ($count !== null && $index >= $count) {
                break;
            }

            $occUtc = \DateTimeImmutable::createFromMutable((clone $cur)->setTimezone($utc));
            if ($occUtc >= $winStart && $occUtc <= $winEnd) {
                $out[] = $occUtc;
            }

            $this->advanceDate($cur, $freq, $interval);
            $index++;
            $guard++;
        }

        return $out;
    }

    /**
     * Get a single event
     */
    public function getEvent(string $email, int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT e.*, c.user_email 
            FROM calendar_events e
            JOIN calendars c ON e.calendar_id = c.id
            WHERE e.id = ? AND c.user_email = ?
        ');
        $stmt->execute([$id, strtolower($email)]);
        $event = $stmt->fetch();
        
        if ($event) {
            $event['all_day'] = (bool)$event['all_day'];
            $event['reminders'] = json_decode($event['reminders'] ?? '[]', true) ?: [];
            if (isset($event['is_meeting'])) {
                $event['is_meeting'] = (bool)$event['is_meeting'];
            }
            unset($event['user_email']);
            
            $event['participants'] = $this->getEventParticipants((int)$event['id']);
        }
        
        return $event ?: null;
    }

    private function getEventParticipants(int $eventId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT user_email as email, status
                FROM calendar_event_participants
                WHERE event_id = ?
                ORDER BY invited_at ASC
            ");
            $stmt->execute([$eventId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            return [];
        }
    }

    private function getParticipantsForEvents(array $eventIds): array
    {
        if (empty($eventIds)) return [];
        try {
            $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
            $stmt = $this->db->prepare("
                SELECT event_id, user_email as email, status
                FROM calendar_event_participants
                WHERE event_id IN ({$placeholders})
                ORDER BY invited_at ASC
            ");
            $stmt->execute(array_values($eventIds));
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $map = [];
            foreach ($rows as $row) {
                $eid = (int)$row['event_id'];
                unset($row['event_id']);
                $map[$eid][] = $row;
            }
            return $map;
        } catch (\PDOException $e) {
            return [];
        }
    }
    
    /**
     * Get event with shared access check.
     * Returns the event if user owns it OR has shared access (direct or via groups).
     * Returns ['event' => ..., 'permission' => 'own'|'view'|'edit', 'owner_email' => ...]
     */
    public function getEventWithAccess(string $email, int $id, array $userGroupIds = []): ?array
    {
        $email = strtolower($email);
        
        // 1. Check if user owns the event
        $ownEvent = $this->getEvent($email, $id);
        if ($ownEvent) {
            return ['event' => $ownEvent, 'permission' => 'own', 'owner_email' => $email];
        }
        
        // 2. Check shared access - direct user share
        $stmt = $this->db->prepare('
            SELECT e.*, c.user_email as owner_email, cs.permission, cs.can_see_details
            FROM calendar_events e
            JOIN calendars c ON e.calendar_id = c.id
            JOIN calendar_shares cs ON cs.calendar_id = c.id AND cs.shared_with_email = ?
            WHERE e.id = ?
        ');
        $stmt->execute([$email, $id]);
        $result = $stmt->fetch();
        
        if ($result) {
            $event = $this->formatEvent($result);
            return [
                'event' => $event,
                'permission' => $result['permission'],
                'owner_email' => $result['owner_email'],
                'can_see_details' => (bool)$result['can_see_details']
            ];
        }
        
        // 3. Check shared access - via group membership
        if (!empty($userGroupIds)) {
            $placeholders = implode(',', array_fill(0, count($userGroupIds), '?'));
            $stmt = $this->db->prepare("
                SELECT e.*, c.user_email as owner_email, cs.permission, cs.can_see_details
                FROM calendar_events e
                JOIN calendars c ON e.calendar_id = c.id
                JOIN calendar_shares cs ON cs.calendar_id = c.id AND cs.shared_with_group_id IN ($placeholders)
                WHERE e.id = ?
                LIMIT 1
            ");
            $params = array_merge($userGroupIds, [$id]);
            $stmt->execute($params);
            $result = $stmt->fetch();
            
            if ($result) {
                $event = $this->formatEvent($result);
                return [
                    'event' => $event,
                    'permission' => $result['permission'],
                    'owner_email' => $result['owner_email'],
                    'can_see_details' => (bool)$result['can_see_details']
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Update event with shared access (checks permission)
     */
    public function updateEventWithAccess(string $email, int $id, array $data, array $userGroupIds = []): ?array
    {
        $access = $this->getEventWithAccess($email, $id, $userGroupIds);
        if (!$access) return null;
        
        // Only owner or 'edit' permission can update
        if ($access['permission'] !== 'own' && $access['permission'] !== 'edit') {
            return null;
        }
        
        // For shared events, use the owner's email for the actual update
        $ownerEmail = $access['owner_email'];
        return $this->updateEvent($ownerEmail, $id, $data);
    }
    
    /**
     * Delete event with shared access (checks permission)
     */
    public function deleteEventWithAccess(string $email, int $id, array $userGroupIds = []): bool
    {
        $access = $this->getEventWithAccess($email, $id, $userGroupIds);
        if (!$access) return false;
        
        // Only owner or 'edit' permission can delete
        if ($access['permission'] !== 'own' && $access['permission'] !== 'edit') {
            return false;
        }
        
        $ownerEmail = $access['owner_email'];
        return $this->deleteEvent($ownerEmail, $id);
    }
    
    /**
     * Get all emails that have shared access to a calendar (for WebSocket notifications)
     */
    public function getCalendarShareRecipients(int $calendarId, string $ownerEmail): array
    {
        $recipients = [];
        $ownerEmail = strtolower($ownerEmail);
        
        // Direct user shares
        $stmt = $this->db->prepare('
            SELECT shared_with_email FROM calendar_shares 
            WHERE calendar_id = ? AND shared_with_email IS NOT NULL
        ');
        $stmt->execute([$calendarId]);
        while ($row = $stmt->fetch()) {
            $email = strtolower($row['shared_with_email']);
            if ($email !== $ownerEmail) {
                $recipients[] = $email;
            }
        }
        
        // Group shares - get all group member emails
        $stmt = $this->db->prepare('
            SELECT DISTINCT oc.email 
            FROM calendar_shares cs
            JOIN colleague_group_members cgm ON cgm.group_id = cs.shared_with_group_id
            JOIN organization_colleagues oc ON oc.id = cgm.colleague_id
            WHERE cs.calendar_id = ? AND cs.shared_with_group_id IS NOT NULL
        ');
        $stmt->execute([$calendarId]);
        while ($row = $stmt->fetch()) {
            $email = strtolower($row['email']);
            if ($email !== $ownerEmail && !in_array($email, $recipients)) {
                $recipients[] = $email;
            }
        }
        
        return $recipients;
    }

    /**
     * Get accepted participant emails for an event so their other tabs/devices refresh too.
     */
    public function getEventParticipantRecipients(int $eventId, array $excludeEmails = []): array
    {
        $exclude = array_unique(array_map('strtolower', array_filter($excludeEmails)));

        try {
            $stmt = $this->db->prepare('
                SELECT DISTINCT LOWER(user_email) AS user_email
                FROM calendar_event_participants
                WHERE event_id = ? AND status = \'accepted\'
            ');
            $stmt->execute([$eventId]);

            $recipients = [];
            while ($row = $stmt->fetch()) {
                $email = strtolower((string)($row['user_email'] ?? ''));
                if ($email === '' || in_array($email, $exclude, true) || in_array($email, $recipients, true)) {
                    continue;
                }
                $recipients[] = $email;
            }

            return $recipients;
        } catch (\PDOException $e) {
            return [];
        }
    }
    
    /**
     * Helper: Format a raw event row into a clean event array
     */
    private function formatEvent(array $event): array
    {
        $event['all_day'] = (bool)($event['all_day'] ?? false);
        $event['reminders'] = json_decode($event['reminders'] ?? '[]', true) ?: [];
        if (isset($event['is_meeting'])) {
            $event['is_meeting'] = (bool)$event['is_meeting'];
        }
        unset($event['user_email'], $event['owner_email'], $event['permission'], $event['can_see_details']);
        return $event;
    }
    
    /**
     * Create an event
     */
    public function createEvent(string $email, int $calendarId, array $data): ?array
    {
        $calendar = $this->getCalendar($email, $calendarId);
        if (!$calendar) {
            error_log("CalendarService createEvent: calendar not found for email='{$email}' id={$calendarId}");
            return null;
        }
        
        try {
            $uid = $this->generateUID();
            $etag = bin2hex(random_bytes(16));
            
            // Check which optional columns exist (for backwards compatibility)
            $hasClientId = $this->columnExists('calendar_events', 'client_id');
            $hasLinkedEmail = $this->columnExists('calendar_events', 'linked_message_id');
            $hasMeeting = $this->columnExists('calendar_events', 'is_meeting');
            
            // Build dynamic column list based on available columns
            $columns = ['calendar_id', 'uid', 'title', 'description', 'location', 'start_time', 'end_time', 'all_day', 'timezone', 'recurrence', 'reminders', 'color', 'etag'];
            $values = [
                $calendarId,
                $uid,
                $data['title'] ?? 'Untitled Event',
                $data['description'] ?? null,
                $data['location'] ?? null,
                $this->normalizeDateTime($data['start_time'] ?? null),
                $this->normalizeDateTime($data['end_time'] ?? null),
                ($data['all_day'] ?? false) ? 1 : 0,
                $data['timezone'] ?? $calendar['timezone'] ?? 'UTC',
                $data['recurrence'] ?? null,
                json_encode($data['reminders'] ?? []),
                $data['color'] ?? null,
                $etag,
            ];
            
            if ($hasClientId) {
                $columns[] = 'client_id';
                $values[] = $data['client_id'] ?? null;
            }

            if ($this->columnExists('calendar_events', 'board_id')) {
                $columns[] = 'board_id';
                $columns[] = 'card_id';
                $values[] = $data['board_id'] ?? null;
                $values[] = $data['card_id'] ?? null;
            }
            
            if ($hasLinkedEmail) {
                $columns[] = 'linked_message_id';
                $columns[] = 'linked_email_subject';
                $columns[] = 'linked_email_sender';
                $columns[] = 'linked_email_folder';
                $values[] = $data['linked_message_id'] ?? null;
                $values[] = $data['linked_email_subject'] ?? null;
                $values[] = $data['linked_email_sender'] ?? null;
                $values[] = $data['linked_email_folder'] ?? null;
            }
            
            // Meeting columns
            if ($hasMeeting) {
                $columns[] = 'is_meeting';
                $columns[] = 'meeting_token';
                $columns[] = 'meeting_conversation_id';
                $values[] = ($data['is_meeting'] ?? false) ? 1 : 0;
                $values[] = $data['meeting_token'] ?? null;
                $values[] = $data['meeting_conversation_id'] ?? null;
                if ($this->columnExists('calendar_events', 'meeting_room_name')) {
                    $columns[] = 'meeting_room_name';
                    $values[] = $data['meeting_room_name'] ?? null;
                }
            }
            
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $columnList = implode(', ', $columns);
            
            $stmt = $this->db->prepare("
                INSERT INTO calendar_events ($columnList)
                VALUES ($placeholders)
            ");
            
            $stmt->execute($values);
            
            // Capture ID before any other query
            $eventId = (int)$this->db->lastInsertId();
            
            // Update calendar ctag
            $this->updateCalendarCtag($calendarId);
            
            return $this->getEvent($email, $eventId);
        } catch (\PDOException $e) {
            error_log("CalendarService createEvent error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get an event by its meeting token (public — no email required)
     */
    public function getEventByMeetingToken(string $token): ?array
    {
        $stmt = $this->db->prepare('
            SELECT e.*, c.user_email as organizer_email, c.name as calendar_name
            FROM calendar_events e
            JOIN calendars c ON e.calendar_id = c.id
            WHERE e.meeting_token = ? AND e.is_meeting = 1
        ');
        $stmt->execute([$token]);
        $event = $stmt->fetch();
        
        if ($event) {
            $event['all_day'] = (bool)$event['all_day'];
            $event['is_meeting'] = (bool)$event['is_meeting'];
            $event['reminders'] = json_decode($event['reminders'] ?? '[]', true) ?: [];
        }
        
        return $event ?: null;
    }
    
    /**
     * Generate a unique meeting token
     */
    public function generateMeetingToken(): string
    {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Normalize a datetime string to MySQL DATETIME format (`YYYY-MM-DD HH:MM:SS`).
     *
     * Accepts: ISO-8601 (`2026-05-12T17:25:00.123Z`, with or without timezone offset),
     * MySQL DATETIME, RFC 3339, or anything strtotime() can parse. Always stores in UTC
     * because that is the contract the rest of the calendar layer assumes.
     *
     * Why this exists: MariaDB ≥10.2 ships with STRICT_TRANS_TABLES by default, which
     * silently rejects ISO-8601 strings on DATETIME columns and makes the whole INSERT
     * fail. The chat "Meet Now" path was sending `new Date().toISOString()` and 500'd.
     */
    private function normalizeDateTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }
        try {
            $dt = new \DateTimeImmutable($value);
        } catch (\Throwable $e) {
            $ts = strtotime($value);
            if ($ts === false) {
                return $value;
            }
            $dt = (new \DateTimeImmutable('@' . $ts));
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Check if a column exists in a table
     */
    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as cnt FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            $result = $stmt->fetch();
            return $result && (int)$result['cnt'] > 0;
        } catch (\PDOException $e) {
            return false;
        }
    }
    
    /**
     * Update an event
     */
    public function updateEvent(string $email, int $id, array $data): ?array
    {
        $event = $this->getEvent($email, $id);
        if (!$event) return null;
        $oldCalendarId = (int)$event['calendar_id'];
        
        $fields = [];
        $values = [];
        
        if (isset($data['title'])) {
            $fields[] = 'title = ?';
            $values[] = $data['title'];
        }
        if (isset($data['description'])) {
            $fields[] = 'description = ?';
            $values[] = $data['description'];
        }
        if (isset($data['location'])) {
            $fields[] = 'location = ?';
            $values[] = $data['location'];
        }
        if (isset($data['start_time'])) {
            $fields[] = 'start_time = ?';
            $values[] = $this->normalizeDateTime($data['start_time']);
        }
        if (isset($data['end_time'])) {
            $fields[] = 'end_time = ?';
            $values[] = $this->normalizeDateTime($data['end_time']);
        }
        if (isset($data['all_day'])) {
            $fields[] = 'all_day = ?';
            $values[] = $data['all_day'] ? 1 : 0;
        }
        if (isset($data['recurrence'])) {
            $fields[] = 'recurrence = ?';
            $values[] = $data['recurrence'];
        }
        if (isset($data['reminders'])) {
            $fields[] = 'reminders = ?';
            $values[] = json_encode($data['reminders']);
        }
        if (isset($data['color'])) {
            $fields[] = 'color = ?';
            $values[] = $data['color'];
        }
        if (isset($data['calendar_id'])) {
            $targetCalendar = $this->getCalendar($email, (int)$data['calendar_id']);
            if (!$targetCalendar) {
                return null;
            }
            $fields[] = 'calendar_id = ?';
            $values[] = $data['calendar_id'];
        }
        if (array_key_exists('client_id', $data) && $this->columnExists('calendar_events', 'client_id')) {
            $fields[] = 'client_id = ?';
            $values[] = $data['client_id'];
        }
        if (array_key_exists('board_id', $data) && $this->columnExists('calendar_events', 'board_id')) {
            $fields[] = 'board_id = ?';
            $values[] = $data['board_id'];
        }
        if (array_key_exists('card_id', $data) && $this->columnExists('calendar_events', 'card_id')) {
            $fields[] = 'card_id = ?';
            $values[] = $data['card_id'];
        }
        if ((array_key_exists('board_id', $data) || array_key_exists('card_id', $data))
            && $this->columnExists('calendar_events', 'time_bridged_at')) {
            $fields[] = 'time_bridged_at = NULL';
        }
        if (isset($data['is_meeting']) && $this->columnExists('calendar_events', 'is_meeting')) {
            $fields[] = 'is_meeting = ?';
            $values[] = $data['is_meeting'] ? 1 : 0;
        }
        if (array_key_exists('meeting_token', $data) && $this->columnExists('calendar_events', 'meeting_token')) {
            $fields[] = 'meeting_token = ?';
            $values[] = $data['meeting_token'];
        }
        if (array_key_exists('meeting_conversation_id', $data) && $this->columnExists('calendar_events', 'meeting_conversation_id')) {
            $fields[] = 'meeting_conversation_id = ?';
            $values[] = $data['meeting_conversation_id'];
        }
        if (array_key_exists('meeting_room_name', $data) && $this->columnExists('calendar_events', 'meeting_room_name')) {
            $fields[] = 'meeting_room_name = ?';
            $values[] = $data['meeting_room_name'];
        }
        
        if (empty($fields)) {
            return $event;
        }
        
        // Update etag
        $fields[] = 'etag = ?';
        $values[] = bin2hex(random_bytes(16));
        
        $values[] = $id;
        
        $stmt = $this->db->prepare('UPDATE calendar_events SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);
        
        $newCalendarId = isset($data['calendar_id']) ? (int)$data['calendar_id'] : $oldCalendarId;
        $this->updateCalendarCtag($oldCalendarId);
        if ($newCalendarId !== $oldCalendarId) {
            $this->updateCalendarCtag($newCalendarId);
        }
        
        return $this->getEvent($email, $id);
    }
    
    /**
     * Delete an event
     */
    public function deleteEvent(string $email, int $id): bool
    {
        $event = $this->getEvent($email, $id);
        if (!$event) return false;

        return $this->deleteEventById($id);
    }
    
    /**
     * Delete all events for a user
     */
    public function deleteAllEvents(string $email): int
    {
        // Get all calendar IDs for this user
        $stmt = $this->db->prepare('SELECT id FROM calendars WHERE user_email = ?');
        $stmt->execute([$email]);
        $calendarIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        if (empty($calendarIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($calendarIds), '?'));
        $stmt = $this->db->prepare("SELECT id FROM calendar_events WHERE calendar_id IN ($placeholders)");
        $stmt->execute($calendarIds);
        $eventIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);

        return $this->deleteEventsByIds($eventIds);
    }

    /**
     * Delete an event without requiring the owner's email.
     * Sync services use this when they only know the local event ID.
     */
    public function deleteEventById(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT id, calendar_id FROM calendar_events WHERE id = ?');
        $stmt->execute([$id]);
        $event = $stmt->fetch();
        if (!$event) {
            return false;
        }

        $this->removeParticipantsForEventIds([$id]);

        $stmt = $this->db->prepare('DELETE FROM calendar_events WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            $this->updateCalendarCtag((int)$event['calendar_id']);
            return true;
        }

        return false;
    }

    /**
     * Delete multiple events while cleaning participant rows and refreshing ctags.
     */
    public function deleteEventsByIds(array $eventIds): int
    {
        $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds))));
        if (empty($eventIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $this->db->prepare("
            SELECT DISTINCT calendar_id
            FROM calendar_events
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($eventIds);
        $calendarIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);

        $this->removeParticipantsForEventIds($eventIds);

        $stmt = $this->db->prepare("DELETE FROM calendar_events WHERE id IN ($placeholders)");
        $stmt->execute($eventIds);
        $deletedCount = $stmt->rowCount();

        foreach ($calendarIds as $calendarId) {
            $this->updateCalendarCtag($calendarId);
        }

        return $deletedCount;
    }
    
    /**
     * Quick add event (parse natural language)
     */
    public function quickAdd(string $email, int $calendarId, string $text): ?array
    {
        // Simple parsing - can be enhanced
        // Format: "Meeting with John tomorrow at 3pm"
        // Format: "Dentist 2024-01-15 14:00"
        
        $title = $text;
        $startTime = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $endTime = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $allDay = false;
        
        // Try to parse date/time from text
        if (preg_match('/(\d{4}-\d{2}-\d{2})(?:\s+(\d{1,2}:\d{2}))?/i', $text, $matches)) {
            $date = $matches[1];
            $time = $matches[2] ?? '09:00';
            $startTime = $date . ' ' . $time . ':00';
            $endTime = date('Y-m-d H:i:s', strtotime($startTime) + 3600);
            $title = trim(str_replace($matches[0], '', $text));
        } elseif (preg_match('/(tomorrow|today|next\s+\w+)/i', $text, $matches)) {
            $startTime = date('Y-m-d H:i:s', strtotime($matches[1] . ' 09:00'));
            $endTime = date('Y-m-d H:i:s', strtotime($startTime) + 3600);
            $title = trim(preg_replace('/' . preg_quote($matches[0], '/') . '/i', '', $text));
        }
        
        if (empty($title)) {
            $title = 'Quick Event';
        }
        
        return $this->createEvent($email, $calendarId, [
            'title' => $title,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'all_day' => $allDay,
        ]);
    }
    
    // ===== SUBSCRIPTION SUPPORT =====
    
    /**
     * Generate or get subscription token for a calendar
     */
    public function getOrCreateSubscriptionToken(string $email, int $calendarId): ?string
    {
        $calendar = $this->getCalendar($email, $calendarId);
        if (!$calendar) return null;
        
        // Check if token already exists
        if (!empty($calendar['subscription_token'])) {
            return $calendar['subscription_token'];
        }
        
        // Generate new token
        $token = bin2hex(random_bytes(32));
        
        $stmt = $this->db->prepare('UPDATE calendars SET subscription_token = ? WHERE id = ? AND user_email = ?');
        $stmt->execute([$token, $calendarId, strtolower($email)]);
        
        return $token;
    }
    
    /**
     * Regenerate subscription token (invalidates old one)
     */
    public function regenerateSubscriptionToken(string $email, int $calendarId): ?string
    {
        $calendar = $this->getCalendar($email, $calendarId);
        if (!$calendar) return null;
        
        $token = bin2hex(random_bytes(32));
        
        $stmt = $this->db->prepare('UPDATE calendars SET subscription_token = ? WHERE id = ? AND user_email = ?');
        $stmt->execute([$token, $calendarId, strtolower($email)]);
        
        return $token;
    }
    
    /**
     * Get calendar by subscription token (public access, no auth)
     */
    public function getCalendarByToken(string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM calendars WHERE subscription_token = ?');
        $stmt->execute([$token]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Export calendar as ICS by subscription token (public access)
     */
    public function exportICSByToken(string $token): ?string
    {
        $calendar = $this->getCalendarByToken($token);
        if (!$calendar) return null;
        
        // Get events for this calendar
        $stmt = $this->db->prepare('SELECT * FROM calendar_events WHERE calendar_id = ? ORDER BY start_time');
        $stmt->execute([$calendar['id']]);
        $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Webmail//Calendar//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "X-WR-CALNAME:" . $this->escapeICS($calendar['name']) . "\r\n";
        $ics .= "X-WR-TIMEZONE:" . ($calendar['timezone'] ?? 'UTC') . "\r\n";
        $ics .= "REFRESH-INTERVAL;VALUE=DURATION:PT15M\r\n"; // Suggest 15 min refresh
        
        foreach ($events as $event) {
            $ics .= $this->eventToVEvent($event);
        }
        
        $ics .= "END:VCALENDAR\r\n";
        
        return $ics;
    }
    
    // ===== CALDAV SUPPORT =====
    
    /**
     * Generate unique event UID
     */
    private function generateUID(): string
    {
        return bin2hex(random_bytes(16)) . '@webmail';
    }
    
    /**
     * Update calendar sync token
     */
    private function updateCalendarCtag(int $calendarId): void
    {
        $ctag = bin2hex(random_bytes(16));
        $this->db->prepare('UPDATE calendars SET ctag = ? WHERE id = ?')
            ->execute([$ctag, $calendarId]);
    }

    /**
     * The participants table has no FK, so event deletion must clean it manually.
     */
    private function removeParticipantsForEventIds(array $eventIds): void
    {
        $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds))));
        if (empty($eventIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $this->db->prepare("DELETE FROM calendar_event_participants WHERE event_id IN ($placeholders)");
        $stmt->execute($eventIds);
    }
    
    /**
     * Export calendar as iCal format
     */
    public function exportICS(string $email, int $calendarId): ?string
    {
        $calendar = $this->getCalendar($email, $calendarId);
        if (!$calendar) return null;
        
        $events = $this->getEvents($email, $calendarId);
        
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Webmail//Calendar//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "X-WR-CALNAME:" . $this->escapeICS($calendar['name']) . "\r\n";
        
        foreach ($events as $event) {
            $ics .= $this->eventToVEvent($event);
        }
        
        $ics .= "END:VCALENDAR\r\n";
        
        return $ics;
    }

    /**
     * Export ALL of a user's calendars as a single iCal (VCALENDAR) document.
     * Used by the Panel-driven migration export to pull a user's complete
     * calendar out in one file. Returns an empty calendar (valid ICS) when the
     * user has no events.
     */
    public function exportAllICS(string $email): string
    {
        $calendars = $this->getCalendars($email);

        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Webmail//Calendar//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "X-WR-CALNAME:" . $this->escapeICS($email) . "\r\n";

        foreach ($calendars as $calendar) {
            $events = $this->getEvents($email, (int)$calendar['id']);
            foreach ($events as $event) {
                $ics .= $this->eventToVEvent($event);
            }
        }

        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }
    
    /**
     * Convert event to VEVENT format
     */
    private function eventToVEvent(array $event): string
    {
        $vevent = "BEGIN:VEVENT\r\n";
        $vevent .= "UID:" . $event['uid'] . "\r\n";
        $vevent .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        
        if ($event['all_day']) {
            $vevent .= "DTSTART;VALUE=DATE:" . date('Ymd', strtotime($event['start_time'])) . "\r\n";
            $vevent .= "DTEND;VALUE=DATE:" . date('Ymd', strtotime($event['end_time'])) . "\r\n";
        } else {
            $vevent .= "DTSTART:" . gmdate('Ymd\THis\Z', strtotime($event['start_time'])) . "\r\n";
            $vevent .= "DTEND:" . gmdate('Ymd\THis\Z', strtotime($event['end_time'])) . "\r\n";
        }
        
        $vevent .= "SUMMARY:" . $this->escapeICS($event['title']) . "\r\n";
        
        if ($event['description']) {
            $vevent .= "DESCRIPTION:" . $this->escapeICS($event['description']) . "\r\n";
        }
        if ($event['location']) {
            $vevent .= "LOCATION:" . $this->escapeICS($event['location']) . "\r\n";
        }
        if ($event['recurrence']) {
            $vevent .= "RRULE:" . $event['recurrence'] . "\r\n";
        }
        
        $vevent .= "END:VEVENT\r\n";
        
        return $vevent;
    }
    
    /**
     * Escape string for iCal format
     */
    private function escapeICS(string $str): string
    {
        $str = str_replace('\\', '\\\\', $str);
        $str = str_replace("\n", '\\n', $str);
        $str = str_replace(',', '\\,', $str);
        $str = str_replace(';', '\\;', $str);
        return $str;
    }
}

