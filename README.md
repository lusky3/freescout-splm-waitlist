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
the container (this image registers modules at startup), then enable it
under Manage → Settings → SportsPress Waitlist and set the WP site URL
and shared secret to match the value configured on the WP side.

## Development

See `docker/` for a local FreeScout instance to test against:
`scripts/setup-dev-env.sh`.
