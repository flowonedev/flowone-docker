<?php

namespace Webmail\Addons\ProjectHub\Services;

use PDO;

class ProjectHubCardUrlService
{
    private PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    public function getCardTrackedUrls(int $cardId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM projecthub_card_tracked_urls
            WHERE card_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$cardId]);
        return $stmt->fetchAll() ?: [];
    }

    public function addCardTrackedUrl(int $cardId, array $data, string $createdBy): ?array
    {
        $domain = trim($data['url_domain'] ?? '');
        if (!$domain) return null;

        $stmt = $this->db->prepare("
            INSERT INTO projecthub_card_tracked_urls (card_id, url_domain, display_name, title_match, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $cardId,
            $domain,
            $data['display_name'] ?? null,
            $data['title_match'] ?? null,
            strtolower($createdBy),
        ]);

        $id = (int)$this->db->lastInsertId();
        return $this->getById($id);
    }

    public function removeCardTrackedUrl(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM projecthub_card_tracked_urls WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function toggleCardTrackedUrl(int $id, bool $active): bool
    {
        $stmt = $this->db->prepare("UPDATE projecthub_card_tracked_urls SET is_active = ? WHERE id = ?");
        return $stmt->execute([$active ? 1 : 0, $id]);
    }

    public function getAllCardUrlMappings(): array
    {
        $stmt = $this->db->query("
            SELECT ctu.id, ctu.card_id, ctu.url_domain, ctu.display_name, ctu.title_match,
                   l.board_id, cb.client_id
            FROM projecthub_card_tracked_urls ctu
            JOIN webmail_board_cards c ON c.id = ctu.card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            LEFT JOIN client_boards cb ON cb.board_id = l.board_id
            WHERE ctu.is_active = 1
        ");
        return $stmt->fetchAll() ?: [];
    }

    private function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM projecthub_card_tracked_urls WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
