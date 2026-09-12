<?php

namespace Modules\SplmWaitlist\Services;

/**
 * Turns raw API entries into the structured rows the sidebar panel renders
 * as native FreeScout list items -- a season, a color-coded status label,
 * and an optional detail line. Pure and synchronous -- no HTTP, no cache,
 * no Blade -- so it's testable without any of FreeScout's runtime. An
 * unrecognized status falls back to the 'queued' presentation rather than
 * throwing: a future WP-side status value this module doesn't know about
 * yet should degrade, not crash the sidebar.
 */
class EntryRenderer
{
    /**
     * @param array<int, array{season: string, status: string, offered_at: ?string, expires_at: ?string}> $entries
     * @return array<int, array{season: string, statusLabel: string, statusClass: string, detail: ?string}>
     */
    public function render(array $entries): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = $this->renderOne($entry);
        }
        return $rows;
    }

    /**
     * @return array{season: string, statusLabel: string, statusClass: string, detail: ?string}
     */
    private function renderOne(array $entry): array
    {
        $season = (string) ($entry['season'] ?? '');
        $status = (string) ($entry['status'] ?? '');

        switch ($status) {
            case 'offered':
                return $this->renderOffered($season, $entry['expires_at'] ?? null);
            case 'claimed':
                return [
                    'season' => $season,
                    'statusLabel' => 'Accepted',
                    'statusClass' => 'text-success',
                    'detail' => null,
                ];
            case 'queued':
            default:
                return [
                    'season' => $season,
                    'statusLabel' => 'On waitlist',
                    'statusClass' => 'text-muted',
                    'detail' => null,
                ];
        }
    }

    /**
     * @return array{season: string, statusLabel: string, statusClass: string, detail: ?string}
     */
    private function renderOffered(string $season, ?string $expiresAt): array
    {
        if (!$expiresAt) {
            return [
                'season' => $season,
                'statusLabel' => 'Offer sent',
                'statusClass' => 'text-warning',
                'detail' => null,
            ];
        }

        if ($this->isPastDeadline($expiresAt)) {
            return [
                'season' => $season,
                'statusLabel' => 'Offer expired',
                'statusClass' => 'text-danger',
                'detail' => null,
            ];
        }

        return [
            'season' => $season,
            'statusLabel' => 'Offer sent',
            'statusClass' => 'text-warning',
            'detail' => 'Expires ' . $this->relativeTime($expiresAt),
        ];
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
