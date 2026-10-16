# Offing

A shared calendar for the people in your life — partners who see everything, friends who see only what they're tagged in, and clubs/virtual people who don't need accounts at all. Tagline: "What's in the offing."

Full product spec and roadmap: `docs/SPEC.md` — read it before planning any non-trivial feature. This file is a condensed operating reference, not a replacement for it.

## Stack

- **Laravel** (latest) + **Inertia.js + React** (TypeScript, Vite) — `laravel/react-starter-kit` base, Tailwind 4, shadcn/ui.
- **MySQL** in production (20i shared hosting); SQLite is fine for local dev/CI.
- **sabre/vobject** + **spatie/icalendar-generator** for all iCal parsing/generation — never hand-roll RRULE.
- **Laravel Pennant** for feature flags — ship meaty features dark, flag them on progressively.
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

## Where things are

- `docs/SPEC.md` — the full design spec (naming, data model, sharing model, MCP integration, roadmap phases, security/licensing rationale). Roadmap phases (§23) drive GitHub milestones.
- `docs/` → GitHub wiki: one-way mirror, published on push to `main` by `.github/workflows/wiki.yml` (builder: `.github/wiki/build.php`). Stopgap until in-app `/docs` (SPEC §20) renders the same files. `docs/Home.md` = landing, `docs/guide/` = user pages, the rest = Development. Page basenames must be unique (the wiki is flat).
- `.github/deploy/rsync-exclude.txt` — single source of truth for what never leaves CI.
- `app/Models/AuditLog.php`, `app/Models/MagicLink.php` — auth/audit primitives; extend rather than duplicate when adding new tracked actions or token types.
