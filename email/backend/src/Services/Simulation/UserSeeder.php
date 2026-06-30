<?php

declare(strict_types=1);

namespace Webmail\Services\Simulation;

use PDO;

final class UserSeeder
{
    public function __construct(private array $config, private RunRegistry $registry)
    {
    }

    /**
     * @return list<array{email: string, display_name: string, id: int}>
     */
    public function seed(string $runId, string $ownerEmail, string $domain): array
    {
        $db = \Webmail\Core\Database::getConnection($this->config);
        $domain = strtolower($domain);
        $out = [];

        // Display names can collide (real life has duplicate "Márta Varga"), but the email
        // hits a UNIQUE constraint. Track used slugs and disambiguate with a numeric suffix
        // only when needed, so most users keep the plan-format "flowone.sim+marta.varga.rXXXX".
        $usedSlugs = [];
        for ($i = 0; $i < 30; $i++) {
            $baseSlug = \Webmail\Utils\HungarianNameGenerator::slug($i, $runId);
            $slug = $baseSlug;
            $suffix = 2;
            while (isset($usedSlugs[$slug])) {
                $slug = $baseSlug . '.' . $suffix;
                $suffix++;
            }
            $usedSlugs[$slug] = true;

            // Plus-addressing with run_id embedded keeps email UNIQUE across multiple Generate clicks
            // for the same colleague (e.g. "bence.kovacs" appears in run r7af2 and r9c1d as different
            // local-parts, never colliding with the UNIQUE constraint on organization_colleagues.email).
            $email = 'flowone.sim+' . $slug . '.' . $runId . '@' . $domain;
            $name = \Webmail\Utils\HungarianNameGenerator::displayName($i, $runId) . ' [SIM ' . $runId . ']';
            $stmt = $db->prepare('
                INSERT INTO organization_colleagues
                  (organization_domain, email, display_name, is_admin, status, synced_from_mailserver, simulation_run_id, is_simulation)
                VALUES (?, ?, ?, 0, \'active\', 0, ?, 1)
            ');
            $stmt->execute([$domain, strtolower($email), $name, $runId]);
            $id = (int) $db->lastInsertId();
            $this->registry->track($runId, RunRegistry::TYPE_COLLEAGUE, $id, null);
            $out[] = ['email' => strtolower($email), 'display_name' => $name, 'id' => $id];
        }
        return $out;
    }
}
