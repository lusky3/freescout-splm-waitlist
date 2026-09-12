# FreeScout SPLM Waitlist

[![Tests](https://github.com/lusky3/freescout-splm-waitlist/actions/workflows/tests.yml/badge.svg)](https://github.com/lusky3/freescout-splm-waitlist/actions/workflows/tests.yml)
[![Lint](https://github.com/lusky3/freescout-splm-waitlist/actions/workflows/lint.yml/badge.svg)](https://github.com/lusky3/freescout-splm-waitlist/actions/workflows/lint.yml)
[![Semgrep](https://github.com/lusky3/freescout-splm-waitlist/actions/workflows/semgrep.yml/badge.svg)](https://github.com/lusky3/freescout-splm-waitlist/actions/workflows/semgrep.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Shows a customer's SportsPress registration-waitlist status — on the
waitlist, offered a spot, or accepted — in the FreeScout conversation
sidebar. Companion to the REST endpoint added to
`sportspress-league-manager` in the `SportsPress-Admin-Tools` repo (see
`.superpowers/notes/specs/2026-09-11-freescout-splm-waitlist-design.md`
there).

## Requirements

- FreeScout, self-hosted
- PHP 7.4 or 8.0+
- Network access from the FreeScout host to the SportsPress WP site

## Install

Clone into FreeScout's `Modules/` directory as `SplmWaitlist`, restart
the container (this image registers modules at startup), then **enable**
it under Manage → Modules and **configure** it under Manage → Settings →
SportsPress Waitlist — the WP site URL and shared secret must match the
value configured on the WP side.

### Updating

Updates work the same way no matter how you install this. FreeScout core
checks `latestVersionUrl` for every non-official module on each load of
**Manage → Modules**, and shows an **Update Now** button when
`module.json`'s declared version is newer than what's installed — that's
`App\Module::updateModule()` downloading and extracting
`latestVersionZipUrl` in place, verified against the vendored FreeScout
core rather than inferred. No extra module, no license key: any
third-party module carrying those two `module.json` fields updates this
way. Releases are cut by pushing a `vX.Y.Z` tag (see `.github/workflows/release.yml`);
each one is built, verified, and published as a GitHub release carrying
both the zip and the `version.json` that URL points at.

## Development

See `docker/` for a local FreeScout instance to test against:
`scripts/setup-dev-env.sh`.
