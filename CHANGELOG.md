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
- Passkeys (#9): sign in with Face ID, Touch ID or a device PIN, from a "Sign in with a passkey" button or the email field's autofill. After an email-link sign-in, Offing offers to set one up ("Not now" is remembered for 30 days on that device). Settings → Passkeys lists, renames and removes them. Adding a passkey sends a security email; every add, rename, removal, sign-in and rejected attempt is audited. Email links keep working as the fallback and recovery path. Passkeys belong to each environment's exact host (from `APP_URL`).
- Admin area (first slice of #11): `/admin/features` lists every feature flag with its rollout stage and on/off counts, lets an admin override a flag per user or reset it to its default, and audits every change. Admins are set with `ADMIN_EMAILS` in `.env`; everyone else gets a 404.
- Feature flags now declare a rollout stage in code (off / admins / percentage / everyone). New flags start at admins only; `birthday-engine` is now on for admins by default.
- vCard import (#14): upload a `.vcf` contacts export (e.g. iPhone → Contacts → Lists → All Contacts → Export), review it in sections (new, already in Offing, different birthday, duplicates in the file, no birthday), then import. Matches fill blanks only; conflicting birthdays are kept unless you choose the contact's date; notes are opt-in. Only names, birthdays and (optionally) notes are kept — never phones, emails, addresses or photos — and abandoned reviews are pruned after 24 hours. "Undo this import" removes what it added and reverts what it filled, leaving anything you've changed since. Behind the `vcard-import` flag (admins first).
- Birthday reminders by email (#17, #18): Settings → Reminders (off until you switch it on) with "the day before" and "on the day" slots, each on/off with a time in 15-minute steps (defaults 20:00 / 08:00, your timezone). A scanner queues reminders once due — up to 2 hours late after a cron gap, never later — and an outbox drainer sends one email per send time listing everyone, retrying at +5/15/30/60 minutes before giving up. Emails never include notes and carry a signed one-click unsubscribe (and `List-Unsubscribe`). "Send me a test reminder" checks delivery. Behind the `reminders` flag (admins first).
- Admin → Outbox: what reminders were queued, sent, skipped or failed and why — dates only, never people's names.
- Shipshape (spec §25): recurring household chores, on lists that are just yours or shared with a crew. Chores repeat either from when they were done (cleaning) or on set dates (bins, renewals — with an optional heads-up weeks before), and can be left as "needs a date". The board sorts everything you can see by urgency (overdue, today, next 7 days, later); tap to tick with a 10-second undo, or log who did it, when (backdating allowed) or skip it. Chores can be for anyone, one person or a rota that moves on each completion — covering for someone keeps the order. New lists offer 26 starter suggestions, staggered so day one isn't the deep clean. Captains can make a crew list "captains only" to edit; everyone can still tick. Behind the `shipshape` flag (admins first).
- Dashboard → "In the offing": today's and overdue chores (tickable there) and birthdays in the next two weeks, each shown only when its feature is on for you.
- Crews (spec §7): `/crew` is the sharing area — start a crew (you're its captain), invite people by email as crew or captain (they accept or decline from their own Crew page; the captain sees the same message whether or not that email has an account), and add passengers: people without an account, like children, that crewmates act for — either from scratch, or picked from your own People (the crew gets a copy with their name, and their birthday only if you tick it; never your notes). Captains change roles and remove members; every crew always keeps a captain (the last one can't leave or be demoted). Removing a passenger keeps them as a former passenger you can re-add. Deleting a crew needs its name typed, moves its passengers into the captain's own People, and is permanent after 30 days. Every change is audited. Behind the `crews` flag (admins first).
- People can now be owned by a user or a crew (a migration converts every existing person, unchanged, to user-owned). Deleting an account still deletes its People, and if it was a crew's last captain the longest-serving crewmate takes over.
- Admin → Users: add an account (email, name, timezone), switch features on for them in the same step, and optionally email a first sign-in link — worded as a welcome ("Charlie has set up an Offing account for you") for someone who has never signed in. The list shows each user's last sign-in, passkeys and which features are on, with a "Send sign-in link" button. Same rules as `artisan user:create`; every step is audited.
- Cron heartbeat (part of #7): the scheduler pings `HEALTHCHECK_URL` every minute when set, so healthchecks.io can alert if cron stops.
- Push notifications for reminders: Settings → Reminders → "Turn on notifications on this device" (iPhone: after Add to Home Screen, with a short how-to). Delivery is "Notifications, with email as backup", "Email only" or "Both"; email takes over when there are no devices, push isn't set up on the host, or the push service keeps failing. Devices are listed with "Send a test notification" and Remove. Needs VAPID keys from `php artisan push:keys` in `.env`; `php artisan push:check` reports readiness, and Admin → Outbox warns if push is broken.
- Installable web app: the manifest is now linked with root scope and placeholder icons, so Add to Home Screen opens Offing as a standalone app.
- `docs/` is published to the GitHub wiki on every push to `main`, with a user-facing Home page and a first guide page (signing in). The app's Repository and Documentation links now point at this repo and its wiki instead of the Laravel starter kit.
- AI assistants (first slice of spec §12, "Connect Claude"): link your own AI assistant to Offing over OAuth 2.1 and MCP. Tested with Claude (web, desktop, phone, Claude Code); ChatGPT and other MCP clients should work too. The assistant registers itself, you sign in and choose what it may do (read only / add new, the default / change) and whether it sees private events. Settings → AI assistants shows the connector address with steps for Claude, ChatGPT and others, and lists connected apps, which you can change or disconnect at once. Tools: `get_briefing` returns your birthdays, people, crews and chores in one call; `find` searches by text, date range, person, crew or chore (with log entry ids); `save_person` adds people to your own address book (changing them needs "change" access); `log_chore` ticks off or skips a Shipshape chore through the same logbook as the app, with retries not double-logging (undo needs "change" access). Read-only connections aren't offered the write tools at all. Every grant, change, revoke and tool call is audited, without content. Behind the `connect-claude` flag. New hosts need `php artisan connect-claude:keys` pasted into `.env`, and a migration.

### Changed

- Frontend URLs come from Laravel Wayfinder's generated, typed route helpers instead of Ziggy. A mistyped route name is now a `tsc` error, and pages no longer carry the app's full route list (admin routes included) in their HTML. CI now type-checks (`tsc --noEmit`).
- The calendar feed is generated with sabre/vobject (already used for parsing) instead of spatie/icalendar-generator. Names containing double quotes are now escaped correctly per RFC 5545 (previously `\"`).

### Removed

- `spatie/icalendar-generator`, `tightenco/ziggy` and `laravel/sail` (unused). `laravel/tinker` is dev-only and no longer ships to the hosts.

### Security

- Updated vulnerable transitive dependencies flagged by `composer audit`: `league/commonmark` 2.10.4, `guzzlehttp/guzzle` 7.16.0, `laravel/framework` 12.70.0, `symfony/http-foundation` 7.4.21, `league/flysystem` 3.36.0, `symfony/yaml` 8.1.9 (dev). Lockfile-only; no constraint changes.
