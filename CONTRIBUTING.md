# Contributing to Offing

Thanks for taking a look. This is a small, mostly-solo project, but it's
public and built in the open on purpose — contributions are welcome.

Please read the [Code of Conduct](CODE_OF_CONDUCT.md) before participating.

## Before you start

For anything beyond a small fix, skim `docs/SPEC.md` first — it's the full
design spec (naming, data model, sharing model, roadmap phases) and
`CLAUDE.md` for the condensed day-to-day conventions. A few hard rules worth
knowing up front: no passwords anywhere in the schema, no ads/tracking ever,
tasks/to-dos are a permanent non-goal, and every auth/share/token action
must be audited.

## Local development

- **PHP 8.4+ and MySQL**: use XAMPP (Windows/Mac/Linux) or Laravel Herd —
  either supplies the runtime; Laravel and Vite sit on top. SQLite works
  fine for quick local dev too, and is what CI uses.
- **Serve the app**: `php artisan serve` (http://localhost:8000). No Apache
  vhost config needed for day-to-day dev.
- **Composer and Node are separate installs** — Composer for PHP
  dependencies, Node LTS for the Vite build. Day-to-day loop is two
  terminals: `php artisan serve` + `npm run dev` (or `composer run dev`,
  which runs both plus the queue listener and log viewer together).
- **Local email**: run [Mailpit](https://github.com/axllent/mailpit) and
  point your `.env` mail settings at it — every email (including magic
  links) lands in a local inbox UI instead of actually sending.
- **Queued jobs**: mail is queued, not sent synchronously. `composer run dev`
  already runs `queue:listen` for you; if you're running `artisan serve`
  bare, drain the queue manually with `php artisan queue:work --stop-when-empty`.

## Documentation

The [wiki](https://github.com/casman300/offing.uk/wiki) is generated from
`docs/` on every push to `main`. Edit the markdown files there, not the wiki
itself, because wiki edits get overwritten. `docs/Home.md` is the landing page,
`docs/guide/` holds user-facing pages, and everything else is listed under
"Development". Link between docs with relative paths (`[spec](../SPEC.md)`)
and to app screens with root-relative ones (`[People](/people)`). Check your
links with `php .github/wiki/build.php /tmp/wiki`, which CI also runs.

## Before opening a PR

```
vendor/bin/pint
vendor/bin/pest
npx tsc --noEmit
npm run build
```

For anything touching auth, payments, or other security-relevant code,
boot the app and exercise the actual flow in a browser — feature tests
alone have already missed at least one real bug in this codebase (a
mail-rendering bug invisible to `Mail::fake()`).

## Commit sign-off (DCO)

Every commit must include a `Signed-off-by` trailer, certifying you wrote
the change or otherwise have the right to submit it under this project's
licence (the [Developer Certificate of Origin](https://developercertificate.org/)).
Add it automatically with:

```
git commit -s -m "your message"
```

PRs with unsigned commits will fail the `dco` check and need a rebase
(`git rebase --signoff <base-branch>` retroactively signs a whole branch).

## Branching

- `main` is production, protected, releases only.
- `rc` is staging — this is what feature branches target.
- Branch from `rc` as `feat/…` or `fix/…`, keep it short-lived, open the PR
  against `rc`.

## Licence

Offing is licensed under AGPL-3.0-only (see `LICENSE`). By contributing,
you agree your contribution is licensed under the same terms.
