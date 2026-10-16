# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html):
**major** = breaking changes/migrations required, **minor** = new backward-compatible
functionality, **patch** = fixes.

## [Unreleased]

### Added

- Laravel + Inertia/React application scaffold, with sabre/vobject, spatie/icalendar-generator, Laravel Pennant, and Larastan.
- Magic-link authentication (no passwords, anywhere) with audit logging on every auth event.
- Coming-soon splash page and `offing.uk` branding v0 (coastal placeholder palette).
- GitHub Actions CI (Pint, Pest) and CD (`deploy-staging.yml` on push to `rc`, `deploy-production.yml` on release published), building in CI and shipping over rsync/SSH.
- Design spec (`docs/SPEC.md`) and a condensed operating reference (`CLAUDE.md`).
- `php artisan user:create {email} {name?}` for creating accounts by hand until there's a sign-up flow.
- Sentry error tracking for PHP and the browser — errors only (no tracing, replay or session counting), no personal data, login tokens scrubbed from URLs. Off unless `SENTRY_LARAVEL_DSN` is set.
