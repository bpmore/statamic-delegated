# Delegated

**Departmental content ownership and delegated administration for the Statamic 6
control panel.**

A hospital or a university runs one site that many departments write. Statamic's
permissions stop at the collection: a role can edit *pages*, or not, but there is
no way to say "everything under `/cardiology` and nothing else", and no way to
give cardiology an administrator who manages cardiology's people without being
able to reach anyone else's. Delegated adds both — a *territory* (a branch of the
site, a collection, or a taxonomy term) assigned to a user group, enforced
everywhere the control panel could reach content outside it, and a *territory
admin* who can add and remove members within that territory and nothing more.

Delegated restricts the **control panel only**. It does not hide content from
site visitors, and it does not restrict the REST or GraphQL APIs, which have no
user to restrict. See `LICENSE.md` for the plain-words version of what is and is
not covered.

> **Pre-release.** The package scaffold is in place; the product is being built
> phase by phase and is not yet installable for use. This file will be rewritten
> before 1.0.

## Security

A bug that lets a control-panel user reach content outside their territory is a
vulnerability, not a defect. Report it privately — through [GitHub private
vulnerability reporting](https://github.com/bpmore/statamic-delegated/security/advisories/new)
or by email to <(address removed)> — and not in a public issue. You will
get an acknowledgement within 3 business days and a severity within 7 days of
that; a confirmed cross-territory exposure is fixed or mitigated within 30 days.
[`SECURITY.md`](SECURITY.md) has the full policy, including what is in and out
of scope.

## Requirements

- PHP 8.2 or later
- Statamic 6, within the range named in `composer.json`. The constraint is
  deliberately not open-ended: the addon reaches into control-panel internals
  that are not a published interface, so each minor is verified before the range
  is widened.

## Development

The addon is developed on its own and linked into a host site through a Composer
path repository, which is the layout Statamic recommends for an addon reused
across projects.

```
composer install
vendor/bin/pest            # tests
vendor/bin/pint            # formatting
```

`recon/` holds the reconnaissance probes that produced the enforcement-surface
inventory in `PROGRESS.md`. They run from a host site, not from this repository,
and are re-run against every Statamic minor before the constraint is widened.
