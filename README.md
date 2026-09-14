# Delegated

**Departmental content ownership and delegated administration for the Statamic 6
control panel.**

A hospital or a university runs one site that many departments write. Statamic's
permissions stop at the collection: a role can edit *pages*, or not, but there is
no way to say "everything under `/cardiology` and nothing else", and no way to
give cardiology an administrator who manages cardiology's people without being
able to reach anyone else's. Delegated adds both — a *territory* (a branch of the
site, a collection, or a taxonomy term) assigned to user groups or individual
users, enforced everywhere the control panel could reach content outside it,
and a *territory admin* who can add and remove members within that territory
and nothing more.

Delegated restricts the **control panel only**. It does not hide content from
site visitors, and it does not restrict the REST or GraphQL APIs, which have no
user to restrict. The two sections below say exactly what is and is not
covered; `LICENSE.md` says the same in plain words.

## Installation

```
composer require bpmore/statamic-delegated
php artisan migrate
```

The migrations create `delegated_territories`, `delegated_assignments` and
`delegated_audit` on the site's default database connection. Then, as a super
user, give the people who will shape territories the **Delegated → Manage
territories** permission and open **Users → Territories**. A territory admin
needs no permission beyond `access cp` and whatever content permissions their
role carries; **Users → My territories** appears for them once they administer
a territory. Run `php please delegated:doctor` after setup and after any change
to roles, and read `CHANGELOG.md` before each upgrade.

Config (`php artisan vendor:publish --tag=delegated-config`): the resolution
cache store and TTL. Nothing else is configurable; the invariants are not
options.

## Security

A bug that lets a control-panel user reach content outside their territory is a
vulnerability, not a defect. Report it privately — by email to
<(address removed)>, or through [GitHub private vulnerability
reporting](https://github.com/bpmore/statamic-delegated/security/advisories/new)
once the repository is public — and not in a public issue. You will
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

## What Delegated covers

Every control-panel surface through which a signed-in user could reach an
**entry** outside their territory, on both the flat-file and the Eloquent
driver, each with a denial test:

- entry listings, the search box on a listing (with or without a search index),
  and the command palette;
- the edit screen, update, publish and unpublish, live preview, revisions, and
  the two direct-URL redirects (`cp/edit/{id}`, `{entry}/{slug}`), which answer
  404 outside the territory exactly as for an entry that does not exist;
- bulk actions — Statamic's own are refused per entry, and duplicating an
  entry requires the right to view the source;
- creating an entry under a parent: the parent must be inside the territory,
  and creating at the root needs a root territory;
- the structure tree: read pruned to the territory, and a saved tree merged
  back so nothing outside it can be moved, re-parented, or deleted;
- the navigation tree editor, for branches linked to entries outside the
  territory;
- the relationship picker (the `entries` fieldtype), both its listing and its
  validation of submitted ids;
- the users listing and users screens, for anyone in a territory: the people
  they administer and nobody else (invariant 6).

A territory admin's own powers are held to six invariants, each refused with a
named reason and recorded in the audit log: they cannot grant a role they do
not hold, act on a territory they do not administer, touch a super user,
manage roles, widen their own territory, or see users outside it.

## What Delegated does not cover

Stated plainly, because a security product that is vague about its edges is
worse than none.

- **The front end.** Site visitors see whatever the site publishes. Delegated
  does not hide content from them and has no opinion about it.
- **The REST API and GraphQL.** Both authenticate with a shared token, not a
  user, so there is no one to confine. If the site has either switched on,
  content in it is reachable regardless of territory.
- **Anything that is not an entry.** Territories scope entries. Assets,
  globals, forms and their submissions, and the taxonomy *terms* screens are
  governed by Statamic's own permissions only. A taxonomy-term territory
  scopes the entries tagged with the term, not the term itself.
- **A third-party action's `view=form` read path.** Statamic's action runner
  returns an item's full values after only the action's own `authorize()`
  check. Delegated closes this for Statamic's built-in actions, but an addon
  action with a permissive `authorize()` becomes a way to read an entry
  outside the territory. Review the actions a site installs.
- **An entry removed from its tree without being deleted, on the Eloquent
  driver.** It keeps its last URI until saved again (Statamic's behaviour), so
  the department that owned it still sees it; on the file driver it has no URI
  and belongs to nobody. `delegated:doctor` lists such entries. Neither reading
  exposes the entry to another department.
- **Console commands and queue jobs.** No signed-in user, nothing to confine.
- **Statamic versions outside the pinned range.** The addon reaches into
  control-panel internals that are not a published interface; on an unverified
  minor it may restrict less than before without saying so. See Requirements.

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
- Statamic 6, within the range named in `composer.json` — currently 6.31 and
  6.32. The constraint is deliberately not open-ended: the addon reaches into
  control-panel internals that are not a published interface, so CI runs the
  suite against every minor in the range, and the range is widened only after
  a new minor passes. [`DECISIONS.md`](DECISIONS.md) records which minor was
  last verified and the steps for the next one.

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
inventory in `PROGRESS.md`. The inventory probe boots Statamic core alone —
without Delegated — against the vendored `statamic/cms`, and is re-run on every
minor before the constraint is widened:

```
vendor/bin/phpunit -c phpunit.xml recon/DelegatedInventoryProbeTest.php --no-progress 2>&1 \
  | sed -n '/PROBE RESULTS/,/=======/p'
```

The query-scoping spike still runs from a host site (`SPIKE_DRIVER=file|eloquent`).
