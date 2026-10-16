# Offing — Design Spec & MVP Plan

*A shared calendar for the people in your life. Working title — see §2.*

**Status:** Draft v1 · July 2026
**Audience at launch:** Owner + household + friends (dogfood first)
**Hosting:** 20i shared hosting (PHP + MySQL + cron; no daemons, no queue workers)

---

## 1. What this is

A web-based, PWA-installable calendar whose core reason to exist — the thing Google/Apple Calendar do badly — is the **sharing model**: partners who see everything, friends who see only what they're tagged in, and "virtual people" and groups (the D&D club, the craft group) who don't need accounts at all. Second priority is **AI access via MCP** with granular permissions. Birthdays and a card/gift reminder engine come next, with diary layers (Spotify, fitness) as a future delight.

Priorities, in order (from discovery):

1. Sharing model (partner / tagged friends / groups / virtual people)
2. AI access via MCP (read · write-new · edit scopes)
3. Birthdays + card/gift reminder engine
4. Diary layers (past events, Spotify, fitness)

Non-goals for MVP: public signup, monetisation, public "book time with me" page, native apps, Betty chatbot. **Permanent non-goal: tasks/to-dos** — deliberate scope line; Reminders/Todoist do it well and calendar apps die of task creep.

---

## 2. Naming & branding

Nautical family alongside Sea Hosting and Coastal Bows. Casual, fun, slightly silly. Betty the gull migrates over from the map project eventually — as a daily digest voice and later a chatbot ("Betty's Morning Squawk").

**Decided name: Offing.**

An offing is the stretch of sea visible from shore but not yet arrived at port — and *"something in the offing"* already means "coming up soon" in everyday English. Unlike most nautical brand names, this one describes the product directly: a calendar shows you what's coming. It has survived mainly as a fixed idiom, so the standalone word is rare today — genuinely uncommon without feeling invented. Tagline: **"What's in the offing."** Product name is the bare word **Offing**, not "The Offing" (see screening below).

**Screening history (not full legal clearance — see below):**
- ❌ **Shoal** — ruled out. Live Companies House-registered UK fintech (savings app), App Store/Play/Instagram presence.
- ❌ **Buoy** — ruled out. Two independent live USPTO software trademarks (Buoy Health, The Buoy App LLC) in the exact class 009 software category.
- ❌ **Moorings** — ruled out. 50+ year global yacht charter brand, KKR-backed.
- ❌ **Sea Chart** — ruled out. "Chart" is both the literal generic term for what marine-navigation apps sell (SEAiq, SeaNav, NV Charts, OpenSeaMap) and a heavily overloaded generic tech word (charting libraries, dashboards) — descriptive on two fronts, weak as a trademark and for SEO.
- ⚠️ **Tideline** — screened clean, but dropped for **discoverability**: "tide calendar"/"tide chart" is a dense, established app category (Tide Charts, Tides PRO, MyTides, NOAA Tide Chart, Tide Alert, an Apple Design Award finalist) that any tide-rooted name inherits as SEO competition.
- ⚠️ **Neap** — clean, kept only as a fallback; etymology ("scanty, without power") undersells the product.
- ⚠️ **Rutter** — lovely meaning (a mariner's handbook of sailing directions, tides and landmarks — literally a shared family record of time and place), but collides with **Rutter's**, a well-known US convenience store chain, and Rutter Group, a legal publisher. Less known in the UK, per discussion, but a live consumer brand regardless.
- ✅ **Offing** — no software/app trademark hits and dodges both the "tide" and "chart" SEO categories entirely. One soft collision to know about: **"The Offing"** (with "the") is a real, moderately established literary magazine (est. 2015, ~13K followers) — reason to keep the product name as bare **Offing**, not "The Offing". A small Northern Ireland coffee shop uses "Offing Coffee" — negligible risk.

**Domain plan.** `offing.uk` and `offing.co.uk` show no live site — both look genuinely available, and short: five letters plus a two-letter TLD. **Recommendation: register `offing.uk` as primary** via 20i (registration price = renewal price, no hidden fees), with `offing.co.uk` alongside only if cheap, redirecting in. Skip `.app` (Google-run, pricier — mandatory SSL puts it well above `.uk` pricing) and `.day` (per discussion, doesn't fit the 20i setup well) — `.uk` is short, cheap, and fits a UK-built, UK-hosted project better than a generic gTLD anyway.

**Before spending money:** run `offing.uk`/`.co.uk` through 20i's own domain search to confirm live availability (the checks above are search-engine screening, not a registrar or trademark-office query), and a quick pass through the UK IPO trademark search given "The Offing" magazine exists, even though the category (literary publishing vs. software) is unrelated. Branding assets (logo, palette) are a Phase 1 side-quest — a simple wordmark + one accent colour is enough to ship.

---

## 3. Design principles (migrated from the map app)

1. **URLs are the interface.** Everything meaningful has a clean, hackable URL: `/charlie`, `/charlie/2026/07`, `/dnd-club`, `/e/{event-slug}`. Private things get unguessable token URLs, not logins.
2. **No passwords.** Magic links + passkeys only. There is no password column in the database, ever.
3. **Long sessions.** Rolling 90-day sessions. Logging in is rare; being logged out is an event, not a routine.
4. **Session & device management from day one.** Users (and admin) can see every active session/device and revoke any of them.
5. **Log everything from the start.** Append-only `audit_log` for auth events, admin actions, shares granted/revoked, token usage, feed fetches, event changes. Cheap now, priceless later.
6. **Casual, fun tone.** Copy sounds like a person. Error pages have personality. Future: silly seasonal events packs, Betty.
7. **Colour scheme is a user setting with per-device override.** Stored globally in the user profile; any device can override locally (same settings store pattern for future preferences).
8. **No adverts. Ever.** A hard product principle, not a pricing choice: no ads, no ad trackers, no selling or sharing user data. Revenue comes from subscriptions, card posting, and clearly-labelled affiliate links on gift suggestions the user asked for — nothing else. Corollary: analytics, if any, are privacy-first and self-hosted (e.g. Plausible/Umami) or simply absent.
9. **Family resemblance.** Shared visual language with the map app — same type scale philosophy, same "friendly but competent" register.

---

## 4. Recommended stack (with justification)

### Framework: **Laravel** (latest LTS-ish release at build time)

You asked for a recommendation. Laravel wins because almost every hard requirement here is a Laravel batteries-included feature rather than something to build:

- **Sharing/permissions** → Policies & Gates map cleanly onto partner/tagged/private rules.
- **MCP tokens with scopes** → Sanctum personal access tokens *are* scoped bearer tokens; the three MCP levels become token abilities (`calendar:read`, `calendar:write-new`, `calendar:edit`).
- **Email reminders on shared hosting** → Laravel's scheduler runs off a single cron entry (`php artisan schedule:run` every minute); queued mail uses the **database queue driver** drained by the scheduler — no daemon needed.
- **Migrations, seeding, testing, mail, notifications, validation** → all free.
- **Audit logging** → model observers + one package or ~50 lines of code.

Slim/vanilla would mean hand-rolling auth, tokens, scheduling, ORM and mail — precisely the risky parts. Laravel's weight is irrelevant at household scale, and 20i shared hosting runs it fine (set docroot to `/public`, or deploy with the standard shared-hosting layout).

### Frontend: **Inertia.js + React (Vite)**

Recommendation, given your existing React/Vite experience and a calendar being an interaction-heavy UI:

- **Shared-hosting note:** React never runs on the server. Vite compiles it to static JS/CSS in CI; 20i only serves those files. No Node.js on the host, ever.
- Inertia gives SPA-feel pages served by Laravel routes — **no separate API layer to build/secure for the web app** (the JSON API exists only where MCP/feeds need it). One repo, one deploy, one auth/session system.
- React for the calendar views. Start with **FullCalendar** (month/week/agenda, drag-to-create, recurring display) and plan to replace with a custom view when the design language matures. Don't hand-build a calendar grid in the MVP.
- PWA via `vite-plugin-pwa`: manifest, icons, service worker with an offline shell + cached "next 30 days" agenda.

### iCal engine: **sabre/vobject** (parse/expand) + **spatie/icalendar-generator** (emit)

Never hand-roll RRULE. sabre/vobject is the reference PHP implementation (it powers Nextcloud) and handles recurrence expansion, EXDATE, and VTIMEZONE correctly.

### Database: MySQL (20i-provided). See §16 for scale.

---

## 5. iCal fidelity — the recommended line

**"Interop-honest, storage-normalised, round-trip-safe."** Concretely:

- **Internally**, events live in a normalised schema covering the common 95%: start/end with original TZID, all-day flag, RRULE string, EXDATEs, summary/description/location, UID, sequence.
- **Recurrence expansion is always delegated to sabre/vobject**, so *reading* even exotic RRULEs works at near-full fidelity for free.
- **The creation UI only offers common patterns** (daily/weekly/monthly/yearly, interval, by-weekday, ends after/on). No "last Thursday of every 3rd month except December" builder.
- **Imported events keep their raw VEVENT blob** in a `raw_ical` column. On export/feed, unknown properties (X-props, ATTACH, etc.) are re-emitted from the blob so we never silently destroy data we didn't understand. This is the round-trip-safe part, and it's what most "iCal-compliant" apps get wrong.
- Feeds and exports emit valid RFC 5545 (correct folding, escaping, UTF-8, METHOD:PUBLISH, VTIMEZONE where needed) — validated in CI against a fixture suite.

---

## 6. Data model (core tables)

```
users            id, name, email, timezone, settings(json: colour_scheme…), created_at
user_devices     id, user_id, name, push_subscription(json,null), settings_override(json), last_seen_at
sessions         Laravel default + device link; visible/revocable in UI
passkeys         id, user_id, credential_id, public_key, …(WebAuthn)
magic_links      id, user_id, token_hash, expires_at, used_at

people           id, owner_user_id, name, linked_user_id(null),        ← virtual people; link = upgrade path
                 birth_day, birth_month, birth_year(null), notes
addresses        id, person_id, line1, line2, city, region, postcode,  ← postal address for the card/post engine
                 country(iso2), notes                                    (§11); deliberately its own table, not
                                                                          columns on `people` — see notes below
groups           id, owner_user_id, name, slug                          ← first-class (D&D club, craft group)
group_members    group_id, person_id

calendars        id, owner(morph: user|group), name, colour, slug
events           id, calendar_id, uid, title, description, location,
                 starts_at(utc), ends_at(utc), tzid, all_day,
                 rrule(null), exdates(json), visibility(normal|private),
                 transparency(busy|free),                                ← maps to iCal TRANSP
                 source(local|feed), feed_id(null), raw_ical(null),
                 sequence, created_by, updated_at
event_tags       event_id, person_id                                    ← “friends see what they’re tagged in”
occurrences      event_id, starts_at, ends_at                           ← materialised cache, next 12 months,
                                                                          rebuilt by cron; used by reminder scan
                                                                          and fast range queries

shares           id, grantor_user_id, grantee_user_id,
                 level(partner|full_read|tagged_only)
feed_tokens      id, subject(morph: user|person|group|share), token_hash,
                 scope(full|tagged|group), revoked_at                   ← outbound secret ICS URLs
feed_subscriptions id, user_id, url, name, colour, etag, last_hash,
                 last_fetched_at, status                                ← inbound external ICS

reminder_rules   id, user_id, applies_to(morph: person|category|event),
                 kind(notify|card_post), offset_def(json), channel(email|push)
notification_outbox id, user_id, channel, payload, send_at, sent_at     ← drained by cron; named to avoid colliding
                                                                          with Laravel's own `notifications` table/
                                                                          Notifiable trait (already used on `User`)

api_tokens       Sanctum table; abilities = [read | write-new | edit]
audit_log        id, actor(morph), action, subject(morph), meta(json), ip, created_at  ← append-only
```

Notes:
- **Virtual → real upgrade keeps history** because everything references `people.id`; linking a `user_id` later changes nothing downstream.
- **Groups are first-class** despite personal-subscription monetisation — they cost one table now and unlock club feeds immediately.
- **Birthdays are not events.** They're attributes of `people`; a generator projects them into the calendar/feeds/reminders. Year optional (unknown-year birthdays show no age).
- **`addresses` (postal) and the future `places` concept (§23 backlog — named locations + travel buffers, e.g. Home) are deliberately separate and must stay that way.** An address is who-to-post-a-card-to, attached to a `person`; a place is calendar-event geography, attached to a `user`/`calendar`. Don't generalise these into one "location" table — they solve different problems for different owners.
- **`notification_outbox` is named to avoid colliding with Laravel's own notifications system.** `User` already uses the `Notifiable` trait; Laravel's own database-channel notifications (`php artisan notifications:table`) would otherwise create a same-named `notifications` table with an incompatible schema. If Laravel's built-in notifications are ever used for something else (e.g. in-app toasts), that's a separate `notifications` table — don't merge it with the outbox.

---

## 7. Sharing & permissions

| Level | Sees | Can edit | Typical use |
|---|---|---|---|
| **Partner** | Everything except `private` events | Everything except `private` | Spouse |
| **Full read** | Everything except `private` | Nothing | Close family |
| **Tagged only** | Events where their person is tagged | Nothing (MVP) | Friends |
| **Feed token** | Scope of the token (tagged / group / full) | n/a (ICS is read-only) | Virtual people, clubs |

- `private` (visibility) and `busy/free` (transparency, = iCal TRANSP) are **independent flags on every event**. For a Partner: *private + busy* renders as a detail-free "Busy" block (the "pick up flowers" reminder); *private + free* doesn't render at all (the online gift-shopping session). Non-private events use busy/free for availability display as normal. Everyone below Partner level never sees private events either way.
- Tagging someone with a linked account makes the event appear in *their* app; tagging a virtual person surfaces it in their feed URL.
- All grants/revocations hit `audit_log`.

## 8. Virtual people, groups & feeds (MVP consumption = feed-only)

- Creating a person or group mints a revocable **secret ICS URL** (`/feed/{token}.ics`) they subscribe to in Apple/Google Calendar. HTTP caching headers (ETag) so calendar apps poll cheaply.
- Phase 2: lightweight tokenised **web view** (agenda page, no account) at `/g/{slug}?k={token}`.
- Phase 2/3: **upgrade flow** — invite a virtual person; they get magic link → passkey → their tagged history is already there.

## 9. Birthdays

- Add from a person's page: day + month required, year optional. Shows age when year known.
- Projected as all-day yearly occurrences into views, feeds and the reminder scanner.
- Default reminder rule (user-editable): **push/email at 20:00 the day before + 08:00 on the day**.
- Per-person overrides for the card/gift tier (see §11).
- Import: Phase 2 — **vCard (.vcf) upload** covers "import from Apple Contacts" (export all contacts from iPhone → AirDrop/upload the .vcf; parse `BDAY`, match/merge into `people`). No Apple API needed. Also parse the `ADR` field into the person's `addresses` row (§6) where present — same import, no extra step for the user.
- **Addresses are private to the owning user, same as gift notes (§23 backlog).** Postal addresses never appear in shares, feeds, or group views — a partner/tagged-friend/feed-token viewer sees the person tagged on an event, never their address. This is data *about* someone who likely never consented to being in the app.

## 10. Timezones

- Store `starts_at`/`ends_at` in **UTC** plus the **original IANA TZID** the event was created in.
- Recurring events expand **in their origin timezone** (sabre does this), then convert — this is the only DST-correct approach ("every Tuesday 19:00 London" stays 19:00 across BST/GMT).
- Render in the **viewer's** profile timezone (per-device override allowed). Event page shows origin TZ when it differs: *"7pm your time (8pm CET)"*.
- **All-day events are dates, not times.** They never shift across timezones.
- Feeds emit UTC or VTIMEZONE-qualified local times per RFC 5545.

## 11. Reminders (cron architecture)

Single cron entry → Laravel scheduler → three jobs:

1. **Occurrence builder** (hourly): (re)expand recurring events + birthdays into `occurrences` for the next 12 months.
2. **Reminder scanner** (every 5 min): find occurrences whose rule-derived `send_at` falls in the window → write to the `notification_outbox`. Idempotent (unique key on occurrence+rule) so cron granularity/downtime never double-sends.
3. **Outbox drainer** (every minute): send due notifications via channel driver (email now; web push later). Failures retry with backoff.

Design reminders to tolerate 5-minute cron granularity in case 20i limits frequency.

**Card & post engine (Phase 2, schema ready now):** per-person "send a card" rule computes *last posting date* = birthday − postal transit days, rolled **back** past weekends and UK bank holidays (free JSON feed: `gov.uk/bank-holidays.json`, cached locally). Reminder fires the evening before that date: *"Post Auntie Sue's card tomorrow — Monday's a bank holiday."* This is a genuinely differentiating feature; keep it high on the Phase 2 list. Needs a postal `addresses` row on the recipient's `person` (§6) — populate it by hand on the person's page, or parse it from the vCard `ADR` field on import (§9) — no address, no reminder.

## 12. MCP integration

**MVP recommendation: authless-transport MCP server + Sanctum bearer token** (the "pasted token" model, resolved in favour of tokens):

- Endpoint: Streamable HTTP at `/mcp` (Claude supports authless and OAuth remote servers; SSE is being phased out, so Streamable HTTP only). Use the official PHP MCP SDK or a Laravel MCP package.
- User mints a token in Settings choosing exactly one scope set: **read** / **read + write-new** / **read + write-new + edit**. Token is passed as a bearer header where the client supports it, or embedded in the connector URL path (`/mcp/{token}`) where it doesn't. Revocable; every call audited.
- Works day-one with Claude custom connectors, Claude Code, ChatGPT developer-mode connectors, and local scripts.
- **Platform coverage (Claude web / desktop / mobile):** custom connectors are added once via claude.ai or Claude Desktop settings and are then usable from the Claude mobile apps too (servers can't be *added* from mobile, but already-added ones work there). This is true for authless/token servers as well as OAuth ones — so **the token-based MVP already covers all three surfaces**. OAuth (below) is about doing auth *properly* — per-user consent screens, no token handling, revocation UX — not about unlocking platforms. That's why it's safe to hold as Phase 2 expansion.

**Phase 2: OAuth 2.1** (required for polish + any public listing): PKCE (S256), Dynamic Client Registration, protected-resource metadata document, form-urlencoded token endpoint, refresh-token rotation. Laravel Passport covers most of this; DCR is the main gap to add. Do it once the MVP tools are proven.

**MCP tools (MVP):** `list_calendars`, `get_events(range, calendar?)`, `search_events(query)`, `create_event(...)` *(write-new)*, `update_event(id, ...)` / `delete_event(id)` *(edit)*, `list_people`, `get_availability(range)`. Private events are excluded from all responses unless the token owner is the event owner — decide and document before build; spec default: **owner's own token sees private events; scope names never leak them to shares**.

## 13. Importing external calendars

- **Subscribe by URL (MVP):** paste any ICS URL (Google "secret address", Outlook, TeamSnap…). Cron fetches on a schedule with ETag/hash short-circuit; events stored with `source=feed` + `raw_ical`, read-only, own colour, toggleable per view.
- Google Calendar note for onboarding copy: users grab *Settings → Integrate calendar → Secret address in iCal format*.
- **One-off .ics file import (MVP-cheap):** same parser, no subscription row.

## 14. PWA & push (all-iPhone household)

- **MVP:** installable PWA (manifest, icons, offline agenda shell). **Email is the only reminder channel.**
- **Phase 1.5: iOS Web Push.** Works on iOS 16.4+ **only when the PWA is added to the Home Screen**; standard Web Push API + VAPID (no Apple developer account required, `web-push` compatible PHP lib: `minishlink/web-push`). Onboarding must therefore actively coach "Add to Home Screen" (Share → Add to Home Screen) — make it a fun Betty-toned step, and store per-device push subscriptions in `user_devices`.
- **Offline queue (future):** the service worker moves beyond read-only shell — changes and new events made offline are written to an IndexedDB outbox and synced (Background Sync where supported, sync-on-open as fallback) when connectivity returns, with conflict handling via event `sequence` (last-write-wins + audit trail; flag genuine conflicts for review).
- Keep email as permanent fallback; per-rule channel choice lives in `reminder_rules.channel`.
- No native app needed on current evidence; revisit only if iOS web push proves unreliable in dogfooding.

## 15. Auth & onboarding

Flow (target: under 60 seconds, zero passwords):

1. Invite link (from an existing user) → enter email → **magic link**.
2. First login prompts **passkey** creation → Face ID forever after.
3. Pick a colour scheme, confirm timezone (pre-detected). Done.

- Rolling 90-day sessions; "Your devices" page lists sessions/passkeys with revoke buttons.
- Admin panel (you) sees users, sessions, audit log, feed health, outbox.
- Everything auth-related is audited (§3.5).

## 16. Scale ("how many events/users can MySQL handle?")

Short answer: **more than this project will ever see on shared hosting.** MySQL with sane indexes is comfortable into the tens of millions of rows. 10,000 users × 1,000 events = 10M event rows — fine. The dogfood cohort is ~10 users and thousands of rows: statistically zero.

The *actual* ceilings on 20i shared hosting, in the order you'd hit them:

1. Concurrent PHP processes / connection limits (traffic bursts)
2. Cron granularity & execution-time caps (long feed fetches, big occurrence rebuilds — chunk them)
3. Outbound mail rate limits (use per-hour batching; consider Postmark/Resend free tier early for deliverability)

Upgrade path is boring by design: nothing assumes shared hosting except "no daemons". Move to a 20i VPS (or any VPS) → same codebase, switch queue driver from database to Redis, run a real worker, raise cron frequency. Key indexes from day one: `occurrences(starts_at)`, `events(calendar_id, starts_at)`, `event_tags(person_id)`, `audit_log(created_at)`.

## 17. Repo, CI & deployment

- **GitHub repo** (to be created): single Laravel app, `main` = production, PRs + branch deploys later.
- **GitHub Actions** (you've done Buddy→SSH before; Actions does the same job with no extra service):
  1. `composer install --no-dev`, `npm ci && npm run build` (Vite assets built in CI — never on the host)
  2. Deploy over SSH/rsync to 20i (atomic-ish: releases dir + symlink if the host allows; otherwise rsync + `artisan migrate --force` + cache clears)
  3. Staging first: `staging.` subdomain with its own DB.
- 20i cron: one entry, every minute (or 5) → `php artisan schedule:run`.
- Secrets in Actions secrets; `.env` lives on the host only.

## 18. Local development (Windows + XAMPP)

XAMPP fits fine — it supplies the Apache + PHP + MySQL layer; Laravel and Vite sit on top:

- **PHP/MySQL:** use XAMPP's. Point a local DB at XAMPP's MySQL in `.env`. Check XAMPP's PHP version matches production (8.2+) and enable required extensions in `php.ini` (`intl`, `zip`, `pdo_mysql`, `sodium` for passkeys).
- **Serving the app:** skip Apache vhost config entirely for dev — run `php artisan serve` (http://localhost:8000). Apache/vhosts only matter if you want to mimic production routing.
- **Composer + Node are separate installs** (they're dev tools, not runtime): Composer for PHP deps, Node LTS for the Vite build. Day-to-day loop = two terminals: `php artisan serve` + `npm run dev` (Vite dev server with hot reload). **Node never ships to 20i** — CI builds static assets.
- **Local email:** run Mailpit (single .exe) and point `.env` mail settings at it — every reminder email lands in a local inbox UI instead of sending.
- Optional upgrade later: Laravel Herd for Windows replaces XAMPP with a one-click PHP/nginx setup, but XAMPP is 100% workable — no need to change what you know.

## 19. Automated testing (in CI from Phase 0)

Both layers, both in the public pipeline from the first commit:

- **Pest (PHPUnit runtime)** — Laravel's default test framework; same assertions, nicer syntax. Unit + feature tests against an in-memory/ephemeral MySQL. Priority targets: **permission matrix** (partner/full-read/tagged/private/busy — table-driven tests for every combination), **recurrence + timezone expansion** (DST boundary fixtures), **ICS round-trip** (import → export → diff against fixtures), **reminder scheduling idempotency**, **MCP scope enforcement**.
- **Playwright** — E2E against a real browser: onboarding flow (magic link via Mailpit API), create/tag/share an event, feed URL returns valid ICS, PWA manifest present. Runs headless in Actions; also runnable locally on Windows.
- **CI gates:** every PR runs `pint` (code style), `phpstan` (static analysis), Pest, Playwright. Merges blocked on green. Badges in the README since we're public.

## 20. Branching & releases

Your proposed model works; one simplification recommended for a (mostly) solo project:

- **`main` = production.** Protected: PRs only, CI green required, no force-push.
- **`rc` = staging.** Auto-deploys to `staging.` on push. Feature branches merge here first; when staging soaks clean, fast-forward `rc → main`.
- **Feature branches** (`feat/…`, `fix/…`) replace a long-lived `dev` branch — a permanent dev branch tends to drift and rot; short-lived branches into `rc` give the same safety with less merge pain.
- **GitHub Releases: yes.** Tag `main` merges as versioned releases; the *release event* triggers the production deploy (so production deploys are deliberate, auditable acts, not side-effects of a merge). Auto-generated release notes double as the changelog — good practice for building in the open.

**Versioning semantics (standard semver).** `MAJOR.MINOR.PATCH` follows the normal open-source convention rather than a house-invented one — one less thing for contributors to learn: **major** = breaking changes (data migrations users must run, removed/reworked features, API/MCP tool signature changes); **minor** = new functionality, backward-compatible; **patch** = bug fixes, backward-compatible. Hotfixes are simply patches cut out of cycle — see below.

**Release cadence & hotfixes.** Monthly release train from `rc → main` for minor/major versions, but bugs don't wait for the train: `hotfix/…` branches cut *from `main`*, fix, tag a patch release (auto-deploys), then merge back into `rc` so the fix isn't lost at the next train.

**Changelog.** `CHANGELOG.md` at the repo root follows the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format — an `[Unreleased]` section at the top, then dated, linkable version headers (`## [1.2.0] - 2026-09-14`), each grouped into **Added / Changed / Deprecated / Removed / Fixed / Security**. Written for humans, not a raw commit dump; pairs deliberately with the semver decision above (Keep a Changelog explicitly recommends it). Conventional-commit-style PR labels (`feat:`, `fix:`, `chore:`, `security:`) map cleanly onto those categories and let tooling draft each entry, with a human pass before release rather than full auto-generation. A second, separate artefact — a casual `/changelog` page in-app — surfaces the same Added/Fixed items in plain language for users (minor/major releases only, Betty-voiced eventually: "New this month: …"); `CHANGELOG.md` stays the precise developer record, the in-app page is the friendly translation of it.

**Feature flags (from Phase 0).** Use **Laravel Pennant** (first-party, tiny): every meaty feature ships behind a flag, enabled for you first, then household, then everyone. This is what makes monthly trains safe — half-finished work can merge dark — and later doubles as the premium-feature gate for monetisation (a "flag by subscription tier" is the same mechanism).

**Dynamic documentation (early feature, Phase 1b).** Docs are part of the app, not a separate site — markdown pages in the repo, rendered at `/docs`, so they deploy *with* the code and can never describe a version you're not running. Three rules make them "dynamic":
1. **Deep links both ways** — docs reference app screens as real clickable links ("add birthdays on [your People page](/people)"), and app screens carry contextual "?" links to their doc section. A tiny route-name helper keeps links from rotting (broken internal links fail CI).
2. **Flag-aware rendering** — doc sections are tagged with their feature flag; the renderer checks Pennant at request time and badges sections **Preview** or **Premium** (or hides them entirely) to match what *this* user can actually see. Docs never advertise features the reader doesn't have.
3. **Docs in the definition of done** — a feature PR isn't mergeable without its doc page (checklist item in the PR template), which is what keeps them matching deployments forever.

**Until `/docs` ships: the GitHub wiki.** `docs/` is published to the repo's GitHub wiki on every push to `main` (`.github/workflows/wiki.yml` → `.github/wiki/build.php`), and the in-app Documentation link points there for now. It's a one-way mirror: `docs/Home.md` is the landing page, `docs/guide/` holds user-facing pages, and everything else under `docs/` is listed as Development. Links are written the way `/docs` will need them — relative links between docs files, root-relative links (`/people`) for app screens — and broken ones fail CI. When `/docs` lands it renders the same files and the in-app link moves there; the developer pages can stay on the wiki. One gap to accept meanwhile: the wiki isn't flag-aware, so guide pages should only describe features that are fully rolled out, or say plainly that they may not be switched on yet.

## 21. Building in the open: security & licensing

Public repo from day one is viable and healthy **if** we treat the repo as hostile-readable:

**Repo hygiene**
- No secrets, ever: `.env` git-ignored with a committed `.env.example`; deploy keys/API keys only in Actions secrets. Enable GitHub **secret scanning + push protection** and **Dependabot** (security + version PRs) on day one.
- No real data: seeders/fixtures use fake people only. Never commit real feed tokens, screenshots with real events, or the production DB schema dumps with data.
- Branch protection on `main`/`rc`; Actions pinned to SHAs for third-party actions.

**Application security (public code = attackers can read the map, so the locks must be real)**
- Security comes from secrets and design, not obscurity: all tokens (feed URLs, magic links, MCP) are ≥128-bit random, **stored hashed**, revocable, and rate-limited.
- Magic links: single-use, 15-minute expiry, bound to requesting session where possible.
- Standard hardening: CSRF (Laravel default), strict CSP headers, HSTS, signed cookies, throttled auth endpoints, audit log on everything (§3.5).
- `SECURITY.md` with a private disclosure route (email), so researchers don't open public issues for vulns.

**Licence — decided: AGPL-3.0.** Open code + future personal subscriptions is a real tension: MIT would let anyone stand up a competing paid instance from your own repo, which cuts against Phase 3's revenue plan. **AGPL-3.0** resolves it while staying genuinely open source (OSI-approved, real copyleft, no "source available" asterisk): its network-copyleft clause means anyone who runs a modified version *as a service* must publish their changes, which deters silent closed-source commercial forks while leaving self-hosting, personal use, and community contribution completely unrestricted — the same model Nextcloud, Mastodon, and Grafana (pre-relicense) use. It also matches the project's actual spirit: you want it built in the open and improved by others, you just don't want someone else's for-profit "Offing Pro" running on your unpaid contributors' work.

FSL (Functional Source License) was the alternative on the table — source-available, converts to Apache-2.0 after 2 years, explicitly forbids competing hosted offerings. It gives *stronger* commercial protection, but it's not OSI-approved open source, which matters for two practical reasons here: some contributors won't submit PRs to a non-OSI licence on principle, and it reads oddly for a personal/family project rather than a funded startup protecting a product moat. AGPL's copyleft is enough deterrent for the scale of clone risk a project like this actually faces.

**Locking it in:** add `LICENSE` (AGPL-3.0 text) at Phase 0, and require a **DCO** (Developer Certificate of Origin — a sign-off line on commits, lighter-weight than a CLA) in CONTRIBUTING.md from the first external PR. **Relicensing later is hard once outside contributions exist**, so this is deliberately decided now, before the repo goes public, not revisited casually.

**Community files (Phase 0):** `LICENSE`, `README` (with badges + screenshots-of-fake-data), `CHANGELOG.md` (Keep a Changelog format, `[Unreleased]` section from the first commit), `CONTRIBUTING.md` (setup guide = §18, DCO note), `CODE_OF_CONDUCT.md`, `SECURITY.md`, issue + PR templates.

## 22. Operations, data protection & other gaps

**Backups (was missing — the most important gap).** 20i's own backups are convenience, not a strategy. Add: nightly `mysqldump` via the scheduler, encrypted, pushed **off-host** (S3-compatible bucket or even a private repo-adjacent store — anywhere that isn't 20i). Deploy pipeline takes a DB snapshot before running migrations. Test a restore once before dogfooding starts — a backup you've never restored is a hope, not a backup.

**Monitoring & the silent-cron problem.** If the cron entry dies, reminders stop *silently* — the worst failure mode for a reminders product. Fix: the scheduler pings a dead-man's-switch service (healthchecks.io free tier) every run; no ping = you get an email. Add Sentry (free tier) for PHP + JS error tracking and a basic uptime check on prod + staging. Admin panel surfaces feed-fetch failures and outbox backlogs.

**Account deletion & data protection.** Even at dogfood scale, build the delete-my-account flow early (cascading, feed tokens revoked, exports offered first) — bolting it on later is miserable. Note for the public-launch future: virtual people are personal data *about individuals who never signed up* (names, birthdays, gift notes), so a privacy policy, UK GDPR lawful-basis thinking, and per-person erasure will be needed before open registration — flagged now so it's a known cost, not a surprise. Terms of service likewise at public launch, not before.

**Accessibility baseline.** Calendars are notoriously bad for screen readers and keyboard users. MVP bar: full keyboard navigation of views, focus states, colour-contrast-checked palettes (all colour schemes), semantic dates, and the agenda view as the accessible-first representation. Playwright can enforce basics with axe-core assertions in CI.

**Share consent.** Shares (even Partner) are *offered and accepted*, never imposed — the grantee confirms before anything appears in their app. Small UX detail, big trust principle, trivial to build now.

## 23. Roadmap

**Phase 0 — Foundations (repo up first)**
Repo (public, licence decided, community files, branch protection, secret scanning, Dependabot), CI/CD to staging on 20i (pint + phpstan + Pest + Playwright gates), Laravel + Inertia + React skeleton, auth (magic link + passkeys), sessions/devices, audit log, admin shell, **nightly off-host DB backups + tested restore**, **cron heartbeat (healthchecks.io) + Sentry + uptime checks**, branding v0 (name decision, wordmark, accent colour).

**Phase 1a — The Birthday Engine (week-one dogfood goal — no calendar UI at all)**
The fastest path to solving your own problem: auth (magic link + passkey) · **people** (add/edit, list view) · **birthdays with optional year** · **vCard import moved up from Phase 2** (export contacts from iPhone → upload .vcf → parse BDAY → review/merge screen) · **outbound ICS feed URL** — the entire "UI" for viewing is *subscribe this URL in Google/Apple Calendar* · default reminder rule (email, 8pm day before + 8am on the day) via the cron pipeline. Deliverable: within week one, every birthday you care about is in your existing calendar app with reminders, and Offing is already useful before it has a single calendar view.

**Phase 1b — Full calendar MVP (daily-driveable)**
Calendars & events (create/edit/delete, common recurrence, private + busy/free flags, all-day) · month/week/agenda views (FullCalendar) · timezone handling · people (virtual) & groups · tagging · partner + tagged + full-read shares · outbound feed tokens (ICS) · inbound ICS subscriptions + .ics import · birthdays (with/without year) + default reminder rule · email reminders via cron pipeline · installable PWA shell · MCP server with scoped tokens · settings (colour scheme global/per-device) · **event search** · **soft delete + undo/restore** (data already in audit log) · **UK bank holidays as a toggleable layer** (gov.uk feed, shared with card engine) · **full data export** (ICS + JSON) · **weekly "week ahead" digest email** (Sunday evening — proto-Betty voice) · **account deletion flow** · **share offer/accept consent** · **accessibility baseline** (keyboard nav, contrast-checked schemes, axe-core in CI) · **dynamic documentation** (in-app `/docs`, deploys with code, deep-linked both ways, flag-aware Preview/Premium badges — see §20).

**Phase 1.5 — Comfort**
iOS web push · nicer onboarding coach for Add-to-Home-Screen · feed health dashboard · MCP `get_availability`.

**Phase 2 — Differentiators**
Card & post engine with bank holidays · vCard import *(moved to Phase 1a; Phase 2 adds re-import/merge improvements)* · group web views (tokenised, no account) · virtual→real account upgrade flow · OAuth 2.1 for MCP · reminder rule editor UI · **availability polls** ("when can the club meet?" — flagship group feature, future premium candidate) · **RSVPs on tagged events** (yes/no/maybe **+ custom questions per event** — dietary, plus-ones; answerable via tokenised links, no account needed) · **anniversaries & significant dates** (generalise birthdays into a person `dates` table with per-date **show** and **remind** toggles — e.g. a passed relative's birthday visible but never alerted; respectful rendering for remembrance dates, exempt from playful copy) · **conflict warnings across shared calendars** · **bin collection reminders** (council schedule import) · **email-to-event, stage 1** (private inbound address per user via inbound-parse webhook; attached .ics auto-imported using existing parser) · **places & travel buffers** (named places incl. Home; events with locations get a manual/default travel margin rendered as hatched "error bars") · **background layers, stage 1: work hours** (fixed weekly pattern or subscribed timesheet ICS; renders as greyed ambient shading, not events — week view shows shape, day view shows meetings).

**Phase 3 — Delight & revenue**
Diary layers (Spotify, fitness, past events — likely via nightly importers) · **"On this day"** (surfaces past diary-layer entries) · **email-to-event, stage 2** (no .ics attached → AI-parse ticket/confirmation emails for date, time, venue via the Claude API) · **travel buffers, stage 2** (real travel-time estimates via open routing — OSRM/OpenRouteService, fitting the OSM ethos) · **background layers, stage 2: sleep** (usual sleep window as ambient shading; past days can overlay actual sleep from health-data diary layers) · Betty daily digest, then Betty chat · silly seasonal event packs · **public roadmap page with voting** (backlog items promoted from the repo; votes inform, not dictate) · **tide times layer** (UK Admiralty/EA data — nautical brand demands it) · **school term dates layer** (county council data; big for UK families) · public "request time with me" page (**security stance decided:** this is the *only* public availability surface — it shows offered bookable slots within defined hours and never raw free/busy, because a public "busy all week" feed advertises an empty house during holidays; tokenised free/busy feeds remain available for trusted people only) · **monetisation: personal subscription** (free = full family calendar; paid = layers, Betty, advanced reminder engine, polls, more feeds). Schema needs nothing new for billing beyond a `subscriptions` table when the time comes.

**Explicitly deferred, not forgotten:** **CalDAV server** (two-way native sync with Apple/Google Calendar via sabre/dav) — the single biggest step toward being a true Google Calendar replacement, and a major build. Conscious "not yet"; revisit after Phase 2.

**Backlog — approved, unscheduled.** All yes'd; slotted into phases opportunistically. This list lives in the repo only for now; once public, promote items to a public roadmap page with user voting (flag-gated like everything else).
- **Sunrise/sunset + moon phase layer** — golden hour and darkness times; pure astronomy maths, no API; nautical brand + night-photography use.
- **Trips** — container events ("Cornwall week") holding sub-events; the container drives timezone and travel buffers.
- **Weather on upcoming events** — forecast badge within the 5-day window.
- **Milestone highlighting** — 18th/21st/40th/50th birthdays and major anniversaries flagged well in advance; feeds the card/gift engine.
- **Gift claiming** — shared-person gift coordination ("I've got Sue's present") to prevent duplicates; respects private gift-note boundaries.
- **Monthly email digest for non-app relatives** — virtual people can receive a friendly upcoming-events email instead of (or as well as) a feed.
- **Printable month view** — fridge/grandparent grade.
- **Kitchen display mode** — a `/today` dashboard URL for a wall-mounted tablet: today + tomorrow, big type, auto-refresh.
- **Siri Shortcuts support** — Shortcuts hitting the API ("Hey Siri, what's on today?"); closest thing to widgets without a native app (iOS home-screen widgets remain PWA-impossible).
- **"Your Year in Offing"** — December wrapped-style recap from Betty: busiest month, most-seen people, cards posted. Shareable.

**Stretch goals (post-Phase 3, noted so the schema stays ready):**
- **Card posting as a service.** The reminder engine already knows *who*, *when*, and *the last posting day*, and (§6) *where* — the natural upsell is "or tap here and we'll post it for you." Likely built on a print-and-post partner API (UK candidates to research: Prodigi, Stannp) rather than in-house fulfilment: user picks a design + message, we schedule the print job against the computed posting date and the person's `addresses` row, charge per card. This is the strongest per-transaction monetisation idea in the project because the moat is the *data* (dates + rules + address), not the printing. Needs: payments (Stripe), design catalogue, order tracking, a returns/failed-delivery policy, address validation (a bad postcode should surface before the print job fires, not after).
- **Gift memory & suggestions per person.** Extend `people` with `gift_links` (registry/wishlist URLs — Amazon, Etsy…), sizes/interests/"things they mentioned wanting", and past-gifts log (avoids repeat gifts — a real problem). Gift *suggestions* then come almost free via the existing MCP integration ("Claude, Sue's birthday is in 3 weeks — she's into wild swimming and I got her a dry robe last year, ideas under £30?") before we ever build a native suggestions feature. Affiliate links on suggestions are a light additional revenue stream. Privacy note: this is data *about third parties* — keep it strictly private to the owning user, never in shares/feeds/group views.

## 24. Risks & open decisions

| Risk / decision | Position |
|---|---|
| iOS web push reliability | Mitigated: email is permanent fallback; push is 1.5 not 1 |
| RRULE edge cases | Delegated to sabre/vobject; UI limits creation patterns |
| Shared-hosting cron limits | Architecture tolerates 5-min granularity; jobs idempotent |
| Partner view of private events | **Resolved:** independent `private` + `busy/free` flags; private+busy = Busy block, private+free = hidden |
| MCP token in URL (for clients without header support) | Acceptable for dogfood; OAuth in Phase 2 removes it |
| Name/domain | **Resolved:** Offing decided; register `offing.uk` via 20i once confirmed live in their domain search |
| Email deliverability from shared hosting | Consider Postmark/Resend free tier from Phase 1 |
| Licence | **Resolved:** AGPL-3.0 + DCO on external PRs (see §21) |
