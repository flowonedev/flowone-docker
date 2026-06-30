<?php

namespace Webmail\Addons\Calendar\Services;

/**
 * IcsImportService
 * ---------------------------------------------------------------
 * Imports iCalendar (.ics) data into the existing calendar_events
 * store. Dependency-free VEVENT parser (line unfolding, VALUE=DATE
 * all-day, TZID / UTC handling, RRULE passthrough, VALARM -> reminders).
 *
 * Idempotent by (calendar_id, uid) so re-running an import (or a
 * delta migration sweep) updates events in place instead of
 * duplicating them. The hand-rolled RRULE the calendar already stores
 * and expands is preserved verbatim.
 */
class IcsImportService
{
    private \PDO $db;
    private CalendarService $calendars;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
        $this->calendars = new CalendarService($config);
    }

    /**
     * @return array{imported:int,updated:int,total:int}
     */
    public function importIcs(string $email, int $calendarId, string $ics): array
    {
        return $this->upsertEvents($calendarId, $this->parseVevents($ics));
    }

    /**
     * Import a CSV calendar export (e.g. Outlook "Calendar.csv"). Locale-aware:
     * recognises English AND Hungarian column headers, dotted Y.M.D dates,
     * Igaz/Hamis booleans, and quoted multi-line fields. Idempotent by a
     * deterministic UID derived from the row, so re-importing the same file
     * updates in place instead of duplicating.
     *
     * @return array{imported:int,updated:int,total:int}
     */
    public function importCsv(string $email, int $calendarId, string $csv): array
    {
        return $this->upsertEvents($calendarId, $this->parseCsvEvents($csv));
    }

    /**
     * Upsert parsed events into calendar_events, keyed by (calendar_id, uid).
     *
     * @param array<int,array> $events
     * @return array{imported:int,updated:int,total:int}
     */
    private function upsertEvents(int $calendarId, array $events): array
    {
        $imported = 0;
        $updated = 0;
        foreach ($events as $ev) {
            $existing = $this->db->prepare('SELECT id FROM calendar_events WHERE calendar_id = ? AND uid = ?');
            $existing->execute([$calendarId, $ev['uid']]);
            $existingId = $existing->fetchColumn();

            if ($existingId) {
                $stmt = $this->db->prepare(
                    'UPDATE calendar_events SET title=?, description=?, location=?, start_time=?, end_time=?,
                        all_day=?, timezone=?, recurrence=?, reminders=?, etag=? WHERE id=?'
                );
                $stmt->execute([
                    $ev['title'], $ev['description'], $ev['location'], $ev['start_time'], $ev['end_time'],
                    $ev['all_day'] ? 1 : 0, $ev['timezone'], $ev['recurrence'], json_encode($ev['reminders']),
                    bin2hex(random_bytes(16)), (int) $existingId,
                ]);
                $updated++;
            } else {
                $stmt = $this->db->prepare(
                    'INSERT INTO calendar_events
                        (calendar_id, uid, title, description, location, start_time, end_time, all_day, timezone, recurrence, reminders, etag)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $calendarId, $ev['uid'], $ev['title'], $ev['description'], $ev['location'],
                    $ev['start_time'], $ev['end_time'], $ev['all_day'] ? 1 : 0, $ev['timezone'],
                    $ev['recurrence'], json_encode($ev['reminders']), bin2hex(random_bytes(16)),
                ]);
                $imported++;
            }
        }
        return ['imported' => $imported, 'updated' => $updated, 'total' => count($events)];
    }

    /**
     * Resolve the calendar to import into: explicit id, else the user's
     * default (created if necessary).
     */
    public function resolveCalendarId(string $email, ?int $calendarId): int
    {
        if ($calendarId) {
            $cal = $this->calendars->getCalendar($email, $calendarId);
            if ($cal) {
                return (int) $cal['id'];
            }
        }
        $default = $this->calendars->getDefaultCalendar($email);
        if ($default) {
            return (int) $default['id'];
        }
        $created = $this->calendars->createCalendar($email, 'My Calendar', '#3b82f6', true);
        return (int) $created['id'];
    }

    // =====================================================================
    // Parsing
    // =====================================================================

    /** @return array<int,array> */
    public function parseVevents(string $ics): array
    {
        $lines = $this->unfold($ics);
        $events = [];
        $cur = null;
        $alarm = null;
        foreach ($lines as $line) {
            $upper = strtoupper($line);
            if ($upper === 'BEGIN:VEVENT') {
                $cur = $this->emptyEvent();
                continue;
            }
            if ($upper === 'END:VEVENT') {
                if ($cur !== null) {
                    $events[] = $this->finalizeEvent($cur);
                }
                $cur = null;
                continue;
            }
            if ($cur === null) {
                continue;
            }
            if ($upper === 'BEGIN:VALARM') {
                $alarm = [];
                continue;
            }
            if ($upper === 'END:VALARM') {
                if ($alarm !== null && isset($alarm['minutes'])) {
                    $cur['reminders'][] = ['minutes' => $alarm['minutes'], 'method' => $alarm['method'] ?? 'popup'];
                }
                $alarm = null;
                continue;
            }

            [$prop, $params, $value] = $this->splitLine($line);
            if ($prop === null) {
                continue;
            }

            if ($alarm !== null) {
                if ($prop === 'TRIGGER') {
                    $alarm['minutes'] = $this->parseTriggerMinutes($value);
                } elseif ($prop === 'ACTION') {
                    $alarm['method'] = strtolower($value) === 'email' ? 'email' : 'popup';
                }
                continue;
            }

            switch ($prop) {
                case 'UID':
                    $cur['uid'] = $value;
                    break;
                case 'SUMMARY':
                    $cur['title'] = $this->unescapeText($value);
                    break;
                case 'DESCRIPTION':
                    $cur['description'] = $this->unescapeText($value);
                    break;
                case 'LOCATION':
                    $cur['location'] = $this->unescapeText($value);
                    break;
                case 'DTSTART':
                    [$dt, $allDay, $tz] = $this->parseDateTime($value, $params);
                    $cur['start_time'] = $dt;
                    $cur['all_day'] = $allDay;
                    if ($tz) {
                        $cur['timezone'] = $tz;
                    }
                    break;
                case 'DTEND':
                    [$dt, $allDay] = $this->parseDateTime($value, $params);
                    $cur['end_time'] = $dt;
                    if ($allDay) {
                        $cur['_dtend_is_date'] = true;
                    }
                    break;
                case 'DURATION':
                    $cur['_duration'] = $value;
                    break;
                case 'RRULE':
                    $cur['recurrence'] = $value;
                    break;
            }
        }
        return $events;
    }

    private function finalizeEvent(array $e): array
    {
        if (($e['title'] ?? '') === '') {
            $e['title'] = '(no title)';
        }
        if (($e['uid'] ?? '') === '') {
            $e['uid'] = 'ics-' . md5(($e['title'] ?? '') . '|' . ($e['start_time'] ?? '') . '|' . random_int(0, 99999));
        }
        if (($e['start_time'] ?? null) === null) {
            $e['start_time'] = gmdate('Y-m-d H:i:s');
        }
        // Derive end from DURATION or default to +1h (or all-day same day).
        if (($e['end_time'] ?? null) === null) {
            if (!empty($e['_duration'])) {
                $e['end_time'] = $this->applyDuration($e['start_time'], $e['_duration']);
            } elseif ($e['all_day']) {
                $e['end_time'] = date('Y-m-d 23:59:59', strtotime($e['start_time']));
            } else {
                $e['end_time'] = date('Y-m-d H:i:s', strtotime($e['start_time']) + 3600);
            }
        } elseif ($e['all_day'] && !empty($e['_dtend_is_date'])) {
            // iCalendar all-day DTEND is exclusive — pull back one day to
            // the inclusive end the UI expects.
            $end = strtotime($e['end_time']) - 86400;
            $e['end_time'] = date('Y-m-d 23:59:59', $end);
        }
        unset($e['_duration'], $e['_dtend_is_date']);
        return $e;
    }

    private function emptyEvent(): array
    {
        return [
            'uid' => '', 'title' => '', 'description' => null, 'location' => null,
            'start_time' => null, 'end_time' => null, 'all_day' => false,
            'timezone' => 'UTC', 'recurrence' => null, 'reminders' => [],
        ];
    }

    /** Unfold folded lines (continuation starts with space/tab). */
    private function unfold(string $ics): array
    {
        $ics = str_replace(["\r\n", "\r"], "\n", $ics);
        $out = [];
        foreach (explode("\n", $ics) as $raw) {
            if ($raw === '') {
                continue;
            }
            if (($raw[0] === ' ' || $raw[0] === "\t") && !empty($out)) {
                $out[count($out) - 1] .= substr($raw, 1);
            } else {
                $out[] = $raw;
            }
        }
        return $out;
    }

    /** @return array{0:?string,1:array,2:string} [prop, params, value] */
    private function splitLine(string $line): array
    {
        $colon = strpos($line, ':');
        if ($colon === false) {
            return [null, [], ''];
        }
        $left = substr($line, 0, $colon);
        $value = substr($line, $colon + 1);
        $parts = explode(';', $left);
        $prop = strtoupper(array_shift($parts));
        $params = [];
        foreach ($parts as $p) {
            if (str_contains($p, '=')) {
                [$k, $v] = explode('=', $p, 2);
                $params[strtoupper($k)] = $v;
            }
        }
        return [$prop, $params, $value];
    }

    /**
     * @return array{0:string,1:bool,2:?string} [mysqlDateTime, allDay, tz]
     */
    private function parseDateTime(string $value, array $params): array
    {
        $value = trim($value);
        $isDate = (strtoupper($params['VALUE'] ?? '') === 'DATE') || preg_match('/^\d{8}$/', $value);
        if ($isDate && preg_match('/^(\d{4})(\d{2})(\d{2})/', $value, $m)) {
            return ["{$m[1]}-{$m[2]}-{$m[3]} 00:00:00", true, null];
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z)?$/', $value, $m)) {
            $dt = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
            $isUtc = ($m[7] ?? '') === 'Z';
            $tz = $isUtc ? 'UTC' : ($params['TZID'] ?? null);
            return [$dt, false, $tz];
        }
        // Fallback: best-effort strtotime.
        $ts = strtotime($value);
        return [$ts ? date('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s'), false, $params['TZID'] ?? null];
    }

    private function applyDuration(string $start, string $duration): string
    {
        // ISO-8601 duration, e.g. PT1H30M, P1D.
        if (!preg_match('/^(-)?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', strtoupper($duration), $m)) {
            return date('Y-m-d H:i:s', strtotime($start) + 3600);
        }
        $sign = ($m[1] ?? '') === '-' ? -1 : 1;
        $secs = ((int) ($m[2] ?? 0)) * 604800
            + ((int) ($m[3] ?? 0)) * 86400
            + ((int) ($m[4] ?? 0)) * 3600
            + ((int) ($m[5] ?? 0)) * 60
            + ((int) ($m[6] ?? 0));
        return date('Y-m-d H:i:s', strtotime($start) + ($sign * $secs));
    }

    private function parseTriggerMinutes(string $value): int
    {
        if (preg_match('/^(-)?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', strtoupper(trim($value)), $m)) {
            $mins = ((int) ($m[2] ?? 0)) * 10080
                + ((int) ($m[3] ?? 0)) * 1440
                + ((int) ($m[4] ?? 0)) * 60
                + ((int) ($m[5] ?? 0));
            return $mins;
        }
        return 15;
    }

    private function unescapeText(string $v): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $v);
    }

    // =====================================================================
    // CSV (Outlook calendar export) parsing
    // =====================================================================

    /**
     * Parse an Outlook-style CSV calendar export into normalized events.
     * Column order varies across Outlook versions/locales, so headers are
     * matched by (accent-insensitive) name with a positional fallback for the
     * always-present leading Subject/Start/End/All-day columns. Properly
     * handles quoted multi-line fields (descriptions often span lines).
     *
     * @return array<int,array>
     */
    public function parseCsvEvents(string $csv): array
    {
        // Strip a UTF-8 BOM if present.
        if (strncmp($csv, "\xEF\xBB\xBF", 3) === 0) {
            $csv = substr($csv, 3);
        }

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return [];
        }
        fwrite($fh, $csv);
        rewind($fh);

        $header = fgetcsv($fh, 0, ',', '"', '');
        if (!is_array($header)) {
            fclose($fh);
            return [];
        }
        $map = $this->mapCsvHeader($header);

        $events = [];
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (!is_array($row)) {
                continue;
            }
            // Skip fully blank rows (trailing newlines, separator artefacts).
            if (trim(implode('', array_map(static fn($c) => (string) $c, $row))) === '') {
                continue;
            }

            $get = static function (string $field) use ($row, $map): string {
                $idx = $map[$field] ?? null;
                if ($idx === null || !array_key_exists($idx, $row)) {
                    return '';
                }
                return trim((string) $row[$idx]);
            };

            $title = $get('subject');
            $allDay = $this->csvTruthy($get('all_day'));

            $start = $this->csvDateTime($get('start_date'), $get('start_time'), $allDay);
            if ($start === null) {
                continue; // no usable start -> not importable
            }
            $end = $this->csvDateTime($get('end_date'), $get('end_time'), $allDay);

            if ($allDay) {
                $startDay = date('Y-m-d', strtotime($start));
                $start = $startDay . ' 00:00:00';
                if ($end !== null && date('Y-m-d', strtotime($end)) > $startDay) {
                    // Outlook all-day end date is the day AFTER (exclusive);
                    // pull back to an inclusive 23:59:59 like the ICS importer.
                    $end = date('Y-m-d 23:59:59', strtotime(date('Y-m-d', strtotime($end)) . ' -1 day'));
                } else {
                    $end = $startDay . ' 23:59:59';
                }
            } elseif ($end === null || strtotime($end) < strtotime($start)) {
                $end = date('Y-m-d H:i:s', strtotime($start) + 3600);
            }

            $description = $get('description');
            $location = $get('location');

            $events[] = [
                'uid' => 'csv-' . md5($title . '|' . $start . '|' . $end . '|' . $location),
                'title' => $title !== '' ? $title : '(no title)',
                'description' => $description !== '' ? $description : null,
                'location' => $location !== '' ? $location : null,
                'start_time' => $start,
                'end_time' => $end,
                'all_day' => $allDay,
                'timezone' => 'UTC',
                'recurrence' => null,
                'reminders' => $this->csvReminders(
                    $get('reminder_onoff'),
                    $get('reminder_date'),
                    $get('reminder_time'),
                    $start
                ),
            ];
        }

        fclose($fh);
        return $events;
    }

    /**
     * Map CSV header cells to canonical fields by accent-insensitive name,
     * with a positional fallback for the fixed leading Outlook columns.
     *
     * @param array<int,?string> $header
     * @return array<string,int>
     */
    private function mapCsvHeader(array $header): array
    {
        $aliases = [
            'subject'        => ['subject', 'targy'],
            'start_date'     => ['start date', 'kezdo datum'],
            'start_time'     => ['start time', 'kezdo idopont'],
            'end_date'       => ['end date', 'befejezesi datum', 'befejezo datum'],
            'end_time'       => ['end time', 'befejezes idopontja', 'befejezo idopont'],
            'all_day'        => ['all day event', 'all day', 'egesz napos'],
            'reminder_onoff' => ['reminder on/off', 'reminder on off', 'emlekezteto be/ki', 'emlekezteto be ki'],
            'reminder_date'  => ['reminder date', 'emlekezteto datuma'],
            'reminder_time'  => ['reminder time', 'emlekezteto idopontja'],
            'location'       => ['location', 'hely'],
            'description'    => ['description', 'leiras', 'notes', 'megjegyzes'],
        ];

        $map = [];
        foreach ($header as $idx => $cell) {
            $norm = $this->csvNormalizeHeader((string) $cell);
            if ($norm === '') {
                continue;
            }
            foreach ($aliases as $field => $names) {
                if (!isset($map[$field]) && in_array($norm, $names, true)) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }

        // Locale-proof fallback: Outlook always emits these first six columns
        // in this order regardless of UI language.
        foreach (['subject' => 0, 'start_date' => 1, 'start_time' => 2, 'end_date' => 3, 'end_time' => 4, 'all_day' => 5] as $field => $pos) {
            if (!isset($map[$field]) && array_key_exists($pos, $header)) {
                $map[$field] = $pos;
            }
        }

        return $map;
    }

    private function csvNormalizeHeader(string $s): string
    {
        $s = trim($s);
        if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
            $s = substr($s, 3);
        }
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        $from = ['á', 'é', 'í', 'ó', 'ö', 'ő', 'ú', 'ü', 'ű', 'â', 'ä', 'ô', 'à', 'è'];
        $to   = ['a', 'e', 'i', 'o', 'o', 'o', 'u', 'u', 'u', 'a', 'a', 'o', 'a', 'e'];
        $s = str_replace($from, $to, $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim((string) $s);
    }

    private function csvTruthy(string $v): bool
    {
        $v = strtolower(trim($v));
        return in_array($v, ['true', '1', 'yes', 'y', 'igaz', 'igen', 'on'], true);
    }

    /**
     * Combine a CSV date + time into a MySQL datetime. Returns null when the
     * date cannot be parsed. All-day events always start at midnight.
     */
    private function csvDateTime(string $date, string $time, bool $allDay): ?string
    {
        $d = $this->csvParseDate($date);
        if ($d === null) {
            return null;
        }
        $t = $allDay ? '00:00:00' : $this->csvParseTime($time);
        return sprintf('%04d-%02d-%02d %s', $d[0], $d[1], $d[2], $t);
    }

    /** @return array{0:int,1:int,2:int}|null [year, month, day] */
    private function csvParseDate(string $s): ?array
    {
        $s = rtrim(trim($s), '.');
        if ($s === '') {
            return null;
        }
        // Year-first: 2026.5.6 / 2026-05-06 / 2026/5/6 (ISO + Hungarian dotted)
        if (preg_match('#^(\d{4})[.\-/](\d{1,2})[.\-/](\d{1,2})$#', $s, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return [(int) $m[1], (int) $m[2], (int) $m[3]];
        }
        // US slash: M/D/YYYY
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)
            && checkdate((int) $m[1], (int) $m[2], (int) $m[3])) {
            return [(int) $m[3], (int) $m[1], (int) $m[2]];
        }
        // European dotted, year last: D.M.YYYY
        if (preg_match('#^(\d{1,2})\.(\d{1,2})\.(\d{4})$#', $s, $m)
            && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return [(int) $m[3], (int) $m[2], (int) $m[1]];
        }
        // Fallback: let PHP try whatever locale string it is.
        $ts = strtotime($s);
        if ($ts !== false) {
            return [(int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts)];
        }
        return null;
    }

    private function csvParseTime(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '00:00:00';
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([ap])\.?m\.?$/i', $s, $m)) {
            $h = (int) $m[1];
            $ampm = strtolower($m[4]);
            if ($ampm === 'p' && $h < 12) {
                $h += 12;
            } elseif ($ampm === 'a' && $h === 12) {
                $h = 0;
            }
            return sprintf('%02d:%02d:%02d', min(23, max(0, $h)), min(59, (int) $m[2]), min(59, (int) ($m[3] ?? 0)));
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
            return sprintf('%02d:%02d:%02d', min(23, max(0, (int) $m[1])), min(59, (int) $m[2]), min(59, (int) ($m[3] ?? 0)));
        }
        return '00:00:00';
    }

    /**
     * Best-effort reminder: when the reminder flag is on, derive "minutes
     * before start" from the reminder date/time. Falls back to 15 minutes.
     *
     * @return array<int,array{minutes:int,method:string}>
     */
    private function csvReminders(string $onoff, string $date, string $time, string $start): array
    {
        if (!$this->csvTruthy($onoff)) {
            return [];
        }
        $d = $this->csvParseDate($date);
        $startTs = strtotime($start);
        if ($d === null || $startTs === false) {
            return [['minutes' => 15, 'method' => 'popup']];
        }
        $remTs = strtotime(sprintf('%04d-%02d-%02d %s', $d[0], $d[1], $d[2], $this->csvParseTime($time)));
        if ($remTs === false) {
            return [['minutes' => 15, 'method' => 'popup']];
        }
        $minutes = (int) round(($startTs - $remTs) / 60);
        if ($minutes < 0 || $minutes > 40320) { // negative or > 4 weeks -> default
            $minutes = 15;
        }
        return [['minutes' => $minutes, 'method' => 'popup']];
    }
}
