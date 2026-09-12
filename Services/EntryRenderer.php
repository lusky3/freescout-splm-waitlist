<?php

namespace Modules\SplmWaitlist\Services;

/**
 * Turns raw API entries into the lines the sidebar panel shows. Pure and
 * synchronous -- no HTTP, no cache, no Blade -- so it's testable without
 * any of FreeScout's runtime. An unrecognized status falls back to the
 * 'queued' wording rather than throwing: a future WP-side status value
 * this module doesn't know about yet should degrade, not crash the
 * sidebar.
 */
class EntryRenderer
{
    /**
     * @param array<int, array{season: string, status: string, offered_at: ?string, expires_at: ?string}> $entries
     * @return string[] One rendered line per entry, in the order given.
     */
    public function render(array $entries): array
    {
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = $this->renderOne($entry);
        }
        return $lines;
    }

    private function renderOne(array $entry): string
    {
        $season = (string) ($entry['season'] ?? '');
        $status = (string) ($entry['status'] ?? '');

        switch ($status) {
            case 'offered':
                $expiresAt = $entry['expires_at'] ?? null;
                if (!$expiresAt) {
                    return sprintf('Offer sent (%s)', $season);
                }
                if ($this->isPastDeadline($expiresAt)) {
                    return sprintf('Offer sent — deadline passed (%s)', $season);
                }
                return sprintf('Offer sent — expires %s (%s)', $this->relativeTime($expiresAt), $season);
            case 'claimed':
                return sprintf('Accepted spot (%s)', $season);
            case 'queued':
            default:
                return sprintf('On waitlist (%s)', $season);
        }
    }

    private function relativeTime(string $iso8601Utc): string
    {
        $target = strtotime($iso8601Utc);
        if ($target === false) {
            return $iso8601Utc;
        }
        $diff = $target - time();
        if ($diff <= 0) {
            return 'soon';
        }
        $hours = (int) round($diff / 3600);
        if ($hours < 1) {
            return 'in less than an hour';
        }
        if ($hours < 24) {
            return sprintf('in %d hour%s', $hours, $hours === 1 ? '' : 's');
        }
        $days = (int) round($hours / 24);
        return sprintf('in %d day%s', $days, $days === 1 ? '' : 's');
    }

    private function isPastDeadline(string $iso8601Utc): bool
    {
        $target = strtotime($iso8601Utc);
        // An unparseable date is treated as NOT past -- fail toward the
        // less alarming, still-reviewed-by-a-human message ("expires
        // <raw string>") rather than falsely claiming a deadline passed.
        return $target !== false && $target <= time();
    }
}
