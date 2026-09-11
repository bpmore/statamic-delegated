# PROGRESS — Delegated

**Spec:** `delegated-build-spec.md` · **Package:** `bpmore/statamic-delegated` · price unresolved (spec §9)
**Rule:** do the next unfinished task only, then update this file, verify the build, and commit.

> **This is a security product.** A missed enforcement point means a department saw content
> it was not entitled to see. Every check needs a test proving access is *denied*, not only
> that the happy path works. Read spec §1 before starting.

## Phase 0 — Reconnaissance (no code)
- [x] **Enforcement surface inventory** (spec §5): for each CP surface, log against a restricted test user whether `EntryPolicy` is consulted and how a restriction would apply. Entry listing, edit screen, direct URL, bulk actions, structure view, global search, command palette, REST, GraphQL, Live Preview, revisions, navigation editing. Write findings into Notes.
- [x] Read `doefom/restrict` source; record how it hooks the listing and what Statamic 6 + Eloquent would need
- [x] **Spike query scoping** on both drivers — can the entry query be scoped by territory before execution, on flat file and on Eloquent? Record the answer; it determines the architecture
- [x] Do not write an enforcement check until all three are in Notes

## Phase 1 — Foundations
- [x] Scaffold; confirm `statamic/cms: ^6.0` (pinned to a tested range, not open-ended) and `php: ^8.2`
- [x] `SECURITY.md` with a contact and stated response time — before any listing exists
- [x] Migrations: `delegated_territories`, `delegated_assignments`, `delegated_audit`
- [x] Territory resolution service with caching, unit-tested, no CP booted

## Phase 2 — Escalation invariants (tests before features)
- [x] Cannot grant a role they do not hold — written failing, parked as `todo` until 2.7
- [x] Cannot add a user to a territory they do not administer — written failing, parked as `todo` until 2.7
- [ ] Cannot edit, demote or delete a super user
- [ ] Cannot create or modify roles
- [ ] Cannot widen their own territory
- [ ] Cannot see users with no overlapping assignment
- [ ] All six failing, then all six passing

## Phase 3 — Enforcement
- [ ] Query scoping (or documented post-filter fallback with its limits stated)
- [ ] One task per surface from the Phase 0 inventory, each with a denial test
- [ ] Super users explicitly exempt, and documented as such

## Phase 4 — Territory management
- [ ] CP screens: territories, assignments, membership
- [ ] Territory admin self-service, constrained by the Phase 2 invariants
- [ ] Audit log, scoped per territory
- [ ] `delegated:doctor` — orphaned territories, entries with no owner

## Phase 5 — Hardening and ship
- [ ] CI matrix axis on `statamic/cms` minor versions, not just PHP
- [ ] `DECISIONS.md` recording the last verified Statamic version
- [ ] README stating plainly: control panel only, not front-end, not the API surface you haven't covered
- [ ] Re-run the Phase 0 inventory against the shipping version
- [ ] Tag 1.0

## Notes
<!-- Record surprises, decisions and blockers here. If a task is wrong or blocked, write why and stop. -->

### Phase 0.1 — Enforcement surface inventory (statamic/cms **v6.31.0**, 2026-09-11)

**Method.** Source read of `vendor/statamic/cms` 6.31.0 plus an empirical probe,
`recon/DelegatedInventoryProbeTest.php`, run from the host site with every Stache store and the
user/role/group paths redirected to a fixture directory (host site untouched). Fixture: structured
`pages` collection (`root: true`, route `{parent_uri}/{slug}`) with `/home`, `/cardiology`,
`/cardiology/trials`, `/oncology`, `/oncology/chemo`, `/oncology/secret-draft` (draft); nav `main`
linking cardiology, oncology, chemo. Personas: **nobody** (`access cp` only); **member** (every
`pages` permission including the "other authors" variants, plus a `Gate::before` that returns
`false` for any ability whose subject entry has a URI under `/oncology` — i.e. *a simulated
territory-aware policy layer*). Two questions per surface: (a) is `EntryPolicy` consulted at all?
(b) if the policy were territory-aware, what still leaks? Re-run the probe on every Statamic minor.

**Architectural facts that shape everything below.**

1. `EntryPolicy::before()` returns `true` for super users **and for anyone holding
   `configure collections`**. That permission is a total bypass of every entry ability. Delegated
   must treat it as super-equivalent: document it, exclude it from anything a territory admin can
   grant (Phase 2), and have `delegated:doctor` flag non-super users who hold it.
2. **Every surface that calls `can(<ability>, $entry)` is closed for free by hooking the policy
   layer** (a `Gate::before`/`after`, or a policy that wraps `EntryPolicy`). The probe confirmed that
   a territory-aware Gate alone closes: edit screen, update, publish/unpublish, live preview,
   revisions (index/show/restore), nav page edit, relationship-picker `data`, command-palette search,
   and the built-in bulk actions whose `authorize()` checks the item (delete, publish, unpublish).
   Scope **all** abilities (view/edit/update/delete/publish), not only `view` — several endpoints
   authorize `update`/`publish` without ever checking `view`.
3. **The surfaces that never consult the policy per entry are the product**: entry listing (and its
   search path), the relationship-picker index, the collection tree (GET and PATCH), the nav tree
   (GET and PATCH), flat reorder, `duplicate_entry`, and `create` under a parent. Each is itemised
   below with its seam.
4. **Author split.** When a blueprint has an `author` field, `EntryPolicy` splits edit/delete/publish
   into own-vs-"other authors" permissions (`hasAnotherAuthor()`). The host's `page` blueprint has one.
   Territory sits *on top of* this, not instead of it. Phase 2: a territory admin must not be able to
   grant an "other authors" permission they do not hold.
5. In v6 an entry's parent is changed only via the tree PATCH endpoint or `_parent` at create time —
   the edit form has no `parent` field. So "re-parenting across a territory boundary" has exactly two
   entry points: `collections.tree.update` and `collections.entries.store`.

**Inventory.** ✅ = policy consulted per entry (closed by a territory-aware policy);
❌ = not consulted (needs its own enforcement); ⚠️ = consulted but with a caveat.

| Surface | Policy? | Observed (member = territory-aware Gate; nobody = `access cp` only) | How a restriction applies |
|---|---|---|---|
| **Entry listing** `GET cp/collections/{c}/entries` | ❌ | member: 200, 6 rows, `total=6`, all oncology rows incl. the draft. Only `authorize('view', $collection)` (collection-level). nobody: 403 (collection-level). | **Official hook exists**: `Statamic\Hooks\CP\EntriesIndexQuery::hook('query', fn ($payload, $next) => …)` runs on `$payload->query` *before* `paginate()`. Precedent in the same method: multi-site already does `$query->whereIn('site', Site::authorized()…)`. Scope the query here. Caveat: when `$collection->hasSearchIndex()` the query is a **search-index query builder**, not an entry query builder — whether it accepts a URI/parent `where` is a Phase 0.3 spike item. |
| Listing `?search=` (no index) | ❌ | member: 200 → Oncology, Oncology Chemo, Oncology Secret Draft. Falls back to `where('title','like')` then the same hook. | Same hook; same search-index caveat. |
| **Entry edit screen** `GET …/entries/{id}` | ✅ | member: 403 oncology, 200 cardiology. `authorize('view', $entry)`. `readOnly` flag from `cant('edit')`. | Policy layer. |
| **Direct URL to an entry ID** | ✅/⚠️ | Wrong collection in path → 404 (route binding checks `$entry->collection()->id() === $collection->id()`). `GET …/entries/{id}/{anything}` → 302 to `editUrl()` and `GET cp/edit/{id}` → 302 to `editUrl()` **for any CP user, including nobody**, before any policy check. The target then 403s. | Policy layer closes the target. The two redirects are an **existence + collection oracle** (302 vs 404) for any entry ID. Low severity; note in README, optionally 404 them via a route override. |
| Update `PATCH …/entries/{id}` | ✅ | member: 403 oncology, 200 cardiology. `authorize('update')`. | Policy layer. |
| Publish / unpublish `POST …/{id}/publish` and `…/unpublish` | ✅ | member: 403 both. `authorize('publish')`. Note: never checks `view`. | Policy layer — scope `publish` too. |
| **Bulk actions** `POST …/entries/actions` | ⚠️ | `ActionController::run()` resolves **arbitrary submitted IDs** (`Entry::find`), never filters by `visibleTo()`, and relies solely on each action's `authorize($user,$item)`. Built-ins: `Delete`→`can('delete')`, `Publish`/`Unpublish`→`can('publish')` (member: 403 on oncology ✅). **`DuplicateEntry::authorize()` only checks `can('create',[Entry::class,$collection,$site])` — never anything on the source entry.** Result: `actions/list` offers `duplicate_entry` on an out-of-territory entry, and running it returns 200 and creates a full-content copy (probe: 6→7→8 entries). For a top-level branch the copy is **not appended to the tree** (`getEntryParentFromStructure` → root → skipped), so it has `uri=NULL` and **the member then opens it: 200, `content="Body of Oncology"`**. Two requests = full read of a restricted entry. With `context.view=form`, `run()` also returns `getItemData($items->first())` (full values) after only the action's own `authorize()` — safe for built-ins once all abilities are scoped, but any third-party action with a permissive `authorize()` becomes a read path. | (1) Scope every ability so built-in authorize()s deny. (2) **Wrap/replace `DuplicateEntry`** so `authorize()` also requires `view` on the source (or add a `Gate::before` on `create` that inspects `$action->items`). (3) Define territory for **orphan entries (not in tree, `uri()===null`)**: they must resolve to "no territory → site default → deny for non-super", never to "unrestricted". (4) `delegated:doctor` should list orphans. (5) Denial test for every built-in action on an out-of-territory ID, list and form context. |
| **Structure / tree view** `GET cp/collections/{c}/tree` | ❌ | **No `authorize()` at all.** nobody (`access cp` only): 200 with the full tree — id, `entry_title`, slug, url, status (incl. the draft), blueprint, redirect for every entry — while `GET cp/collections/pages` correctly 403s. Only `can_delete` per row touches the policy (UI flag). | `TreeBuilder::build()` accepts an optional `query` param that filters pages (used by the front-end `nav` tag) but `buildForController()` doesn't pass one. Options: override the route/controller to authorize the collection and prune branches outside territory (walk once, cache); or add a `TreeBuilder` hook if upstream adds one. **Core bug candidate** (no auth on tree index) — decide whether to report upstream before 1.0. |
| Tree update `PATCH cp/collections/{c}/tree` | ❌ | `authorize('reorder', $collection)` only. member moved `/oncology` (with children) under `/cardiology` → 200, new URI `/cardiology/oncology`. **Under a URI-based territory model, moving a branch into your territory makes it yours**: the next request deleted `chemo` via `deletedEntries` and succeeded because its URI was now under `/cardiology`. | Diff the submitted tree against the saved tree (`Tree::diff()->moved()` exists); require the caller to own **both** the old and new position of every moved branch, and `view`/`delete` on every `deletedEntries` id (the controller already filters delete by `can('delete')`, which is fine once scoped). Deny the whole request if any branch fails. |
| Flat reorder `POST …/entries/reorder` | ❌ | `authorize('reorder', $collection)` only. Probe 500'd (`Root page cannot have children` — endpoint is for orderable, non-root collections) so not exercised; same class as tree update. | Same treatment as tree update; test on an orderable collection. |
| **Global search / command palette** `GET cp/command-palette/search` | ✅⚠️ | Post-filters `->get()->filter(fn ($r) => can('view', $r->getSearchable()))->take(10)`. member: "Oncology" → no rows; "Cardiology" → 2 rows. nobody: no rows. The `cp` index defaults to `searchables: content,users,addons`. | Policy layer closes it. It is a **post-filter over the full result set** — fine for correctness (filter happens before `take(10)`), but on a large index it reads everything the query matched. Acceptable for v1; document. |
| Command palette index `GET cp/command-palette` | n/a | Cached forever per user id (`statamic-command-palette-{id}`), invalidated on content events; contains nav/blueprint links only, **no entry-level items** (probe: 0). | Nothing to do. Re-check on each minor in case entry links are added. |
| **REST API** `GET /api/collections/{c}/entries` | n/a (userless) | Registered at boot only if `statamic.api.enabled`; per-resource allow-list defaults to `false`; middleware = `RequireStatamicPro`, `HandleAuthentication` (a **single static bearer token**, or nothing), `Cache`. No user identity, no policy. Returns every *published* entry; drafts 404 unless `status` is an allowed filter. | **Cannot be territory-scoped — there is no user.** README must say so plainly (spec §8) and recommend leaving collections disabled or token-gated. |
| **GraphQL** `POST /graphql` | n/a (userless) | Same shape: config-gated, static token via `HandleToken`, published-only unless `status` is in `allowed_filters` (probe: `filter:{status:"any"}` → validation error by default). | Same as REST. |
| **Live Preview** | ✅ | `POST …/{id}/preview` → `authorize('update')` (member: 403 oncology, 200 cardiology, token issued). `GET …/{id}/preview` (popout) → 200 but it is only the Inertia shell; content is fetched via the token minted by the authorized POST. `create/{site}/preview` → `authorize('create')`. | Policy layer. Popout shell is not a leak. |
| **Revisions** | ✅ | index/show → `authorize('view')`; store → `edit`; restore → `cant('edit') → 403`. member: 403 ×3 on oncology, 200 on cardiology. | Policy layer. |
| **Navigation tree** `GET cp/navigation/{nav}/tree` | ❌ | **No `authorize()` at all.** nobody (no nav permission): 200 with every branch incl. `entry_title` of linked entries, while `GET cp/navigation/main` 403s. | Same treatment as the collection tree. Core bug candidate. |
| Navigation update `PATCH cp/navigation/{nav}/tree` | ❌ | `authorize('edit', $tree)` = `edit {nav} nav` only. member moved the oncology branch under cardiology **and added a new branch pointing at `oncdraft`** (a draft in another territory) → 200; `entry_title` then appears in the tree. `updateData()` accepts any `entry` id. | Require `view` on the entry behind every added/changed branch. Moving nav branches is not a content-ownership question in itself (the nav is not the territory); v1 can restrict only the *add/link* case and document that nav structure is nav-permission-governed. Decide in Phase 3. |
| Nav page edit `GET cp/navigation/{nav}/pages/{id}/edit` | ✅ | `authorize('view', $nav->in($site))` then `authorize('view', $entry)`. member: 403 on the oncology-linked page. | Policy layer. |
| **Relationship / entries picker** `GET cp/fieldtypes/relationship` (not in spec table) | ❌ | `Fieldtypes\Entries::getIndexItems()` filters to *collections* the user can `view`, then `Entry::query()` with no per-entry check and **no hook**. member: 200 with all 6 incl. the draft. nobody: no rows (collection-level). | Second listing surface. No official hook — needs a fieldtype override (`Entries` fieldtype is bindable via `Fieldtype::extend`/binding the class) or a query macro. Phase 0.3 spike must cover this query too. |
| Relationship picker `POST cp/fieldtypes/relationship/data` | ✅ | `authorizeItemData()` → `can('view')`; out-of-territory id returns `invalid`. (Base `Relationship::authorizeItemData` is fail-open by comment; `Entries` and `Terms` override it.) | Policy layer. |
| **Create** `POST …/entries/{site}` with `_parent` (not in spec table) | ❌ | `authorize('create', [Entry::class, $collection, $site])` — **the intended parent is not part of the ability's arguments**, so a territory-aware policy cannot see it. member created `/oncology/sneaky` → 200. | Read `_parent` (store) / `parent` (create screen) from the request and require the caller to own that branch; treat root/no-parent as "site default". Same for `EntryPreviewController::create` and `EntryTitleFormatController::create`. |
| Localize `POST …/{id}/localize` | ✅ | `authorize('edit', $entry)`. Not exercised (single-site fixture). | Policy layer; multi-site fixture in Phase 3. |
| Dashboard collection widget | ✅ (inherits) | Renders `collection-widget` which calls the entries listing endpoint. | Covered by the listing hook. |
| Assets | out of scope v1 | `AssetsIndexQuery` hook exists for later. | — |

**Two core issues worth an upstream report** (decide before Phase 3; report responsibly, they are
information-disclosure in the CP, not front-end): (1) `CollectionTreeController::index` and
`NavigationTreeController::index` have no authorization — `access cp` alone enumerates every
structured collection and nav. (2) `DuplicateEntry::authorize()` never checks the source entry,
so `create {c} entries` + a known ID copies content the user cannot view. Delegated must close
both regardless of upstream.

**Probe-run gotchas** (for whoever re-runs it): PHPUnit 12 ignores `@test` annotations — use a
`test_` prefix. API/GraphQL/revisions routes are registered at boot, so they need env vars, not
`config()` in `setUp()`. Solo edition throws `StatamicProRequiredException` above one user
(`CountUsers` middleware) — `STATAMIC_PRO_ENABLED=true`. `Stache::stores()->each(…->directory())`
plus `app(RoleRepository::class)->path()` / `app(UserGroupRepository::class)->path()` is enough to
sandbox the file driver; blueprints are *not* sandboxed (they load from `resources/blueprints`).

### Phase 0.2 — `doefom/restrict` read (v0.5.4, commit `d47213b`, 2024-06-02, MIT; last activity mid-2024)

Three files: `ServiceProvider.php`, `Restrict.php`, `Stache/Query/EntryQueryBuilder.php`.
Requires a *custom* `EntryPolicy` subclass bound by the site; the addon itself only filters.

**How it hooks the listing.** It does not touch the listing controller. In `bootAddon()` it
rebinds the concrete flat-file builder, `Statamic\Stache\Query\EntryQueryBuilder`, to a subclass
that overrides `getFilteredKeys()` — the Stache step that resolves the set of matching item keys
*before* `orderKeys()`/`limitKeys()` and before `getCountForPagination()`. The override hydrates
every matched key (`$this->store->getItem($key)`) and keeps it only if
`app(EntryPolicy::class)->view($user, $entry)` is true. Guards: `Statamic::isCpRoute()`, user is
non-super, the bound policy is not the stock class, and **not `runningInConsole()`**.

**Consequences of that design (record, do not repeat):**

- Filtering happens before pagination, so page sizes and totals are correct — better than the
  post-paginate filter the spec warns about. But it is **O(n) per query**: every key in the
  collection is hydrated and the policy runs per row on every listing page, count, and pluck.
  On a 40k-entry collection that is 40k policy evaluations per request (the Stache item cache
  makes the second hydration cheap, not the first, and never the policy).
- It is **global**: every `Entry::query()` on a CP route is filtered, not just the listing — the
  relationship picker index, the listing's LIKE search fallback, and *internal* queries too.
  Concrete side-effect: `DuplicateEntry::generateTitleAndSlug()` uses `queryEntries()->where('slug')
  ->count()` to avoid slug collisions; filtered, it can return 0 for a slug that exists and produce
  a collision. Any query-builder-level filter needs an explicit "unscoped" escape hatch.
- It calls the policy method **directly**, bypassing the Gate: policy `before()` never runs, so the
  `configure collections` bypass (Phase 0.1 fact 1) is not honoured in listings while it *is* on the
  edit screen, and a `Gate::before`/`after` is invisible to it. Listing and single-entry decisions
  can disagree — the one property a territory product cannot have.
- `runningInConsole()` is true under PHPUnit, so the addon switches itself off inside feature
  tests; it is effectively untestable as written. Do not gate on that.
- It reads `User::current()` inside the query builder. Queue jobs, CLI, and Live Preview token
  rendering have no user → unfiltered, which is fine for CP-only scope but is a hidden global.
- `Statamic::isCpRoute()` gating means REST/GraphQL/front-end are untouched — consistent with
  spec §8, and the README says so plainly. Copy that README stance.

**What Statamic 6 would need (flat file).** Nothing structural. In 6.31.0 `Entry::query()` still
resolves `Statamic\Contracts\Entries\QueryBuilder` → the concrete Stache builder bound in
`Stache\ServiceProvider`, `getFilteredKeys()` still exists with the same role, and
`Statamic::isCpRoute()` still exists. Only the `^v4.0|^v5.0` composer constraint blocks it. The
per-row model would port as-is — and carry every problem above with it.

**What Eloquent would need — and why restrict never did it.** `statamic/eloquent-driver` v5.11.1
(the Statamic 6 line, requires `cms ^6.10`) binds `Statamic\Eloquent\Entries\EntryQueryBuilder`,
which extends `Statamic\Query\EloquentQueryBuilder` and wraps a real Illuminate builder. There is
**no key list to filter**: `get()`/`paginate()`/`count()` go straight to SQL with LIMIT/OFFSET
applied by the database. A per-row policy can only run *after* the page comes back, which is
exactly the "page one has 9 rows, page two has 14, totals lie" failure in spec §5. The only correct
Eloquent implementation is to add `where` clauses before execution — i.e. the restriction must be
expressible as a **query predicate**, not as a PHP callback. That is the structural reason the
policy-callback model cannot be made driver-agnostic, and the reason restrict's Eloquent support
stayed "planned".

**What both drivers accept (reading only — the Phase 0.3 spike must confirm by running it):**

- Flat file: `CollectionEntriesStore` ships **`uri` and `parent` indexes** (plus `slug`, `site`,
  `published`, `title`, `origin`, `collectionHandle`), and the Stache builder supports `like`
  (`filterTestLike` → SQL-LIKE-to-regex). So `where('uri', 'like', '/cardiology/%')` and
  `whereIn('parent', [...])` resolve **from the index without hydrating entries**. Nested/`or`
  wheres are supported (`type == 'Nested'`), so a union across several territories is expressible.
- Eloquent: `uri` is a **native column** (`COLUMNS` = id, site, origin_id, published, slug, uri,
  data, date, collection, …); `parent` is not (structure lives in the trees table), so scope by
  URI prefix or by an explicit ID list, not by parent.
- Both implement the same `QueryBuilder` contract, so a predicate added in the official
  `Statamic\Hooks\CP\EntriesIndexQuery::hook('query')` is driver-agnostic and runs before
  `paginate()`.
- Open question for the spike: when a collection has a search index configured,
  `EntriesController::indexQuery()` returns a `Statamic\Search\QueryBuilder`, a third builder
  class; whether it honours a `uri`/`parent` `where` needs testing on both the local and Algolia
  drivers, or the listing's search path needs its own guard.
- Open question for the spike: nothing hooks the relationship-picker index
  (`Fieldtypes\Entries::getIndexQuery`), so a hook-only design leaves it open (Phase 0.1 table).
  Candidates: rebind the `entries` fieldtype class, or a scoped rebind of the builder with an
  unscoped escape hatch. Decide after the spike, not before.

**Direction this points to (recommendation, to be confirmed by 0.3):** express territory as query
predicates derived from one resolution service, and use the *same* service inside the policy layer
for single-entry decisions. Two consumers, one source of truth, so listing and edit screen cannot
disagree. Do not adopt restrict's per-row policy filter.

### Phase 0.3 — Query-scoping spike, both drivers (cms 6.31.0, eloquent-driver 5.11.1)

**Method.** `recon/QueryScopingSpikeTest.php`, run twice from the host site with `SPIKE_DRIVER=file`
and `SPIKE_DRIVER=eloquent`. The Eloquent run flips only the `entries` repository to Eloquent
in-process (config set before `RegisterProviders`, string-id model + migration on the in-memory
SQLite) — `statamic/eloquent-driver` is now a **`require-dev`** dependency of the host site, config
unpublished, so the host stays on the file driver at runtime (verified: `Entry::query()` is still the
Stache builder). Fixture: structured `pages` (`/cardiology` → `/trials` → `/phase-2`, `/oncology` →
`/chemo` + draft, and a `/cardiology-alumni` sibling to catch prefix bugs), a `news` collection
tagged with a `departments` taxonomy, a `pages` search index, and a 1,500-entry branch for timing.

**Answer: yes, on both drivers, with correct pagination — but only through a compiled predicate,
not a naive one.** Details:

| Probe | Flat file (Stache) | Eloquent |
|---|---|---|
| Builder class | `Statamic\Stache\Query\EntryQueryBuilder` | `Statamic\Eloquent\Entries\EntryQueryBuilder` |
| Branch: `uri = X OR uri LIKE 'X/%'` | ✅ 3 rows, sibling `/cardiology-alumni` excluded | ✅ same |
| `paginate(2)` under that scope | ✅ 2 / 1, total 3 | ✅ same |
| Listing endpoint via `EntriesIndexQuery::hook('query')`, `perPage=2` | ✅ 200, 2 / 1, `meta.total` 3 | ✅ same |
| Listing `?search=` with a search index (`Statamic\Search\QueryBuilder`) | ✅ predicate applied as a PHP post-filter over the hit set, before pagination (alumni excluded) | ✅ same |
| Taxonomy: top-level `whereTaxonomy` / `whereTaxonomyIn` | ✅ | ✅ |
| `whereIn('parent', …)` (direct children) | ✅ `Parents` index, updated in-process | ⚠️ works only because `data->parent` is denormalised by **queued** `UpdateCollectionEntryParent` jobs on tree save — lags behind on a real queue. `uri` is updated synchronously (`UpdateStructuredEntryUris` → direct `update`). Scope by URI, never by parent, on Eloquent. |
| `whereIn('id', <ids from tree>)` | ✅ | ✅ — the always-portable fallback |
| **Naive union** `branch OR where('collection','news')` | ❌ **collapsed to the branch rows** | ✅ |
| **Naive union** `branch OR (collection + whereTaxonomy)` inside a nested closure | ❌ term group dropped → fails *closed* (no news rows) | ❌ **`whereTaxonomy` dropped → fails *open*: all four news rows returned, including untagged** |
| **Compiled union** (below) | ✅ 7 rows, `paginate(3)` = 3/3/1 total 7 | ✅ identical |
| Case: `uri = '/CARDIOLOGY'` / `LIKE '/CARDIOLOGY/%'` | both match (Stache `=` lowercases both sides; `like` is `/i` regex) | `like` matches (driver emits `LOWER(uri) LIKE`), `=` does not on SQLite (collation-dependent on MySQL) |
| Cost, 1,508-entry collection, scoped `count()` vs restrict-style hydrate-and-filter | 4.2 ms vs 26 ms (index warm) | **0.2 ms vs 587 ms** |
| Relationship-picker index | hook does not fire (`hookHits=0`, all 8 rows) | same |

**Why the naive form breaks — the Stache builder is not a query builder for two columns.**
`Stache\Query\EntryQueryBuilder::where()`/`whereIn()` intercept `collection` and append it to a
`$collections` scan-set instead of a where clause: the `or` boolean is ignored, and inside a nested
closure the clause lands on a throwaway clone and is silently dropped. `whereTaxonomy` has the same
shape on **both** drivers (`$taxonomyWheres` is only applied on the top-level builder). Nothing
throws. On Eloquent the dropped taxonomy clause widens the OR group to the whole collection — a
silent over-grant, which is the exact failure class this product exists to prevent.

**The compiled form that works on both** (this is the shape the resolution service must emit):

1. Top level, always: `whereIn('collection', <every collection any of the user's territories
   touches>)` — the scan-set Stache needs, a harmless narrowing on Eloquent.
2. One nested `where(fn)` OR-group whose members never call `where('collection')` or
   `whereTaxonomy`:
   - structure branch → `where('uri', X)->orWhere('uri', 'like', X.'/%')` — portable, `uri` is
     refreshed synchronously on tree save on both drivers;
   - whole collection → `where($col, handle)` where **`$col` is the one driver-specific token**:
     `collectionHandle` (a real Stache index) or `collection` (the Eloquent column);
   - taxonomy term → `whereJsonContains($field, $term)` — supported by both builders;
   - fallback for anything else → `orWhereIn('id', [...])` resolved up front (one extra query,
     cacheable).
3. Normalise territory `scope_ref` URIs to lowercase at write time; do not rely on driver case
   rules (spike shows they differ per operator and per driver).
4. Orphan entries (`uri === null`, not in the tree) never match a branch predicate on either
   driver — consistent with the Phase 0.1 rule that orphans belong to no territory. `delegated:doctor`
   lists them.

**Architecture decision (recorded, not yet implemented):**

- Territory = query predicates compiled per driver by one service; the same service answers
  single-entry questions (`contains($entry)`) for the policy layer. Detect the driver from
  `get_class(Entry::query())` (or the bound `QueryBuilder` contract), not from config.
- Listing surfaces: the official `EntriesIndexQuery` hook (listing + its search path). The
  relationship-picker index is unreached by any hook and needs its own seam (Phase 3 task).
- Do not rebind the query builder globally (Phase 0.2 side effects). Do not filter per row.
- Two denial tests fall directly out of this spike and must exist in Phase 3: (a) a user with a
  branch territory and a term territory does not see untagged entries of the term's collection on
  Eloquent; (b) multi-territory listings return the union without collapsing on flat file.
- `Hookable::runHooks()` rebinds hook closures to the hookable instance — `$this` inside a hook is
  `EntriesIndexQuery`, not the registering object. Register hooks from a static/closed-over context.
- Spec §11 "multiple territories per user — confirm resolution is a union and the listing handles an
  OR across branches without collapsing": confirmed on both drivers **with the compiled form**, and
  confirmed to collapse on flat file with the naive form.

Phase 0 is complete: all three prerequisites for writing an enforcement check are in Notes.

### Phase 1.1 — Scaffold (2026-09-11)

Hand-scaffolded to match the sibling `bpmore/statamic-lifecycle` layout rather than `make:addon`,
which scaffolds PHPUnit inside the host's `addons/` directory. Namespace `Bpmore\StatamicDelegated`,
slug `delegated`, Pest on `Statamic\Testing\AddonTestCase` with `PreventsSavingStacheItemsToDisk`,
Pint (laravel preset), an arch test (strict types, no debug calls), a CI workflow with the PHP axis
only (the `statamic/cms` minor axis is Phase 5), `LICENSE.md` with a plain-words scope section
(control panel only; APIs and front end untouched; verified versions only; super users and
`configure collections` bypass), a pre-release `README.md`, and `CHANGELOG.md`.

- **Pin: `statamic/cms: ~6.31.0`.** 6.31.0 is the only version anything has been verified
  against (all of Phase 0). Widen per minor as CI proves it; `tests/Feature/AddonTest.php` fails
  if the constraint drifts back to `^6.x` or loses its lower-bound form. Resolved here:
  cms 6.31.0, Laravel 13.31.0, Testbench 11.2.0, Pest 3.8.7, on PHP 8.4. `php: ^8.2` as specified.
- **Host wiring:** path repository `../statamic-delegated` + `bpmore/statamic-delegated: @dev`
  in the host's `composer.json`, symlinked; `Addon::get()` resolves name/slug in the host and the
  host stays on cms 6.31.0.
- `AddonTestCase` requires `tests/__fixtures__/dev-null/` to exist (it derives the fake Stache
  path from the provider's file and `mkdir`s inside it) — committed with a `.gitkeep`.
- `Statamic::version()` throws under Testbench (shells out to Composer in the skeleton app,
  which has no lock file). Use `Composer\InstalledVersions::getPrettyVersion('statamic/cms')`.
- No JS/Vite scaffold yet: nothing to build until the first CP screen (Phase 4). CLAUDE.md
  updated to say so; the "not yet scaffolded" paragraph removed.
- `composer.lock` is committed (CI runs `composer update` per PHP version, as the sibling does).

### Phase 1.2 — SECURITY.md (2026-09-11)

`SECURITY.md` plus a Security section in the README (spec §1 wants the contact where a buyer looks
first); `composer.json` already carried `support.security`. Guarded by a test that the policy names
the advisory channel, an email, and a bolded response time, and that the README links both.

- **Contact.** Primary: GitHub private vulnerability reporting
  (`…/security/advisories/new`). Fallback: the repo's git-author address, since no sibling addon
  has a dedicated security mailbox to reuse. Both are the owner's to change before listing.
- **⚠️ Owner action before listing:** private vulnerability reporting is a per-repository
  setting (Settings → Code security → Private vulnerability reporting). The `advisories/new` URL
  is a 404 until it is switched on. Also worth a dedicated address if one exists by then.
- **Response times** (chosen to be keepable by one person): acknowledge within 3 business days;
  reproduce + severity within 7 calendar days of that; fix or published mitigation within 30 days
  for a confirmed cross-territory exposure; anything else next release and ≤ 90 days. Coordinated
  disclosure, 90-day cap, credit by default, no bounty.
- **Scope** mirrors the Phase 0.1 inventory and the six Phase 2 invariants; out-of-scope list
  matches `LICENSE.md` (front end, REST/GraphQL, super users and `configure collections`, assets,
  versions outside the pin — "report anyway").
- `statamic.dev/security` does not exist (404); Statamic's own policy is linked at
  `github.com/statamic/cms/security/policy` (200).
- Support line: `main` only until 1.0; then current minor, previous minor for 90 days.

### Phase 1.3 — Migrations (2026-09-11)

Three migrations under `database/migrations`, loaded with `loadMigrationsFrom` and publishable as
`delegated-migrations`; 14 schema tests in `tests/Feature/MigrationsTest.php` (tables, columns,
uniques, indexes, cascade, null-on-delete, FK rejection, audit survival, rollback). Applied in the
host with `php artisan migrate` (batch 2). Decisions beyond spec §6's column list:

- **Default connection, not a private one.** Lifecycle uses its own SQLite file so a flat-file
  site needs no database; Delegated's rows are authorisation data about the site's own users, and
  a site with database users keeps both on one connection. Spec §7 already says "database
  required". A configurable connection can be added later without a schema change.
- `delegated_territories`: added `updated_at` (cache invalidation on change, spec §6). Two unique
  indexes: `(site, slug)` and **`(site, scope_type, scope_ref)`** — one territory per scope, so
  "find the owning territory" is never ambiguous; several groups sharing a branch is several
  assignments to one territory. `parent_id` is a self-FK with `nullOnDelete`: a child whose
  parent is deleted becomes a root, its members keep what they had. `scope_type` is a string,
  not a DB enum (a fourth kind must not need a migration on every site). `scope_ref` is 512 wide
  and lower-cased at write time (Phase 0.3 case finding).
- `delegated_assignments`: `territory_id` FK `cascadeOnDelete`; `subject_id`/`granted_by` are
  191-char strings (file-driver UUIDs or whatever the Eloquent site chose — no FK possible);
  `granted_by` nullable so seeders/CLI can grant and a deleted admin does not take assignments
  down with them; index on `(subject_type, subject_id)` for the per-listing hot path.
- `delegated_audit`: **no foreign keys at all**, no `updated_at`. Append-only is enforced by the
  model (Phase 4), and the schema must not be able to cascade or rewrite a row. `territory_id` is
  a plain nullable bigint, indexed with `created_at` for the per-territory log.
- Testbench 11's `testing` SQLite connection sets `foreign_key_constraints` from
  `DB_FOREIGN_KEYS`, **default off**. `phpunit.xml` now sets it `true`; without it the cascade,
  null-on-delete and FK-rejection tests pass vacuously. Every real driver enforces.
- `php please migrate` in the host fails with "missing: timezone" — `please` prefixes
  `statamic:` and Symfony abbreviation-matches `migrate` to `statamic:migrate-dates-to-utc`.
  Use `php artisan migrate`. Added to CLAUDE.md.

### Phase 1.4 — Territory resolution service (2026-09-11)

`src/Territories/` is a framework-free core (arch-tested: no Statamic/Illuminate/Laravel/Carbon
reference allowed): `ScopeType`, `Level`, `Normalise`, `ContentFacts`, `Territory`, `Assignment`,
`Membership`, and `TerritoryMap` — the "territory tree in memory" of spec §6. `src/Resolution/` is
the framework side: `TerritorySource` (interface), `DatabaseTerritorySource` (two plain reads, no
joins), and `TerritoryResolver` (the singleton with the cache). 21 unit tests run in 30 ms with no
application; 11 feature tests cover the DB and the caching contract. Resolver boots in the host.

**Semantics decided here (spec §4 left them open):**

- **Two questions, one source.** `containing(facts)` → territories covering the content, most
  specific first; `owner()` = first, `null` = site default (nobody but super). `membershipFor
  (subjects)` → territory id ⇒ level. The policy layer and the query compiler will both ask the
  same map, so they cannot disagree — the Phase 0 requirement.
- **Precedence when several territories contain one entry:** structure branch (deepest URI
  prefix) → taxonomy term → collection. Branch first because the tree is where content *lives*; a
  term is a field an editor could change to hand content to another department. Encoded in
  `ScopeType::precedence()` and `Territory::specificity()`.
- **"Walks up until it finds an owning territory"** = take the deepest matching branch prefix;
  a branch territory covers its whole sub-tree including any sub-territories' branches. A
  sub-territory exists to give a narrower group access to a narrower area, not to shadow the
  parent's members.
- **Membership flows down the hierarchy (`parent_id`), never up**, at the same level: a
  department's admin administers its sub-units. Union across subjects (a user in two departments
  is in both — spec §11). Where two routes reach one territory the higher level wins.
- **Orphans (`uri === null`) are under no branch** — they belong to nobody, never to everybody.
  This is the property that closes the Phase 0.1 duplicate-entry exfiltration; tested explicitly.
- **Sibling prefixes never match** (`/cardiology` ≠ `/cardiology-alumni`); a root branch `/`
  covers every entry that has a URI; comparisons are case-insensitive and trailing-slash-blind
  after `Normalise` (the Phase 0.3 case finding — normalised on *both* sides, at store and at
  compare, so a hand-edited row still works).
- Territories are site-bound whatever their scope; a dangling `parent_id` is a root; an
  assignment to a non-existent territory grants nothing; parent-chain cycles cannot hang a request.

**Caching, and one deviation from spec §6.** The spec says "user's effective territories cached
per session, invalidated on assignment change". Implemented instead as: the *whole map*
(territories + assignments — hundreds of rows at most) cached under a version-stamped key
(`delegated.territories.map.v{n}`), memoised per request in a `delegated` Blink store, with
membership computed per call from the map (hash lookups). `invalidate()` bumps the version —
one counter, no key scanning, invalidates every process at once. Reasons: (a) a user's *groups*
are Statamic's data and change without telling us; keeping them as an input at compute time
means a Statamic-side group edit can never be served stale, where a per-session cache of
"effective territories" would be; (b) one cache entry to reason about instead of one per user.
Measured: 200 rows × (owner + membership) = **2 queries**; the next request = **0 queries**.
The TTL (`delegated.cache.ttl`, default 3600 s, `null` = until next change) is only a backstop
for a row edited by hand — tested as such, including that within the TTL such a change is
invisible by design. Bug found by that test: `?? 3600` turned a configured `null` into an hour;
fixed with `array_key_exists`.

Not here yet, on purpose: building `ContentFacts` from a Statamic `Entry` (needs `uri()`,
collection, terms, site — Phase 3), the per-driver predicate compiler (Phase 3.1), and any
write path (territories and assignments are still inserted by hand; whoever writes must call
`invalidate()` — Phase 4 will wrap that).

### Phase 2 — approach, and invariant 1 (2026-09-11)

**How "tests before features" is squared with "never stop with a red suite".** Each invariant is
written as real Pest tests, run once *un-marked* to prove it fails against the current code (the
failure is recorded here), then parked with Pest's `->todo()`, which does not execute the body and
keeps `pest --ci` green (`--ci` only forbids `->only()`). Task 2.7 un-parks all six, shows them
failing together, then implements until they pass. `todo` rather than `skip` because that is what
they are; the un-marked run guards against a typo in a body that would otherwise never execute.

**The contract the tests are written against** (`src/Administration/`, no implementation bound):
`Actor` (user id, super flag, roles held directly *or via groups* — Statamic's own meaning of
"holds" — and the subjects membership resolves from); `EscalationDenied` with one named
constructor per invariant and a public `$invariant` so a test asserts *which* rule refused;
`TerritoryAdministration` interface with `addMember`, `removeMember`, `setRoles`, `updateUser`,
`deleteUser`, `updateTerritory`, `visibleUsers` — and deliberately **no** create/modify-role
operation (invariant 4 starts as an absence in the surface). Every method does the whole thing or
throws before writing.

**Harness:** `tests/Support/Site` builds roles/groups/users (sandboxed Stache; roles/groups YAML
repointed into `tests/__fixtures__/dev-null` by `TestCase`, wiped per test) and territories/
assignments (in-memory DB, resolver invalidated on every write). `SiteFixtureTest` pins the harness
so a denial can never pass because a fixture silently failed to save.

**Invariant 1 — cannot grant a role they do not hold** (`CannotGrantRoleNotHeldTest`, 9 tests):
happy path (held role → granted, assignment written); not-held → `EscalationDenied::ROLE_NOT_HELD`
and *nothing* written; mixed held+not-held → refused whole (no partial grant); `setRoles` on an
existing member → refused; the admin giving *themselves* a role → refused; held via group → held;
group's role without being in the group → not held; a non-existent role → refused (never
"harmless"); super user → exempt. Un-marked run: **9 failed** —
`BindingResolutionException: Target [TerritoryAdministration] is not instantiable` — i.e. failing
because the feature does not exist, which is the point.

**Invariant 2 — cannot add a user to a territory they do not administer**
(`CannotAddToTerritoryNotAdministeredTest`, 14 tests). Fixture: `/` → `/cardiology` → `/cardiology/
trials`, and `/` → `/oncology`; `head` is admin of `/cardiology`, `staff` a member. Allowed: own
territory; a *child* of it (administration flows down with membership, Phase 1.4); two territories
when admin of both; admin held via a group. Refused, with nothing written and
`TERRITORY_NOT_ADMINISTERED`: a sibling (`/oncology`); the **parent** (`/` — up is not down); a
member who is not an admin, even at home; any level (adding an *admin* elsewhere is worse, not
different); a group subject; the admin adding *themselves* elsewhere; **removing** a member from
another territory (removal is the same boundary — and would be a denial of service on another
department); a territory id that does not exist; and — order of checks — a request whose roles
invariant 1 *would* allow but whose territory is not theirs grants no role either. Super exempt.
Un-marked run: **14 failed**, all on `TerritoryAdministration is not instantiable`.
