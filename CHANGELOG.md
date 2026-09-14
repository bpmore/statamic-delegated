# Changelog

## 1.0.0 — 2026-09-14

First release. Verified against Statamic 6.31.0 and 6.32.0 on PHP 8.3 and 8.4,
flat-file and Eloquent drivers.

### Territories

- A territory is a structure branch, a collection, or a taxonomy term in one
  site, assigned to user groups or individual users at `member` or `admin`
  level. Membership flows down the territory hierarchy. Precedence when several
  contain the same content: branch, then term, then collection.
- A root (`/`) territory is the whole site, entries outside the tree included.
  Below the root an entry outside its tree belongs to nobody.

### Enforcement (control panel only)

Every surface through which a signed-in user could reach an entry outside
their territory, each with a denial test on both drivers: entry listings and
listing search (with or without a search index), the command palette, the edit
screen, update, publish and unpublish, live preview, revisions, the direct-URL
redirects (404 outside the territory), bulk actions and duplicate, creating
under a parent, the structure tree (pruned on read, merged on save), the
navigation tree editor, the relationship picker, and the users listing and
screens. Super users and holders of `configure collections` are exempt, as
Statamic's own policy makes them.

### Delegated administration

- Territory admins add and remove members (by email address or group handle),
  set the roles they themselves hold, and rename their territories, under six
  invariants: cannot grant a role they do not hold; cannot act on a territory
  they do not administer; cannot edit, demote or delete a super user; cannot
  create or modify roles; cannot widen their own territory; cannot see users
  with no assignment overlapping their territory.
- Control-panel screens: **Territories** (site administration, behind the
  `manage territories` permission) and **My territories** (self-service for
  territory admins).

### Audit log

Append-only rows for every territory, assignment and member change and for
every refused request, naming the invariant. Read site-wide by `manage
territories` holders, and per territory by its admins with actors from outside
the territory unnamed.

### `php please delegated:doctor`

Reports territories whose scope no longer exists, dangling parents,
assignments to deleted users or groups, empty territories, non-super holders of
`configure collections`, territory admins with user-management permissions,
entries outside their stored tree, trees naming missing entries, and content
that belongs to no territory. `--json`; exit 1 on a problem.

### Not covered, by design

The front end, the REST and GraphQL APIs, assets, globals, forms, taxonomy
term screens, console and queue, third-party actions' `view=form` read path,
and Statamic versions outside the pinned range. See the README.
