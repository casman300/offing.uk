# Offing

A shared calendar for the people in your life — partners who see everything, friends who see only what they're tagged in, and clubs/virtual people who don't need accounts at all. Tagline: "What's in the offing."

Full product spec and roadmap: `docs/SPEC.md` — read it before planning any non-trivial feature. This file is a condensed operating reference, not a replacement for it.

## Stack

- **Laravel** (latest) + **Inertia.js + React** (TypeScript, Vite) — `laravel/react-starter-kit` base, Tailwind 4, shadcn/ui.
- **MySQL** in production (20i shared hosting); SQLite is fine for local dev/CI.
- **sabre/vobject** + **spatie/icalendar-generator** for all iCal parsing/generation — never hand-roll RRULE.
- **Laravel Pennant** for feature flags — ship meaty features dark, flag them on progressively. Every flag extends `App\Features\StagedFeature` (stable `$name`, `$description`, `$stage`), is listed in `App\Features\Features`, and starts at `Rollout::Admins`; promote it by changing `$stage` in a PR. Pennant stores each user's first resolved value, so a stage change only reaches already-checked users after "Reset to default" on `/admin/features`. Admins are `ADMIN_EMAILS` in each host's `.env` — never a DB flag.
- **Pest** for tests, **Pint** for style, **Larastan** for static analysis (`phpstan.neon`).
- Hosting has **no daemons, no queue workers, no Node** — the scheduler (`schedule:run` via cron) drains the database queue; Vite/PWA assets are built in CI and shipped as static files.

## Hard rules (don't relitigate these)

- **No passwords, ever.** Magic link + passkey auth only — there is no `password` column anywhere in the schema.
- **No ads, no tracking, no selling data.** Revenue is subscriptions/cards/affiliate links only, if ever.
- **Tasks/to-dos are a permanent non-goal.** Don't add them even if it seems small.
- **Audit everything auth/share/token-related** from day one — `AuditLog::record()` (see `app/Models/AuditLog.php`), append-only, no `updated_at`.
- **`private` and `busy/free` are independent flags on events** — don't collapse them into one visibility concept.
- Licence is **AGPL-3.0-only** — this was a deliberate call given future paid-hosting plans; don't casually suggest MIT.

## Branching & CI

- `main` = production (protected, releases only). `rc` = staging (auto-deploys on push). Feature branches (`feat/…`, `fix/…`) off `rc`, short-lived.
- `deploy-staging.yml` fires on push to `rc`; `deploy-production.yml` fires on GitHub release `published` — production deploys are a deliberate act, not a merge side-effect.
- Both ship over rsync/SSH to 20i (no Node/Composer required on the host — `vendor/` and built assets are shipped as files). Exclude list lives in `.github/deploy/rsync-exclude.txt`, shared by both workflows — add new dev-only paths there, not inline in the YAML.
- All GitHub Actions are **pinned to commit SHAs** (`uses: owner/repo@<sha> # vX.Y.Z`), not floating tags.
- On 20i, the control-panel PHP version (8.5) only applies to web requests — plain `php` over SSH/cron is an old default (8.0). Deploys find a usable CLI via `deploy/find-php.sh` (ships to the host with the app); never call bare `php` in deploy scripts or cron.
- `.env` lives on the host only, never in CI, never in the repo — DB credentials and `APP_KEY` are set up manually per environment, once.

## Conventions

- Conventional-commit-style prefixes (`feat:`, `fix:`, `chore:`, `security:`) map to `CHANGELOG.md`'s Keep a Changelog categories.
- Semver: major = breaking/migrations required, minor = new backward-compatible functionality, patch = fixes.
- Before committing PHP/TS changes: `vendor/bin/pint`, `vendor/bin/pest`, `npx tsc --noEmit`, `npm run build`. Don't just trust the diff — boot the app (`php artisan serve`) and exercise the actual flow when touching auth, payments, or anything security-relevant; feature tests alone have already missed at least one real bug in this codebase (a mail-rendering bug invisible to `Mail::fake()`).
- Timezones: store UTC + origin IANA TZID, expand recurrence in the origin timezone (sabre does this), render in the viewer's profile timezone.

## Naming — decided, don't relitigate

- The reminder outbox table is `notification_outbox`, **never** `notifications` — `User` already uses Laravel's `Notifiable` trait, and `php artisan notifications:table` creates its own incompatible `notifications` table. If Laravel's built-in notification system gets used for something else later (in-app toasts, etc.), that's the `notifications` table; don't merge it with the outbox. See `docs/SPEC.md` §6.
- `addresses` (postal, per-`person`, for the card/post engine) and the future `places` concept (§23 backlog — named locations + travel buffers) are different things with different owners. Don't generalise them into one "location" table.
- Domain `App\Models\Event` will share a bare name with Laravel's own eventing (`Illuminate\Support\Facades\Event`, the `event()` helper). Not a blocker, just alias or fully-qualify when both are in scope in the same file — most likely in tests using `Event::fake()` for framework events.
- Keep domain "card" (the posted greeting card, §11/§23) out of PHP/TS class names that could collide with `resources/js/components/ui/card.tsx` (the shadcn UI primitive already in use everywhere). Name it something like `PostedCard`/`GreetingCard` when it's built, not bare `Card`.

## Cloud sessions (Claude Code on the web)

The cloud container ships **PHP 8.3**, but `composer.lock` needs **≥ 8.4** — so `composer install` fails and there's no `vendor/`. Don't spend time on PPAs, static PHP builds or getcomposer.org: the egress proxy blocks them (and Debian mirrors, and GitHub zipball downloads). Instead, first thing:

```sh
.claude/cloud-php-setup.sh   # idempotent; ~3 min the first time, seconds after
```

It starts Docker, builds a PHP 8.5 image from `ubuntu:26.04` (archive.ubuntu.com is reachable), installs `dphp` as a wrapper, installs Composer deps on the host from git sources (with a workaround for `phpstan/phpstan`, which only has a zipball), sets up `.env`, and runs `npm run build`. Then run every PHP command through `dphp`: `dphp vendor/bin/pest`, `dphp vendor/bin/pint`, `dphp vendor/bin/phpstan analyse --memory-limit=1G`, `dphp php artisan …`.

- Feature tests that render pages 500 with "Vite manifest not found" / "Unable to locate file in Vite manifest" until you `npm run build` again after adding a page.
- To exercise the app: `docker run -d --network host -v "$PWD:$PWD" -w "$PWD" offing-php php artisan serve --host=127.0.0.1 --port=8123`. Log in by minting a signed `login.consume` URL in tinker (`URL::temporarySignedRoute`, with `APP_URL` set to the served host) — the route is `signed`, so a bare `/login/{id}/{token}` bounces to the login page. Drive it with Playwright (`executablePath: '/opt/pw-browsers/chromium'`).
- `tests/Feature/Reminders/PushTest.php` fails about 1 run in 12 (`hex2bin(): … even length` inside minishlink/web-push with random keys). That's a known flake, not your change.
- Larastan isn't in CI yet and reports existing errors; check only that the files you touched are clean.

## Where things are

- `docs/SPEC.md` — the full design spec (naming, data model, sharing model, MCP integration, roadmap phases, security/licensing rationale). Roadmap phases (§23) drive GitHub milestones.
- `docs/` → GitHub wiki: one-way mirror, published on push to `main` by `.github/workflows/wiki.yml` (builder: `.github/wiki/build.php`). Stopgap until in-app `/docs` (SPEC §20) renders the same files. `docs/Home.md` = landing, `docs/guide/` = user pages, the rest = Development. Page basenames must be unique (the wiki is flat).
- `.github/deploy/rsync-exclude.txt` — single source of truth for what never leaves CI.
- `app/Models/AuditLog.php`, `app/Models/MagicLink.php` — auth/audit primitives; extend rather than duplicate when adding new tracked actions or token types.
