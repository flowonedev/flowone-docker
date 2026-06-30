<?php

namespace Webmail\Services;

use PDO;

class CalendarInviteService
{
    private PDO $db;
    private array $config;
    private SmtpService $smtp;

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->smtp = new SmtpService($config['smtp'] ?? []);
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTablesExist());
    }
    
    /**
     * Ensure required tables exist
     */
    private function ensureTablesExist(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS calendar_event_participants (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    event_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    status ENUM('pending', 'accepted', 'declined', 'tentative') NOT NULL DEFAULT 'pending',
                    invited_by_email VARCHAR(255) NOT NULL,
                    invited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    responded_at TIMESTAMP NULL DEFAULT NULL,
                    response_message TEXT DEFAULT NULL,
                    invite_token VARCHAR(64) NOT NULL,
                    reminder_sent TINYINT(1) DEFAULT 0,
                    
                    INDEX idx_event_id (event_id),
                    INDEX idx_user_email (user_email),
                    INDEX idx_status (status),
                    INDEX idx_invite_token (invite_token),
                    
                    UNIQUE KEY unique_event_participant (event_id, user_email),
                    UNIQUE KEY unique_invite_token (invite_token)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\PDOException $e) {
            error_log("CalendarInviteService table creation error: " . $e->getMessage());
        }
    }

    /**
     * Invite participants to an event
     * @param string|null $meetingJoinUrlParam Full join URL (e.g. https://flowone.pro/guest/call/{token}) or legacy meeting_token hex for /meet/{token}
     */
    public function inviteParticipants(int $eventId, array $emails, int $invitedBy, ?string $organizerEmail = null, ?string $meetingJoinUrlParam = null): array
    {
        $results = ['success' => [], 'failed' => []];
        
        // Get event details
        $stmt = $this->db->prepare("
            SELECT e.*, c.name as calendar_name, c.color as calendar_color, c.user_email as owner_email
            FROM calendar_events e
            LEFT JOIN calendars c ON e.calendar_id = c.id
            WHERE e.id = ?
        ");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$event) {
            return ['error' => 'Event not found'];
        }
        
        // Use the calendar owner's email as organizer
        $organizerEmail = $organizerEmail ?? $event['owner_email'];
        $organizer = [
            'email' => $organizerEmail,
            'display_name' => $organizerEmail
        ];
        
        $baseUrl = rtrim($this->config['app_url'] ?? 'https://flowone.pro', '/');
        $meetingJoinUrl = $this->resolveMeetingJoinUrl($baseUrl, $meetingJoinUrlParam, $event);
        
        // Check if this is a meeting (explicit URL, or legacy token on the event)
        $isMeeting = $meetingJoinUrl !== null;
        
        // Collect all valid attendee emails for the iCalendar ATTENDEE list
        $allAttendeeEmails = array_filter(
            array_map(fn($e) => trim(strtolower($e)), $emails),
            fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
        );

        foreach ($emails as $email) {
            $email = trim(strtolower($email));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $results['failed'][] = ['email' => $email, 'reason' => 'Invalid email'];
                continue;
            }
            
            try {
                // Check if already invited
                $stmt = $this->db->prepare("
                    SELECT id, status FROM calendar_event_participants 
                    WHERE event_id = ? AND user_email = ?
                ");
                $stmt->execute([$eventId, $email]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $results['failed'][] = ['email' => $email, 'reason' => 'Already invited'];
                    continue;
                }
                
                // Generate unique invite token
                $inviteToken = bin2hex(random_bytes(32));
                
                // Insert participant
                $stmt = $this->db->prepare("
                    INSERT INTO calendar_event_participants 
                    (event_id, user_email, invited_by_email, invite_token, status)
                    VALUES (?, ?, ?, ?, 'pending')
                ");
                $stmt->execute([$eventId, $email, $organizerEmail, $inviteToken]);
                $participantId = $this->db->lastInsertId();
                
                // Send invitation email with RFC 5545 iCalendar (native Gmail/Outlook support)
                $emailResult = $this->sendInvitationEmail($event, $email, $organizer, $inviteToken, $meetingJoinUrl, $allAttendeeEmails);
                
                if ($emailResult['success']) {
                    $results['success'][] = ['email' => $email, 'participant_id' => $participantId];
                } else {
                    $results['success'][] = [
                        'email' => $email, 
                        'participant_id' => $participantId,
                        'email_sent' => false,
                        'email_error' => $emailResult['error']
                    ];
                }
                
            } catch (\Exception $e) {
                $results['failed'][] = ['email' => $email, 'reason' => $e->getMessage()];
            }
        }
        
        return $results;
    }

    /**
     * Resolve the clickable meeting URL for invites.
     */
    private function resolveMeetingJoinUrl(string $baseUrl, ?string $param, array $event): ?string
    {
        if ($param !== null && $param !== '') {
            if (preg_match('#^https?://#i', $param) || str_contains($param, '/guest/call/')) {
                return $param;
            }
            // Opaque token segment: treat as legacy calendar meeting_token (/meet/...)
            if (preg_match('/^[a-f0-9]{32,128}$/i', $param)) {
                return $baseUrl . '/meet/' . rawurlencode($param);
            }

            return $baseUrl . '/meet/' . rawurlencode($param);
        }
        if (!empty($event['is_meeting']) && !empty($event['meeting_token'])) {
            return $baseUrl . '/meet/' . rawurlencode((string)$event['meeting_token']);
        }

        return null;
    }

    /**
     * Send invitation email with RFC 5545 iCalendar for native Gmail/Outlook support
     * @param string|null $meetingJoinUrl If provided, includes a "Join Meeting" button
     * @param array $allAttendeeEmails All attendee emails for the iCalendar ATTENDEE list
     */
    private function sendInvitationEmail(array $event, string $recipientEmail, array $organizer, string $inviteToken, ?string $meetingJoinUrl = null, array $allAttendeeEmails = []): array
    {
        try {
            // Get SMTP credentials for the organizer
            $smtp = new SmtpService($this->config['smtp'] ?? []);
            
            // For now, use the system SMTP settings
            // In production, you might want to use the organizer's own SMTP
            $smtp->setCredentials(
                $this->config['smtp']['username'] ?? $this->config['mail_from'] ?? 'noreply@flowone.pro',
                $this->config['smtp']['password'] ?? ''
            );
            
            $startDate = new \DateTime($event['start_time']);
            $endDate = new \DateTime($event['end_time']);
            
            $baseUrl = rtrim($this->config['app_url'] ?? 'https://flowone.pro', '/');
            $acceptUrl = "{$baseUrl}/api/calendar/invite/{$inviteToken}/accept";
            $declineUrl = "{$baseUrl}/api/calendar/invite/{$inviteToken}/decline";
            
            $subject = $meetingJoinUrl
                ? "Meeting Invitation: {$event['title']}" 
                : "Calendar Invitation: {$event['title']}";
            
            $bodyHtml = $this->buildInvitationEmailHtml($event, $organizer, $startDate, $endDate, $acceptUrl, $declineUrl, $meetingJoinUrl);
            $bodyText = $this->buildInvitationEmailText($event, $organizer, $startDate, $endDate, $acceptUrl, $declineUrl, $meetingJoinUrl);
            
            // Generate RFC 5545 iCalendar data for native Gmail/Outlook calendar integration
            $attendees = !empty($allAttendeeEmails) ? $allAttendeeEmails : [$recipientEmail];
            $icalData = $this->generateICalInvite($event, $organizer['email'], $attendees);
            
            $result = $smtp->send([
                'to' => [['email' => $recipientEmail]],
                'subject' => $subject,
                'body_html' => $bodyHtml,
                'body_text' => $bodyText,
                'from_name' => $organizer['display_name'] ?? $organizer['email'],
                'ical' => $icalData,
                'attachments' => [
                    [
                        'content' => $icalData,
                        'name' => 'invite.ics',
                        'type' => 'text/calendar; method=REQUEST',
                    ],
                ],
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Generate RFC 5545 iCalendar invite data
     * This produces the VCALENDAR with METHOD:REQUEST that Gmail/Outlook/Apple Mail
     * recognize as a native calendar invitation with Accept/Decline buttons.
     */
    private function generateICalInvite(array $event, string $organizerEmail, array $attendeeEmails): string
    {
        $uid = $event['uid'] ?? (bin2hex(random_bytes(16)) . '@webmail');
        $dtstamp = gmdate('Ymd\THis\Z');
        $created = gmdate('Ymd\THis\Z');
        
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//Webmail//Calendar//EN\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:REQUEST\r\n";
        
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$uid}\r\n";
        $ical .= "DTSTAMP:{$dtstamp}\r\n";
        $ical .= "CREATED:{$created}\r\n";
        $ical .= "SEQUENCE:0\r\n";
        $ical .= "STATUS:CONFIRMED\r\n";
        
        if (!empty($event['all_day'])) {
            $ical .= "DTSTART;VALUE=DATE:" . date('Ymd', strtotime($event['start_time'])) . "\r\n";
            $ical .= "DTEND;VALUE=DATE:" . date('Ymd', strtotime($event['end_time'])) . "\r\n";
        } else {
            $ical .= "DTSTART:" . gmdate('Ymd\THis\Z', strtotime($event['start_time'])) . "\r\n";
            $ical .= "DTEND:" . gmdate('Ymd\THis\Z', strtotime($event['end_time'])) . "\r\n";
        }
        
        $ical .= "SUMMARY:" . $this->escapeICS($event['title'] ?? 'Meeting') . "\r\n";
        
        if (!empty($event['description'])) {
            $ical .= "DESCRIPTION:" . $this->escapeICS($event['description']) . "\r\n";
        }
        if (!empty($event['location'])) {
            $ical .= "LOCATION:" . $this->escapeICS($event['location']) . "\r\n";
        }
        
        $ical .= "ORGANIZER;CN=" . $this->escapeICS($organizerEmail) . ":MAILTO:{$organizerEmail}\r\n";
        
        foreach ($attendeeEmails as $attendee) {
            $attendee = trim(strtolower($attendee));
            if (filter_var($attendee, FILTER_VALIDATE_EMAIL)) {
                $ical .= "ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:MAILTO:{$attendee}\r\n";
            }
        }
        
        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";
        
        return $ical;
    }

    private function escapeICS(string $str): string
    {
        $str = str_replace('\\', '\\\\', $str);
        $str = str_replace("\n", '\\n', $str);
        $str = str_replace(',', '\\,', $str);
        $str = str_replace(';', '\\;', $str);
        return $str;
    }

    /**
     * Build an RFC 5545 METHOD:REPLY VCALENDAR for an incoming invite.
     * Used to RSVP (accept/decline/tentative) to invitations received by
     * email from any provider (Google, Microsoft, Apple, ...) so the
     * organizer's calendar updates automatically.
     *
     * $event MUST contain:
     *   - uid             (string)  iCalendar UID from the original VEVENT
     *   - organizer_email (string)  organizer mailto address
     *   - dtstart_raw     (string)  raw DTSTART value from the original ICS
     *   - dtend_raw       (string)  raw DTEND value from the original ICS
     * Optional:
     *   - sequence        (int)     mirrors the original SEQUENCE (default 0)
     *   - summary         (string)
     *   - all_day         (bool)
     *   - dtstart_params  (string)  raw `;TZID=...` etc. from DTSTART
     *   - dtend_params    (string)  raw `;TZID=...` etc. from DTEND
     *
     * $response: 'accepted' | 'declined' | 'tentative'
     */
    public static function buildIcalReply(array $event, string $attendeeEmail, string $response, ?string $attendeeName = null, ?string $comment = null): string
    {
        $partstatMap = [
            'accepted'  => 'ACCEPTED',
            'declined'  => 'DECLINED',
            'tentative' => 'TENTATIVE',
        ];
        $partstat = $partstatMap[$response] ?? 'NEEDS-ACTION';

        $uid = (string)($event['uid'] ?? (bin2hex(random_bytes(16)) . '@webmail'));
        $sequence = (int)($event['sequence'] ?? 0);
        $dtstamp = gmdate('Ymd\THis\Z');
        $organizerEmail = (string)($event['organizer_email'] ?? '');

        $escape = static function (string $str): string {
            $str = str_replace('\\', '\\\\', $str);
            $str = str_replace("\n", '\\n', $str);
            $str = str_replace(',', '\\,', $str);
            $str = str_replace(';', '\\;', $str);
            return $str;
        };

        // Render DTSTART/DTEND. Prefer UTC (no VTIMEZONE needed). If the source
        // TZID can't be resolved, fall back to embedding the original
        // VTIMEZONE block(s) so the REPLY is still self-contained per RFC 5545.
        $renderedStart = self::renderReplyDateTime('DTSTART', $event, 'dtstart');
        $renderedEnd   = self::renderReplyDateTime('DTEND',   $event, 'dtend');
        $dtstartLine   = $renderedStart['line'];
        $dtendLine     = $renderedEnd['line'];
        $needsVtimezone = $renderedStart['needs_vtimezone'] || $renderedEnd['needs_vtimezone'];

        $ical  = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//FlowOne//Webmail//EN\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:REPLY\r\n";

        if ($needsVtimezone && !empty($event['vtimezones']) && is_array($event['vtimezones'])) {
            foreach ($event['vtimezones'] as $vtz) {
                $vtz = rtrim((string)$vtz);
                if ($vtz !== '') {
                    $ical .= $vtz . "\r\n";
                }
            }
        }

        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$uid}\r\n";
        $ical .= "DTSTAMP:{$dtstamp}\r\n";
        $ical .= "SEQUENCE:{$sequence}\r\n";

        if ($dtstartLine !== '') {
            $ical .= $dtstartLine;
        }
        if ($dtendLine !== '') {
            $ical .= $dtendLine;
        }

        if (!empty($event['summary'])) {
            $ical .= "SUMMARY:" . $escape((string)$event['summary']) . "\r\n";
        }

        if ($organizerEmail !== '') {
            $ical .= "ORGANIZER:MAILTO:{$organizerEmail}\r\n";
        }

        $attendeeCn = $attendeeName ? ';CN=' . $escape($attendeeName) : '';
        $ical .= "ATTENDEE{$attendeeCn};PARTSTAT={$partstat}:MAILTO:{$attendeeEmail}\r\n";

        if ($comment !== null && $comment !== '') {
            $ical .= "COMMENT:" . $escape($comment) . "\r\n";
        }

        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";

        return $ical;
    }

    /**
     * Build invitation email HTML
     */
    private function buildInvitationEmailHtml(array $event, array $organizer, \DateTime $start, \DateTime $end, string $acceptUrl, string $declineUrl, ?string $meetingJoinUrl = null): string
    {
        $organizerName = $organizer['display_name'] ?? $organizer['email'];
        $location = $event['location'] ? "<p class=\"event-detail\"><strong>Where:</strong> {$event['location']}</p>" : '';
        $description = $event['description'] ? "<p>{$event['description']}</p>" : '';
        
        $dateFormat = $event['all_day'] 
            ? $start->format('l, F j, Y') 
            : $start->format('l, F j, Y \a\t g:i A') . ' - ' . $end->format('g:i A');
        
        $isMeeting = !empty($meetingJoinUrl);
        $inviteType = $isMeeting ? 'a meeting' : 'an event';
        
        $meetingSection = '';
        if ($isMeeting) {
            $meetingSection = <<<MEETING
        <div style="margin: 20px 0; padding: 16px; background: #eff6ff; border-radius: 8px; text-align: center;">
            <p style="margin: 0 0 12px 0; color: #1e40af; font-weight: 600;">This is an online meeting</p>
            <a href="{$meetingJoinUrl}" style="display: inline-block; padding: 14px 32px; background: #3b82f6; color: white; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 16px;">Join Meeting</a>
            <p style="margin: 12px 0 0 0; font-size: 12px; color: #6b7280;">Or copy this link: {$meetingJoinUrl}</p>
        </div>
MEETING;
        }
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .event-card { background: #f8f9fa; border-radius: 12px; padding: 24px; margin: 20px 0; border-left: 4px solid #3b82f6; }
        .event-title { font-size: 20px; font-weight: 600; margin-bottom: 12px; color: #1a1a1a; }
        .event-detail { margin: 8px 0; color: #555; }
        .buttons { margin: 24px 0; }
        .btn { display: inline-block; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 500; margin-right: 12px; }
        .btn-accept { background: #22c55e; color: white; }
        .btn-decline { background: #ef4444; color: white; }
        .footer { color: #888; font-size: 12px; margin-top: 24px; padding-top: 16px; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="container">
        <p><strong>{$organizerName}</strong> has invited you to {$inviteType}:</p>
        
        <div class="event-card">
            <div class="event-title">{$event['title']}</div>
            <p class="event-detail"><strong>When:</strong> {$dateFormat}</p>
            {$location}
            {$description}
        </div>
        
        {$meetingSection}
        
        <div class="buttons">
            <a href="{$acceptUrl}" class="btn btn-accept">Accept</a>
            <a href="{$declineUrl}" class="btn btn-decline">Decline</a>
        </div>
        
        <div class="footer">
            <p>You can also respond by clicking the links above.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Build invitation email plain text
     */
    private function buildInvitationEmailText(array $event, array $organizer, \DateTime $start, \DateTime $end, string $acceptUrl, string $declineUrl, ?string $meetingJoinUrl = null): string
    {
        $organizerName = $organizer['display_name'] ?? $organizer['email'];
        $dateFormat = $event['all_day'] 
            ? $start->format('l, F j, Y') 
            : $start->format('l, F j, Y \a\t g:i A') . ' - ' . $end->format('g:i A');
        
        $location = $event['location'] ? "\nWhere: {$event['location']}" : '';
        $description = $event['description'] ? "\n\n{$event['description']}" : '';
        $isMeeting = !empty($meetingJoinUrl);
        $inviteType = $isMeeting ? 'a meeting' : 'an event';
        
        $meetingLink = $isMeeting ? "\n\nJoin Meeting: {$meetingJoinUrl}" : '';
        
        return <<<TEXT
{$organizerName} has invited you to {$inviteType}:

{$event['title']}

When: {$dateFormat}{$location}{$description}{$meetingLink}

To respond:
- Accept: {$acceptUrl}
- Decline: {$declineUrl}
TEXT;
    }

    /**
     * Respond to an invitation
     */
    public function respondToInvitation(string $inviteToken, string $response, ?string $message = null): array
    {
        if (!in_array($response, ['accepted', 'declined', 'tentative'])) {
            return ['success' => false, 'error' => 'Invalid response'];
        }
        
        // Get participant and event details (including meeting columns if they exist)
        $hasMeetingCols = $this->columnExists('calendar_events', 'is_meeting');
        $meetingSelect = $hasMeetingCols ? ', e.is_meeting, e.meeting_token, e.meeting_conversation_id' : '';
        
        $stmt = $this->db->prepare("
            SELECT p.*, e.title, e.start_time, e.end_time, e.calendar_id, e.description, e.location, e.all_day,
                   p.invited_by_email as organizer_email
                   {$meetingSelect}
            FROM calendar_event_participants p
            JOIN calendar_events e ON p.event_id = e.id
            WHERE p.invite_token = ?
        ");
        $stmt->execute([$inviteToken]);
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$participant) {
            return ['success' => false, 'error' => 'Invitation not found'];
        }
        
        // Update participant status
        $stmt = $this->db->prepare("
            UPDATE calendar_event_participants 
            SET status = ?, responded_at = NOW(), response_message = ?
            WHERE invite_token = ?
        ");
        $stmt->execute([$response, $message, $inviteToken]);
        
        // Accepted invitations stay linked to the organizer's event.
        // Creating a second local copy causes duplicate entries and drift.
        if ($response === 'accepted') {
            // If this is a meeting, auto-add the participant to the meeting chat conversation
            if (!empty($participant['is_meeting']) && !empty($participant['meeting_conversation_id'])) {
                $this->addParticipantToMeetingChat(
                    (int)$participant['meeting_conversation_id'],
                    $participant['user_email'],
                    $participant['organizer_email']
                );
            }
        }
        
        // Send notification to organizer
        if ($participant['organizer_email']) {
            $this->notifyOrganizer($participant, $response);
        }
        
        return [
            'success' => true,
            'event_title' => $participant['title'],
            'response' => $response
        ];
    }
    
    /**
     * Auto-add a participant to the meeting chat conversation when they accept
     */
    private function addParticipantToMeetingChat(int $conversationId, string $participantEmail, string $organizerEmail): void
    {
        try {
            $chatService = new \Webmail\Addons\Chat\Services\ChatService($this->config);
            $chatService->addParticipantByEmail($conversationId, $participantEmail, $organizerEmail);
        } catch (\Exception $e) {
            // Log but don't fail the invitation response
            error_log("CalendarInviteService: Failed to add participant to meeting chat: " . $e->getMessage());
        }
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
     * Create a copy of the event for an accepted participant
     * Uses user_email pattern like other tables
     */
    private function createEventForParticipant(array $participant): void
    {
        $participantEmail = $participant['user_email'];
        
        // Check if participant has a default calendar (by email)
        $stmt = $this->db->prepare("
            SELECT id FROM calendars 
            WHERE user_email = ? AND is_default = 1
            LIMIT 1
        ");
        $stmt->execute([$participantEmail]);
        $calendar = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$calendar) {
            // Check if they have any calendar
            $stmt = $this->db->prepare("
                SELECT id FROM calendars 
                WHERE user_email = ?
                LIMIT 1
            ");
            $stmt->execute([$participantEmail]);
            $calendar = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$calendar) {
            // Create a default calendar for the participant
            $stmt = $this->db->prepare("
                INSERT INTO calendars (user_email, name, is_default) VALUES (?, 'My Calendar', 1)
            ");
            $stmt->execute([$participantEmail]);
            $calendarId = $this->db->lastInsertId();
        } else {
            $calendarId = $calendar['id'];
        }
        
        // Create event for participant using data from the invitation
        // Generate a unique UID for the new event
        $uid = uniqid('evt_') . '_' . bin2hex(random_bytes(8));
        
        $stmt = $this->db->prepare("
            INSERT INTO calendar_events 
            (calendar_id, uid, title, description, location, start_time, end_time, all_day)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $calendarId,
            $uid,
            $participant['title'],
            $participant['description'] ?? null,
            $participant['location'] ?? null,
            $participant['start_time'],
            $participant['end_time'],
            $participant['all_day'] ?? 0
        ]);
    }

    /**
     * Notify organizer of response with RFC 5545 iCalendar REPLY
     */
    private function notifyOrganizer(array $participant, string $response): void
    {
        try {
            $smtp = new SmtpService($this->config['smtp'] ?? []);
            $smtp->setCredentials(
                $this->config['smtp']['username'] ?? $this->config['mail_from'] ?? 'noreply@flowone.pro',
                $this->config['smtp']['password'] ?? ''
            );
            
            $responseText = ucfirst($response);
            $subject = "Event Response: {$participant['user_email']} {$responseText} - {$participant['title']}";
            
            $message = $participant['response_message'] 
                ? "<p>Message: {$participant['response_message']}</p>" 
                : '';
            
            $bodyHtml = <<<HTML
<p><strong>{$participant['user_email']}</strong> has {$response} your event invitation:</p>
<p><strong>{$participant['title']}</strong></p>
{$message}
HTML;
            
            // Generate iCalendar REPLY so the organizer's calendar updates automatically
            $icalReply = $this->generateICalReply($participant, $response);
            
            $sendParams = [
                'to' => [['email' => $participant['organizer_email']]],
                'subject' => $subject,
                'body_html' => $bodyHtml,
                'body_text' => strip_tags($bodyHtml),
            ];
            
            if ($icalReply) {
                $sendParams['ical'] = $icalReply;
                $sendParams['attachments'] = [
                    [
                        'content' => $icalReply,
                        'name' => 'response.ics',
                        'type' => 'text/calendar; method=REPLY',
                    ],
                ];
            }
            
            $smtp->send($sendParams);
        } catch (\Exception $e) {
            error_log("Failed to notify organizer: " . $e->getMessage());
        }
    }

    /**
     * Generate iCalendar REPLY for organizer notification
     */
    private function generateICalReply(array $participant, string $response): ?string
    {
        $partstatMap = [
            'accepted'  => 'ACCEPTED',
            'declined'  => 'DECLINED',
            'tentative' => 'TENTATIVE',
        ];
        $partstat = $partstatMap[$response] ?? 'NEEDS-ACTION';
        
        $uid = $participant['uid'] ?? (bin2hex(random_bytes(16)) . '@webmail');
        $dtstamp = gmdate('Ymd\THis\Z');
        $attendeeEmail = $participant['user_email'];
        $organizerEmail = $participant['organizer_email'] ?? $participant['invited_by_email'];
        
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//Webmail//Calendar//EN\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:REPLY\r\n";
        
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$uid}\r\n";
        $ical .= "DTSTAMP:{$dtstamp}\r\n";
        $ical .= "SEQUENCE:0\r\n";
        
        if (!empty($participant['all_day'])) {
            $ical .= "DTSTART;VALUE=DATE:" . date('Ymd', strtotime($participant['start_time'])) . "\r\n";
            $ical .= "DTEND;VALUE=DATE:" . date('Ymd', strtotime($participant['end_time'])) . "\r\n";
        } else {
            $ical .= "DTSTART:" . gmdate('Ymd\THis\Z', strtotime($participant['start_time'])) . "\r\n";
            $ical .= "DTEND:" . gmdate('Ymd\THis\Z', strtotime($participant['end_time'])) . "\r\n";
        }
        
        $ical .= "SUMMARY:" . $this->escapeICS($participant['title'] ?? '') . "\r\n";
        $ical .= "ORGANIZER:MAILTO:{$organizerEmail}\r\n";
        $ical .= "ATTENDEE;PARTSTAT={$partstat}:MAILTO:{$attendeeEmail}\r\n";
        
        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";
        
        return $ical;
    }

    /**
     * Import participants from an incoming invite (no emails sent)
     * Used when adding a received calendar invite to your own calendar.
     */
    public function importParticipants(int $eventId, array $emails, string $organizerEmail): array
    {
        $results = ['success' => [], 'failed' => []];
        
        $stmt = $this->db->prepare("SELECT id FROM calendar_events WHERE id = ?");
        $stmt->execute([$eventId]);
        if (!$stmt->fetch()) {
            return ['error' => 'Event not found'];
        }
        
        foreach ($emails as $email) {
            $email = trim(strtolower($email));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $results['failed'][] = ['email' => $email, 'reason' => 'Invalid email'];
                continue;
            }
            
            try {
                $stmt = $this->db->prepare("
                    SELECT id FROM calendar_event_participants 
                    WHERE event_id = ? AND user_email = ?
                ");
                $stmt->execute([$eventId, $email]);
                if ($stmt->fetch()) {
                    $results['failed'][] = ['email' => $email, 'reason' => 'Already added'];
                    continue;
                }
                
                $inviteToken = bin2hex(random_bytes(32));
                
                $stmt = $this->db->prepare("
                    INSERT INTO calendar_event_participants 
                    (event_id, user_email, invited_by_email, invite_token, status)
                    VALUES (?, ?, ?, ?, 'accepted')
                ");
                $stmt->execute([$eventId, $email, $organizerEmail, $inviteToken]);
                $participantId = $this->db->lastInsertId();
                
                $results['success'][] = ['email' => $email, 'participant_id' => $participantId];
                
            } catch (\Exception $e) {
                $results['failed'][] = ['email' => $email, 'reason' => $e->getMessage()];
            }
        }
        
        return $results;
    }

    /**
     * Get participants for an event
     */
    public function getParticipants(int $eventId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, user_email, status, invited_at, responded_at, response_message
            FROM calendar_event_participants
            WHERE event_id = ?
            ORDER BY invited_at ASC
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get pending invitations for a user (by email)
     */
    public function getPendingInvitations(string $email): array
    {
        $stmt = $this->db->prepare("
            SELECT p.*, e.title, e.description, e.location, e.start_time, e.end_time, e.all_day,
                   p.invited_by_email as organizer_email
            FROM calendar_event_participants p
            JOIN calendar_events e ON p.event_id = e.id
            WHERE p.user_email = ? AND p.status = 'pending'
            ORDER BY e.start_time ASC
        ");
        $stmt->execute([strtolower($email)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Remove a participant from an event
     */
    public function removeParticipant(int $eventId, string $email): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM calendar_event_participants 
            WHERE event_id = ? AND user_email = ?
        ");
        $stmt->execute([$eventId, strtolower($email)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Render a single DTSTART/DTEND line for an iCalendar REPLY.
     *
     * Returns ['line' => string, 'needs_vtimezone' => bool].
     * Strategy:
     *   1. UTC values (`...Z`) and VALUE=DATE all-day are passed through unchanged.
     *   2. Floating local time with a resolvable TZID is converted to UTC
     *      (no VTIMEZONE required - the universally-compatible path).
     *   3. If the TZID can't be resolved, keep the original line so the
     *      caller can ship the source VTIMEZONE alongside it.
     *   4. Last resort: fall back to dtstart/dtend (already-formatted) values.
     */
    private static function renderReplyDateTime(string $prop, array $event, string $key): array
    {
        $rawKey    = $key . '_raw';
        $paramsKey = $key . '_params';

        if (!empty($event[$rawKey])) {
            $rawValue = (string)$event[$rawKey];
            $params   = (string)($event[$paramsKey] ?? '');

            $isAllDay = stripos($params, 'VALUE=DATE') !== false
                || (strlen(trim($rawValue)) === 8 && ctype_digit(trim($rawValue)));

            if ($isAllDay) {
                $valueParam = stripos($params, 'VALUE=DATE') !== false ? '' : ';VALUE=DATE';
                return [
                    'line' => "{$prop}{$params}{$valueParam}:{$rawValue}\r\n",
                    'needs_vtimezone' => false,
                ];
            }

            // Already in UTC: pass through.
            if (substr($rawValue, -1) === 'Z') {
                $cleanParams = self::stripTzidParam($params);
                return [
                    'line' => "{$prop}{$cleanParams}:{$rawValue}\r\n",
                    'needs_vtimezone' => false,
                ];
            }

            // Floating local time with a TZID: try to convert to UTC.
            if (preg_match('/TZID=([^;:]+)/i', $params, $tzMatch)) {
                $tz = self::resolveTimezone(trim($tzMatch[1]));
                if ($tz !== null) {
                    try {
                        $dt = \DateTime::createFromFormat('Ymd\THis', $rawValue, $tz);
                        if ($dt !== false) {
                            $dt->setTimezone(new \DateTimeZone('UTC'));
                            $cleanParams = self::stripTzidParam($params);
                            return [
                                'line' => "{$prop}{$cleanParams}:" . $dt->format('Ymd\THis\Z') . "\r\n",
                                'needs_vtimezone' => false,
                            ];
                        }
                    } catch (\Exception $e) {
                        // Fall through to embedding VTIMEZONE.
                    }
                }

                // Couldn't resolve - keep the original TZID line and ask
                // the caller to embed the source VTIMEZONE.
                return [
                    'line' => "{$prop}{$params}:{$rawValue}\r\n",
                    'needs_vtimezone' => true,
                ];
            }

            // No TZID and no Z: floating time. Pass through; clients will
            // interpret it in the user's local zone, which is acceptable.
            return [
                'line' => "{$prop}{$params}:{$rawValue}\r\n",
                'needs_vtimezone' => false,
            ];
        }

        // Fallbacks based on the parsed (already-localized) value.
        if (!empty($event['all_day']) && !empty($event[$key])) {
            return [
                'line' => "{$prop};VALUE=DATE:" . date('Ymd', strtotime((string)$event[$key])) . "\r\n",
                'needs_vtimezone' => false,
            ];
        }
        if (!empty($event[$key])) {
            return [
                'line' => "{$prop}:" . gmdate('Ymd\THis\Z', strtotime((string)$event[$key])) . "\r\n",
                'needs_vtimezone' => false,
            ];
        }

        return ['line' => '', 'needs_vtimezone' => false];
    }

    /**
     * Remove `;TZID=...` from an iCalendar property params string while
     * preserving any other params (e.g. VALUE=DATE-TIME).
     */
    private static function stripTzidParam(string $params): string
    {
        if ($params === '') {
            return '';
        }
        $cleaned = preg_replace('/;TZID=[^;:]*/i', '', $params);
        return $cleaned ?? $params;
    }

    /**
     * Resolve an iCalendar TZID to a PHP DateTimeZone, accepting:
     *   1. IANA names      ("Europe/Budapest")
     *   2. Windows names   ("Central Europe Standard Time") via the intl
     *      extension when available, or a bundled fallback map.
     * Returns null when the zone can't be identified.
     */
    private static function resolveTimezone(string $tzid): ?\DateTimeZone
    {
        $tzid = trim($tzid, " \t\"");
        if ($tzid === '') {
            return null;
        }

        try {
            return new \DateTimeZone($tzid);
        } catch (\Exception $e) {
            // Not a recognized PHP zone; try Windows mapping below.
        }

        if (class_exists('\\IntlTimeZone')) {
            try {
                $iana = \IntlTimeZone::getIDForWindowsID($tzid);
                if (is_string($iana) && $iana !== '') {
                    try {
                        return new \DateTimeZone($iana);
                    } catch (\Exception $e) {
                        // Fall through to bundled map.
                    }
                }
            } catch (\Throwable $e) {
                // Some intl builds throw on unknown ids.
            }
        }

        $iana = self::windowsToIanaTimezone($tzid);
        if ($iana !== null) {
            try {
                return new \DateTimeZone($iana);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Fallback Windows-name -> IANA map for servers without the intl
     * extension. Covers the common zones used by Outlook/Teams invites.
     * Source: CLDR windowsZones.xml (truncated to common entries).
     */
    private static function windowsToIanaTimezone(string $windowsId): ?string
    {
        static $map = null;
        if ($map === null) {
            $map = [
                'utc'                                 => 'Etc/UTC',
                'gmt standard time'                   => 'Europe/London',
                'greenwich standard time'             => 'Atlantic/Reykjavik',
                'w. europe standard time'             => 'Europe/Berlin',
                'central europe standard time'        => 'Europe/Budapest',
                'romance standard time'               => 'Europe/Paris',
                'central european standard time'      => 'Europe/Warsaw',
                'e. europe standard time'             => 'Europe/Bucharest',
                'gtb standard time'                   => 'Europe/Athens',
                'fle standard time'                   => 'Europe/Kiev',
                'turkey standard time'                => 'Europe/Istanbul',
                'russian standard time'               => 'Europe/Moscow',
                'belarus standard time'               => 'Europe/Minsk',
                'kaliningrad standard time'           => 'Europe/Kaliningrad',
                'eastern standard time'               => 'America/New_York',
                'central standard time'               => 'America/Chicago',
                'mountain standard time'              => 'America/Denver',
                'pacific standard time'               => 'America/Los_Angeles',
                'us mountain standard time'           => 'America/Phoenix',
                'alaskan standard time'               => 'America/Anchorage',
                'hawaiian standard time'              => 'Pacific/Honolulu',
                'atlantic standard time'              => 'America/Halifax',
                'canada central standard time'        => 'America/Regina',
                'central standard time (mexico)'      => 'America/Mexico_City',
                'mountain standard time (mexico)'     => 'America/Chihuahua',
                'pacific standard time (mexico)'      => 'America/Tijuana',
                'sa eastern standard time'            => 'America/Cayenne',
                'sa pacific standard time'            => 'America/Bogota',
                'sa western standard time'            => 'America/La_Paz',
                'argentina standard time'             => 'America/Buenos_Aires',
                'e. south america standard time'      => 'America/Sao_Paulo',
                'china standard time'                 => 'Asia/Shanghai',
                'tokyo standard time'                 => 'Asia/Tokyo',
                'korea standard time'                 => 'Asia/Seoul',
                'singapore standard time'             => 'Asia/Singapore',
                'taipei standard time'                => 'Asia/Taipei',
                'india standard time'                 => 'Asia/Kolkata',
                'sri lanka standard time'             => 'Asia/Colombo',
                'pakistan standard time'              => 'Asia/Karachi',
                'arabian standard time'               => 'Asia/Dubai',
                'arabic standard time'                => 'Asia/Baghdad',
                'arab standard time'                  => 'Asia/Riyadh',
                'iran standard time'                  => 'Asia/Tehran',
                'israel standard time'                => 'Asia/Jerusalem',
                'jordan standard time'                => 'Asia/Amman',
                'egypt standard time'                 => 'Africa/Cairo',
                'south africa standard time'          => 'Africa/Johannesburg',
                'morocco standard time'               => 'Africa/Casablanca',
                'aus eastern standard time'           => 'Australia/Sydney',
                'cen. australia standard time'        => 'Australia/Adelaide',
                'w. australia standard time'          => 'Australia/Perth',
                'aus central standard time'           => 'Australia/Darwin',
                'tasmania standard time'              => 'Australia/Hobart',
                'new zealand standard time'           => 'Pacific/Auckland',
                'azores standard time'                => 'Atlantic/Azores',
                'cape verde standard time'            => 'Atlantic/Cape_Verde',
            ];
        }

        $key = strtolower(trim($windowsId, " \t\""));
        return $map[$key] ?? null;
    }
}

