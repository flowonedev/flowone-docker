<?php

declare(strict_types=1);

namespace Webmail\Services\Simulation;

use PDO;

final class HubSeeder
{
    public function __construct(private array $config, private RunRegistry $registry)
    {
    }

    /**
     * @param list<int> $boardIds length 5 — board 0 in folder 0; 1+2 in folder 1; 3+4 in folder 2
     * @return array{space_id: int, folder_ids: list<int>}
     */
    public function seed(string $runId, string $ownerEmail, array $boardIds): array
    {
        $db = \Webmail\Core\Database::getConnection($this->config);
        $owner = strtolower($ownerEmail);
        $label = '[SIM ' . $runId . '] Studio Run';

        $db->prepare('
            INSERT INTO projecthub_spaces (user_email, name, color, icon, sort_order, simulation_run_id)
            VALUES (?, ?, \'#6366f1\', \'folder_special\', 0, ?)
        ')->execute([$owner, $label, $runId]);
        $spaceId = (int) $db->lastInsertId();
        $this->registry->track($runId, RunRegistry::TYPE_SPACE, $spaceId, null);

        $folderNames = ['Brand Refresh', 'Internal Tools', 'Q3 Campaigns'];
        $folderIds = [];
        foreach ($folderNames as $idx => $fname) {
            $db->prepare('
                INSERT INTO projecthub_folders (space_id, user_email, name, icon, sort_order)
                VALUES (?, ?, ?, \'folder\', ?)
            ')->execute([$spaceId, $owner, $fname, $idx]);
            $fid = (int) $db->lastInsertId();
            $this->registry->track($runId, RunRegistry::TYPE_FOLDER, $fid, null);
            $folderIds[] = $fid;
        }

        $links = [
            [$folderIds[0], $boardIds[0], 0],
            [$folderIds[1], $boardIds[1], 0],
            [$folderIds[1], $boardIds[2], 1],
            [$folderIds[2], $boardIds[3], 0],
            [$folderIds[2], $boardIds[4], 1],
        ];
        foreach ($links as [$fid, $bid, $ord]) {
            $db->prepare('
                INSERT INTO projecthub_folder_boards (folder_id, board_id, sort_order, simulation_run_id)
                VALUES (?, ?, ?, ?)
            ')->execute([$fid, $bid, $ord, $runId]);
            $linkId = (int) $db->lastInsertId();
            $this->registry->track($runId, RunRegistry::TYPE_FOLDER_BOARD_LINK, $linkId, null);
        }

        return ['space_id' => $spaceId, 'folder_ids' => $folderIds];
    }
}
