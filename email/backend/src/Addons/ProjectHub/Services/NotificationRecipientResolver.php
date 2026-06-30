<?php

declare(strict_types=1);

namespace Webmail\Addons\ProjectHub\Services;

use PDO;

/**
 * Single source of truth for Project Hub notification recipient lists.
 * Merges assignees, watchers, and optional extra emails; dedupes; optionally removes the actor.
 */
class NotificationRecipientResolver
{
    private PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    /**
     * @param array<int, string> $sources Subset of: 'assignees', 'watchers'
     * @param array<int, string> $additionalEmails Extra recipients (e.g. newly assigned user, @mentions)
     * @return array<int, string> Unique lowercased emails
     */
    public function resolve(
        int $cardId,
        string $actorEmail,
        array $sources,
        array $additionalEmails = [],
        bool $excludeActor = true
    ): array {
        $actor = strtolower(trim($actorEmail));
        $set = [];

        $wantAssignees = in_array('assignees', $sources, true);
        $wantWatchers = in_array('watchers', $sources, true);

        if ($wantAssignees && $wantWatchers) {
            $stmt = $this->db->prepare("
                SELECT DISTINCT email FROM (
                    SELECT user_email AS email FROM projecthub_card_assignees WHERE card_id = ?
                    UNION
                    SELECT user_email AS email FROM projecthub_watchers WHERE card_id = ?
                ) combined
            ");
            $stmt->execute([$cardId, $cardId]);
        } elseif ($wantAssignees) {
            $stmt = $this->db->prepare('SELECT user_email AS email FROM projecthub_card_assignees WHERE card_id = ?');
            $stmt->execute([$cardId]);
        } elseif ($wantWatchers) {
            $stmt = $this->db->prepare('SELECT user_email AS email FROM projecthub_watchers WHERE card_id = ?');
            $stmt->execute([$cardId]);
        } else {
            $stmt = null;
        }

        if ($stmt !== null) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $e = strtolower(trim((string)($row['email'] ?? '')));
                if ($e !== '') {
                    $set[$e] = true;
                }
            }
        }

        foreach ($additionalEmails as $em) {
            $e = strtolower(trim((string)$em));
            if ($e !== '') {
                $set[$e] = true;
            }
        }

        if ($excludeActor && $actor !== '') {
            unset($set[$actor]);
        }

        return array_keys($set);
    }

    /**
     * For comment + @mention: everyone in assignees ∪ watchers ∪ mentions gets ph_comment_added (once).
     * ph_mention goes only to mentioned users who are not already assignees or watchers (no double notify).
     *
     * @param array<int, string> $mentionedEmails From MentionService / structured payload
     * @return array{comment_recipients: array<int, string>, mention_only: array<int, string>}
     */
    public function resolveCommentAndMentionSplit(
        int $cardId,
        string $actorEmail,
        array $mentionedEmails
    ): array {
        $base = $this->resolve($cardId, $actorEmail, ['assignees', 'watchers'], [], true);
        $baseSet = array_fill_keys($base, true);

        $commentRecipients = $this->resolve($cardId, $actorEmail, ['assignees', 'watchers'], $mentionedEmails, true);

        $mentionOnly = [];
        foreach ($mentionedEmails as $em) {
            $e = strtolower(trim((string)$em));
            if ($e === '' || strtolower(trim($actorEmail)) === $e) {
                continue;
            }
            if (!isset($baseSet[$e])) {
                $mentionOnly[] = $e;
            }
        }

        return [
            'comment_recipients' => $commentRecipients,
            'mention_only' => array_values(array_unique($mentionOnly)),
        ];
    }
}
