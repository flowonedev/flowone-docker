<?php

declare(strict_types=1);

namespace Webmail\Services\Simulation;

use PDO;

final class ActivitySeeder
{
    public function __construct(private array $config, private RunRegistry $registry)
    {
    }

    /**
     * @param list<array{card_id: int, board_id: int}> $cards
     */
    public function seed(string $runId, string $ownerEmail, array $cards): void
    {
        $db = \Webmail\Core\Database::getConnection($this->config);
        $owner = strtolower($ownerEmail);
        $details = json_encode(['simulation' => true, 'run_id' => $runId], JSON_THROW_ON_ERROR);
        $meta = json_encode(['simulation' => true, 'run_id' => $runId], JSON_THROW_ON_ERROR);
        foreach ($cards as $c) {
            $cid = (int) $c['card_id'];
            $bid = (int) $c['board_id'];
            $db->prepare('
                INSERT INTO webmail_card_activity (card_id, user_email, action, details, simulation_run_id)
                VALUES (?, ?, \'comment\', ?, ?)
            ')->execute([$cid, $owner, $details, $runId]);
            $aid = (int) $db->lastInsertId();
            $this->registry->track($runId, RunRegistry::TYPE_CARD_ACTIVITY, $aid, null);

            $db->prepare('
                INSERT INTO activity_log
                  (user_email, action_type, entity_type, entity_id, entity_name, board_id, metadata, simulation_run_id)
                VALUES (?, \'simulation_seed\', \'card\', ?, ?, ?, ?, ?)
            ')->execute([$owner, $cid, 'Sim activity', $bid, $meta, $runId]);
            $lid = (int) $db->lastInsertId();
            $this->registry->track($runId, RunRegistry::TYPE_ACTIVITY_LOG, $lid, null);
        }
    }
}
