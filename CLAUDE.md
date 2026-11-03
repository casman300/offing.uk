# Offing

A shared calendar for the people in your life — partners who see everything, friends who see only what they're tagged in, and clubs/virtual people who don't need accounts at all. Tagline: "What's in the offing."

Full product spec and roadmap: `docs/SPEC.md` — read it before planning any non-trivial feature. This file is a condensed operating reference, not a replacement for it.

## Stack

- **Laravel** (latest) + **Inertia.js + React** (TypeScript, Vite) — `laravel/react-starter-kit` base, Tailwind 4, shadcn/ui.
- **MySQL** in production (20i shared hosting); SQLite is fine for local dev/CI.
- **sabre/vobject** for all iCal/vCard parsing *and* generation (one library both ways, so the feed and its tests can't disagree) — never hand-roll RRULE.
- **Laravel Wayfinder** for frontend URLs: typed route helpers generated at build time (`import people from '@/routes/people'; people.show.url(id)`), never hard-coded paths. Generated files (`resources/js/{routes,actions,wayfinder}`) are gitignored — `php artisan wayfinder:generate` (or any Vite build) recreates them, so the CI build must run after `composer install`.
- **Keep the dependency list short.** Prefer what the Laravel starter kit ships over a third-party package; don't add a library for what a few lines of our own code (or an existing dependency) already covers.
- **Laravel Pennant** for feature flags — ship meaty features dark, flag them on progressively. Every flag extends `App\Features\StagedFeature` (stable `$name`, `$description`, `$stage`), is listed in `App\Features\Features`, and declares `Rollout::Admins` as its starting stage. Stages nest: Off ⊂ Admins ⊂ Beta ⊂ Percentage ⊂ Everyone. Promote a flag on `/admin/features` (stored in `feature_stages`, audited, per host); the class's `$stage` is only the default until then. Pennant's `features` table holds **explicit per-user overrides only** (`App\Features\OverridesOnlyDriver` never stores a resolved value), so a stage change reaches everyone on their next request. Admins are `ADMIN_EMAILS` in each host's `.env` — never a DB flag; beta testers are `users.is_beta_tester`, ticked on `/admin/users`, and get features only.
- **`laravel/mcp` + Passport** for "Connect Claude" (SPEC §12): one OAuth 2.1 MCP server at `/mcp` serves the Claude Directory listing *and* self-hosters' custom connectors — no pasted tokens, no token-in-URL. Keep tools few and broad (`get_briefing` first), every tool annotated with `title` + `readOnlyHint`/`destructiveHint`.
- **Pest** for tests, **Pint** for style, **Larastan** for static analysis (`phpstan.neon`).
- Hosting has **no daemons, no queue workers, no Node** — the scheduler (`schedule:run` via cron) drains the database queue; Vite/PWA assets are built in CI and shipped as static files.

## Hard rules (don't relitigate these)

- **No passwords, ever.** Magic link + passkey auth only — there is no `password` column anywhere in the schema.
- **No ads, no tracking, no selling data.** Revenue is subscriptions/cards/affiliate links only, if ever.
- **Tasks/to-dos are a permanent non-goal.** Don't add them even if it seems small. The one carve-out is **Shipshape household chores** (SPEC §1/§25): every chore *repeats* — no one-offs, subtasks, priorities or due times. If a change only makes sense for something that happens once, it's task creep: say no. Generic shared lists aren't approved either.
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
- **Every commit needs a `Signed-off-by:` trailer** — the `dco` workflow (`.github/workflows/dco.yml`) fails any PR into `rc`/`main` with an unsigned commit (see CONTRIBUTING.md). Always `git commit -s`; Claude sessions sign off as their own git identity (`Claude <noreply@anthropic.com>`), never as the user. Forgot? On your own branch, `git rebase --signoff origin/rc` then `git push --force-with-lease` — content unchanged, only trailers added.
- Semver: major = breaking/migrations required, minor = new backward-compatible functionality, patch = fixes.
- Before committing PHP/TS changes: `vendor/bin/pint`, `vendor/bin/phpstan analyse` (level 7, must stay at 0 — no baseline), `vendor/bin/pest`, `npx tsc --noEmit`, `npm run build`. Don't just trust the diff — boot the app (`php artisan serve`) and exercise the actual flow when touching auth, payments, or anything security-relevant; feature tests alone have already missed at least one real bug in this codebase (a mail-rendering bug invisible to `Mail::fake()`).
- Timezones: store UTC + origin IANA TZID, expand recurrence in the origin timezone (sabre does this), render in the viewer's profile timezone.

## Claude Code cloud sessions: getting PHP 8.5

The cloud container ships PHP 8.3, but the lock file needs ≥ 8.4 (Symfony 8). Don't try apt PPAs, Debian mirrors, static-PHP downloads or getcomposer.org (the network policy blocks them all, and GitHub zipball downloads too). First thing in a session, run:

```sh
.claude/cloud-php-setup.sh   # idempotent: ~3 min the first time, seconds after
```

It starts Docker, builds a PHP 8.5 image (`offing-php`, from `ubuntu:26.04`, whose archive is reachable), installs a `dphp` wrapper, installs Composer and npm deps (again whenever a lockfile changes, e.g. after merging `rc`), sets up `.env` and builds the frontend. Then:

- Run PHP through `dphp`: `dphp vendor/bin/pest --parallel`, `dphp vendor/bin/pint`, `dphp vendor/bin/phpstan analyse --memory-limit=1G`, `dphp php artisan …`. Pass `</dev/null` from non-interactive scripts, or it waits on stdin.
- Rebuild the frontend with `PATH=/tmp/offing-cloud/bin:$PATH npm run build` (the build runs `php artisan wayfinder:generate`, and that path holds a `php` shim). Feature tests 500 with "Unable to locate file in Vite manifest" until you rebuild after adding a page.
- **Boot the app** for real flows: `touch database/database.sqlite`, `dphp php artisan migrate`, `dphp php artisan user:create you@example.com You`, `dphp php artisan offing:feature <flag> you@example.com --on`, then serve with
  `docker run -d --rm --name offing-serve --network host -v "$PWD:$PWD" -w "$PWD" offing-php php artisan serve --host=127.0.0.1 --port=8000`.
  Set `APP_URL=http://localhost:8000`. Magic links are queued: run `dphp php artisan queue:work --stop-when-empty`, then find the link in `storage/logs/laravel.log` (`MAIL_MAILER=log`; unescape `&amp;`) and press **Continue** on the page it opens. Drive it with Playwright's preinstalled Chromium (`/opt/pw-browsers/chromium-1194/chrome-linux/chrome`). Repeated logins hit the rate limiter — `dphp php artisan cache:clear`. `docker kill offing-serve` when done.

If the script breaks, the manual recipe is what it automates: Composer runs **on the host** with `--ignore-platform-req=php --prefer-source --no-scripts` (git clones work; zipballs don't). `phpstan/phpstan` has no git source in the lock, so its locked commit is shallow-fetched, zipped and the lock pointed at the zip for that one install (never commit that). Afterwards the checkouts' `.git` dirs are deleted, or `sebastian/version` prints `proc_open()` warnings into HTTP responses; then `php artisan package:discover`.

## Naming — decided, don't relitigate

- The reminder outbox table is `notification_outbox`, **never** `notifications` — `User` already uses Laravel's `Notifiable` trait, and `php artisan notifications:table` creates its own incompatible `notifications` table. If Laravel's built-in notification system gets used for something else later (in-app toasts, etc.), that's the `notifications` table; don't merge it with the outbox. See `docs/SPEC.md` §6.
- `addresses` (postal, per-`person`, for the card/post engine) and the future `places` concept (§23 backlog — named locations + travel buffers) are different things with different owners. Don't generalise them into one "location" table.
- Domain `App\Models\Event` will share a bare name with Laravel's own eventing (`Illuminate\Support\Facades\Event`, the `event()` helper). Not a blocker, just alias or fully-qualify when both are in scope in the same file — most likely in tests using `Event::fake()` for framework events.
- **Crew** is the one sharing primitive for jointly-owned things (SPEC §7) — roles Captain / Crew / Passenger. It replaced the old `groups` idea; don't reintroduce a separate groups table. Personal visibility `shares` (Partner / Full read / Tagged only) are a different, one-way concept and stay separate. Things are shared by **who owns them** (`owner` morph: user | crew), never per-item share lists.
- **People are owned by a user or a crew** (`owner` morph). User-owned people are a private address book; adding one to a crew shares the name only. Membership changes never delete a person. People link to accounts only by accepted invite or confirmed crewmate suggestion — never by matching phone numbers/emails.
- Keep domain "card" (the posted greeting card, §11/§23) out of PHP/TS class names that could collide with `resources/js/components/ui/card.tsx` (the shadcn UI primitive already in use everywhere). Name it something like `PostedCard`/`GreetingCard` when it's built, not bare `Card`.

## Where things are

- `docs/SPEC.md` — the full design spec (naming, data model, sharing model, MCP integration, roadmap phases, security/licensing rationale). Roadmap phases (§23) drive GitHub milestones.
- `docs/` → GitHub wiki: one-way mirror, published on push to `main` by `.github/workflows/wiki.yml` (builder: `.github/wiki/build.php`). Stopgap until in-app `/docs` (SPEC §20) renders the same files. `docs/Home.md` = landing, `docs/guide/` = user pages, the rest = Development. Page basenames must be unique (the wiki is flat).
- `.github/deploy/rsync-exclude.txt` — single source of truth for what never leaves CI.
- `app/Models/AuditLog.php`, `app/Models/MagicLink.php` — auth/audit primitives; extend rather than duplicate when adding new tracked actions or token types.
