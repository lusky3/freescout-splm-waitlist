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
        $lines = $renderer->render([
            ['season' => 'S2026', 'status' => 'queued', 'offered_at' => null, 'expires_at' => null],
        ]);
        $this->assertSame(['On waitlist (S2026)'], $lines);
    }

    public function test_renders_a_claimed_entry(): void
    {
        $renderer = new EntryRenderer();
        $lines = $renderer->render([
            ['season' => 'S2026', 'status' => 'claimed', 'offered_at' => null, 'expires_at' => null],
        ]);
        $this->assertSame(['Accepted spot (S2026)'], $lines);
    }

    public function test_renders_an_offered_entry_with_a_relative_deadline(): void
    {
        $renderer = new EntryRenderer();
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 7200);
        $lines = $renderer->render([
            ['season' => 'W2026-27', 'status' => 'offered', 'offered_at' => gmdate('Y-m-d\TH:i:s\Z'), 'expires_at' => $expires],
        ]);
        $this->assertSame(['Offer sent — expires in 2 hours (W2026-27)'], $lines);
    }

    public function test_renders_an_offered_entry_whose_deadline_has_already_passed(): void
    {
        $renderer = new EntryRenderer();
        $expired = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $lines = $renderer->render([
            ['season' => 'S2026', 'status' => 'offered', 'offered_at' => null, 'expires_at' => $expired],
        ]);
        $this->assertSame(['Offer sent — deadline passed (S2026)'], $lines);
    }

    public function test_does_not_say_soon_for_a_deadline_in_the_past(): void
    {
        $renderer = new EntryRenderer();
        $expired = gmdate('Y-m-d\TH:i:s\Z', time() - 1);
        $lines = $renderer->render([
            ['season' => 'S2026', 'status' => 'offered', 'offered_at' => null, 'expires_at' => $expired],
        ]);
        $this->assertStringNotContainsString('soon', $lines[0]);
    }

    public function test_renders_an_offered_entry_with_no_deadline(): void
    {
        $renderer = new EntryRenderer();
        $lines = $renderer->render([
            ['season' => 'S2026', 'status' => 'offered', 'offered_at' => null, 'expires_at' => null],
        ]);
        $this->assertSame(['Offer sent (S2026)'], $lines);
    }

    public function test_renders_multiple_entries_in_order(): void
    {
        $renderer = new EntryRenderer();
        $lines = $renderer->render([
            ['season' => 'S2025', 'status' => 'claimed', 'offered_at' => null, 'expires_at' => null],
            ['season' => 'S2026', 'status' => 'queued', 'offered_at' => null, 'expires_at' => null],
        ]);
        $this->assertSame(['Accepted spot (S2025)', 'On waitlist (S2026)'], $lines);
    }

    public function test_treats_an_unknown_status_as_queued_rather_than_crashing(): void
    {
        $renderer = new EntryRenderer();
        $lines = $renderer->render([
            ['season' => 'S2026', 'status' => 'something-new', 'offered_at' => null, 'expires_at' => null],
        ]);
        $this->assertSame(['On waitlist (S2026)'], $lines);
    }
}
