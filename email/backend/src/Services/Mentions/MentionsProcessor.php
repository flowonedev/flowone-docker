<?php

namespace Webmail\Services\Mentions;

use Webmail\Addons\EmailTracking\Services\TrackingService;
use Webmail\Utils\EmailNormalizer;

/**
 * Orchestrates "scan body → persist mentions → notify recipients" for one
 * message. Used from both MessageController::send (outbound) and the inbound
 * processing seam in MailboxController::message.
 *
 * Notification side-effects
 * ─────────────────────────
 *   For each persisted mention that resolves to a local user (i.e. has a
 *   `mentioned_user_email`):
 *     - if the recipient has opted in to mention notifications (per-user
 *       setting `notify_on_mention`, default ON), insert one notification.
 *     - dedup is enforced via the `dedup_hash` column added by migration 166
 *       — INSERT … ON DUPLICATE KEY UPDATE no-ops the second insert.
 *
 * The processor is intentionally side-effect-tolerant: any failure inside
 * notify-recipient is logged and swallowed, never bubbled, because the
 * caller is already in a "best-effort post-send" block that must not break
 * the actual mail send.
 */
final class MentionsProcessor
{
    private MentionsService $mentions;
    private ?TrackingService $tracking = null;
    private array $config;

    public function __construct(array $config)
    {
        $this->config   = $config;
        $this->mentions = new MentionsService($config);
    }

    /**
     * @param string $ownerEmail   The mailbox owner this row belongs to
     *                             (sender on outbound copy, recipient on
     *                             inbound copy).
     * @param array  $message      ['message_id','direction','sender_email',
     *                              'subject','sent_at','folder','uid',
     *                              'recipients' = [emails…]]
     * @param string $bodyHtml
     * @param string $bodyText
     * @return int Number of newly inserted mention rows.
     */
    public function process(string $ownerEmail, array $message, string $bodyHtml = '', string $bodyText = ''): int
    {
        // Recipients double as the hint map for resolving bare `@firstname`
        // tokens in the plain-text path of the parser. We additionally
        // expand the hint set with the sender, the owner, the owner's
        // organisation colleagues, and the owner's recent contacts —
        // otherwise Gmail-originated mail (which only ever produces plain
        // "@robert" with no domain) can't be resolved when robert isn't
        // already in this message's To/Cc.
        $messageRecipients = array_filter(array_map(
            [EmailNormalizer::class, 'normalize'],
            (array) ($message['recipients'] ?? [])
        ));
        $hints = $this->expandHints($ownerEmail, $message['sender_email'] ?? '', $messageRecipients);

        // Domain bias: when an ambiguous bare `@robert` could resolve to
        // robert@pixelranger.hu OR robert@vendor.com, prefer the one in
        // the owner's domain. This is the heuristic that makes
        // Gmail-originated "@robert" work for internal mentions.
        $ownerDomain = EmailNormalizer::domainOf($ownerEmail);
        $mentions = MentionParser::extract($bodyHtml, $bodyText, $hints, [
            'preferDomain' => $ownerDomain,
        ]);

        // Structured log — written for every call so production troubleshooting
        // is one `grep '[mentions]' php_errors.log` away. The body-has-at
        // signal lets us distinguish "no '@' in the text at all" from "had
        // '@' tokens but none resolved", which is the most common reason
        // the Mentions smart view ends up empty.
        $bodyHasAt = (strpos($bodyHtml, '@') !== false) || (strpos($bodyText, '@') !== false);
        error_log(sprintf(
            '[mentions] owner=%s msg_id=%s dir=%s body_at=%s hints=%d found=%d',
            $ownerEmail,
            (string) ($message['message_id'] ?? '<none>'),
            (string) ($message['direction'] ?? '?'),
            $bodyHasAt ? '1' : '0',
            count($hints),
            count($mentions)
        ));

        if (empty($mentions)) return 0;

        $inserted = $this->mentions->recordMentions($ownerEmail, $message, $mentions);

        // Notification side-effect — only on INBOUND, because outbound notify
        // would spam the sender about their own outgoing mentions, and the
        // recipient side will handle inbound separately when their mailbox
        // processes the same message.
        if (($message['direction'] ?? '') === 'inbound') {
            $this->maybeNotify($ownerEmail, $message, $mentions);
        }

        return $inserted;
    }

    /**
     * Expand the per-message recipient list with everything we know about
     * the owner's "address book", so the parser can resolve bare `@robert`
     * tokens from Gmail/Outlook-originated mail that doesn't include the
     * mention's domain.
     *
     * Sources (deduped by canonical email, ambiguity-safe — if two contacts
     * share a local-part the parser drops the mention rather than guessing):
     *   1. Recipients of this message (To/Cc/Bcc) — already canonicalised.
     *   2. The owner themselves (so `@me` and `@<owner-local-part>` resolve).
     *   3. The sender (so `@<sender-firstname>` resolves to a reply target).
     *   4. organization_colleagues filtered to the owner's domain.
     *   5. email_contacts for this owner, ordered by use_count (top 200).
     *
     * Capped at 1,000 addresses overall — the regex pass is O(N) on the
     * hint count, and 1k is plenty for a real-world address book.
     *
     * Each source is wrapped in try/catch because the addon tables may not
     * exist in every install (Team / Colleagues addon is feature-gated);
     * the parser must still work in a stripped-down deployment.
     *
     * @param string   $ownerEmail            canonical or raw
     * @param string   $senderEmail           raw From: header
     * @param string[] $messageRecipients     already-canonicalised To/Cc/Bcc
     * @return string[] canonical addresses, dedup-preserving-order
     */
    private array $hintCache = [];
    private function expandHints(string $ownerEmail, string $senderEmail, array $messageRecipients): array
    {
        $owner = EmailNormalizer::normalize($ownerEmail) ?? '';
        if ($owner === '' ) return $messageRecipients;

        if (!isset($this->hintCache[$owner])) {
            $extra = [];
            $ownerDomain = EmailNormalizer::domainOf($owner);

            // 4. Organisation colleagues (same domain as the owner) —
            //    typically the most valuable signal for in-company mentions.
            if ($ownerDomain !== null) {
                try {
                    $db = \Webmail\Core\Database::getConnection($this->config);
                    $stmt = $db->prepare(
                        'SELECT email FROM organization_colleagues
                         WHERE organization_domain = ?
                         LIMIT 500'
                    );
                    $stmt->execute([$ownerDomain]);
                    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $em) {
                        $n = EmailNormalizer::normalize($em);
                        if ($n !== null) $extra[] = $n;
                    }
                } catch (\Throwable $e) {
                    // Colleague addon not installed — skip silently.
                }
            }

            // 5. Recent contacts for this owner (use frequency wins on ties).
            try {
                $db = \Webmail\Core\Database::getConnection($this->config);
                $stmt = $db->prepare(
                    'SELECT contact_email FROM email_contacts
                     WHERE user_email = ?
                     ORDER BY use_count DESC, last_used_at DESC
                     LIMIT 200'
                );
                $stmt->execute([$owner]);
                foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $em) {
                    $n = EmailNormalizer::normalize($em);
                    if ($n !== null) $extra[] = $n;
                }
            } catch (\Throwable $e) {
                try {
                    // Schema fallback: very old email_contacts has no last_used_at.
                    $db = \Webmail\Core\Database::getConnection($this->config);
                    $stmt = $db->prepare(
                        'SELECT contact_email FROM email_contacts
                         WHERE user_email = ?
                         ORDER BY use_count DESC
                         LIMIT 200'
                    );
                    $stmt->execute([$owner]);
                    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $em) {
                        $n = EmailNormalizer::normalize($em);
                        if ($n !== null) $extra[] = $n;
                    }
                } catch (\Throwable $e2) {
                    // No contacts table — fine.
                }
            }

            $this->hintCache[$owner] = $extra;
        }

        // 1+2+3 are per-message — merge with the cached owner-level hints.
        $sender = EmailNormalizer::normalize($senderEmail);
        $perMessage = [$owner];
        if ($sender !== null) $perMessage[] = $sender;
        $perMessage = array_merge($perMessage, $messageRecipients);

        $merged = array_merge($perMessage, $this->hintCache[$owner]);

        // Dedupe preserving order. Per-message hints come first so they win
        // any ambiguity tie-breaks the parser may apply later.
        $seen = [];
        $out = [];
        foreach ($merged as $addr) {
            if (!is_string($addr) || $addr === '') continue;
            if (isset($seen[$addr])) continue;
            $seen[$addr] = true;
            $out[] = $addr;
            if (count($out) >= 1000) break;
        }
        return $out;
    }

    private function maybeNotify(string $ownerEmail, array $message, array $mentions): void
    {
        $owner = EmailNormalizer::normalize($ownerEmail);
        if ($owner === null) return;

        // Was the owner actually mentioned in this message? (Outbound is the
        // sender's perspective; inbound is per-recipient. We only notify the
        // owner if they themselves appear in the mention list.)
        $ownerMentioned = false;
        foreach ($mentions as $m) {
            if (!empty($m['email']) && $m['email'] === $owner) {
                $ownerMentioned = true;
                break;
            }
        }
        if (!$ownerMentioned) return;

        // Respect the per-user opt-out. Default ON.
        if (!$this->isNotificationsEnabled($owner)) return;

        try {
            if ($this->tracking === null) {
                $this->tracking = new TrackingService($this->config);
            }
            $messageId = trim((string) ($message['message_id'] ?? ''), " \t\r\n<>");
            if ($messageId === '') return;

            $sender  = $message['sender_email'] ?? '';
            $subject = (string) ($message['subject'] ?? '');

            // Dedup hash: stable across re-processing the same message.
            // SHA-256 → 64 hex chars, matches the column definition.
            $dedup = hash('sha256', $owner . '|email_mention|' . $messageId);

            $title = 'You were mentioned';
            $body  = $sender !== ''
                ? trim($sender) . ' mentioned you' . ($subject !== '' ? ' in “' . self::truncate($subject, 80) . '”' : '')
                : ('You were mentioned' . ($subject !== '' ? ' in “' . self::truncate($subject, 80) . '”' : ''));

            // We can't use TrackingService::createNotification because that
            // method doesn't know about dedup_hash. Inline INSERT … ON
            // DUPLICATE KEY UPDATE to use the column without touching the
            // existing helper. This is the *only* writer to dedup_hash today
            // — when other notification types want dedup, they should
            // migrate to this pattern.
            $db = \Webmail\Core\Database::getConnection($this->config);
            $stmt = $db->prepare(
                'INSERT INTO notifications (user_email, type, title, message, data, dedup_hash)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE id = id'
            );
            $stmt->execute([
                $owner,
                'email_mention',
                $title,
                $body,
                json_encode([
                    'message_id' => $messageId,
                    'sender'     => $sender,
                    'subject'    => $subject,
                    'folder'     => $message['folder'] ?? null,
                ]),
                $dedup,
            ]);
        } catch (\Throwable $e) {
            error_log('[MentionsProcessor::maybeNotify] ' . $e->getMessage());
        }
    }

    /**
     * Per-user opt-out for mention notifications. Reads the same JSON file
     * SettingsController writes to — same path convention (md5(lower(email)).json
     * under /var/www/vps-email/data/settings).
     *
     * Default ON: a user who has never opened the setting still gets notified.
     */
    private array $notifyCache = [];
    private function isNotificationsEnabled(string $ownerEmail): bool
    {
        if (array_key_exists($ownerEmail, $this->notifyCache)) {
            return $this->notifyCache[$ownerEmail];
        }
        $enabled = true; // default ON
        try {
            $settingsDir = $this->config['settings_dir']
                ?? ($this->config['storage']['settings_path'] ?? '/var/www/vps-email/data/settings');
            $hash = md5(strtolower($ownerEmail));
            $file = rtrim($settingsDir, '/\\') . '/' . $hash . '.json';
            if (is_file($file)) {
                $raw = @file_get_contents($file);
                if ($raw !== false) {
                    $json = json_decode($raw, true);
                    if (is_array($json) && array_key_exists('notify_on_mention', $json)) {
                        $enabled = (bool) $json['notify_on_mention'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Default stands.
        }
        $this->notifyCache[$ownerEmail] = $enabled;
        return $enabled;
    }

    private static function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) return $s;
        return mb_substr($s, 0, $max - 1) . '…';
    }
}
