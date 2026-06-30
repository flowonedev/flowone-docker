<?php

namespace Webmail\Services\Mentions;

use Webmail\Utils\EmailNormalizer;

/**
 * Persistence + lookup for @mentions.
 *
 * Single source of truth for the `webmail_message_mentions` table. Owned by:
 *   - MessageController::send (outbound: records mentions on the sent copy
 *     so the sender can also see them in `mentions:me` — matches Outlook
 *     behaviour).
 *   - MailboxController::message (inbound: records mentions on the recipient
 *     side so each recipient has their own row).
 *
 * Identity resolution
 * ───────────────────
 * `mentioned_user_email` is set when the @-mentioned address corresponds to
 * a real local user (i.e. someone with a `webmail_session_tracking` row, a
 * `webmail_accounts` row, or a `webmail_colleagues` row — any signal that
 * the address is a known mailbox in this installation). External addresses
 * leave that column NULL so the UI can still chip-render them but the
 * notification system knows to skip cross-tenant push.
 */
final class MentionsService
{
    private \PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    /**
     * Persist a batch of mentions for one (owner, message). Idempotent via
     * the UNIQUE(owner_email, message_id, mentioned_email_norm) constraint.
     *
     * @param string                                                              $ownerEmail
     * @param array{message_id:string, folder?:?string, uid?:?int, direction:string, sender_email:string, subject?:?string, sent_at?:?string} $message
     * @param array<int, array{email:string,label:string,text:string,source:string}>                                                          $mentions
     * @return int  number of rows actually inserted (excludes idempotent skips)
     */
    public function recordMentions(string $ownerEmail, array $message, array $mentions): int
    {
        if (empty($mentions)) return 0;

        $owner = EmailNormalizer::normalize($ownerEmail);
        if ($owner === null) return 0;

        $messageId = trim((string)($message['message_id'] ?? ''), " \t\r\n<>");
        if ($messageId === '') return 0;

        $sender = EmailNormalizer::normalize($message['sender_email'] ?? '') ?? '';
        if ($sender === '') return 0;

        $direction = ($message['direction'] ?? 'inbound') === 'outbound' ? 'outbound' : 'inbound';
        $folder    = isset($message['folder']) ? mb_substr((string) $message['folder'], 0, 1024) : null;
        $uid       = isset($message['uid']) && (int) $message['uid'] > 0 ? (int) $message['uid'] : null;
        $subject   = isset($message['subject']) ? mb_substr((string) $message['subject'], 0, 998) : null;
        $sentAt    = $this->normalizeDate($message['sent_at'] ?? null);

        $ownerDomain  = EmailNormalizer::domainOf($owner);
        $senderDomain = EmailNormalizer::domainOf($sender);

        $stmt = $this->db->prepare(
            'INSERT INTO webmail_message_mentions
             (owner_email, message_id, folder, uid, direction, sender_email,
              mentioned_email, mentioned_email_norm, mentioned_user_email,
              mention_text, trust, subject, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               folder = COALESCE(VALUES(folder), folder),
               uid    = COALESCE(VALUES(uid), uid),
               mentioned_user_email = COALESCE(VALUES(mentioned_user_email), mentioned_user_email),
               subject = COALESCE(VALUES(subject), subject),
               sent_at = COALESCE(VALUES(sent_at), sent_at)'
        );

        $inserted = 0;
        foreach ($mentions as $m) {
            $norm = $m['email'] ?? null;
            if (!is_string($norm) || $norm === '') continue;

            $resolvedUser = $this->resolveLocalUser($norm);

            // Trust hierarchy — purely informational, drives the UI badge.
            //   verified = sender is the inbox owner (you @-mentioned someone
            //              in your own draft)
            //   internal = sender domain == owner domain
            //   external = otherwise
            $trust = 'external';
            if ($sender === $owner) $trust = 'verified';
            elseif ($senderDomain !== null && $ownerDomain !== null && $senderDomain === $ownerDomain) {
                $trust = 'internal';
            }

            try {
                $stmt->execute([
                    $owner,
                    $messageId,
                    $folder,
                    $uid,
                    $direction,
                    $sender,
                    mb_substr($m['email'], 0, 255),
                    $norm,
                    $resolvedUser,
                    isset($m['text']) ? mb_substr((string) $m['text'], 0, 255) : null,
                    $trust,
                    $subject,
                    $sentAt,
                ]);
                // rowCount: 1 = inserted, 2 = updated existing, 0 = no change.
                if ($stmt->rowCount() === 1) $inserted++;
            } catch (\PDOException $e) {
                error_log('[MentionsService] insert failed: ' . $e->getMessage());
            }
        }

        return $inserted;
    }

    /**
     * Return the full mention rows for one message (UI: render chip list
     * + trust badge inside the email view).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMentionsForMessage(string $ownerEmail, string $messageId): array
    {
        $owner = EmailNormalizer::normalize($ownerEmail);
        if ($owner === null) return [];
        $mid   = trim($messageId, " \t\r\n<>");
        if ($mid === '') return [];

        $stmt = $this->db->prepare(
            'SELECT mentioned_email, mentioned_user_email, mention_text, trust, direction, sender_email
             FROM webmail_message_mentions
             WHERE owner_email = ? AND message_id = ?'
        );
        $stmt->execute([$owner, $mid]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Best-effort: does this canonical address correspond to a local user?
     * Returns the canonical address on hit, NULL otherwise.
     *
     * Strategy (cheap → expensive):
     *   1. Same domain as any account in webmail_accounts → likely-local.
     *   2. Exact match in webmail_session_tracking.user_email or
     *      webmail_accounts.email or webmail_colleagues.email.
     *
     * We cache the lookup per-process so a body with 5 @mentions doesn't
     * fire 15 SELECTs.
     */
    private array $localUserCache = [];

    public function resolveLocalUser(string $canonicalEmail): ?string
    {
        if (isset($this->localUserCache[$canonicalEmail])) {
            return $this->localUserCache[$canonicalEmail] ?: null;
        }

        // Probe each known signal table. We swallow PDOExceptions because
        // some installations may not have webmail_colleagues / webmail_accounts
        // (addon-gated tables); resolution is best-effort, never fatal.
        $tables = [
            ['table' => 'webmail_session_tracking', 'col' => 'user_email'],
            ['table' => 'webmail_accounts',         'col' => 'email'],
            ['table' => 'webmail_colleagues',       'col' => 'email'],
        ];
        $found = null;
        foreach ($tables as $t) {
            try {
                $stmt = $this->db->prepare(
                    'SELECT 1 FROM ' . $t['table'] . ' WHERE LOWER(' . $t['col'] . ') = ? LIMIT 1'
                );
                $stmt->execute([$canonicalEmail]);
                if ($stmt->fetchColumn()) {
                    $found = $canonicalEmail;
                    break;
                }
            } catch (\PDOException $e) {
                // table missing — try the next signal
            }
        }

        // Cache result (NULL stored as '' to distinguish from "not cached").
        $this->localUserCache[$canonicalEmail] = $found ?? '';
        return $found;
    }

    private function normalizeDate(?string $raw): ?string
    {
        if (!is_string($raw) || $raw === '') return null;
        $ts = strtotime($raw);
        if ($ts === false) return null;
        return date('Y-m-d H:i:s', $ts);
    }
}
