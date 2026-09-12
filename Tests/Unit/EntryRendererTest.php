<?php

namespace Tests\Unit;

use Modules\SplmWaitlist\Services\EntryRenderer;
use PHPUnit\Framework\TestCase;

class EntryRendererTest extends TestCase
{
    public function test_renders_nothing_for_an_empty_list(): void
    {
        $renderer = new EntryRenderer();
        $this->assertSame([], $renderer->render([]));
    }

    public function test_renders_a_queued_entry(): void
    {
        $renderer = new EntryRenderer();
        $rows = $renderer->render([
            ['season' => 'S2026', 'status' => 'queued', 'offered_at' => null, 'expires_at' => null],
        ]);
        $this->assertSame([
            ['season' => 'S2026', 'statusLabel' => 'On waitlist', 'statusClass' => 'text-muted', 'detail' => null],
        ], $rows);
    }

    public function test_renders_a_claimed_entry(): void
    {
        $renderer = new EntryRenderer();
        $rows = $renderer->render([
            ['season' => 'S2026', 'status' => 'claimed', 'offered_at' => null, 'expires_at' => null],
        ]);
        $this->assertSame([
            ['season' => 'S2026', 'statusLabel' => 'Accepted', 'statusClass' => 'text-success', 'detail' => null],
        ], $rows);
    }

    public function test_renders_an_offered_entry_with_a_relative_deadline(): void
    {
        $renderer = new EntryRenderer();
        $offeredAt = gmdate('Y-m-d\TH:i:s\Z');
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 7200);
        $rows = $renderer->render([
            ['season' => 'W2026-27', 'status' => 'offered', 'offered_at' => $offeredAt, 'expires_at' => $expires],
        ]);
        $this->assertSame([
            [
                'season' => 'W2026-27',
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
            ['season' => 'S2026', 'status' => 'offered', 'offered_at' => null, 'expires_at' => $expired],
        ]);
        $this->assertSame([
            ['season' => 'S2026', 'statusLabel' => 'Offer expired', 'statusClass' => 'text-danger', 'detail' => null],
        ], $rows);
    }

    public function test_does_not_say_soon_for_a_deadline_in_the_past(): void
    {
        $renderer = new EntryRenderer();
        $expired = gmdate('Y-m-d\TH:i:s\Z', time() - 1);
        $rows = $renderer->render([
            ['season' => 'S2026', 'status' => 'offered', 'offered_at' => null, 'expires_at' => $expired],
        ]);
        $this->assertSame('Offer expired', $rows[0]['statusLabel']);
        $this->assertNull($rows[0]['detail']);
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
            ['season' => 'S2026', 'status' => 'offered', 'offered_at' => null, 'expires_at' => $expires],
        ]);
        $this->assertSame('Expires in less than an hour', $rows[0]['detail']);
    }

    public function test_renders_an_offered_entry_expiring_in_more_than_a_day(): void
    {
        $renderer = new EntryRenderer();
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 50 * 3600);
        $rows = $renderer->render([
            ['season' => 'S2026', 'status' => 'offered', 'offered_at' => null, 'expires_at' => $expires],
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
            ['season' => 'S2026', 'status' => 'offered', 'offered_at' => null, 'expires_at' => 'not-a-real-date'],
        ]);
        $this->assertSame('Expires not-a-real-date', $rows[0]['detail']);
        $this->assertSame('text-warning', $rows[0]['statusClass']);
    }

    public function test_renders_an_offered_entry_with_no_deadline(): void
    {
        $renderer = new EntryRenderer();
        $rows = $renderer->render([
            ['season' => 'S2026', 'status' => 'offered', 'offered_at' => null, 'expires_at' => null],
        ]);
        $this->assertSame([
            ['season' => 'S2026', 'statusLabel' => 'Offer sent', 'statusClass' => 'text-warning', 'detail' => null],
        ], $rows);
    }

    public function test_renders_multiple_entries_in_order(): void
    {
        $renderer = new EntryRenderer();
        $rows = $renderer->render([
            ['season' => 'S2025', 'status' => 'claimed', 'offered_at' => null, 'expires_at' => null],
            ['season' => 'S2026', 'status' => 'queued', 'offered_at' => null, 'expires_at' => null],
        ]);
        $this->assertSame(['S2025', 'S2026'], array_column($rows, 'season'));
        $this->assertSame(['Accepted', 'On waitlist'], array_column($rows, 'statusLabel'));
    }

    public function test_treats_an_unknown_status_as_queued_rather_than_crashing(): void
    {
        $renderer = new EntryRenderer();
        $rows = $renderer->render([
            ['season' => 'S2026', 'status' => 'something-new', 'offered_at' => null, 'expires_at' => null],
        ]);
        $this->assertSame([
            ['season' => 'S2026', 'statusLabel' => 'On waitlist', 'statusClass' => 'text-muted', 'detail' => null],
        ], $rows);
    }
}
