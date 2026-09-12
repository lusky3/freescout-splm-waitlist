# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Sidebar panel now uses FreeScout's native collapsible accordion card
  (matching the WooCommerce module's "Recent Orders" panel and core's
  "Previous Conversations" panel) instead of a plain unstyled block, with
  each entry's status color-coded — green for accepted, orange for a
  pending offer, red for an expired one, muted for on the waitlist.
- Each entry now also shows the player's position (Player/Goalie) next
  to the season, and — except when there's an active, unexpired offer
  — how long they've been on the waitlist (e.g. "On waitlist since Aug
  3"). Requires the companion WP endpoint to expose the new `position`
  and `created_at` fields; degrades gracefully (omits them) against an
  older WP side that doesn't yet.

## [1.0.0] - 2026-09-12

First release.

### Added

- Shows a customer's SportsPress registration-waitlist status — on the
  waitlist, offered a spot (with a relative expiry, or "deadline passed"
  once it's lapsed), or accepted — in the FreeScout conversation sidebar,
  the same way FreeScout's WooCommerce module shows order history.
- Talks to a companion REST endpoint on the SportsPress site
  (`sportspress-league-manager`'s `POST /splm/v1/waitlist/customer-status`)
  over an HMAC-SHA256-signed request (sha256 over `timestamp.body`, a
  ±300s replay window) — no WordPress login involved, just a shared
  secret configured on both sides.
- Settings page (Manage → Settings → SportsPress Waitlist) for the WP
  site URL and shared secret, masked with a show/hide toggle.
- Fails silently on any error — an unreachable WP site, a wrong secret, a
  timeout — so a misconfiguration or outage never breaks the agent's
  conversation page, only shows nothing. Caches a successful lookup for a
  minute so opening a conversation doesn't hit WordPress on every render.
- Verified end-to-end against a real WordPress + SportsPress + WooCommerce
  site and a real FreeScout instance by the companion
  [freescout-sportspress-e2e](https://github.com/lusky3/freescout-sportspress-e2e)
  Docker environment, which runs the whole chain (including a real signed
  HTTP round-trip) on every push and weekly.
- 42 unit tests (PHPUnit), 96%+ line coverage on everything reasonably
  unit-testable — the FreeScout framework-wiring itself is
  integration-tested by the e2e environment above instead of faked.
- CI: lint (PSR-12, `php -l`, `composer validate`), a PHP 7.4/8.2 test
  matrix with an enforced 90% coverage floor, and a weekly Semgrep scan.
- GitHub-native self-update: `latestVersionUrl`/`latestVersionZipUrl` in
  `module.json` let FreeScout's own Modules page detect and install new
  releases without needing Module Manager or any other extra module.

[Unreleased]: https://github.com/lusky3/freescout-splm-waitlist/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/lusky3/freescout-splm-waitlist/releases/tag/v1.0.0
