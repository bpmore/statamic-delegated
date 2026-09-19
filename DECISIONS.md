# Decisions

The record of what Delegated has been verified against and why it is built the
way it is. `PROGRESS.md` holds the full working notes; this file is the short
version that survives them.

## Verified Statamic versions

Delegated reaches into control-panel internals that are not a published
interface (hooks on the entries index query, the structure tree endpoints, the
users listing, the relationship picker, `EntryPolicy::before`). Each Statamic
minor is verified before the range in `composer.json` is widened to include it.

| statamic/cms | Verified | PHP | Suite | Asset build | Phase 0 inventory |
|---|---|---|---|---|---|
| 6.31.0 | 2026-09-11 → 12 | 8.3, 8.4 | built and passed against it throughout | yes | run against it (`recon/`, PROGRESS.md Phase 0.1) |
| 6.32.0 | 2026-09-12, inventory 2026-09-14 | 8.4 locally; CI 8.3, 8.4 | 398 pass | reproduces the committed bundle byte for byte | re-run from this repo: every surface answers as on 6.31.0 (PROGRESS.md Phase 5.4) |
| 6.33.0 | 2026-09-19 | 8.4 locally; CI leg added for 8.3, 8.4 | 398 pass | reproduces the committed bundle byte for byte | re-run from this repo: all 61 lines identical to the 6.32.0 run (PROGRESS.md, Statamic 6.33.0) |

**Last verified: 6.33.0.** Pin: `>=6.31.0 <6.34.0`.

## Re-verification on every Statamic minor

CI's `next-minor` job installs the newest 6.x and runs the suite; it is
advisory (`continue-on-error`) and cannot fail a branch. When it goes red, or
when a new minor appears, in this order:

1. From this repo: `composer require "statamic/cms:~6.NN.0" --with-all-dependencies`
   then `vendor/bin/pest --ci`. Every failure is a surface that moved; fix the
   enforcement, never the test's expectation, unless the surface is gone.
2. `npm run build` and `git diff --exit-code -- resources/dist`. A changed
   bundle means the Vite plugin or the UI kit changed; check the pages by hand.
3. Re-run the Phase 0 inventory from this repo — it boots core without
   Delegated against the vendored minor:
   `vendor/bin/phpunit -c phpunit.xml recon/DelegatedInventoryProbeTest.php --no-progress 2>&1 | sed -n '/PROBE RESULTS/,/=======/p'`
   — and compare line by line with the previous run in `PROGRESS.md`. A
   surface that answers differently is a new enforcement point, and a new
   denial test. Also `diff -rq` the two minors' `src/` (Composer's cache has
   both archives) for anything under `Http/Controllers/CP`, `Hooks`, `Policies`,
   `Fieldtypes`, `Structures`, `Actions`, `Stache/Query`, `Query`.
4. Click through the three screens in a host on that minor: Territories,
   My territories, and both Activity modals.
5. Add the minor to `statamic:` in `.github/workflows/tests.yml`, widen the
   pin in `composer.json`, add a row to the table above, commit naming the
   minor.

A minor that cannot be made to pass is not added; the pin stays where it is
and the README's requirement line says so.

## Decisions

Dated, in the order they were made. The reasoning in full is under the same
heading in `PROGRESS.md`.

### 1. PHP floor is `^8.3`, not the spec's `^8.2` (2026-09-11)

Every release of `statamic/eloquent-driver` requires 8.3, as do Laravel 13
and the `statamic/statamic` skeleton; Statamic 6 reaches 8.2 only through
Laravel 12. A product that promises both drivers must test both in its own
suite, which needs the driver installable here. Reversible: one constraint
line, and the Eloquent tests leave CI.

### 2. Statamic is pinned to a range of verified minors (2026-09-11, widened 2026-09-12)

Never a caret. The table above is the range. Spec §7.

### 3. Territory resolution is one pure service, consumed twice (2026-09-11)

`TerritoryMap` answers "who owns this content" from plain facts (site,
collection, URI, terms). The entries-index query hook compiles it into
predicates per driver; the policy layer asks it per entry. One source of
truth, so a listing and an edit screen cannot disagree about an entry.
Precedence when several territories contain the same content: branch, then
term, then collection — the tree is where content lives; a term is a field an
editor could change.

### 4. A root (`/`) territory is the whole site, orphans included (2026-09-11)

Below the root an entry with no URI (not in its collection's tree) belongs to
nobody, which is what closes the duplicate-a-page exfiltration path from Phase
0.1. At the root, "everything" has to include such entries, because on the
Eloquent driver they carry a route-derived URI as if they sat at the root and
no URI predicate can tell them apart. `delegated:doctor` lists them.

### 5. Super users and holders of `configure collections` are exempt (2026-09-11)

`EntryPolicy::before()` answers yes to everything for both, and Delegated does
not pretend otherwise: both are documented as outside every territory, a
territory admin can never grant either, and the doctor flags any non-super
holder of `configure collections` as a problem.

### 6. Membership flows down the hierarchy, and only down (2026-09-11)

Whoever is in a territory is in its descendants at the same level; a level
never flows up. Where two routes reach the same territory the higher level
wins.

### 7. A territory's scope is immutable to the people who administer it (2026-09-11)

Rather than define "wider" and get it wrong once, invariant 5 is enforced as:
`scope_type`, `scope_ref`, `parent_id` and `site` cannot be changed by anyone
who administers the territory or an ancestor of it. They may rename it. Only
`manage territories` reshapes a territory.

### 8. Visibility is downward only, and seeing is looser than changing (2026-09-11)

A territory admin sees the users assigned to territories they administer, or
descendants — never an ancestor, or a department head could act on the
site-wide editors who administer them. A user who also belongs to a territory
the admin does not administer is *shown* but cannot be *changed*
(`USER_SHARED`). A member who administers nothing sees nobody.

### 9. Who is confined on the users screens (2026-09-11)

Anyone who belongs to a territory. Not confined: super users, and people in
no territory at all — a site keeps a non-super user administrator by keeping
them out of every territory. Deliberately narrower than the content exemption.

### 10. Both users and groups can be assigned (2026-09-11)

Spec §6's schema names both subject types; spec §11 recommended groups only
in v1. Both are supported: a department head adding one colleague by email is
the common case (decision 13). Recorded so it is a choice, not an oversight.

### 11. Navigations are not hidden; their editors are (2026-09-11)

A navigation is public site structure, so a territory user sees every branch
of the nav tree. Editing a branch linked to an entry outside the territory,
and the tree PATCH, are refused.

### 12. `setRoles` keeps roles the actor does not hold (2026-09-12)

Replacing a member's roles would let an admin silently strip a role they do
not hold — a demotion they have no standing for. So an admin changes only the
roles they hold; asking *for* a role not held is still refused outright.

### 13. Members are added by email address, not from a list (2026-09-12)

Invariant 6: the admin is not handed a list of everyone on the site. An
unknown address is a validation error, never a hint.

### 14. The audit log is append-only by shape, and redacts outsiders (2026-09-12)

`AuditLog` has `record`, `forTerritories`, `latest` and nothing else; the
table has no `updated_at`. A territory admin reads their territories' rows;
an actor from outside them appears as "someone outside your territory", and a
refused target the viewer may not see loses both name and id. The target of a
territory's own row was in the territory when the row was written, so is
named even after leaving. The site-wide log (`manage territories`) names
everyone. The log starts when Delegated does; earlier territories have no
creation row.

### 15. The resolver caches plain arrays (2026-09-12)

Laravel 13's `cache.serializable_classes => false` refuses cached objects;
`Territory` and `Assignment` round-trip through `toArray`/`fromArray`.

### 16. Control panel only (2026-09-11, stated throughout)

Delegated does not hide content from site visitors and does not restrict the
REST or GraphQL APIs, which have no user to restrict. `README.md` says so,
`LICENSE.md` says so in plain words, and `SECURITY.md` puts the front end and
the APIs out of scope.
