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
- People (#12): add, edit, list, search and soft-delete the people in your life, with Undo and a "Recently deleted" list. Deletes and restores are audited. Behind the `birthday-engine` Pennant flag (off by default).
- Birthdays on the person page (#13): day + month with an optional year. Enter a year or an age (age needs the date); "Turns 43 in 12 days" in the list. 29 February is observed on 28 February in non-leap years.
- Per-user timezone in profile settings, which decides when "today" is.
- `php artisan offing:feature {feature} {email} --on|--off` to flip a feature flag for one user (audited).
- Admin area (first slice of #11): `/admin/features` lists every feature flag with its rollout stage and on/off counts, lets an admin override a flag per user or reset it to its default, and audits every change. Admins are set with `ADMIN_EMAILS` in `.env`; everyone else gets a 404.
- Feature flags now declare a rollout stage in code (off / admins / percentage / everyone). New flags start at admins only; `birthday-engine` is now on for admins by default.
