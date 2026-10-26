# Security Policy

Offing handles real personal data — birthdays, postal addresses, private
calendar events, magic-link and feed tokens — so we take security reports
seriously and would rather hear about a problem privately than see it in a
public issue.

## Reporting a vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Instead, email **charlie@casman.co.uk** with:

- A description of the vulnerability and its potential impact
- Steps to reproduce it (a minimal example is ideal)
- Any suggested fix, if you have one

You should get an acknowledgement within a few days. This is a small,
mostly-solo project without a formal SLA, but genuine vulnerabilities will
be prioritised over everything else on the roadmap.

## Scope

In scope: the application code in this repository, its GitHub Actions
workflows, and the deployment configuration they describe.

Out of scope: the underlying 20i hosting infrastructure itself, and
third-party dependencies (report those upstream — though we'd still like to
know, so we can track and patch).

## Our commitments

- All tokens (magic links, feed URLs, MCP API tokens) are high-entropy,
  stored hashed, revocable, and rate-limited.
- No passwords exist anywhere in the schema — there is nothing to leak in
  a credential-stuffing sense.
- Every auth, share, and token-related action is written to an append-only
  audit log from day one.
- Dependabot and secret scanning are enabled on this repository.

See `docs/SPEC.md` §21 for the fuller security and licensing rationale.
