<?php

namespace Webmail\Addons\Reactions\Services;

/**
 * ReactionService - Handles email reactions (like Outlook)
 * 
 * Reactions are stored in database and visible to all email participants.
 * When reacting to external emails, a notification email is sent.
 */
class ReactionService
{
    private \PDO $db;
    private array $config;
    private static bool $tableChecked = false;
    
    // Available reaction emojis (Outlook-style)
    public const EMOJIS = [
        'thumbsup' => '👍',
        'heart' => '❤️',
        'party' => '🎉',
        'laugh' => '😂',
        'surprised' => '😲',
        'worried' => '😟',
    ];
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        try {
            $this->db = \Webmail\Core\Database::getConnection($config);
            
            \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTableExists());
        } catch (\PDOException $e) {
            error_log("ReactionService DB connection error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Auto-create the reactions table if it doesn't exist
     */
    private function ensureTableExists(): void
    {
        if (self::$tableChecked) {
            return;
        }
        
        try {
            $sql = "CREATE TABLE IF NOT EXISTS webmail_reactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id VARCHAR(500) NOT NULL,
                reactor_email VARCHAR(255) NOT NULL,
                reactor_name VARCHAR(255) DEFAULT NULL,
                emoji VARCHAR(20) NOT NULL,
                participants TEXT NOT NULL,
                subject VARCHAR(500) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_reaction (message_id(191), reactor_email(100), emoji(20)),
                INDEX idx_message_id (message_id(191)),
                INDEX idx_reactor (reactor_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $this->db->exec($sql);
            self::$tableChecked = true;
        } catch (\PDOException $e) {
            error_log("ReactionService table creation error: " . $e->getMessage());
            // Table might already exist with different structure, mark as checked anyway
            self::$tableChecked = true;
        }
    }
    
    /**
     * Add a reaction to an email
     * Returns the reaction data or null if toggled off
     */
    public function addReaction(
        string $messageId,
        string $reactorEmail,
        string $reactorName,
        string $emoji,
        array $participants,
        ?string $subject = null
    ): ?array {
        // Validate emoji
        if (!isset(self::EMOJIS[$emoji])) {
            throw new \InvalidArgumentException("Invalid emoji: $emoji");
        }
        
        // Check if reaction already exists (toggle off)
        $existing = $this->getReaction($messageId, $reactorEmail, $emoji);
        if ($existing) {
            $this->removeReaction($existing['id']);
            return null; // Toggled off
        }
        
        // Add new reaction
        $stmt = $this->db->prepare('
            INSERT INTO webmail_reactions 
            (message_id, reactor_email, reactor_name, emoji, participants, subject)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $messageId,
            strtolower($reactorEmail),
            $reactorName,
            $emoji,
            json_encode(array_map('strtolower', $participants)),
            $subject
        ]);
        
        return [
            'id' => $this->db->lastInsertId(),
            'message_id' => $messageId,
            'reactor_email' => $reactorEmail,
            'reactor_name' => $reactorName,
            'emoji' => $emoji,
            'emoji_char' => self::EMOJIS[$emoji],
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }
    
    /**
     * Get a specific reaction
     */
    public function getReaction(string $messageId, string $reactorEmail, string $emoji): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM webmail_reactions 
            WHERE message_id = ? AND reactor_email = ? AND emoji = ?
        ');
        $stmt->execute([$messageId, strtolower($reactorEmail), $emoji]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Remove a reaction by ID
     */
    public function removeReaction(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM webmail_reactions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Remove a reaction by details (for current user)
     */
    public function removeReactionByDetails(string $messageId, string $reactorEmail, string $emoji): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM webmail_reactions 
            WHERE message_id = ? AND reactor_email = ? AND emoji = ?
        ');
        $stmt->execute([$messageId, strtolower($reactorEmail), $emoji]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get all reactions for a message (visible to a specific user)
     */
    public function getReactionsForMessage(string $messageId, string $viewerEmail): array
    {
        $viewerEmail = strtolower($viewerEmail);
        
        $stmt = $this->db->prepare('
            SELECT * FROM webmail_reactions 
            WHERE message_id = ?
            ORDER BY created_at ASC
        ');
        $stmt->execute([$messageId]);
        $reactions = $stmt->fetchAll();
        
        // Filter to only show reactions where viewer is a participant
        $visible = [];
        foreach ($reactions as $reaction) {
            $participants = json_decode($reaction['participants'], true) ?: [];
            if (in_array($viewerEmail, $participants) || $reaction['reactor_email'] === $viewerEmail) {
                $reaction['emoji_char'] = self::EMOJIS[$reaction['emoji']] ?? $reaction['emoji'];
                $visible[] = $reaction;
            }
        }
        
        return $visible;
    }
    
    /**
     * Get reactions for multiple messages (batch for email list)
     */
    public function getReactionsForMessages(array $messageIds, string $viewerEmail): array
    {
        if (empty($messageIds)) {
            return [];
        }
        
        $viewerEmail = strtolower($viewerEmail);
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        
        $stmt = $this->db->prepare("
            SELECT * FROM webmail_reactions 
            WHERE message_id IN ($placeholders)
            ORDER BY created_at ASC
        ");
        $stmt->execute($messageIds);
        $allReactions = $stmt->fetchAll();
        
        // Group by message_id and filter by participant visibility
        $grouped = [];
        foreach ($allReactions as $reaction) {
            $participants = json_decode($reaction['participants'], true) ?: [];
            if (in_array($viewerEmail, $participants) || $reaction['reactor_email'] === $viewerEmail) {
                $msgId = $reaction['message_id'];
                if (!isset($grouped[$msgId])) {
                    $grouped[$msgId] = [];
                }
                $reaction['emoji_char'] = self::EMOJIS[$reaction['emoji']] ?? $reaction['emoji'];
                $grouped[$msgId][] = $reaction;
            }
        }
        
        return $grouped;
    }
    
    /**
     * Get reaction summary for a message (grouped by emoji with counts)
     */
    public function getReactionSummary(string $messageId, string $viewerEmail): array
    {
        $reactions = $this->getReactionsForMessage($messageId, $viewerEmail);
        
        $summary = [];
        foreach ($reactions as $reaction) {
            $emoji = $reaction['emoji'];
            if (!isset($summary[$emoji])) {
                $summary[$emoji] = [
                    'emoji' => $emoji,
                    'emoji_char' => self::EMOJIS[$emoji] ?? $emoji,
                    'count' => 0,
                    'reactors' => [],
                    'user_reacted' => false,
                ];
            }
            $summary[$emoji]['count']++;
            $summary[$emoji]['reactors'][] = [
                'email' => $reaction['reactor_email'],
                'name' => $reaction['reactor_name'],
            ];
            if ($reaction['reactor_email'] === strtolower($viewerEmail)) {
                $summary[$emoji]['user_reacted'] = true;
            }
        }
        
        return array_values($summary);
    }
    
    /**
     * Get reaction summaries for multiple messages (batch)
     */
    public function getReactionSummaries(array $messageIds, string $viewerEmail): array
    {
        $grouped = $this->getReactionsForMessages($messageIds, $viewerEmail);
        
        $summaries = [];
        foreach ($grouped as $msgId => $reactions) {
            $summary = [];
            foreach ($reactions as $reaction) {
                $emoji = $reaction['emoji'];
                if (!isset($summary[$emoji])) {
                    $summary[$emoji] = [
                        'emoji' => $emoji,
                        'emoji_char' => self::EMOJIS[$emoji] ?? $emoji,
                        'count' => 0,
                        'reactors' => [],
                        'user_reacted' => false,
                    ];
                }
                $summary[$emoji]['count']++;
                $summary[$emoji]['reactors'][] = [
                    'email' => $reaction['reactor_email'],
                    'name' => $reaction['reactor_name'],
                ];
                if ($reaction['reactor_email'] === strtolower($viewerEmail)) {
                    $summary[$emoji]['user_reacted'] = true;
                }
            }
            $summaries[$msgId] = array_values($summary);
        }
        
        return $summaries;
    }
    
    // Color presets for reaction emails (user can have accent color preference)
    private const COLOR_PRESETS = [
        'blue' => ['primary' => '#1565c0', 'secondary' => '#1976d2', 'bg' => '#e3f2fd', 'bgLight' => '#bbdefb', 'border' => '#64b5f6'],
        'purple' => ['primary' => '#7b1fa2', 'secondary' => '#9c27b0', 'bg' => '#f3e5f5', 'bgLight' => '#e1bee7', 'border' => '#ba68c8'],
        'teal' => ['primary' => '#00796b', 'secondary' => '#009688', 'bg' => '#e0f2f1', 'bgLight' => '#b2dfdb', 'border' => '#4db6ac'],
        'orange' => ['primary' => '#e65100', 'secondary' => '#ff9800', 'bg' => '#fff3e0', 'bgLight' => '#ffe0b2', 'border' => '#ffb74d'],
        'pink' => ['primary' => '#c2185b', 'secondary' => '#e91e63', 'bg' => '#fce4ec', 'bgLight' => '#f8bbd9', 'border' => '#f06292'],
        'green' => ['primary' => '#2e7d32', 'secondary' => '#43a047', 'bg' => '#e8f5e9', 'bgLight' => '#c8e6c9', 'border' => '#81c784'],
        'red' => ['primary' => '#c62828', 'secondary' => '#e53935', 'bg' => '#ffebee', 'bgLight' => '#ffcdd2', 'border' => '#ef5350'],
        'indigo' => ['primary' => '#283593', 'secondary' => '#3f51b5', 'bg' => '#e8eaf6', 'bgLight' => '#c5cae9', 'border' => '#7986cb'],
    ];

    /**
     * Generate notification email HTML for external recipients
     * Uses Re: subject for proper threading in Gmail/Outlook
     * Supports dynamic accent colors based on user preference
     */
    public function generateNotificationEmail(
        string $reactorName,
        string $emoji,
        string $originalSubject,
        ?string $originalSnippet = null,
        ?string $accentColor = null
    ): array {
        $emojiChar = self::EMOJIS[$emoji] ?? $emoji;
        
        // Use "Re:" prefix for proper threading in Gmail/Outlook
        // Remove existing Re:/Fwd: prefixes first to avoid "Re: Re: Re:"
        $cleanSubject = preg_replace('/^(Re|Fwd?|Fw):\s*/i', '', $originalSubject);
        $subject = "Re: $cleanSubject";
        
        // Get color scheme based on accent or default to blue
        $colorKey = $accentColor && isset(self::COLOR_PRESETS[$accentColor]) ? $accentColor : 'blue';
        $colors = self::COLOR_PRESETS[$colorKey];
        
        // Clean, centered card design with border and subtle background
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 32px 20px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" width="400" cellspacing="0" cellpadding="0" border="0" style="background: linear-gradient(135deg, ' . $colors['bg'] . ' 0%, ' . $colors['bgLight'] . ' 100%); border: 2px solid ' . $colors['border'] . '; border-radius: 16px; overflow: hidden;">
                    <tr>
                        <td style="padding: 32px; text-align: center;">
                            <div style="font-size: 48px; margin-bottom: 16px;">' . $emojiChar . '</div>
                            <div style="color: ' . $colors['primary'] . '; font-size: 18px; font-weight: 600; margin-bottom: 4px;">' . htmlspecialchars($reactorName) . '</div>
                            <div style="color: ' . $colors['secondary'] . '; font-size: 14px;">reacted to your message</div>
                        </td>
                    </tr>
                </table>
                <!-- Footer -->
                <table role="presentation" width="400" cellspacing="0" cellpadding="0" border="0">
                    <tr>
                        <td style="padding: 16px 0 0 0; text-align: center;">
                            <div style="font-size: 10px; color: #9e9e9e; letter-spacing: 0.3px;">
                                Email system powered by <span style="color: #757575;">Devcon Email</span> · Created by <span style="color: #757575;">Pixel Ranger Studio</span>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        
        // Simple text version with footer
        $text = "$emojiChar $reactorName reacted to your message\n\n---\nEmail system powered by Devcon Email · Created by Pixel Ranger Studio";
        
        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];
    }
    
    /**
     * Check if an email is from our domain (local user)
     */
    public function isLocalEmail(string $email): bool
    {
        // Extract domain from email
        $parts = explode('@', strtolower($email));
        if (count($parts) !== 2) {
            return false;
        }
        
        $domain = $parts[1];
        
        // Check against known local domains
        // You can expand this list or make it configurable
        $localDomains = [
            'devcon1.hu',
            'pixelranger.hu',
            'localhost',
        ];
        
        return in_array($domain, $localDomains);
    }
    
    /**
     * Get available emojis
     */
    public static function getAvailableEmojis(): array
    {
        $result = [];
        foreach (self::EMOJIS as $key => $char) {
            $result[] = [
                'key' => $key,
                'emoji' => $char,
            ];
        }
        return $result;
    }
}


