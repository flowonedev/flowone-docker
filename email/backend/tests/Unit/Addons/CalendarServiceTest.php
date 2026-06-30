<?php

namespace Webmail\Tests\Unit\Addons;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Webmail\Addons\Calendar\Services\CalendarService;

class CalendarServiceTest extends TestCase
{
    private array $config;
    private ?CalendarService $service = null;
    private string $testEmail = 'phpunit-calendar@flowone.pro';

    protected function setUp(): void
    {
        $this->config = require __DIR__ . '/../../../src/config.php';

        if (!$this->canConnectToDb()) {
            $this->markTestSkipped('Database not available');
        }

        $this->service = new CalendarService($this->config);
    }

    protected function tearDown(): void
    {
        if ($this->service) {
            $this->service->deleteAllEvents($this->testEmail);
            $calendars = $this->service->getCalendars($this->testEmail);
            foreach ($calendars as $cal) {
                if (!($cal['is_default'] ?? false)) {
                    $this->service->deleteCalendar($this->testEmail, $cal['id']);
                }
            }
        }
    }

    private function canConnectToDb(): bool
    {
        try {
            new \PDO(
                "mysql:host=" . getenv('DB_HOST') . ";dbname=" . getenv('DB_NAME'),
                getenv('DB_USER'),
                getenv('DB_PASS')
            );
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    #[Test]
    public function getCalendars_creates_default_when_empty(): void
    {
        $calendars = $this->service->getCalendars($this->testEmail);

        $this->assertNotEmpty($calendars, 'Should auto-create a default calendar');
        $this->assertIsArray($calendars);

        $hasDefault = false;
        foreach ($calendars as $cal) {
            if (!empty($cal['is_default'])) {
                $hasDefault = true;
                break;
            }
        }
        $this->assertTrue($hasDefault, 'At least one calendar should be marked as default');
    }

    #[Test]
    public function createCalendar_returns_calendar_with_correct_data(): void
    {
        $calendar = $this->service->createCalendar($this->testEmail, 'Test Calendar', '#ff5733');

        $this->assertNotNull($calendar);
        $this->assertEquals('Test Calendar', $calendar['name']);
        $this->assertEquals('#ff5733', $calendar['color']);
        $this->assertArrayHasKey('id', $calendar);
    }

    #[Test]
    public function getCalendar_returns_created_calendar(): void
    {
        $created = $this->service->createCalendar($this->testEmail, 'Fetchable Calendar');
        $fetched = $this->service->getCalendar($this->testEmail, $created['id']);

        $this->assertNotNull($fetched);
        $this->assertEquals($created['id'], $fetched['id']);
        $this->assertEquals('Fetchable Calendar', $fetched['name']);
    }

    #[Test]
    public function getCalendar_returns_null_for_nonexistent(): void
    {
        $result = $this->service->getCalendar($this->testEmail, 999999);

        $this->assertNull($result);
    }

    #[Test]
    public function updateCalendar_changes_name_and_color(): void
    {
        $created = $this->service->createCalendar($this->testEmail, 'Old Name', '#000000');

        $updated = $this->service->updateCalendar($this->testEmail, $created['id'], [
            'name' => 'New Name',
            'color' => '#ffffff',
        ]);

        $this->assertNotNull($updated);
        $this->assertEquals('New Name', $updated['name']);
        $this->assertEquals('#ffffff', $updated['color']);
    }

    #[Test]
    public function deleteCalendar_removes_it(): void
    {
        $created = $this->service->createCalendar($this->testEmail, 'Doomed Calendar');
        $deleted = $this->service->deleteCalendar($this->testEmail, $created['id']);

        $this->assertTrue($deleted);

        $fetched = $this->service->getCalendar($this->testEmail, $created['id']);
        $this->assertNull($fetched);
    }

    #[Test]
    public function createEvent_returns_event_with_correct_fields(): void
    {
        $calendars = $this->service->getCalendars($this->testEmail);
        $calendarId = $calendars[0]['id'];

        $event = $this->service->createEvent($this->testEmail, $calendarId, [
            'title' => 'Team Standup',
            'start_date' => '2026-04-01 09:00:00',
            'end_date' => '2026-04-01 09:30:00',
            'description' => 'Daily standup meeting',
            'location' => 'Conference Room A',
        ]);

        $this->assertNotNull($event);
        $this->assertEquals('Team Standup', $event['title']);
        $this->assertArrayHasKey('id', $event);
        $this->assertArrayHasKey('start_date', $event);
        $this->assertArrayHasKey('end_date', $event);
    }

    #[Test]
    public function getEvent_returns_created_event(): void
    {
        $calendars = $this->service->getCalendars($this->testEmail);
        $calendarId = $calendars[0]['id'];

        $created = $this->service->createEvent($this->testEmail, $calendarId, [
            'title' => 'Fetchable Event',
            'start_date' => '2026-04-02 10:00:00',
            'end_date' => '2026-04-02 11:00:00',
        ]);

        $fetched = $this->service->getEvent($this->testEmail, $created['id']);

        $this->assertNotNull($fetched);
        $this->assertEquals($created['id'], $fetched['id']);
        $this->assertEquals('Fetchable Event', $fetched['title']);
    }

    #[Test]
    public function updateEvent_modifies_title(): void
    {
        $calendars = $this->service->getCalendars($this->testEmail);
        $calendarId = $calendars[0]['id'];

        $created = $this->service->createEvent($this->testEmail, $calendarId, [
            'title' => 'Original Title',
            'start_date' => '2026-04-03 14:00:00',
            'end_date' => '2026-04-03 15:00:00',
        ]);

        $updated = $this->service->updateEvent($this->testEmail, $created['id'], [
            'title' => 'Updated Title',
        ]);

        $this->assertNotNull($updated);
        $this->assertEquals('Updated Title', $updated['title']);
    }

    #[Test]
    public function deleteEvent_removes_it(): void
    {
        $calendars = $this->service->getCalendars($this->testEmail);
        $calendarId = $calendars[0]['id'];

        $created = $this->service->createEvent($this->testEmail, $calendarId, [
            'title' => 'To Delete',
            'start_date' => '2026-04-04 08:00:00',
            'end_date' => '2026-04-04 09:00:00',
        ]);

        $deleted = $this->service->deleteEvent($this->testEmail, $created['id']);
        $this->assertTrue($deleted);

        $fetched = $this->service->getEvent($this->testEmail, $created['id']);
        $this->assertNull($fetched);
    }

    #[Test]
    public function getEvents_filters_by_date_range(): void
    {
        $calendars = $this->service->getCalendars($this->testEmail);
        $calendarId = $calendars[0]['id'];

        $this->service->createEvent($this->testEmail, $calendarId, [
            'title' => 'March Event',
            'start_date' => '2026-03-15 10:00:00',
            'end_date' => '2026-03-15 11:00:00',
        ]);

        $this->service->createEvent($this->testEmail, $calendarId, [
            'title' => 'May Event',
            'start_date' => '2026-05-15 10:00:00',
            'end_date' => '2026-05-15 11:00:00',
        ]);

        $aprilEvents = $this->service->getEvents(
            $this->testEmail,
            $calendarId,
            '2026-04-01',
            '2026-04-30'
        );

        foreach ($aprilEvents as $event) {
            $this->assertNotEquals('March Event', $event['title']);
            $this->assertNotEquals('May Event', $event['title']);
        }
    }

    #[Test]
    public function getAllEvents_returns_events_across_calendars(): void
    {
        $cal1 = $this->service->createCalendar($this->testEmail, 'Work');
        $cal2 = $this->service->createCalendar($this->testEmail, 'Personal');

        $this->service->createEvent($this->testEmail, $cal1['id'], [
            'title' => 'Work Meeting',
            'start_date' => '2026-06-01 09:00:00',
            'end_date' => '2026-06-01 10:00:00',
        ]);

        $this->service->createEvent($this->testEmail, $cal2['id'], [
            'title' => 'Gym',
            'start_date' => '2026-06-01 18:00:00',
            'end_date' => '2026-06-01 19:00:00',
        ]);

        $all = $this->service->getAllEvents($this->testEmail, '2026-06-01', '2026-06-30');

        $titles = array_column($all, 'title');
        $this->assertContains('Work Meeting', $titles);
        $this->assertContains('Gym', $titles);
    }

    #[Test]
    public function deleteAllEvents_clears_everything(): void
    {
        $calendars = $this->service->getCalendars($this->testEmail);
        $calendarId = $calendars[0]['id'];

        $this->service->createEvent($this->testEmail, $calendarId, [
            'title' => 'Event 1',
            'start_date' => '2026-07-01 09:00:00',
            'end_date' => '2026-07-01 10:00:00',
        ]);
        $this->service->createEvent($this->testEmail, $calendarId, [
            'title' => 'Event 2',
            'start_date' => '2026-07-02 09:00:00',
            'end_date' => '2026-07-02 10:00:00',
        ]);

        $count = $this->service->deleteAllEvents($this->testEmail);
        $this->assertGreaterThanOrEqual(2, $count);

        $remaining = $this->service->getAllEvents($this->testEmail);
        $this->assertEmpty($remaining);
    }

    #[Test]
    public function quickAdd_parses_text_to_event(): void
    {
        $calendars = $this->service->getCalendars($this->testEmail);
        $calendarId = $calendars[0]['id'];

        $event = $this->service->quickAdd($this->testEmail, $calendarId, 'Lunch with team tomorrow at noon');

        $this->assertNotNull($event);
        $this->assertArrayHasKey('title', $event);
        $this->assertArrayHasKey('id', $event);
    }

    #[Test]
    public function exportICS_returns_valid_ics_string(): void
    {
        $calendars = $this->service->getCalendars($this->testEmail);
        $calendarId = $calendars[0]['id'];

        $this->service->createEvent($this->testEmail, $calendarId, [
            'title' => 'ICS Export Test',
            'start_date' => '2026-08-01 10:00:00',
            'end_date' => '2026-08-01 11:00:00',
        ]);

        $ics = $this->service->exportICS($this->testEmail, $calendarId);

        $this->assertNotNull($ics);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('ICS Export Test', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
    }
}
