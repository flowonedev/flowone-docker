<?php
/**
 * dav-migrate.php — local CLI for Panel-driven contacts/calendar migration.
 * ---------------------------------------------------------------------------
 * This is the URL-free, key-free counterpart to the /internal/dav-* HTTP
 * endpoints. The Panel's privileged agent runs this script directly on the
 * same server, so contacts/calendar import & export work with ZERO networking,
 * ZERO API keys and ZERO webmail-URL configuration.
 *
 * It bootstraps the Email App exactly like the cron scripts (loads .env +
 * autoloader, then the merged config) and calls the same services the
 * /internal/dav-* routes use, so behaviour is identical (idempotent, UID-based).
 *
 * All heavy payloads are passed as files (never argv) to stay clear of ARG_MAX
 * and to preserve the exact bytes (vCard/iCalendar require CRLF line endings):
 *   - import: --in  points at a file holding the raw VCF/CSV/ICS to import
 *   - export: --out is the file this script writes the produced VCF/ICS into
 *
 * stdout carries ONLY a single JSON object describing the result, so the agent
 * can parse it reliably. All diagnostics go to stderr.
 *
 * Usage:
 *   php dav-migrate.php --action=export --type=contacts --user=a@b.tld --out=/tmp/x.vcf
 *   php dav-migrate.php --action=export --type=calendar --user=a@b.tld --out=/tmp/x.ics
 *   php dav-migrate.php --action=import --type=contacts --user=a@b.tld --in=/tmp/x.vcf [--format=vcf|csv]
 *   php dav-migrate.php --action=import --type=calendar --user=a@b.tld --in=/tmp/x.ics
 */

// Never leak PHP notices/warnings into stdout — that would corrupt the JSON the
// agent parses. Send everything to stderr instead.
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

/** Emit a JSON result on stdout and exit. */
function dav_out(array $payload, int $exitCode = 0): void
{
    fwrite(STDOUT, json_encode($payload));
    exit($exitCode);
}

/** Emit a JSON error on stdout and exit non-zero. */
function dav_fail(string $message): void
{
    dav_out(['success' => false, 'error' => $message], 1);
}

try {
    $opts = getopt('', ['action:', 'type:', 'user:', 'format::', 'in::', 'out::']);

    $action = strtolower(trim((string) ($opts['action'] ?? '')));
    $type   = strtolower(trim((string) ($opts['type'] ?? '')));
    $user   = strtolower(trim((string) ($opts['user'] ?? '')));
    $format = isset($opts['format']) ? strtolower(trim((string) $opts['format'])) : '';
    $inFile = isset($opts['in']) ? (string) $opts['in'] : '';
    $outFile = isset($opts['out']) ? (string) $opts['out'] : '';

    if (!in_array($action, ['import', 'export'], true)) {
        dav_fail("--action must be 'import' or 'export'");
    }
    if (!in_array($type, ['contacts', 'calendar'], true)) {
        dav_fail("--type must be 'contacts' or 'calendar'");
    }
    if ($user === '' || !filter_var($user, FILTER_VALIDATE_EMAIL)) {
        dav_fail('--user must be a valid email address');
    }

    // Bootstrap the Email App (loads .env + autoloader), then the merged config.
    require __DIR__ . '/../cron/bootstrap.php';
    $config = require __DIR__ . '/../src/config.php';

    $localPart = preg_replace('/[^a-z0-9_.-]+/i', '_', strstr($user, '@', true) ?: $user);

    if ($action === 'export') {
        if ($outFile === '') {
            dav_fail('--out is required for export');
        }

        if ($type === 'contacts') {
            $svc = new \Webmail\Addons\Contacts\Services\AddressBookService($config);
            $data = $svc->exportVcf($user, null);
            $count = substr_count(strtoupper($data), 'BEGIN:VCARD');
            $filename = $localPart . '-contacts.vcf';
            $mime = 'text/vcard';
        } else {
            $calSvc = new \Webmail\Addons\Calendar\Services\CalendarService($config);
            $data = $calSvc->exportAllICS($user);
            $count = substr_count(strtoupper($data), 'BEGIN:VEVENT');
            $filename = $localPart . '-calendar.ics';
            $mime = 'text/calendar';
        }

        if (@file_put_contents($outFile, (string) $data) === false) {
            dav_fail('Could not write export output to ' . $outFile);
        }

        dav_out([
            'success' => true,
            'filename' => $filename,
            'mime' => $mime,
            'count' => (int) $count,
            'bytes' => strlen((string) $data),
        ]);
    }

    // action === 'import'
    if ($inFile === '' || !is_file($inFile) || !is_readable($inFile)) {
        dav_fail('--in must point to a readable file holding the data to import');
    }
    $data = (string) file_get_contents($inFile);
    if (trim($data) === '') {
        dav_fail('No data to import (input file is empty)');
    }

    if ($type === 'contacts') {
        $svc = new \Webmail\Addons\Contacts\Services\AddressBookService($config);
        $bookId = (int) ($svc->getOrCreateDefaultAddressBook($user)['id'] ?? 0);
        if ($format === '') {
            $format = stripos($data, 'BEGIN:VCARD') !== false ? 'vcf' : 'csv';
        }
        $result = $format === 'csv'
            ? $svc->importCsv($user, $bookId, $data)
            : $svc->importVcf($user, $bookId, $data);
    } else {
        $importer = new \Webmail\Addons\Calendar\Services\IcsImportService($config);
        $calId = $importer->resolveCalendarId($user, null);
        // Calendars come as iCalendar (.ics) OR a CSV export (e.g. Outlook
        // "Calendar.csv"). Honour an explicit --format, else auto-detect: an
        // ICS file always carries a VCALENDAR/VEVENT marker.
        if ($format === '') {
            $format = (stripos($data, 'BEGIN:VCALENDAR') !== false || stripos($data, 'BEGIN:VEVENT') !== false)
                ? 'ics'
                : 'csv';
        }
        $result = $format === 'csv'
            ? $importer->importCsv($user, $calId, $data)
            : $importer->importIcs($user, $calId, $data);
    }

    dav_out([
        'success' => true,
        'imported' => (int) ($result['imported'] ?? 0),
        'updated' => (int) ($result['updated'] ?? 0),
        'total' => (int) ($result['total'] ?? 0),
    ]);
} catch (\Throwable $e) {
    dav_fail($e->getMessage());
}
