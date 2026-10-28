# Offing — Design Spec & MVP Plan

*A shared calendar for the people in your life. Working title — see §2.*

**Status:** Draft v1 · July 2026
**Audience at launch:** Owner + household + friends (dogfood first)
**Hosting:** 20i shared hosting (PHP + MySQL + cron; no daemons, no queue workers)

---

## 1. What this is

A web-based, PWA-installable calendar whose core reason to exist — the thing Google/Apple Calendar do badly — is the **sharing model**: partners who see everything, friends who see only what they're tagged in, and "virtual people" and crews (the household, the D&D club, the craft group) whose members don't all need accounts. Second priority is **AI access via MCP** with granular permissions. Birthdays and a card/gift reminder engine come next, with diary layers (Spotify, fitness) as a future delight.

Priorities, in order (from discovery):

1. Sharing model (partner / tagged friends / crews / virtual people)
2. AI access via MCP — "Connect Claude" with OAuth (read · write-new · edit scopes)
3. Birthdays + card/gift reminder engine
4. Diary layers (past events, Spotify, fitness)

Non-goals for MVP: public signup, monetisation, public "book time with me" page, native apps, Betty chatbot. **Permanent non-goal: tasks/to-dos** — deliberate scope line; Reminders/Todoist do it well and calendar apps die of task creep.

**One narrow carve-out: household chores (Shipshape, §25).** A chore is *recurring household upkeep* — it must repeat (daily/weekly/monthly/yearly), it belongs to a household that shares it, and it has no subtasks, priorities, projects or due times. One-offs are not chores, ever: "fix the shed door" is a task and stays out. The test for any future change is simple — if it only makes sense for something that happens once, it's task creep and the answer is no. Generic shared lists are **not** implied by this carve-out; they're a separate decision (§23 backlog).

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
- **MCP access** → `laravel/mcp` + Passport give an OAuth 2.1 MCP server out of the box; the three access levels (read / write-new / edit) live on our own per-connection grant. See §12.
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

### iCal engine: **sabre/vobject** (parse, expand *and* emit)

Never hand-roll RRULE. sabre/vobject is the reference PHP implementation (it powers Nextcloud) and handles recurrence expansion, EXDATE, and VTIMEZONE correctly. It generates our feeds too: one library both ways means what we emit is exactly what we (and the tests) parse back. spatie/icalendar-generator was originally used for emitting; it was dropped to keep the dependency list short.

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
passkeys         id, user_id, name, credential_id, credential(json),   ← WebAuthn; standalone sign-in, magic link
                 last_used_at                                             stays as fallback; credential = full record
magic_links      id, user_id, token_hash, expires_at, used_at

people           id, owner(morph: user|crew), name, linked_user_id(null), ← virtual people; link = upgrade path.
                 birth_day, birth_month, birth_year(null), notes          user-owned = your private address book;
                                                                          crew-owned = shared with that crew (§7)
addresses        id, person_id, line1, line2, city, region, postcode,  ← postal address for the card/post engine
                 country(iso2), notes                                    (§11); deliberately its own table, not
                                                                          columns on `people` — see notes below
crews            id, name, created_by, deleted_at                       ← shared spaces (§7); replace the old
                                                                          `groups` idea (household, craft group)
crew_members     id, crew_id, user_id(null), person_id(null),          ← exactly one of user/person; a person
                 added_from_person_id(null),                              member is a passenger (no account).
                 role(captain|crew|passenger), joined_at, left_at         added_from = which of *your* People a
                                                                          passenger was copied from (picker only)
crew_invites     id, crew_id, email, role, invited_by_user_id,         ← single-use, hashed, 7-day expiry;
                 token_hash, expires_at, accepted_at, revoked_at          accepting can create the account (§15)

calendars        id, owner(morph: user|crew), name, colour, slug
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
feed_tokens      id, subject(morph: user|person|crew|share), name, token_hash,
                 scope(full|tagged|crew), last_used_at, last_user_agent,
                 revoked_at                                             ← outbound secret ICS URLs; hash-only,
                                                                          shown once, max 3 active per subject
feed_subscriptions id, user_id, url, name, colour, etag, last_hash,
                 last_fetched_at, status                                ← inbound external ICS

reminder_rules   id, user_id, applies_to(morph: person|category|event),
                 kind(notify|card_post), offset_def(json), channel(email|push)
notification_outbox id, user_id, channel, payload, send_at, sent_at     ← drained by cron; named to avoid colliding
                                                                          with Laravel's own `notifications` table/
                                                                          Notifiable trait (already used on `User`)

chore_lists      id, owner(morph: user|crew), name,                     ← Shipshape (§25); the sharing unit —
                 editors(crew|captains), deleted_at                       chores inherit their list's visibility
chores           id, chore_list_id, title, area(null), notes(null),
                 mode(fixed|floating), rrule(null),                     ← fixed: RRULE on dates, via sabre
                 every(null), unit(day|week|month|year, null),          ← floating: interval from last done
                 due_on(date, null), lead_days(null),                   ← null due_on = "needs a date"
                 assign(anyone|member|rota), assignee_member_id(null),
                 rota_position, version, deleted_at                     ← version: optimistic lock on ticks
chore_rota       chore_id, crew_member_id, position
chore_entries    id, chore_id, kind(done|skip), done_on(date),          ← the ship's log; append-only apart
                 done_by_member_id(null), done_by_name, chore_title,      from voiding. Names are snapshots so
                 logged_by_user_id(null), via(app|pass), reason(null),    history survives renames and leavers;
                 before(json),                                            via=pass when a passenger ticks (§7)
                 voided_at(null), voided_by_user_id(null), void_reason(null), created_at

oauth_*          Passport tables (clients, access/refresh tokens);      ← Connect Claude (§12); every token
                 one client per connected Claude                          has the one scope `mcp:use`
ai_grants        oauth_client_id, user_id, level, include_private,      ← consent choices per connection
                 barometer(bool), last_used_at, revoked_at
audit_log        id, actor(morph), action, subject(morph), meta(json), ip, created_at  ← append-only
```

Notes:
- **Virtual → real upgrade keeps history** because everything references `people.id`; linking a `user_id` later changes nothing downstream.
- **Crews are first-class** despite personal-subscription monetisation — they're the one sharing primitive (§7): households, clubs and craft groups are all crews, and calendars, chore lists and anything shared later hang off them.
- **People are owned by a user *or* a crew.** Your People stay your private address book. A crew-owned person is shared with that crew — a passenger like a child or a club member without an account. Adding one of *your* people to a crew makes a crew-owned **copy** with their display name — plus their birthday only if you tick "share their birthday" — never notes, addresses or gift notes. Your own entry stays private and doesn't sync with the copy. The membership remembers which of your people it came from, so the picker shows who's already aboard and re-adding a former passenger reuses their copy. Passengers can also be added from scratch, in which case they exist only in the crew (the D&D group's members who aren't in anyone's People). **Membership changes never delete a person**: a removed passenger stays in the crew's People as a former passenger, and when a crew is deleted its people move to the deleting captain's People. Only an explicit delete removes a person (soft, restorable).
- **People link to accounts by consent only.** `linked_user_id` is set when an invite is accepted (from a person's page, or a passenger being promoted) or when you confirm a suggestion between existing crewmates. No matching on phone numbers or emails, ever: it would tell anyone who adds a contact whether that number has an account (user enumeration), and it means storing more data about people who never agreed. The vCard importer keeps ignoring `TEL`/`EMAIL`. Each side links its own person; neither can see or change the other's.
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
- **AI connections** (§12) see what their user sees, minus private events unless the user ticked "include my private events". Each share and crew has an **"Allow in AI connections"** setting (on by default; sharer or Captain controls it) — off downgrades that source to busy/free for the grantee's connections only.

### Crews — the shared spaces

The levels above answer *"how much of **my** calendar can you see?"* — one-way, grantor → grantee. A **crew** answers a different question: *"what do **we** own together?"* It's the single sharing primitive for jointly-owned things — a household's chore lists today, shared calendars and other shared things later. (It replaces the separate `groups` concept this spec used to have; a club or craft group is just a crew.)

| Role | Who | Can do |
|---|---|---|
| **Captain** | Has an account | Everything Crew can, plus manage members and roles, rename/delete the crew, move a crew list back to private |
| **Crew** | Has an account | See and edit everything shared with the crew; tick off and void chores; add passengers |
| **Passenger** | A crew-owned person, no account | Can't sign in. Can be assigned chores (crew tick them off on their behalf) and, later, receive things through feeds/tokenised views |

- **Something is shared by who owns it.** Chore lists, and later calendars, are owned by a user (private) or a crew (shared with every crewmate). No per-item share lists — so nothing is ever half-shared, and a new housemate gets everything by joining once.
- **Joining is offered and accepted** (§22 share consent): invites are by email, single-use and expiring (7 days); nothing appears until the invitee says yes (§15). The captain sees the same response whether or not the email has an account, so invites can't be used to probe who's on Offing. Every invite is emailed: the link opens a page that, without changing anything, asks a newcomer for just a name (account created verified, signed in, aboard), sends an existing account to sign in first (a 7-day invite is never a sign-in link for an account that already has data), and won't answer on behalf of whoever else is signed in on that browser. The account with that email can also answer from its own `/crew` page. The link's token is hashed on `crew_invites.token_hash`, and answering either way is an atomic claim, so it works once.
- **Every crew always has a captain.** The last captain can't leave or be demoted — hand over first. Deleting a crew is captain-only, confirmed by typing its name, soft for 30 days.
- **Leaving** ends access at once. Chores pinned to the leaver become "anyone", they drop out of rotas (if it was their turn it passes to the next person), and the log keeps their name. Their private lists and People are untouched.
- Every membership change, role change, invite (sent/accepted/declined/revoked) and list move hits `audit_log`.
- **Promoting a passenger** to crew is an invite whose acceptance links the new account to the existing person (§6), so their history comes with them — the same virtual → real upgrade as §8.

#### Getting aboard: links, not search

**No Offing-wide people search, ever** — a name or email box that answers "they're on Offing" lets anyone probe who uses the app. Search only covers people you already have (your People, your crews' members). People get aboard through three kinds of link, all built on the magic-link token machinery (≥128-bit, stored hashed, revocable) and all audited:

| Link | For | Opening it | Lifetime |
|---|---|---|---|
| **Personal invite** | One person — emailed, or "copy link" for WhatsApp | Sign in (or sign up with just a name), then accept or decline. Made from a **passenger's row** ("Give Gimli an account"), accepting turns *that same member* into crew — same row, same history | Single use, 7 days |
| **Crew join link** | Anyone it's shared with (a club's group chat) | Sign in or sign up, then **ask to join**. Default: the captain approves each request ("Wants to join" list); per link the captain can switch on **auto-join**. Joiners always arrive as crew, never captain, and never see the passenger list before they're approved | Captain-set expiry and optional max uses; revocable |
| **Passenger pass** | One passenger, no account | A no-account page showing *that crew's* shared things, greeting them by name. They can **respond** (vote, RSVP) and **tick their own assigned chores** ("✓ Alfie, via his pass") — never edit, never see emails or anyone's private People | One active per passenger; no expiry timer but a visible "last used"; any crewmate can create, copy, revoke or regenerate it |

- **Merging**: if someone joins the "wrong" way (open link, while a passenger for them already exists), the captain gets "This is the same person as passenger Gimli" on their row — the two become one member with the passenger's history and the account. Joiners never claim a passenger themselves.
- When a passenger copied from *your* People gets an account, your own entry for them is offered the consent link to it (§6) — that's what shows "Gimli (in D&D)" on your People page.
- The passenger pass is built with the first feature that needs it (passenger ticks or polls), not in Shipshape v1 — but chore log entries carry a "via pass" marker from the start.

#### Who can edit what's shared

Every shared thing (chore list, calendar, poll…) has one switch — **who can edit: all crew / captains only** — defaulting to **all crew**. *Responding* is separate from editing and always allowed to anyone it's shared with: ticking your own chores, voting, RSVPing. Passengers never edit, whatever the switch. (The DM's campaign calendar: captains only; everyone still RSVPs.) No per-person permission lists.

#### Crews and personal calendar shares

Two questions, two concepts: personal shares (the table above) answer *how much of **my** calendar can you see* — one-way, offered and accepted; crews answer *what do **we** own together*. They stay separate — **joining a crew never shares your calendar** (the D&D group mustn't see your dentist) — but they're connected in the UI: "Share my calendar with…" offers your crewmates as quick picks, the crew page shows each crewmate's share status ("Sam sees your calendar · Partner"), and accepting a crew invite can *offer* a share on the welcome screen ("Also share your calendar with Charlie? Partner / Full read / Not now").

## 8. Virtual people, crews & feeds (MVP consumption = feed-only)

- Creating a person or crew mints a revocable **secret ICS URL** (`/feed/{token}.ics`) they subscribe to in Apple/Google Calendar. HTTP caching headers (ETag) so calendar apps poll cheaply.
- Phase 2: lightweight tokenised **web view** (agenda page, no account) for a crew's passengers at `/c/{slug}?k={token}`.
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

## 12. Connect Claude (MCP integration)

**Goal:** any Offing user links their own Claude in under a minute, and it works on every Claude surface (web, desktop, mobile, Claude Code) and with other MCP clients (ChatGPT, Cursor…) for free. We test with Claude; the user-facing name is generic ("AI assistants") because nothing in the server is Claude-specific. Decided in the October 2026 research slice; supersedes the earlier "pasted token MVP, OAuth in Phase 2" plan.

### One server, OAuth from day one

- **Remote MCP server** at `/mcp`, Streamable HTTP only (SSE is deprecated). Built on **`laravel/mcp`** with **Passport** as the authorization server: `Mcp::oauthRoutes()` provides protected-resource and auth-server metadata, PKCE (S256) and **Dynamic Client Registration**. Add **Client ID Metadata Documents** (CIMD, the MCP 2025-11-25 preferred registration path, which Claude uses) if the package doesn't yet. Refresh-token rotation on.
- **No pasted tokens, no token-in-URL.** The token-in-URL MVP is dropped — it would be built only to be thrown away, and secret URLs leak into logs. Sanctum is not needed for MCP.
- The UX this buys: *Connect* in Claude → Offing's consent screen (already signed in by passkey) → choose access → done. Revocation is a normal "Connected apps" list.

### Distribution: Directory listing + custom connector, same code

- **offing.uk is listed in Claude's Connectors Directory** (one-click Connect on every surface). Requirements as of Oct 2026: OAuth, every tool has a `title` plus `readOnlyHint` or `destructiveHint`, documentation URL, privacy policy URL, support contact, icon, and a reviewer test account with fake sample data. Submitted via `claude.ai/directory/manage`; auto-scanned and listed as *Community* by default, with Verified as a later step. Submit once the flag is promoted to `Rollout::Everyone` and the tools are stable — renaming tools after listing is a breaking change for users.
- **Self-hosters get the identical flow, no separate path.** Every instance implements the same OAuth, so "Add custom connector → `https://their-host/mcp`" gives the same consent screen. The Directory form also has a *users connect to different URLs* option (URL pattern / user-supplied URL) — **open question:** ask `mcp-review@anthropic.com` whether one Offing listing can cover arbitrary self-hosted instances. If not, custom connector is the documented self-host route.
- Claude Code: `claude mcp add --transport http offing https://<host>/mcp`. No Claude Code plugin for now (decided); revisit if we want the skill one-click, since the Directory only accepts skills bundled in a plugin.

### Consent: access level + private toggle

The consent screen asks two things (three for Barometer users), nothing more:

1. **Access level** (one of three; **read + write-new is pre-selected**):

   | | read | + write-new | + edit |
   |---|---|---|---|
   | Briefing, find, availability | ✅ | ✅ | ✅ |
   | Create events (incl. tags + reminders), add people/birthdays, log a chore done | | ✅ | ✅ |
   | Change/delete existing events, people, reminders; undo a chore log | | | ✅ |

2. **"Include my private events"** — off by default. When off, the connection sees the user's own private events exactly as a Partner would (private+busy = detail-free Busy block, private+free = absent).
3. **Barometer** (only if the user has it) — a separate opt-in; see below.

No per-calendar/per-crew pickers — the connection otherwise sees what the user sees, under the rules below.

**Where the choices live:** in our own `ai_grants` row per user + OAuth client, **not** in OAuth scopes. `laravel/mcp` advertises a single scope (`mcp:use`) and MCP clients request what's advertised, so every token carries just `mcp:use`; the MCP server reads the grant on each request. Bonus: changing a connection's level takes effect immediately, with no re-consent and no token reissue.

**Settings → AI assistants** page: this instance's own MCP URL with a copy button; steps for Claude (web/desktop add the connector, mobile inherits it), ChatGPT, Claude Code and "any other MCP client with OAuth", plus a note that we test with Claude; a download of the Offing skill; and the **Connected apps** list — one row per OAuth client with level, private toggle, last used, edit (no reconnect needed to widen/narrow) and Revoke. Grants, edits, revokes and every tool call are audited (`AuditLog::record()`).

### Barometer: write in, stats out (health data)

Barometer (the private anxiety log, §26) is UK GDPR special-category health data, so it sits **outside** the access levels as its own opt-in:

- **Separate consent switch**: "Barometer: let Claude add readings and see stats". Off by default, shown only to users who have the Barometer flag, and it's its own flag on the grant (`ai_grants.barometer`). When it's off, the Barometer tools aren't listed to that connection at all, so Claude doesn't know they exist.
- **`log_reading`** (write): day/time (optional, as in the app), first rating (required) and the optional text fields. Content goes *in*: the user already said it to Claude, so writing it adds no exposure. The result is a bare confirmation ("Logged, 7/10 at 14:20") and **never echoes the text**. It can also add a second rating to the user's latest reading from today that doesn't have one ("I'm down to 3 now"). There's no edit, delete, list or read-back: corrections happen in the app.
- **`get_barometer_stats`** (read, aggregates only): reading count, average first rating, average drop from first to second rating, weekly averages, and time-of-day/weekday buckets over a range. **No per-reading rows and no free text, ever.** The same "describe, don't diagnose" rule applies as in the app (§26), and results carry that framing.
- **Never in `get_briefing`, `find` or any other tool**, and never in any other share or connection. Reviewing details, editing, exports and anything safeguarding-related stay in the app.
- **Server instructions** (only when the scope is granted): don't interpret severity or diagnose. If the user mentions risk to themselves, signpost Samaritans (116 123) and their GP or therapist, and point to the app for the full log.
- **Audit**: grant/revoke, each logged reading and each stats call, with no content (matches Barometer's own audit rule).
- **Directory**: the submission's data-handling step asks whether the connector handles personal health data. Answer **yes** and describe this write-only/aggregate design.
- **Rollout**: both ship with Barometer's first slice (#35). Both need the `connect-claude` *and* `barometer` flags plus the grant's switch. `log_reading` also needs "Read and add new". `get_barometer_stats` works at any level (it's read-only) and returns counts and averages computed from the dates and ratings only; the query never selects the text columns. #37 builds the in-app stats on the same numbers. If no time is given, a new reading is logged as now. A date with no time is logged with the time not recorded. A repeat of the same reading within two minutes is treated as a retry and not logged again.

### Other people's data

Connecting Claude sends data to a third party, including data other people shared with you. Rule: **a connection sees what you see in Offing, never others' private events, and sharers can opt out.**

- Every personal share and every crew gets an **"Allow in AI connections"** setting, **on by default**, controlled by the sharer (crews: the Captain). When off, the grantee's connections see that source as busy/free blocks only — the grantee's own app view is unchanged.
- Person notes about third parties (gift memory, sizes — §23 backlog) are only ever returned to the owning user's own connections, never via a share or crew.

### Tool design: few, broad, rich (minimal round trips)

Aim: most questions answered in **1–2 calls**. About eight tools, each returning dense **compact text** (not verbose JSON) with **names + ids inline** ("Sue (person 12)") so Claude can act without a lookup call.

| Tool | Scope | Notes |
|---|---|---|
| `get_briefing` | read | One call: who you are, timezone, today's date, access level, calendars, crews (with your role), people (names + ids), today + next 7 days, upcoming birthdays, chores due. Server instructions say "call this first". |
| `find` | read | One broad query: date range, free text, person, crew, calendar — any combination. Covers search, range fetch and "what's on with Sue". |
| `get_availability` | read | Free/busy across you + chosen people/crews for a range. |
| `save_event` | write-new (create) / edit (update) | Create or update; takes tags (people/crews) and reminders as parameters. `destructiveHint`. Result states who was notified ("Sue and 3 crew were notified"). |
| `delete_event` | edit | Soft delete — undo window applies. `destructiveHint`. |
| `save_person` | write-new / edit | Own address book only (crew-owned people per role); birthdays included. |
| `log_chore` | write-new (log) / edit (undo) | "I've done the bins." Creating/editing chore lists stays in the app. |
| `log_reading`, `get_barometer_stats` | Barometer grant only | Health data. Write in, aggregates out, never details. See Barometer above. |

Plus:

- **Server `instructions`** (sent once at connect): vocabulary (Partner, Crew/Captain/Passenger, private vs busy), "call `get_briefing` first", UK date style, confirm with the user before tagging a crew. This is the condensed form of the skill, so users who never install it still get the key habits.
- **Offing skill** (`SKILL.md`, downloadable from Settings → AI assistants for upload to claude.ai): the fuller version of those habits.
- **MCP prompts**: "Plan my week", "Birthdays coming up", "What's due on Shipshape?" — cheap, shown where clients support them.
- **Outward effects** (tagging notifies real people): rely on Claude's per-tool approval via `destructiveHint`, explicit "who was notified" in results, audit, and soft-delete undo. No held/draft notifications.
- Annotations on every tool (`title` + `readOnlyHint`/`destructiveHint`) — a Directory requirement anyway.

### Rollout

`connect-claude` StagedFeature, starting at `Rollout::Admins`. Built **now (Phase 1a+)**, after Crews & Shipshape, and grows with the app:

1. OAuth + consent + Connected apps + Settings page; `get_briefing`, `find`, `save_person`, `log_chore` over people, birthdays and chores (all exist today).
2. Event tools (`save_event`, `delete_event`, `get_availability`) and the "Allow in AI connections" share setting as the Phase 1b calendar lands.
3. Promote to Everyone → Directory submission (needs a privacy policy page and a seeded reviewer account first).

### Open questions / known risks

- `laravel/mcp` issue #92 (the `mcp:use` scope skipped under `route:cache`) is fixed in v1.1: the service provider registers the scope after boot. Verified with cached routes and config.
- `laravel/mcp` v1.1.1 has server-side DCR but **not CIMD**. Claude falls back to DCR, so we can launch without it; add CIMD later (exact redirect-URI match, SSRF-safe fetch, cache).
- DCR is an open endpoint: throttle it, keep the redirect-domain allowlist (`config/mcp.php` — claude.ai, claude.com, chatgpt.com, localhost for Claude Code and desktop clients; env-extendable for self-hosters) and prune clients never authorised within a day.
- Self-hosted instances under one Directory listing — ask Anthropic (above).
- Briefing size budget — cap lists (e.g. people by recency, events to 7 days) so one call stays small; `find` is the escape hatch.
- Later (backlog): **MCP Apps** — interactive UI inside Claude (a week view or the Shipshape board rendered in-chat); also a Claude Code/Directory plugin bundling connector + skill.

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

**Where accounts come from (still no public signup).** Exactly two routes, both audited:
- **An admin creates it** — `/admin/users` ("Add user": name, email, timezone, which flags to switch on, optionally send a sign-in link now) or `artisan user:create` over SSH. The flag checkboxes matter: a new non-admin user gets nothing from an `Admins`-stage feature until it's switched on for them.
- **A crew invite is the signup** (§7). The captain enters an email and a role; the invitee opens the link and either signs in (existing account) or gives a name (new account), then accepts or declines. Invite tokens reuse the magic-link machinery (hashed, single-use, atomic claim) rather than a second token system, with a 7-day expiry since invitations aren't sign-ins. While a feature is admin-only, the accept screen tells the captain if the newcomer doesn't have it yet, instead of failing silently.

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

**Feature flags (from Phase 0).** Use **Laravel Pennant** (first-party, tiny): every meaty feature ships behind a flag, enabled for you first, then household, then everyone. Each flag has a **rollout stage** — Off, Admins (`ADMIN_EMAILS`), Beta testers (admins plus users ticked on `/admin/users`), Percentage (all of those plus a stable slice) or Everyone — declared in code as a starting point and changed on `/admin/features`, audited. Per-user overrides win over the stage. Only overrides are stored, so a stage change reaches everyone at once. This is what makes monthly trains safe — half-finished work can merge dark — and later doubles as the premium-feature gate for monetisation (a "flag by subscription tier" is the same mechanism).

**Dynamic documentation (early feature, Phase 1b).** Docs are part of the app, not a separate site — markdown pages in the repo, rendered at `/docs`, so they deploy *with* the code and can never describe a version you're not running. Three rules make them "dynamic":
1. **Deep links both ways** — docs reference app screens as real clickable links ("add birthdays on [your People page](/people)"), and app screens carry contextual "?" links to their doc section. A tiny route-name helper keeps links from rotting (broken internal links fail CI).
2. **Flag-aware rendering** — doc sections are tagged with their feature flag; the renderer checks Pennant at request time and badges sections **Preview** or **Premium** (or hides them entirely) to match what *this* user can actually see. Docs never advertise features the reader doesn't have.
3. **Docs in the definition of done** — a feature PR isn't mergeable without its doc page (checklist item in the PR template), which is what keeps them matching deployments forever.

**Until `/docs` ships: the GitHub wiki.** `docs/` is published to the repo's GitHub wiki on every push to `main` (`.github/workflows/wiki.yml` → `.github/wiki/build.php`), and the in-app Documentation link points there for now. It's a one-way mirror, with wiki editing restricted to collaborators: `docs/Home.md` is the landing page, `docs/guide/` holds user-facing pages, and everything else under `docs/` is listed as Development. Links are written the way `/docs` will need them — relative links between docs files, root-relative links (`/people`) for app screens — and broken ones fail CI. When `/docs` lands it renders the same files and the in-app link moves there; the developer pages can stay on the wiki. One gap to accept meanwhile: the wiki isn't flag-aware, so guide pages should only describe features that are fully rolled out, or say plainly that they may not be switched on yet.

## 21. Building in the open: security & licensing

Public repo from day one is viable and healthy **if** we treat the repo as hostile-readable:

**Repo hygiene**
- No secrets, ever: `.env` git-ignored with a committed `.env.example`; deploy keys/API keys only in Actions secrets. Enable GitHub **secret scanning + push protection** and **Dependabot** (security + version PRs) on day one.
- No real data: seeders/fixtures use fake people only. Never commit real feed tokens, screenshots with real events, or the production DB schema dumps with data.
- Branch protection on `main`/`rc`; Actions pinned to SHAs for third-party actions.

**Application security (public code = attackers can read the map, so the locks must be real)**
- Security comes from secrets and design, not obscurity: all tokens (feed URLs, magic links, MCP OAuth access/refresh tokens) are ≥128-bit random, **stored hashed**, revocable, and rate-limited.
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

**Phase 1a+ — Crews & Shipshape (ahead of the calendar)**
Household chores are the next dogfood need, so they jump the queue — and they bring the crew sharing model with them, which the calendar then reuses. Built in this order, each PR dark behind its flag (§25): spec (this) · **`/admin/users`** (add users + switch flags on for them — unblocks a partner on day one) · **crews** (crews, members, roles, crew-owned people, `/crew`) · **Shipshape core + starter picker** (lists, chores, fixed/floating schedules, tick/skip/backdate, rota, undo, the board, and the Dashboard's "In the offing" view) · **crew invites** (invite = signup) · **the ship's log + voiding** · **guide page**.

Then **Connect Claude** (§12): OAuth MCP connector behind `connect-claude` — briefing, find, people/birthdays and chore logging first; event tools join as Phase 1b lands.

**Phase 1b — Full calendar MVP (daily-driveable)**
Calendars & events (create/edit/delete, common recurrence, private + busy/free flags, all-day) · month/week/agenda views (FullCalendar) · timezone handling · people (virtual) & crews · tagging · partner + tagged + full-read shares · outbound feed tokens (ICS) · inbound ICS subscriptions + .ics import · birthdays (with/without year) + default reminder rule · email reminders via cron pipeline · installable PWA shell · Connect Claude event tools (§12 — the connector itself ships in 1a+) · settings (colour scheme global/per-device) · **event search** · **soft delete + undo/restore** (data already in audit log) · **UK bank holidays as a toggleable layer** (gov.uk feed, shared with card engine) · **full data export** (ICS + JSON) · **weekly "week ahead" digest email** (Sunday evening — proto-Betty voice) · **account deletion flow** · **share offer/accept consent** · **accessibility baseline** (keyboard nav, contrast-checked schemes, axe-core in CI) · **dynamic documentation** (in-app `/docs`, deploys with code, deep-linked both ways, flag-aware Preview/Premium badges — see §20).

**Phase 1.5 — Comfort**
iOS web push · nicer onboarding coach for Add-to-Home-Screen · feed health dashboard.

**Phase 2 — Differentiators**
Card & post engine with bank holidays · vCard import *(moved to Phase 1a; Phase 2 adds re-import/merge improvements)* · crew web views for passengers (tokenised, no account) · virtual→real account upgrade flow · reminder rule editor UI · **availability polls** ("when can the club meet?" — flagship group feature, future premium candidate) · **RSVPs on tagged events** (yes/no/maybe **+ custom questions per event** — dietary, plus-ones; answerable via tokenised links, no account needed) · **anniversaries & significant dates** (generalise birthdays into a person `dates` table with per-date **show** and **remind** toggles — e.g. a passed relative's birthday visible but never alerted; respectful rendering for remembrance dates, exempt from playful copy) · **conflict warnings across shared calendars** · **bin collection reminders** (council schedule import) · **email-to-event, stage 1** (private inbound address per user via inbound-parse webhook; attached .ics auto-imported using existing parser) · **places & travel buffers** (named places incl. Home; events with locations get a manual/default travel margin rendered as hatched "error bars") · **background layers, stage 1: work hours** (fixed weekly pattern or subscribed timesheet ICS; renders as greyed ambient shading, not events — week view shows shape, day view shows meetings).

**Barometer** (opt-in, flag-gated, see §26) — private CBT-style anxiety log: readings + list + PDF/CSV export first, then the second-rating nudge and stats; calendar marker once a calendar view exists.

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
- **Shipshape "On deck" digest** — one morning email of today's and overdue chores that are yours or anyone's (never other people's), skipped on empty days. Deliberately not in Shipshape v1; wants calendar context first so it can become one combined daily email.
- **Chores as an optional feed layer** — opt-in, off by default. VTODO support in calendar apps is poor and fake all-day events clutter, so it needs a good answer before it ships.
- **Shipshape stats & fairer rotas** — who's done what this month, effort points, "whoever did least". Fun, not v1.
- **Shared generic lists** (shopping and the like) — would reuse the same owner-by-user-or-crew sharing as chore lists. **Not approved**: it needs its own decision against the tasks non-goal (§1) before anything is built.

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
| MCP token in URL (for clients without header support) | **Resolved:** dropped — OAuth from day one (§12) |
| Other people's data reaching Claude via a connection | **Resolved:** sees what you see minus private; sharers/Captains can switch a share/crew to busy-only for AI connections (§12) |
| Name/domain | **Resolved:** Offing decided; register `offing.uk` via 20i once confirmed live in their domain search |
| Email deliverability from shared hosting | Consider Postmark/Resend free tier from Phase 1 |
| Licence | **Resolved:** AGPL-3.0 + DCO on external PRs (see §21) |
| Chores becoming a to-do app | **Resolved:** recurring household upkeep only, no one-offs (§1, §25); generic lists need their own decision |
| Groups vs shared spaces | **Resolved:** one concept, crews with captain/crew/passenger roles (§7); personal visibility shares stay separate |

## 25. Shipshape — household chores

*Decided October 2026. Flag `shipshape` (starts at `Admins`). Built before the Phase 1b calendar (§23, Phase 1a+).*

**What it is.** Shared, recurring household chores you tick off: the bins, the hoovering, the monthly deep clean, the car MOT. It's the §1 carve-out — every chore repeats, and nothing here is a task. **Shipshape** is the section's name (nav, flag, docs); the copy still says "chores" so nobody has to guess. Ticking something off writes to the **log** (the ship's log).

**Part of Offing, not beside it.** Shipshape lives in the normal app shell — a sidebar item next to People, the same shadcn components and breadcrumbs, no separate header or branding. **Crew** is its own top-level item, because calendars and anything shared later hang off it too. The **Dashboard** becomes the combined view, "In the offing": today's and overdue chores (tickable there, same undo) plus upcoming birthdays, and events once the calendar lands. Each part only appears if its flag is on for you.

### Lists, areas and the board

- A **chore list** is owned by **you** (private) or **a crew** (shared with every crewmate, §7). Sharing is set on the list only and chores inherit it. A private list can be moved to a crew (audited as a share); moving a crew list back to private is captain-only and confirmed, since it removes everyone else's access. Typical setup: a private "Mine" and the crew's "Home".
- An **area** (Kitchen, Bathroom, Car, Admin…) is a free-text label with suggestions, for grouping and filtering only — not another level of hierarchy.
- **The board merges every list you can see, sorted by urgency**: Overdue · Today · This week · Later · Needs a date — with filters for each list. Urgency, not list, is the primary grouping because that's what a chore board is for.

### Schedules

Every chore picks one of two modes; the starter suggestions come pre-set so most people never think about it:

| Mode | Next due date | Stored as | Good for |
|---|---|---|---|
| **Floating** (default for cleaning) | `done_on` + interval — the clock restarts when you tick it | `every` + `unit` | Clean the oven every 3 months |
| **Fixed** (default for bills/renewals) | The next date in its pattern after today, however late you were | RRULE on dates, expanded by sabre (§5) | Bins every Tuesday, car tax on 1 March |

- **One live due date per chore, so missed ones never pile up.** Three missed weekly hoovers is "2 weeks overdue", not three outstanding copies; ticking it clears it.
- **Chores are dates, not times** (like all-day events, §10): `due_on` is a DATE and "today" is the viewer's profile timezone. No DST maths anywhere.
- **"Needs a date"**: a fixed chore without a date (e.g. a starter "Car MOT" whose date you don't know yet) sits in its own group and never shows as overdue. No invented dates, ever.
- **Heads-up** (fixed only): `lead_days` puts it on the board early — car insurance shows 3 weeks before renewal so there's time to shop around — while the due date stays the renewal date.
- **Skip ≠ done.** "Skip this one" (away on holiday) moves the date on and logs a *skip* with an optional reason, so history and any future stats stay honest. A skip doesn't move the rota.

### Assignment & rotas

- A chore is **anyone**, **pinned to one member**, or on a **rota** (an ordered list of crew members, passengers included). Private lists have no assignment — it's just you.
- **Anyone can tick any chore.** Each entry records **done by** (who actually did it — defaults to you, can be a passenger), **logged by** (the signed-in account, always) and **when** (today, yesterday or a picked date; never the future).
- **The rota advances on completion, from whoever it was assigned to — not whoever did it.** Covering for someone doesn't steal their turn or give yours away. Skips don't advance it.
- Floating chores compute the next due date from the date it was **done**, not when it was logged ("cleaned the oven Saturday, logging it Monday").

### Forgiveness: undo, voiding and the log

- **Ticking is instant** — no confirmation dialog — with a 10-second **Undo** toast (the toast also links to "who/when").
- After that, **any crewmate can void any entry** from the log ("logged by mistake"). Voiding is never a delete: the entry stays, struck through, with who voided it. Each entry stores the chore's `before` state (due date, rota position); voiding the chore's most recent live entry restores it. Voiding an older entry just strikes it from history and stats — later entries already superseded its effect on the schedule. An Undo from the toast is a void with `void_reason = undo`, which the log hides by default.
- **Two people ticking at once**: ticks carry the chore's `version`; a stale tick gets a friendly "Sam just logged this" instead of a double entry.
- **History never rewrites itself.** Entries snapshot the chore's title and the doer's name, so renames, leavers and deleted accounts don't change last month's log. Deleting a chore or list is soft with undo, and its entries stay in the list's history. The log is kept indefinitely (it's tiny), filterable by person and area.
- **Audit.** Chore entries are their own append-only history, so ticks don't also go to `audit_log`. Everything about sharing does (§3.5): list moves between owners, list deletes and restores, and all crew events (§7).

### Starter suggestions

When a list is created you get **"Want a head start?"** — suggestions grouped Daily / Weekly / Monthly deep clean / Yearly & renewals, **all pre-ticked**, with tick-all/untick-all per group. Fixed items show an inline date field (left blank → "needs a date"); bins asks for bin day. Seeded **per list**, not per user, so a partner joining never duplicates anything. Also reachable later from "Browse suggestions". The set lives in code (a config/class), not the database, so improving it is a PR.

Daily: wash up / load dishwasher · wipe kitchen sides · make the bed · feed pets.
Weekly: hoover & mop floors · clean bathroom · change bedding · bins & recycling out *(fixed, bin day)* · dust surfaces · laundry · clear out fridge.
Monthly deep clean: clean the oven & hob · inside the microwave · descale kettle & taps · windows & mirrors · wash the bins · test smoke & CO alarms *(fixed)* · dishwasher & washing-machine filters.
Yearly & renewals *(all fixed, date needed unless noted)*: car tax · car MOT · car insurance · home insurance · boiler service · TV licence · gutters & drains *(floating)* · rotate the mattress *(floating, 6-monthly)*.

### Deliberately not in v1

No notifications (a daily "On deck" digest waits for calendar context — §23 backlog). Chores stay **out of ICS feeds**. No stats or leaderboards. No per-chore reminder times. No one-off chores, ever (§1).

## 26. Barometer (private anxiety log)

An opt-in, owner-only anxiety/worry log modelled on the common CBT anxiety-tracking form. Each entry is a **reading**. Tracking epic: #34.

**Naming.** "Barometer" in the UI and code (`Barometer` flag, `barometer_readings`): nautical, scaled like the ratings, and not a "diary" or "journal" — those words are kept free for a more general section later. Exports are titled plainly ("Anxiety log") so they read as the user's log, not our branding; the PDF footer carries a small plain-text "Made with Offing · offing.uk" credit — no link, no tracking parameters.

**Data model.** One fixed column per header of the paper form: no template engine until a second CBT worksheet gives us real cases to generalise from.

| Form header | Column | Notes |
|---|---|---|
| Day/Time | `occurred_at` (UTC, nullable) + `tzid` + `local_date` | `local_date` always set (from the time, or given directly); day grouping/exports/stats never do TZ maths. Null `occurred_at` = time not recorded (a worry remembered from an earlier day) — never an invented midnight, so time-of-day stats can skip it |
| Situation | `situation` | encrypted text |
| Thoughts | `thoughts` | encrypted text |
| Physical sensations | `sensations` | encrypted text |
| Anxiety rating | `rating_before` | 0–10, required, plain integer |
| What did I do? | `coping_action` | encrypted text — a past-tense record, not a task (§1 non-goal) |
| What did I say to myself? | `self_talk` | encrypted text |
| Second rating | `rating_after` | 0–10, nullable, usually added later |

Only the day and first rating are required (quick capture mid-anxiety); the time defaults to now but can be changed or left out. Free text uses Laravel's `encrypted` cast (`APP_KEY`), so it can't be searched in SQL — ratings stay plain for stats. Hard deletes only; account deletion cascades.

**Privacy.** Health data (UK GDPR special category). Never visible to partners — "see everything" does not include it — or to crews (§7), and never in ICS feeds, free/busy or notification text. AI assistants get it only through Barometer's own consent switch on the connection (§12, "Barometer: write in, stats out"): readings can be logged, stats come back as aggregates, and no reading or free text is ever read back. Audit rows record deletes and exports, never content.

**Describe, don't diagnose.** Stats only describe ("average 6, down 2.5 after coping"); nothing interprets severity — that keeps us clear of software-as-a-medical-device territory. The UI names the CBT origin, says it works best alongside a therapist, and signposts Samaritans (116 123).

**Phases.**
1. **Readings, list, export** (#35): add/edit/delete; list grouped by day; PDF (dompdf, A4 landscape, laid out like the paper form, header repeats per page) and CSV (UTF-8 BOM, formula-injection guard) over a date range of up to a year, with optional column exclusion. Streamed with `no-store`, never written to disk.
2. **Check-ins** (#36): ~30 minutes after a reading is saved (from the app or an AI assistant), a content-free "Time for a quick check-in" via `notification_outbox` (`kind = barometer_check_in`, payload = the reading id only), linking to the reading's second rating.
   - **Who gets one:** only readings with a time that happened within the last hour (backfills and "time not recorded" get none), with no second rating yet, for users with the flag and `users.barometer_check_ins` on (the default).
   - **Re-checked at send time:** skipped if the second rating was added, the reading deleted or check-ins switched off. Given up two hours after it was due (a cron outage), because a late check-in is worse than none.
   - **Delivery:** push if the user has a device, otherwise email, and push problems fall straight back to email (no push retry: it's time-sensitive). The words never mention anxiety or what was logged: push title "Offing", body "Time for a quick check-in."; the email subject is the same.
   - **Off switch:** on the Barometer page, plus a signed one-click link in the email (RFC 8058, same pattern as reminders). Both audited.
   - Drained by `reminders:drain` alongside birthdays; admin Outbox shows it as "Barometer check-in" and nothing else.
3. **Stats & look-back** (#37): average drop from first to second rating (the headline), weekly averages, weekday × time-of-day heatmap, later cross-referenced with the user's own calendar. Optional summary page on the PDF. v2 candidate: optional tags on coping actions/sensations ("deep breaths drops you 3 points on average").
4. **Calendar marker** (#38, waits for a calendar view): a layer, never rows in `events` — days marked by peak rating, day view pins each reading.
