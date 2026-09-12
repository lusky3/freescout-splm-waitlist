<?php

namespace Tests\Unit;

use Modules\SplmWaitlist\Services\EntryRenderer;
use PHPUnit\Framework\TestCase;

class EntryRendererTest extends TestCase
{
    private const CREATED_AT = '2026-08-03T09:30:00Z';

    public function test_renders_nothing_for_an_empty_list(): void
    {
        $renderer = new EntryRenderer();
        $this->assertSame([], $renderer->render([]));
    }

    public function test_renders_a_queued_entry(): void
    {
        $renderer = new EntryRenderer();
        $rows = $renderer->render([
            [
                'season' => 'S2026',
                'status' => 'queued',
                'position' => 'player',
                'created_at' => self::CREATED_AT,
                'offered_at' => null,
                'expires_at' => null,
            ],
        ]);
        $this->assertSame([
            [
                'season' => 'S2026',
                'position' => 'Player',
                'statusLabel' => 'On waitlist',
                'statusClass' => 'text-muted',
                'detail' => 'On waitlist since Aug 3',
            ],
        ], $rows);
    }

    public function test_renders_a_claimed_entry(): void
    {
        $renderer = new EntryRenderer();
        $rows = $renderer->render([
            [
                'season' => 'S2026',
                'status' => 'claimed',
                'position' => 'goalie',
                'created_at' => self::CREATED_AT,
                'offered_at' => null,
                'expires_at' => null,
            ],
        ]);
        $this->assertSame([
            [
                'season' => 'S2026',
                'position' => 'Goalie',
                'statusLabel' => 'Accepted',
                'statusClass' => 'text-success',
                'detail' => 'On waitlist since Aug 3',
            ],
        ], $rows);
    }

    public function test_renders_an_offered_entry_with_a_relative_deadline(): void
    {
        $renderer = new EntryRenderer();
        $offeredAt = gmdate('Y-m-d\TH:i:s\Z');
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 7200);
        $rows = $renderer->render([
            [
                'season' => 'W2026-27',
                'status' => 'offered',
                'position' => 'player',
                'created_at' => self::CREATED_AT,
                'offered_at' => $offeredAt,
                'expires_at' => $expires,
            ],
        ]);
        $this->assertSame([
            [
                'season' => 'W2026-27',
                'position' => 'Player',
                'statusLabel' => 'Offer sent',
                'statusClass' => 'text-warning',
                'detail' => 'Expires in 2 hours',
            ],
        ], $rows);
    }

    public function test_renders_an_offered_entry_whose_deadline_has_already_passed(): void
    {
        $renderer = new EntryRenderer();
        $expired = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $rows = $renderer->render([
            [
                'season' => 'S2026',
                'status' => 'offered',
                'position' => 'player',
                'created_at' => self::CREATED_AT,
                'offered_at' => null,
                'expires_at' => $expired,
            ],
        ]);
        $this->assertSame([
            [
                'season' => 'S2026',
                'position' => 'Player',
                'statusLabel' => 'Offer expired',
                'statusClass' => 'text-danger',
                'detail' => 'On waitlist since Aug 3',
            ],
        ], $rows);
    }

    public function test_does_not_say_soon_for_a_deadline_in_the_past(): void
    {
        $renderer = new EntryRenderer();
        $expired = gmdate('Y-m-d\TH:i:s\Z', time() - 1);
        $rows = $renderer->render([
            [
                'season' => 'S2026',
                'status' => 'offered',
                'position' => 'player',
                'created_at' => self::CREATED_AT,
                'offered_at' => null,
                'expires_at' => $expired,
            ],
        ]);
        $this->assertSame('Offer expired', $rows[0]['statusLabel']);
        $this->assertStringNotContainsString('soon', $rows[0]['detail']);
    }

    public function test_renders_an_offered_entry_expiring_in_under_an_hour(): void
    {
        // 15 minutes, not 30: relativeTime() rounds hours via round($diff /
        // 3600), and round(1800/3600) == round(0.5) == 1 (PHP rounds .5 away
        // from zero) -- which would satisfy the "N hours" branch instead of
        // the "less than an hour" one this test means to exercise. 900s
        // rounds to 0.
        $renderer = new EntryRenderer();
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 900);
        $rows = $renderer->render([
            [
                'season' => 'S2026',
                'status' => 'offered',
                'position' => 'player',
                'created_at' => self::CREATED_AT,
                'offered_at' => null,
                'expires_at' => $expires,
            ],
        ]);
        $this->assertSame('Expires in less than an hour', $rows[0]['detail']);
    }

    public function test_renders_an_offered_entry_expiring_in_more_than_a_day(): void
    {
        $renderer = new EntryRenderer();
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 3600);
        $rows = $renderer->render([
            [
                'season' => 'S2026',
                'status' => 'offered',
                'position' => 'player',
                'created_at' => self::CREATED_AT,
                'offered_at' => null,
                'expires_at' => $expires,
            ],
        ]);
        $this->assertSame('Expires in 2 days', $rows[0]['detail']);
    }

    public function test_renders_an_unparseable_deadline_verbatim_rather_than_crashing(): void
    {
        // isPastDeadline() and relativeTime() both call strtotime() on the
        // same string; an unparseable value fails BOTH the same way (never
        // "past", falls through to relativeTime()), which then has nothing
        // better to show than the raw string. Deliberately accepted as-is
        // (see the design review's Minor #12) rather than "fixed" here --
        // this test documents and pins that accepted behavior.
        $renderer = new EntryRenderer();
        $rows = $renderer->render([
            [
                'season' => 'S2026',
                'status' => 'offered',
                'position' => 'player',
                'created_at' => self::CREATED_AT,
                'offered_at' => null,
                'expires_at' => 'not-a-real-date',
            ],
        ]);
        $this->assertSame('Expires not-a-real-date', $rows[0]['detail']);
        $this->assertSame('text-warning', $rows[0]['statusClass']);
    }

    public function test_renders_an_offered_entry_with_no_deadline(): void
    {
        $renderer = new EntryRenderer();
        $rows = $renderer->render([
            [
                'season' => 'S2026',
                'status' => 'offered',
                'position' => 'player',
                'created_at' => self::CREATED_AT,
                'offered_at' => null,
                'expires_at' => null,
            ],
        ]);
        $this->assertSame([
            [
                'season' => 'S2026',
                'position' => 'Player',
                'statusLabel' => 'Offer sent',
                'statusClass' => 'text-warning',
                'detail' => 'On waitlist since Aug 3',
            ],
        ], $rows);
    }

    public function test_renders_multiple_entries_in_order(): void
    {
        $renderer = new EntryRenderer();
        $rows = $renderer->render([
            [
                'season' => 'S2025',
                'status' => 'claimed',
                'position' => 'player',
                'created_at' => self::CREATED_AT,
                'offered_at' => null,
                'expires_at' => null,
            ],
            [
                'season' => 'S2026',
                'status' => 'queued',
                'position' => 'goalie',
                'created_at' => self::CREATED_AT,
                'offered_at' => null,
                'expires_at' => null,
            ],
        ]);
        $this->assertSame(['S2025', 'S2026'], array_column($rows, 'season'));
        $this->assertSame(['Accepted', 'On waitlist'], array_column($rows, 'statusLabel'));
        $this->assertSame(['Player', 'Goalie'], array_column($rows, 'position'));
    }

    public function test_treats_an_unknown_status_as_queued_rather_than_crashing(): void
    {
        $renderer = new EntryRenderer();
        $rows = $renderer->render([
            [
                'season' => 'S2026',
                'status' => 'something-new',
                'position' => 'player',
                'created_at' => self::CREATED_AT,
                'offered_at' => null,
                'expires_at' => null,
            ],
        ]);
        $this->assertSame('On waitlist', $rows[0]['statusLabel']);
        $this->assertSame('text-muted', $rows[0]['statusClass']);
    }

    public function test_treats_an_unknown_position_as_absent_rather_than_crashing(): void
    {
        $renderer = new EntryRenderer();
        $rows = $renderer->render([
            [
                'season' => 'S2026',
                'status' => 'queued',
                'position' => 'something-new',
                'created_at' => self::CREATED_AT,
                'offered_at' => null,
                'expires_at' => null,
            ],
        ]);
        $this->assertNull($rows[0]['position']);
    }

    public function test_treats_a_missing_position_as_absent(): void
    {
        $renderer = new EntryRenderer();
        $rows = $renderer->render([
            [
                'season' => 'S2026',
                'status' => 'queued',
                'created_at' => self::CREATED_AT,
                'offered_at' => null,
                'expires_at' => null,
            ],
        ]);
        $this->assertNull($rows[0]['position']);
    }

    public function test_treats_a_missing_or_unparseable_created_at_as_no_detail(): void
    {
        $renderer = new EntryRenderer();
        $rows = $renderer->render([
            [
                'season' => 'S2026',
                'status' => 'queued',
                'position' => 'player',
                'offered_at' => null,
                'expires_at' => null,
            ],
        ]);
        $this->assertNull($rows[0]['detail']);

        $rows = $renderer->render([
            [
                'season' => 'S2026',
                'status' => 'queued',
                'position' => 'player',
                'created_at' => 'not-a-real-date',
                'offered_at' => null,
                'expires_at' => null,
            ],
        ]);
        $this->assertNull($rows[0]['detail']);
    }
}
