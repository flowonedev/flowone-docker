<?php
/**
 * contacts-calendar-import-test.php
 *
 * Regression guard for the dependency-free migration parsers that back
 * the Contacts (VCF/CSV) and Calendar (ICS) import features:
 *   - AddressBookService::parseVcards() / buildVcard()
 *   - IcsImportService::parseVevents()
 *
 * These run pure-function checks only (no DB, no network), so the
 * services are instantiated via reflection without their PDO-touching
 * constructors. Safe to run anywhere.
 *
 * Usage: php tests/contacts-calendar-import-test.php [--verbose] [--json]
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Addons\Contacts\Services\AddressBookService;
use Webmail\Addons\Calendar\Services\IcsImportService;
use Webmail\Services\ContactsService;

/** Instantiate a service without running its DB-touching constructor. */
function noCtor(string $cls)
{
    return (new ReflectionClass($cls))->newInstanceWithoutConstructor();
}

/** Invoke a private/protected method via reflection. */
function callPrivate(object $obj, string $method, array $args)
{
    $m = (new ReflectionClass($obj))->getMethod($method);
    $m->setAccessible(true);
    return $m->invoke($obj, ...$args);
}

$runner = new FlowOneTestRunner('contacts-calendar-import', $argv);

// ─── vCard parsing ───────────────────────────────────────────────────
$runner->section('1. VCARD');

$svc = noCtor(AddressBookService::class);

$vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:abc-123\r\nFN:John Q. Public\r\nN:Public;John;Q;;\r\n"
    . "ORG:Acme Inc.\r\nTITLE:Engineer\r\nEMAIL;TYPE=WORK:john@acme.com\r\nEMAIL;TYPE=HOME:jq@home.com\r\n"
    . "TEL;TYPE=CELL:+1-555-1234\r\nADR;TYPE=WORK:;;1 Main St;Springfield;IL;62704;USA\r\n"
    . "BDAY:1980-05-15\r\nNOTE:Likes coffee\\nand tea\r\nEND:VCARD\r\n"
    . "BEGIN:VCARD\nVERSION:2.1\nN;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:Kov=C3=A1cs;Bel=C3=A1\n"
    . "FN;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:Kov=C3=A1cs Bel=C3=A1\nEMAIL:bela@example.hu\nEND:VCARD\n";

$cards = $svc->parseVcards($vcf);

$runner->test('parses two vCards', fn() => $runner->assertEquals(2, count($cards)));
$runner->test('reads FN', fn() => $runner->assertEquals('John Q. Public', $cards[0]['full_name']));
$runner->test('splits N into first/last', function () use ($runner, $cards) {
    $runner->assertEquals('John', $cards[0]['first_name']);
    $runner->assertEquals('Public', $cards[0]['last_name']);
});
$runner->test('reads ORG/TITLE', function () use ($runner, $cards) {
    $runner->assertEquals('Acme Inc.', $cards[0]['organization']);
    $runner->assertEquals('Engineer', $cards[0]['job_title']);
});
$runner->test('reads two typed emails', function () use ($runner, $cards) {
    $runner->assertEquals(2, count($cards[0]['emails']));
    $runner->assertEquals('work', $cards[0]['emails'][0]['type']);
    $runner->assertEquals('john@acme.com', $cards[0]['emails'][0]['value']);
});
$runner->test('reads phone', fn() => $runner->assertEquals('+1-555-1234', $cards[0]['phones'][0]['value']));
$runner->test('reads ADR city', fn() => $runner->assertEquals('Springfield', $cards[0]['addresses'][0]['city']));
$runner->test('reads BDAY', fn() => $runner->assertEquals('1980-05-15', $cards[0]['birthday']));
$runner->test('unescapes NOTE newline', fn() => $runner->assertTrue(str_contains($cards[0]['notes'], "\n")));
$runner->test('preserves UID', fn() => $runner->assertEquals('abc-123', $cards[0]['uid']));
$runner->test('decodes quoted-printable UTF-8', fn() => $runner->assertEquals('Kovács Belá', $cards[1]['full_name']));
$runner->test('derives UID from email when missing', fn() => $runner->assertTrue(str_starts_with($cards[1]['uid'], 'vcf-')));

$runner->section('2. VCARD ROUND-TRIP');
$built = $svc->buildVcard($cards[0]);
$runner->test('build emits FN', fn() => $runner->assertTrue(str_contains($built, 'FN:John Q. Public')));
$runner->test('build emits both EMAILs', fn() => $runner->assertEquals(2, substr_count($built, 'EMAIL;TYPE=')));
$reparsed = $svc->parseVcards($built);
$runner->test('round-trip keeps emails', fn() => $runner->assertEquals(2, count($reparsed[0]['emails'])));
$runner->test('round-trip keeps org', fn() => $runner->assertEquals('Acme Inc.', $reparsed[0]['organization']));

// ─── CSV parsing ─────────────────────────────────────────────────────
$runner->section('3. CSV (Google/Outlook columns)');

$csv = "Name,Given Name,Family Name,E-mail 1 - Value,Phone 1 - Value,Organization 1 - Name\r\n"
    . "Jane Roe,Jane,Roe,jane@roe.io,+1 555 0100,Roe LLC\r\n"
    . "Bob Smith,Bob,Smith,bob@smith.io,,\r\n";
$importMethod = (new ReflectionClass(AddressBookService::class))->getMethod('parseCsv');
$importMethod->setAccessible(true);
$rows = $importMethod->invoke($svc, $csv);
$runner->test('parses header + 2 rows', fn() => $runner->assertEquals(3, count($rows)));
$runner->test('header has 6 columns', fn() => $runner->assertEquals(6, count($rows[0])));

// ─── ICS parsing ─────────────────────────────────────────────────────
$runner->section('4. ICS (VEVENT)');

$ics = noCtor(IcsImportService::class);
$cal = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:evt-1\r\nSUMMARY:Team Sync\r\n"
    . "DESCRIPTION:Weekly\\, sync\r\nLOCATION:Room A\r\nDTSTART:20240115T093000Z\r\nDTEND:20240115T100000Z\r\n"
    . "RRULE:FREQ=WEEKLY;BYDAY=MO\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nEND:VALARM\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nUID:evt-2\r\nSUMMARY:Holiday\r\nDTSTART;VALUE=DATE:20240220\r\nDTEND;VALUE=DATE:20240221\r\n"
    . "END:VEVENT\r\nEND:VCALENDAR\r\n";

$events = $ics->parseVevents($cal);
$runner->test('parses two events', fn() => $runner->assertEquals(2, count($events)));
$runner->test('reads SUMMARY', fn() => $runner->assertEquals('Team Sync', $events[0]['title']));
$runner->test('unescapes DESCRIPTION comma', fn() => $runner->assertEquals('Weekly, sync', $events[0]['description']));
$runner->test('UTC DTSTART -> datetime', fn() => $runner->assertEquals('2024-01-15 09:30:00', $events[0]['start_time']));
$runner->test('UTC DTEND -> datetime', fn() => $runner->assertEquals('2024-01-15 10:00:00', $events[0]['end_time']));
$runner->test('UTC tz flagged', fn() => $runner->assertEquals('UTC', $events[0]['timezone']));
$runner->test('timed event not all-day', fn() => $runner->assertEquals(false, $events[0]['all_day']));
$runner->test('RRULE preserved', fn() => $runner->assertEquals('FREQ=WEEKLY;BYDAY=MO', $events[0]['recurrence']));
$runner->test('VALARM -> 15m reminder', fn() => $runner->assertEquals(15, $events[0]['reminders'][0]['minutes']));
$runner->test('DATE value -> all-day', fn() => $runner->assertEquals(true, $events[1]['all_day']));
$runner->test('all-day start', fn() => $runner->assertEquals('2024-02-20 00:00:00', $events[1]['start_time']));
$runner->test('all-day exclusive DTEND pulled back', fn() => $runner->assertEquals('2024-02-20 23:59:59', $events[1]['end_time']));

// ─── Autocomplete shaping (address book -> suggestion rows) ──────────
$runner->section('5. AUTOCOMPLETE SHAPING');

$ab = noCtor(AddressBookService::class);
$shaped = callPrivate($ab, 'shapeAutocomplete', [[
    ['id' => 7, 'full_name' => 'Jane Roe', 'emails' => json_encode([['type' => 'work', 'value' => 'jane@roe.io']]), 'origin' => 'manual', 'use_count' => 5, 'last_used' => null, 'is_synced' => 1],
    ['id' => 8, 'full_name' => 'No Email', 'emails' => json_encode([]), 'origin' => 'auto', 'use_count' => 0, 'last_used' => null, 'is_synced' => 0],
    ['id' => 9, 'full_name' => 'Auto Person', 'emails' => json_encode([['type' => 'other', 'value' => 'auto@x.io']]), 'origin' => 'auto', 'use_count' => 2, 'last_used' => null, 'is_synced' => 0],
]]);
$runner->test('drops rows without an email', fn() => $runner->assertEquals(2, count($shaped)));
$runner->test('extracts primary email', fn() => $runner->assertEquals('jane@roe.io', $shaped[0]['email']));
$runner->test('maps is_synced to bool', fn() => $runner->assertTrue($shaped[0]['is_synced'] === true));
$runner->test('non-synced flagged false', fn() => $runner->assertTrue($shaped[1]['is_synced'] === false));
$runner->test('carries origin', fn() => $runner->assertEquals('auto', $shaped[1]['origin']));

// ─── People merge: de-dupe + ranking (synced > saved > seen) ─────────
$runner->section('6. PEOPLE MERGE / RANK');

$cs = noCtor(ContactsService::class);
$bookRows = [
    ['id' => 1, 'full_name' => 'Synced Sam', 'email' => 'sam@synced.io', 'is_synced' => true, 'origin' => 'manual', 'use_count' => 1, 'last_used' => null],
    ['id' => 2, 'full_name' => 'Other Olive', 'email' => 'olive@other.io', 'is_synced' => false, 'origin' => 'auto', 'use_count' => 9, 'last_used' => null],
];
$seenRows = [
    // Duplicate of Synced Sam (different case) — must merge, not duplicate.
    ['contact_email' => 'SAM@synced.io', 'contact_name' => 'Sam', 'use_count' => 50, 'last_used' => null, 'contact_id' => 1],
    // Seen-only person (never saved).
    ['contact_email' => 'seen@only.io', 'contact_name' => 'Seen Only', 'use_count' => 100, 'last_used' => null, 'contact_id' => null],
];
$map = callPrivate($cs, 'buildPeopleMap', [$bookRows, $seenRows]);
$runner->test('merges duplicate email (case-insensitive)', fn() => $runner->assertEquals(3, count($map)));
$runner->test('saved row keeps contact_id after merge', fn() => $runner->assertEquals(1, $map['sam@synced.io']['contact_id']));
$runner->test('merge lifts use_count from cache', fn() => $runner->assertEquals(50, $map['sam@synced.io']['use_count']));
$runner->test('seen-only is not saved', fn() => $runner->assertTrue($map['seen@only.io']['is_saved'] === false));

$ranked = callPrivate($cs, 'rankPeople', [$map, 10]);
$runner->test('synced contact ranks first', fn() => $runner->assertEquals('sam@synced.io', $ranked[0]['contact_email']));
$runner->test('saved (non-synced) outranks seen-only', function () use ($runner, $ranked) {
    $runner->assertEquals('olive@other.io', $ranked[1]['contact_email']);
    $runner->assertEquals('seen@only.io', $ranked[2]['contact_email']);
});

exit($runner->finish());
