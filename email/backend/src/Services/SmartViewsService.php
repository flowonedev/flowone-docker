<?php

namespace Webmail\Services;

use Webmail\Services\Search\Parser;
use Webmail\Services\SmartViews\FiltersNormalizer;

/**
 * CRUD + reorder for user-defined Smart Views (saved searches).
 *
 * Tenancy is by lowercased `email`, matching every other per-user table.
 *
 * Reorder algorithm:
 *   The position column has a UNIQUE(email, position) index so we never end
 *   up with duplicate positions. To swap N positions atomically, we run a
 *   two-pass transaction:
 *     1. Negate every affected row's position (positions become unique in
 *        the negative space — guaranteed not to collide with positive ones).
 *     2. Write the new positive positions.
 *   This works for any reordering shape (move-up, move-down, drag-many).
 */
final class SmartViewsService
{
    private const NAME_MAX  = 64;
    private const ICON_MAX  = 32;
    private const COLOR_MAX = 16;
    private const QUERY_MAX = 2048;

    private const ALLOWED_SCOPES = ['folder', 'all', 'accounts'];

    private \PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(string $email): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, icon, color, query, filters_json, schema_version, scope, position, created_at, updated_at
             FROM webmail_smart_views
             WHERE email = ?
             ORDER BY position ASC, id ASC'
        );
        $stmt->execute([strtolower($email)]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map([$this, 'hydrateRow'], $rows);
    }

    public function get(string $email, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, icon, color, query, filters_json, schema_version, scope, position, created_at, updated_at
             FROM webmail_smart_views WHERE email = ? AND id = ?'
        );
        $stmt->execute([strtolower($email), $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrateRow($row) : null;
    }

    /**
     * Create a smart view. `query` is canonicalised through the AST parser
     * (round-tripped through Parser → toQueryString) so we always store the
     * normalised form. `filters_json` is whitelist-validated.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(string $email, array $input): array
    {
        $email = strtolower($email);
        $data  = $this->validateAndCanonicalise($input);

        $position = $this->nextPosition($email);

        $stmt = $this->db->prepare(
            'INSERT INTO webmail_smart_views
             (email, name, icon, color, query, filters_json, schema_version, scope, position)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $email,
            $data['name'],
            $data['icon'],
            $data['color'],
            $data['query'],
            $data['filters_json'],
            $data['schema_version'],
            $data['scope'],
            $position,
        ]);

        return $this->get($email, (int)$this->db->lastInsertId());
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(string $email, int $id, array $input): ?array
    {
        $existing = $this->get($email, $id);
        if (!$existing) return null;

        $data = $this->validateAndCanonicalise($input + $existing);

        $stmt = $this->db->prepare(
            'UPDATE webmail_smart_views
             SET name = ?, icon = ?, color = ?, query = ?, filters_json = ?, schema_version = ?, scope = ?
             WHERE email = ? AND id = ?'
        );
        $stmt->execute([
            $data['name'],
            $data['icon'],
            $data['color'],
            $data['query'],
            $data['filters_json'],
            $data['schema_version'],
            $data['scope'],
            strtolower($email),
            $id,
        ]);

        return $this->get($email, $id);
    }

    public function delete(string $email, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM webmail_smart_views WHERE email = ? AND id = ?');
        $stmt->execute([strtolower($email), $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reorder Smart Views. `$orderedIds` is the full list of this user's
     * smart-view IDs in their new order. Ids not in this user's set are
     * silently ignored.
     *
     * @param int[] $orderedIds
     */
    public function reorder(string $email, array $orderedIds): bool
    {
        $email = strtolower($email);
        $orderedIds = array_values(array_unique(array_map('intval', $orderedIds)));
        if (empty($orderedIds)) return true;

        // Whitelist against the user's actual views.
        $own = array_column($this->listForUser($email), 'id');
        $orderedIds = array_values(array_intersect($orderedIds, $own));
        if (empty($orderedIds)) return true;

        $this->db->beginTransaction();
        try {
            // Pass 1: shift every affected row into the negative space.
            // We use `-(position + 1)` rather than `-position` because the
            // row at position 0 would otherwise stay at 0 (since -0 == 0)
            // and collide with the first pass-2 write. With +1 every
            // negated value is distinct AND strictly negative, so there
            // are no collisions either inside the bulk update (single
            // statement → constraint checked once at the end) or against
            // the positive values pass 2 writes.
            $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
            $stmt = $this->db->prepare(
                "UPDATE webmail_smart_views SET position = -(position + 1)
                 WHERE email = ? AND id IN ($placeholders)"
            );
            $stmt->execute(array_merge([$email], $orderedIds));

            // Pass 2: write the new positive positions one row at a time.
            $stmt = $this->db->prepare(
                'UPDATE webmail_smart_views SET position = ? WHERE email = ? AND id = ?'
            );
            foreach ($orderedIds as $pos => $id) {
                $stmt->execute([$pos, $email, $id]);
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{name:string, icon:string, color:string, query:string, filters_json:?string, schema_version:int, scope:string}
     */
    private function validateAndCanonicalise(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Smart View name is required');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            $name = mb_substr($name, 0, self::NAME_MAX);
        }

        $icon = trim((string)($input['icon'] ?? 'filter_alt'));
        if (mb_strlen($icon) > self::ICON_MAX) $icon = mb_substr($icon, 0, self::ICON_MAX);

        $color = trim((string)($input['color'] ?? 'primary'));
        if (mb_strlen($color) > self::COLOR_MAX) $color = mb_substr($color, 0, self::COLOR_MAX);

        $rawQuery = (string)($input['query'] ?? '');
        if (mb_strlen($rawQuery) > self::QUERY_MAX) {
            throw new \InvalidArgumentException('query too long');
        }

        // Round-trip through the AST so we always store the canonical form.
        // Unknown operators get demoted, malformed bits are normalised.
        $canonical = Parser::parseString($rawQuery)->toQueryString();
        if ($canonical === '' && empty($input['filters_json'])) {
            throw new \InvalidArgumentException('Smart View must have a query or filters_json');
        }

        // filters_json — accept array or pre-encoded string.
        $filtersInput = $input['filters_json'] ?? null;
        if (is_string($filtersInput) && $filtersInput !== '') {
            $filtersInput = json_decode($filtersInput, true);
        }
        $normalised = FiltersNormalizer::normalize($filtersInput ?: []);
        $filtersJson = !empty($normalised['filters']) ? json_encode($normalised['filters']) : null;

        $scope = (string)($input['scope'] ?? 'all');
        if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
            $scope = 'all';
        }

        return [
            'name'           => $name,
            'icon'           => $icon,
            'color'          => $color,
            'query'          => $canonical,
            'filters_json'   => $filtersJson,
            'schema_version' => $normalised['schema_version'],
            'scope'          => $scope,
        ];
    }

    private function nextPosition(string $email): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(position), -1) + 1 AS next FROM webmail_smart_views WHERE email = ?'
        );
        $stmt->execute([$email]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateRow(array $row): array
    {
        $row['id']             = (int)$row['id'];
        $row['position']       = (int)$row['position'];
        $row['schema_version'] = (int)$row['schema_version'];
        if (!empty($row['filters_json']) && is_string($row['filters_json'])) {
            $decoded = json_decode($row['filters_json'], true);
            $row['filters_json'] = is_array($decoded) ? $decoded : null;
        } else {
            $row['filters_json'] = null;
        }
        return $row;
    }
}
