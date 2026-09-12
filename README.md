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

## The audit log

Every change Delegated makes is written to `delegated_audit` before anything
else happens: territories created, changed and deleted; assignments granted,
changed and revoked; a member's roles, attributes and deletion; and every
request an invariant *refused*, with the invariant's name. The table has no
`updated_at` and the code has no way to change or remove a row.

A site administrator (`manage territories`) reads the whole log from the
Territories screen. A territory admin reads their own territory's log from My
territories — their rows, but an actor from outside the territory (a site
administrator, say) appears as "someone outside your territory", never by name.

The log begins when Delegated is installed; territories that existed before it
have no creation row.

## `php please delegated:doctor`

Reads everything and changes nothing. It reports territories whose branch,
collection, term or site no longer exists (their members can reach nothing);
assignments to users or groups that are gone; territories with nobody in
them; non-super users holding `configure collections`, whom Statamic exempts
from every entry check; territory admins who also hold a user-management
permission and so can leave their territory through Statamic's own screens;
entries missing from their collection's stored tree, and trees naming entries
that do not exist; and, per collection and site, how much content belongs to
no territory at all. `--json` for scripts. Exit code 1 when there is a
problem, so it can gate a deploy.

## Who Delegated does not restrict

Some people bypass everything Delegated does, by design, and the product is safer
for saying so than for pretending otherwise.

- **Super users.** Statamic exempts them from every policy check, and so does
  Delegated: a super user sees every entry, every branch, every user, on every
  screen, whether or not they are also listed in a territory. Being listed as
  head of a department never narrows what a super user can do.
- **Anyone holding the `configure collections` permission.** Statamic's own
  entry policy treats that permission as a full bypass (`EntryPolicy::before()`
  returns true for it), so restricting the listing for such a user would only be
  theatre. Delegated treats it as super-equivalent for *content*. A territory
  admin can never hand it out (Phase 2, invariant 4); who holds it is the
  site's decision.
- **People in no territory at all, on the users screens only.** Delegated
  confines a territory member's view of Statamic's users screens to the people
  they administer. Someone who belongs to no territory is left to Statamic's own
  user permissions — that is how a site keeps a non-super user administrator.
  Their view of *content* is still nothing, because nothing is theirs.
- **Console commands and queue jobs.** There is no signed-in user to confine.

Everyone else — every territory member and territory admin, whatever roles they
hold — is confined to their territories on every control-panel surface listed
in `PROGRESS.md`'s inventory.

## Requirements

- PHP 8.3 or later
- Statamic 6 **Pro** — roles, permissions and more than one user are Pro features, and so
  is everything Delegated does
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
npm install && npm run build   # control panel assets (the build is committed)
```

The suite runs every enforcement scenario twice: under `tests/Feature` with entries in the
flat-file Stache, and under `tests/Feature/Eloquent` with entries in the database through
`statamic/eloquent-driver`. A scenario that passes on one driver and not the other is a bug.

`recon/` holds the reconnaissance probes that produced the enforcement-surface
inventory in `PROGRESS.md`. They run from a host site, not from this repository,
and are re-run against every Statamic minor before the constraint is widened.
