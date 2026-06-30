<?php
/**
 * Backfill people links (migration 185 companion)
 * ---------------------------------------------------------------
 * Links pre-existing rows to the canonical address-book person:
 *   - client_contacts.contact_id  -> contacts.id   (origin = 'client')
 *   - email_contacts.contact_id   -> contacts.id   (origin = 'auto', only
 *                                     addresses already at/over the threshold)
 *
 * Auto/client people are placed in the per-user non-synced "Other contacts"
 * pool, so this NEVER pushes anything to a phone via CardDAV. Manually-saved
 * contacts and the synced book are left untouched.
 *
 * The matching is done in PHP (decoding the JSON `emails` column) because that
 * is far more reliable than SQL JSON LIKEs. Safe to re-run — every step is
 * idempotent (it skips rows that already have a contact_id and de-dupes by
 * email inside the address book).
 *
 * Usage:
 *   php backfill-people-links.php              # all users
 *   php backfill-people-links.php user@dom.tld # single user
 *   php backfill-people-links.php --dry-run    # report only, no writes
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Webmail\Core\Database;
use Webmail\Addons\Contacts\Services\AddressBookService;

$config = require __DIR__ . '/../src/config.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$onlyUser = null;
foreach ($args as $a) {
    if ($a !== '--dry-run' && strpos($a, '@') !== false) {
        $onlyUser = strtolower(trim($a));
    }
}

$threshold = (int)($config['contacts']['auto_add_threshold'] ?? 3);
$threshold = $threshold > 0 ? $threshold : 3;

echo "=== People links backfill ===\n";
echo 'Mode: ' . ($dryRun ? 'DRY RUN (no writes)' : 'LIVE') . "\n";
echo 'Auto-add threshold: ' . $threshold . "\n";
echo 'Scope: ' . ($onlyUser ?: 'all users') . "\n\n";

try {
    $db = Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Database error: ' . $e->getMessage() . "\n");
    exit(1);
}

// Building an AddressBookService self-heals all the migration-185 columns.
$ab = new AddressBookService($config);

// Collect the set of users to process.
$users = [];
if ($onlyUser) {
    $users[] = $onlyUser;
} else {
    foreach (['SELECT DISTINCT user_email FROM email_contacts', 'SELECT DISTINCT user_email FROM clients'] as $sql) {
        try {
            foreach ($db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $u) {
                $u = strtolower(trim((string)$u));
                if ($u !== '') {
                    $users[$u] = true;
                }
            }
        } catch (\PDOException $e) {
            // table may not exist; skip
        }
    }
    $users = array_keys($users);
}

$totalClients = 0;
$totalSeen = 0;

foreach ($users as $userEmail) {
    echo "User: {$userEmail}\n";

    // --- client_contacts -> canonical (origin = client) -------------------
    try {
        $stmt = $db->prepare(
            'SELECT cc.id, cc.email, cc.name
             FROM client_contacts cc JOIN clients c ON c.id = cc.client_id
             WHERE c.user_email = ? AND cc.contact_id IS NULL AND cc.email <> ""'
        );
        $stmt->execute([$userEmail]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $contact = $ab->findOrCreateByEmail($userEmail, $row['email'], $row['name'] ?: null, 'client');
            if ($contact) {
                if (!$dryRun) {
                    $db->prepare('UPDATE client_contacts SET contact_id = ? WHERE id = ?')
                        ->execute([(int)$contact['id'], (int)$row['id']]);
                }
                $totalClients++;
            }
        }
        echo "  client_contacts linked: " . count($rows) . " candidate(s)\n";
    } catch (\PDOException $e) {
        echo "  client_contacts skipped: " . $e->getMessage() . "\n";
    }

    // --- email_contacts (>= threshold) -> canonical (origin = auto) -------
    try {
        $stmt = $db->prepare(
            'SELECT contact_email, contact_name FROM email_contacts
             WHERE user_email = ? AND contact_id IS NULL AND use_count >= ?'
        );
        $stmt->execute([$userEmail, $threshold]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $contact = $ab->findOrCreateByEmail($userEmail, $row['contact_email'], $row['contact_name'] ?: null, 'auto');
            if ($contact) {
                if (!$dryRun) {
                    $db->prepare('UPDATE email_contacts SET contact_id = ? WHERE user_email = ? AND contact_email = ?')
                        ->execute([(int)$contact['id'], $userEmail, strtolower(trim($row['contact_email']))]);
                }
                $totalSeen++;
            }
        }
        echo "  email_contacts linked: " . count($rows) . " candidate(s)\n";
    } catch (\PDOException $e) {
        echo "  email_contacts skipped: " . $e->getMessage() . "\n";
    }
}

echo "\nDone. Linked {$totalClients} client contact(s), {$totalSeen} seen address(es)";
echo $dryRun ? " (dry run — nothing written).\n" : ".\n";
