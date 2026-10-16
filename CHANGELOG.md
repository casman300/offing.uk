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
- Calendar feeds (#15): Settings → Calendar feeds mints private ICS subscribe links (up to 3, each named, shown once, revocable, audited) with "last fetched" times. The feed carries each dated birthday as a yearly all-day event with 20:00-day-before and 08:00-on-the-day alerts. 29 Feb birthdays fall on 28 Feb in non-leap years. ETag/304 for cheap polling. Not behind a feature flag.
- Admin area (first slice of #11): `/admin/features` lists every feature flag with its rollout stage and on/off counts, lets an admin override a flag per user or reset it to its default, and audits every change. Admins are set with `ADMIN_EMAILS` in `.env`; everyone else gets a 404.
- Feature flags now declare a rollout stage in code (off / admins / percentage / everyone). New flags start at admins only; `birthday-engine` is now on for admins by default.
- vCard import (#14): upload a `.vcf` contacts export (e.g. iPhone → Contacts → Lists → All Contacts → Export), review it in sections (new, already in Offing, different birthday, duplicates in the file, no birthday), then import. Matches fill blanks only; conflicting birthdays are kept unless you choose the contact's date; notes are opt-in. Only names, birthdays and (optionally) notes are kept — never phones, emails, addresses or photos — and abandoned reviews are pruned after 24 hours. "Undo this import" removes what it added and reverts what it filled, leaving anything you've changed since. Behind the `vcard-import` flag (admins first).
- Birthday reminders by email (#17, #18): Settings → Reminders (off until you switch it on) with "the day before" and "on the day" slots, each on/off with a time in 15-minute steps (defaults 20:00 / 08:00, your timezone). A scanner queues reminders once due — up to 2 hours late after a cron gap, never later — and an outbox drainer sends one email per send time listing everyone, retrying at +5/15/30/60 minutes before giving up. Emails never include notes and carry a signed one-click unsubscribe (and `List-Unsubscribe`). "Send me a test reminder" checks delivery. Behind the `reminders` flag (admins first).
- Admin → Outbox: what reminders were queued, sent, skipped or failed and why — dates only, never people's names.
- Cron heartbeat (part of #7): the scheduler pings `HEALTHCHECK_URL` every minute when set, so healthchecks.io can alert if cron stops.
- Barometer (#35, first slice of #34): a private anxiety log modelled on the CBT anxiety-tracking form. Take a reading with just a time and a 0–10 rating, or fill in situation, thoughts, physical sensations, what you did, what you said to yourself and a second rating (now or later). Readings are listed newest first by day, and export as a printable PDF laid out like the paper form or as a CSV, over a date range of up to a year, with any column except the first rating left out. Free text is encrypted at rest; deletes are permanent; exports and deletes are audited without content. Never shared with anyone. Behind the `barometer` flag (admins first; switch it on per person from `/admin/features`).
- `docs/` is published to the GitHub wiki on every push to `main`, with a user-facing Home page and a first guide page (signing in). The app's Repository and Documentation links now point at this repo and its wiki instead of the Laravel starter kit.
