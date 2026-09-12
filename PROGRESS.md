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
- [x] Cannot grant a role they do not hold — passing (2.7)
- [x] Cannot add a user to a territory they do not administer — passing (2.7)
- [x] Cannot edit, demote or delete a super user — passing (2.7)
- [x] Cannot create or modify roles — passing (2.7)
- [x] Cannot widen their own territory — passing (2.7)
- [x] Cannot see users with no overlapping assignment — passing (2.7)
- [x] All six failing, then all six passing — 81 red, then 84 green; full suite 161

## Phase 3 — Enforcement
- [x] Query scoping (or documented post-filter fallback with its limits stated) — scoped before execution on both drivers, no fallback needed
- [x] One task per surface from the Phase 0 inventory, each with a denial test:
  - [x] 3.2.a Policy layer — every entry ability territory-aware via the Gate; closes edit screen, direct URL, update, publish/unpublish, live preview, revisions, nav page edit, picker `data`, command palette, built-in delete/publish/unpublish actions
  - [x] 3.2.b `duplicate_entry` — require `view` on the source; not offered on out-of-territory items
  - [x] 3.2.c Create under a parent — `_parent` (store) and `parent` (create screen) must land inside territory; preview.create and title-format.create turned out to carry no parent
  - [x] 3.2.d Structure tree GET — authorize the collection, prune branches outside territory
  - [x] 3.2.e Structure tree PATCH and flat reorder — moves confined to territory; `deletedEntries` checked
  - [x] 3.2.f Navigation tree GET and PATCH — linked entries outside territory hidden / not linkable
  - [x] 3.2.g Relationship picker index — scope the fieldtype's query
  - [x] 3.2.h Users listing — a territory admin holding `view users` sees only visible users
  - [x] 3.2.i Direct-URL oracles (`cp/edit/{id}`, `{entry}/{slug}` redirect) — 404 outside territory
- [x] Super users explicitly exempt, and documented as such

## Phase 4 — Territory management
- [x] CP screens: territories, assignments, membership — site-administration screen; clicked through in the host 2026-09-12
- [x] Territory admin self-service, constrained by the Phase 2 invariants — backend and page built and tested; browser pass pending (session expired)
- [x] Audit log, scoped per territory — written on every write and refusal, read site-wide and per territory with outside actors unnamed; browser pass pending (no CP session)
- [x] `delegated:doctor` — orphaned territories, entries with no owner, and the drift and misconfiguration recorded along the way

## Phase 5 — Hardening and ship
- [x] CI matrix axis on `statamic/cms` minor versions, not just PHP — 6.31 and 6.32, plus an advisory newest-6.x leg
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

**Invariant 3 — cannot edit, demote or delete a super user** (`CannotTouchSuperUserTest`, 10
tests). Decision: the rule is stated as *"a super user is not a subject a territory admin can act
on, in any way, through Delegated"* rather than three cases — no exceptions to remember — and the
`super` flag is off-limits in **both** directions (no demoting, no promoting: a territory admin who
could set it on a colleague could set it on a second account of their own). Fixture puts the super
user *inside* cardiology so the refusal is this invariant's and not invariant 6's. Refused, nothing
changed, `SUPER_USER`: `updateUser` (name; email — which is the account; `super => false`);
`setRoles` even to roles the admin holds, and to `[]`; `deleteUser`; `removeMember`/`addMember` of
a super user (neither escalates, the rule just has no exceptions); every one of these against a
super user the admin *cannot see* (whichever check runs first, the answer is no); and
`updateUser(..., ['super' => true])` on an ordinary member, alone or mixed with a harmless field
(nothing applied). Control: the admin *can* rename an ordinary member, so the refusals are about
the target. Super acting on super: exempt. Un-marked run: **10 failed** (unbound contract).

**Invariant 4 — cannot create or modify roles** (`CannotCreateOrModifyRolesTest`, 20 tests).
Two halves. (a) *Structural, passing now:* the contract's method list is pinned by name
(`addMember, deleteUser, removeMember, setRoles, updateTerritory, updateUser, visibleUsers`) and no
method accepts a `Role` — adding a role operation is a deliberate act that must edit the test.
(b) *Behavioural, parked:* no role that **carries** a management permission can be granted through
Delegated **even by an admin who holds it** — otherwise invariant 1 is a relay: a super user who
once gave the head `edit roles` has given it to everyone the head ever adds. The never-grantable
set, data-driven over 11 permissions: `super`, `edit roles`, `edit user groups`, `edit users`,
`create users`, `delete users`, `change passwords`, `assign roles`, `assign user groups`,
`impersonate users`, and `configure collections` (the `EntryPolicy::before()` bypass from
Phase 0.1). Refused with `ROLE_MANAGEMENT`, nothing written, via `addMember` and `setRoles`; the
check is on the role's permissions *at grant time* (a role that was harmless when the admin got
it and is edited to carry `edit roles` later is refused from then on); and `updateUser` refuses
`roles`/`groups` keys so profile edits cannot smuggle a role change. Control: an ordinary held
role still grants. Super exempt. Un-marked run: **19 failed, 1 passed** (the structural one).

Flagged for Phase 3/4, not this invariant: `view users` is *not* in the set, but a territory admin
holding it sees every user through **Statamic's own** users listing, which is invariant 6's
concern on a surface Delegated does not yet touch. The users listing joins the enforcement list.

**Invariant 5 — cannot widen their own territory** (`CannotWidenOwnTerritoryTest`, 14 tests).
Decision: rather than define "wider" and get it wrong once, a territory's **scope is immutable to
anyone who administers it** — `scope_type`, `scope_ref`, `parent_id`, `site` — and to anyone who
administers an ancestor, since a widened child widens what flows down to them (Phase 1.4). An
admin may change a name (control test: rename succeeds, scope untouched). Refused with
`OWN_TERRITORY`, nothing changed: widening to `/`; moving sideways to `/oncology` (not wider, but
not theirs); **narrowing** (looks harmless; refused because "immutable" has no edge cases and "may
only shrink" has several — not undoable by them, and a narrowed parent under a wide child is a
shape nobody meant); changing the scope *kind* (a branch becoming a collection is the whole
collection); re-parenting their own territory (to null or under another); **making another
territory a child of theirs** (would make them its admin); widening or re-parenting a *child* they
administer; moving to another site; a rename mixed with a scope change (refused whole, name
unchanged); an unrecognised key, including `id` (never "harmless"). Boundary: a member (not admin)
and a non-administered territory are refused even a rename. Super exempt. Un-marked run:
**14 failed** (unbound contract).

**Invariant 6 — cannot see users with no overlapping assignment**
(`CannotSeeUsersOutsideTerritoryTest`, 15 tests). Two decisions. **Visibility is downward only**:
`visibleUsers(actor)` = users assigned, directly or through a group, to a territory the actor
*administers* or a descendant of it — never an ancestor, or the head of cardiology could act on
the site-wide editors who administer them. A member who administers nothing sees nobody (the
user-management surface is for admins). **Seeing is looser than changing**: a user who also
belongs to a territory the actor does not administer is visible but *read-only* to them — roles
and emails are global, and editing them reaches into the other department — refused with a new
seventh reason, `EscalationDenied::USER_SHARED`; removing that user from the actor's *own*
territory is still allowed. `updateUser`/`setRoles`/`deleteUser` on an invisible user (data-driven:
oncology-only, oncology-via-group, site-wide, unassigned, super) → `USER_NOT_VISIBLE`, nothing
changed. Visibility tracks assignments live (removed from the last overlapping territory → gone
from the list and un-actionable at once). Controls: a wholly-owned user can be renamed and
deleted; super sees everyone. Un-marked run: **15 failed** (unbound contract).

Decision recorded for Phase 4: `addMember` deliberately does **not** require the target to be
visible — it is the act that creates the overlap. The admin UI should add by email/id, not from a
list; this leaves a small existence oracle (success vs. error tells the admin an id exists),
accepted and to be documented.

### Phase 2.7 — All six failing, then all six passing (2026-09-11)

Un-parked all six files: **81 failed, 4 passed** (the harness and the structural test), every
failure on the unbound contract. Implemented; **84 passed**; full suite **161 passed, 0 todos**.

**Implementation** — `Administration\DelegatedAdministration`, bound to the contract in the
provider; `Storage\TerritoryStore` is the *only* writer of the two tables and invalidates the
resolver on every write; `Administration\ManagementPermissions` is the never-grantable list;
`TerritoryMap` gained two pure views, `assignmentsFor(subjects)` (as written, no inheritance) and
`subjectsIn(territoryIds)`, unit-tested. Every operation has one shape: resolve → check every
applicable invariant → write. Super users skip the invariants but not the sanity checks (a super
user gets `InvalidArgumentException` for a missing territory/user/role, never a silent no-op).
The class header maps each invariant to the private method that enforces it:
1 + 4 `requireRolesGrantable` (exists, held, no management permission — on the role *as it is
now*); 2 `requireAdministers` (also refuses a missing territory); 3 `requireNotSuper`;
4 + credentials `requireProfileAttributes` (`super` → promotion refused; `roles`/`groups` →
role management; `id`, `password`, `password_hash`, `remember_token`, `preferences`, `two_factor*`,
`passkey*` → new `RESERVED_ATTRIBUTE` — a territory admin who can set a colleague's password owns
the account); 5 `requireTerritoryChangesAllowed` (only `name` and `slug`; every other key,
recognised or not, is refused); 6 `requireWhollyWithin` (any overlapping assignment → visible;
any non-overlapping one → `USER_SHARED`) and `visibleUsers` (subjects assigned to administered
territories, groups expanded through `UserGroup::queryUsers()`). `deleteUser` also deletes every
assignment naming the user.

**Two test scenarios were wrong, not the code:** (a) invariant 4's "even one the admin holds" is
impossible for the `super` permission — `User::isSuper()` *is* `hasPermission('super')`, so an
actor holding such a role is super and correctly bypasses everything; replaced with the honest
statement (nobody short of a super user can hand it out, and the target never becomes super).
(b) invariant 5's super-user control widened `/cardiology` to `/` while the fixture's root
territory already owned `/`; the `(site, scope_type, scope_ref)` unique index refused it — rightly
— so the control now widens to the whole collection instead.

**Added beyond the six** (implementation-driven): reserved-attribute refusal (7 attributes,
data-driven); malformed subjects and missing users/groups rejected with `InvalidArgumentException`
before any invariant is consulted, writing nothing; assignment cleanup on delete.

**Deliberately not here** (Phase 4): audit rows for successful and refused operations (all writes
already go through one class, so the wrap is mechanical); an `Actor` factory from the signed-in
Statamic user for the CP; the users-listing surface flagged under invariant 4; the `addMember`
existence oracle recorded under invariant 6.

### Phase 3.1 — Query scoping (2026-09-11)

**Built.** `Scoping\TerritoryScope::apply($query, $territories)` — the Phase 0.3 compiled form as
code: one nested OR-group, one member per territory, each bound to its site; a branch is
`uri = X OR uri LIKE 'X/%'`; a collection is `collectionHandle = X` (Stache/search) or
`collection = X` (Eloquent) — the single driver-specific token, chosen by `instanceof
EloquentQueryBuilder`; a term is an **id list** resolved once per request per territory (nested
`whereTaxonomy` is dropped by both drivers, and the data column a terms field writes to is the
*field's* handle, not the taxonomy's, so it cannot be named without reading every blueprint); an
empty territory set compiles to `whereIn('id', [])` — never to no predicate, which on the Stache
means everything. `Enforcement\ScopesEntryListings` registers it on Statamic's
`EntriesIndexQuery::hook('query')` (closing over its dependencies, since `Hookable` rebinds
`$this`); `Enforcement\Exempt` names who is not restricted (super users, `configure collections`
holders, and no-user contexts). `Resolution\Subjects::of($user)` and
`TerritoryResolver::territoriesFor($subjects)` complete the path from signed-in user to predicate.
**No post-filter fallback was needed**: both drivers scope before execution with correct totals.

**Tested on both drivers, in this repo.** `statamic/eloquent-driver ^5.11` is now a `require-dev`
dependency; `tests/EloquentTestCase` registers its provider and flips only `entries` to Eloquent
by setting config in `resolveApplicationConfiguration()` (the one hook that runs between
`LoadConfiguration` and `RegisterProviders` — `defineEnvironment()` is too late), then migrates
the driver's string-id entries table by path in `setUp()`. `tests/Support/ListingScoping` holds
the 13 scenarios once; `tests/Feature/Scoping` runs them on the Stache and `tests/Feature/
Eloquent` on the database, with `DriverTest` guarding that the driver really took over. Every
scenario is a denial (sibling prefix, orphan, home, other department, no territory → total 0,
union without collapse, term, collection, root, search path) plus the two exemptions.

**⚠️ Decision that deviates from the spec: PHP floor is now `^8.3`, not `^8.2`.** Every release
of `statamic/eloquent-driver` requires 8.3, as do Laravel 13 and the `statamic/statamic` skeleton;
Statamic 6 reaches 8.2 only via Laravel 12. A security product that promises both drivers must
test both in CI, and that needs the driver installable in this repo. The CI matrix is 8.3/8.4.
Reverting is one constraint line plus moving the Eloquent tests out of CI. Recorded in CLAUDE.md
and the README.

**Decision that revises Phase 1.4: a root (`/`) territory is the whole site, orphans included.**
Found by the Eloquent run: on that driver `Entry::uri()` *is* the stored `uri` column — an entry
outside its tree gets a route-derived URI as if it sat at the root (`/stray`), and one dropped from
the tree keeps its last URI until re-saved (`DriverTest` pins both). On the Stache both are null.
"Orphans belong to nobody" therefore cannot be made true on both drivers by a URI predicate, but
"root means everything" can, and is what a `/` grant means. Below the root nothing changes: a
non-root branch never matches an orphan on either driver, so the Phase 0.1 duplicate-entry
exfiltration stays closed. The dropped-from-tree difference is not a cross-territory exposure
(the last URI was inside the old territory) and is now listed in SECURITY.md's out-of-scope.

**Statamic Pro is required** — `CountUsers` refuses any CP request once a second user exists in
the Solo edition; `TestCase` sets `statamic.editions.pro`. Added to the README requirements.

**Pest gotchas recorded:** `beforeEach()` attaches to the file that *calls* it while `it()`
attaches to the test file, so a hook registered from a Support class never runs (the scenarios
expose `setUp()` for the test file's own `beforeEach`); a nested directory cannot override its
parent's `uses()`, so `Pest.php` names the flat-file directories one by one — a new directory
under `Feature` must be added there; closures inside `it()` are rebound to the test case, so
`self::` in a Support class is the test class.

**Not yet scoped (next surfaces):** relationship picker index, tree GET/PATCH, nav tree, the
policy layer for single-entry abilities, bulk actions/duplicate, create-under-parent, users
listing — each a Phase 3.2 task with its own denial test.

### Phase 3.2.a — Policy layer (2026-09-11)

**Built.** `Enforcement\GuardsEntryAbilities` registers one `Gate::before` callback: for any
ability whose arguments include an `Entry`, deny if `Territorial::allows($user, $entry)` is false;
otherwise return null and let Statamic's `EntryPolicy` decide as before. `Territorial` = exempt, or
the resolver's `containing(Facts::of($entry))` intersected with the user's membership; site
default (no containing territory) is a denial for every non-exempt user. `Enforcement\Facts` is
the **only** place an `Entry` becomes `ContentFacts`: `uri` is `Entry::uri()` (what the listing's
`uri` clause compares on both drivers) and terms are read the way Statamic associates entries with
terms for `whereTaxonomy` — the value under the *taxonomy's* handle, wrapped and slugged
(`TaxonomyTermsStore::sync`) — so listing and policy cannot drift. `Gate::before` rather than a
policy subclass: every ability is covered, including ones a site or another addon adds, and a site
that binds its own `EntryPolicy` is not fought with; `Gate::before` runs ahead of the policy's own
`before()`, so the super/`configure collections` bypass is honoured explicitly via `Exempt`.

**Tested on both drivers** (`tests/Support/AbilityScoping`, 20 scenarios × 2): all five abilities
denied outside and allowed inside (`view/edit/update/delete/publish`); no territory → everything
denied; term and collection territories in single-entry checks; the orphan reachable only through a
root territory; super and `configure collections` exempt; **Statamic's own permissions still deny
inside the territory** (the Gate says nothing there). Surfaces, each with denial + control: edit
screen and direct URL (403/200), update (403 and unchanged / 200 and changed), publish and
unpublish, live preview, revision history, navigation page editor for a branch linked to an
outside entry, relationship-picker `data` (outside → `invalid`), command palette search (outside
absent, inside present, super sees it), bulk `delete`/`unpublish` neither offered nor run — a
mixed selection refused whole, inside runs. `Facts` pinned directly on a two-term entry.

Corrected: the id-list comment in `TerritoryScope` — the real reason is that the Stache's
`filterWhereJsonContains` rejects a non-array value while Statamic's association wraps a
`max_items: 1` string, so `whereTaxonomy` at the top level is the faithful test. Test-side: the
real CP form always sends `published`; a PATCH without it is fine on the Stache and violates the
Eloquent column's NOT NULL — the payloads now send it.

Still open on this surface, by design: `duplicate_entry` is still offered on outside items
(3.2.b) — the bulk test asserts only `delete`/`unpublish`.

### Phase 3.2.b — `duplicate_entry` (2026-09-11)

`Actions\DuplicateEntry extends Statamic\Actions\DuplicateEntry`, same auto-derived handle,
`authorize()` = `can('view', $item) && parent::authorize()`. Registered through the provider's
`$actions`; core actions register in `ExtensionServiceProvider::register()` and addon actions at
boot, and `RegistersItself::register()` overwrites an existing handle, so the subclass replaces
the core action everywhere (`Action::get`, `Action::for`, bulk lists) — verified in the host.
Tested on both drivers: not offered on an outside entry (offered on an inside one); refused with
403 in both `list` and `form` context, creating nothing and returning no `data`; a mixed selection
refused whole; an inside duplicate succeeds and **its copy is inside the territory** (`/cardiology/
trials-1`, viewable); super exempt. Fixture: the shared `editor` role gained `create pages entries`,
which the core `authorize()` needs — the control was failing for the fixture's reason, not the
code's.

Noted for the doctor command (Phase 4): duplicating a *top-level* branch entry makes a copy the
core action never appends to the tree — an orphan that, below a root territory, its own creator
cannot open. Not a leak; a wart worth listing.

Still open from Phase 0.1's bulk-action finding: a **third-party** action with a permissive
`authorize()` remains a read path via `context.view=form` (`getItemData` runs after only the
action's own check). Not closable by the Gate; to be stated in the README's limitations (Phase 5).

### Phase 3.2.c — Create under a parent (2026-09-11)

**Built.** `Enforcement\GuardsEntryCreation`, middleware pushed onto `statamic.cp.authenticated`
via the provider's `$middlewareGroups`, acting only on `collections.entries.store` (`_parent`) and
`collections.entries.create` (`parent`). `Enforcement\Placement` computes where the new entry
*would land* as `ContentFacts`: the would-be URI exactly as `EntriesController::entryUri()`
computes it for its own uniqueness check (`UrlBuilder` with `parent_uri`/`slug`/`depth`; root
parent = no parent; a parent not in the tree is refused, never treated as "no parent"); for a
non-structured collection the route alone; terms from the submitted values via the shared
`Facts::termsFrom()`. `Territorial::allowsFacts()` answers. Consequence stated: **a department
member cannot create at the root of the tree** — it belongs to no department; a root territory can.
Inventory correction: `preview.create` and `title-format.create` never read a parent, so there
was nothing to guard there.

**Tested on both drivers** (9 scenarios × 2): under own branch → created at the right URI; under
another department → 403, nothing created; at the root (no `_parent`, empty, or the root page) →
403 for a member, 200 for a root-territory member; a parent not in the tree → 403; a sub-territory
admin confined to their branch, not the parent's; a flat collection judged by route and terms — a
branch member refused, a collection territory allowed, a term territory allowed only when the
submitted values carry the term; the create screen refused for an outside parent; super exempt.
Fixture: the shared `editor` role gained `create news entries` (core `store` authorization).

### Phase 3.2.d — Structure tree GET (2026-09-11)

**Built.** `Enforcement\PrunesStructureTree`, middleware on the CP group acting only on
`collections.tree.index`: first `Gate::authorize('view', $collection)` — the check the
collection's own page already makes and the tree endpoint never did (closes the Phase 0.1 core
gap: `access cp` alone enumerated the site); then, for a non-exempt user, prunes the JSON. An
entry inside the territory is kept whole; one outside is dropped; one outside that sits *above*
an inside entry, or the tree's root, stays as a **redacted placeholder** — id and `entry`, a title
derived from its own URL segment (which the user already knows from their entries' URLs), and
nulls for everything else, flagged `delegated_redacted` — so the tree keeps its shape for the
PATCH merge in 3.2.e. Text/URL branches are nobody's. Tested on both drivers (5 × 2): a
`view`-less user → 403; a member sees only their branch with a redacted root; a sub-branch member
sees a redacted ancestor above their whole sub-branch; a user with permission but no territory in
the collection sees only the redacted root; a super user sees everything, nothing redacted.

**Correction to Phase 1.4 / 3.1's "orphan" story, found by the super-user tree test.**
`CollectionStructure::validateTree()` **re-attaches every entry missing from the stored tree at
the root level whenever the tree is read**, on both drivers. So an entry never placed in the tree
(or dropped from it) is persistently a root-level page (`/{slug}`); "no URI" exists only
transiently within one request (a freshly saved copy before the tree is re-read) or for a
collection without a route. The rule already chosen in 3.1 — a root territory is the whole site;
below the root, no URI means no branch — is exactly right for this, and the two drivers agree on
it. The Eloquent-only difference is narrower than 3.1 said: after an entry is dropped from the
stored tree, the tree re-attaches it at the root but the driver's `uri` *column* (and `Entry::uri()`,
which reads it) keeps the old value until a save — a state the CP's tree editor cannot produce.
Comments and test names now say "root-level stray" instead of "orphan"; the SECURITY.md
out-of-scope bullet and the 3.1 note are read in that light.

### Phase 3.2.e — Tree PATCH and flat reorder (2026-09-11)

**The real hazard on this surface is not the move check — it is that `CollectionTreeController::
update` replaces the whole stored tree with whatever is submitted.** A territory user's editor
holds the pruned view from 3.2.d; saving that as-is would throw away everything they could not
see (and `validateTree` would re-attach it all at the root). So `Enforcement\GuardsStructureTreeUpdate`
(middleware on the CP group, acting on `collections.tree.update` and `collections.entries.reorder`)
runs `Enforcement\TreeMerge` first and hands the controller the **merged whole tree** in place of
the request's. `TreeMerge` is pure (trees in, tree out, membership via a callable; 12 unit tests)
and enforces: every submitted id was shown and every shown id is submitted or deleted (nothing
appears, nothing quietly leaves — leaving a page out would be a move to the root by omission); a
placeholder keeps its parent (outside entries never move); an inside entry may move only under
another inside entry or stay under the parent it had — never to the root or under a placeholder;
a deleted id must be inside **and so must every stored descendant** (Statamic deletes descendants
with it — matters for term-shaped territories); dropped entries keep their place and subtrees,
and the user's submitted order fills the slots their own entries occupied. Any refusal refuses the
whole request with 403 before anything is written. Flat reorder: every submitted id must be
inside, else 403 — which in practice means reordering a linear collection needs a territory
covering the whole collection (a partial listing's page slice never matches the tree's, and the
controller 409s).

**Tested on both drivers** (9 × 2 HTTP scenarios + 12 unit): a reorder within the territory
saves and the rest of the tree is byte-identical; a move under another inside page saves and the
URI follows; a move to the root refused, tree unchanged; pulling `/oncology` under `/cardiology`
(the Phase 0.1 escalation) refused; dropping a page without deleting refused; delete inside
allowed, delete outside refused; a sub-branch member cannot move out from under their placeholder
parent but can reorder within, and a sibling they never saw stays put; super exempt; flat reorder
allowed for a collection territory, refused for a user with the permission but no claim.

Pest trap hit a second time (a `beforeEach` inside a Support class never runs): the scenario
class now exposes `setUp()` like the others. Recorded once more so the next surface avoids it.

### Phase 3.2.f — Navigation tree GET and PATCH (2026-09-11)

**Decision.** A navigation is public site structure, so its branches are not hidden; what a branch
reveals *about the entry behind it* is what is protected. `Enforcement\GuardsNavigationTree`
(middleware on the CP group for `navigation.tree.index` and `navigation.tree.update`): GET now
requires `view` on the nav tree — the endpoint had no check at all (Phase 0.1 core gap) — and, for
a territory user, redacts every entry-derived field of a branch linked to an outside entry
(`entry_title`, `entry_blueprint`, `status`, `edit_url`, `redirect`, `collection`, `can_delete`),
keeping the `url` only if the entry is published and therefore already public; the branch's own
custom title stays; URL branches are nobody's content. PATCH refuses (403, nothing saved) a
**new** branch or a **re-pointed** branch whose target is outside; a branch that keeps its stored
target may be re-titled and moved — nav structure is governed by the nav's own `edit` permission
(the Phase 0.1 decision, now stated in code). The nav page *editor* was already closed by the Gate
in 3.2.a. `NavigationPagesController::update` only validates; the tree PATCH's `data` is the one
write path for targets.

**Tested on both drivers** (8 × 2): a nav-permission-less user → 403; every branch present with
inside intact, published-outside redacted but URL kept, draft-outside redacted including URL,
URL branch untouched; super sees all; new link to a draft outside → 403, branch count unchanged;
re-point to outside → 403; new link to own entry → 200; re-title and move an outside-linked
branch → 200 with the target unchanged; super may link anything.

### Phase 3.2.g — Relationship picker index (2026-09-11)

`Fieldtypes\Entries extends Statamic\Fieldtypes\Entries`, same auto-derived handle `entries`,
registered through the provider's `$fieldtypes`; core fieldtypes register in
`ExtensionServiceProvider::register()` and addon ones at boot, so the subclass replaces the core
binding everywhere the handle is resolved — every `entries` field, Bard link and Link picker
(verified in the host). It overrides `getIndexQuery()` to apply the same `TerritoryScope` the
entry listing uses to the picker's query before it runs; the parent's search path (plain LIKE, or
a `Search\QueryBuilder` when the collection has an index) is covered because the scope handles
all three builders. The picker's `data` endpoint was closed in 3.2.a. Tested on both drivers
(7 × 2): a member sees only their branch with the right total; no territory → empty, total 0; a
picker over every collection returns the union of a branch and a term territory; pagination of the
scoped set; search with and without an index; super unrestricted; the override resolves.

With this, **every listing surface from the Phase 0.1 inventory scopes the query before it runs**:
the entry listing and its search (3.1), the command palette (post-filter, Statamic's own, 3.2.a),
the structure tree (3.2.d), the nav tree (3.2.f), and the picker (3.2.g).

### Phase 3.2.h — Users listing and Statamic's own user screens (2026-09-11)

**Who is confined — a decision.** Anyone who belongs to a territory: their view of Statamic's
users screens becomes invariant 6's (the users they administer, plus themselves; a plain member
sees only themselves). **Not** confined: super users, and people in **no territory at all** —
their user management is Statamic's business under Statamic's permissions. This is deliberately
narrower than `Exempt::user()` (content): a site needs to be able to have a non-super user
administrator, and has one by keeping them out of every territory. Recorded in
`Enforcement\UserVisibility`.

**Seam.** The users listing has no hook, but it applies request `filters` through Statamic's
filter-scope extension and resolves them by handle regardless of `visibleTo`. So `Scopes\VisibleUsers`
is a hidden filter (`visibleTo` false, no fields, no badge) whose `apply()` does `whereIn('id', …)`
**from the signed-in user, ignoring the request's values** — a crafted `filters` parameter cannot
widen it — and `Enforcement\ScopesUsersListing` (middleware on `users.index`) rewrites the
base64-JSON `filters` parameter to include it, so it cannot be left off either. Pagination and
totals stay honest; no controller code copied; works on the search path and the `group` path.
Direct URLs and bulk actions: `Enforcement\GuardsUserAbilities` is a `Gate::before` for `User`
subjects — a confined actor is refused everything on a user they cannot see, and everything but
`view` on one they may not change (a super user, or a user shared with a territory they do not
administer); themselves they always reach, and what they may do to themselves stays Statamic's
decision. `Administration\Visibility` was extracted from `DelegatedAdministration` so the
invariant-6 rule has one implementation for Delegated's operations and Statamic's screens alike;
`Administration\Actors::from($user)` is the Actor factory deferred from Phase 2.

**Tested** (`UsersScopingTest`, 9 scenarios; users are file-driven under both test cases so it
runs once): the admin's listing is exactly their users plus themselves with the right total; a
member sees only themselves; a no-territory user administrator and a super user see everyone; a
search is scoped; a crafted or omitted `filters` parameter changes nothing; pagination of the
confined set; the edit screen refused by direct URL for invisible, site-wide, unassigned, super and
shared users, allowed for wholly-owned users and self; update refused and unchanged for invisible
and shared, allowed for wholly-owned; delete neither offered nor run on an invisible user, run on
a wholly-owned one; the unconfined administrator still edits anyone Statamic lets them.
Test-side: Statamic requires an *elevated session* to edit users — its own 403, disabled in the
file so the refusals proven are Delegated's.

### Phase 3.2.i — Direct-URL oracles (2026-09-12)

`Enforcement\ClosesEntryOracles`, middleware on the CP group matching the two unnamed redirect
routes by URI (`cp/edit/{id}` and `cp/collections/{collection}/entries/{entry}/{slug}`): for a
confined user, an entry outside their territories answers 404 — exactly what a non-existent id
answers — instead of 302-to-edit-URL, which told any control-panel user the entry existed and
which collection it was in. Inside, and for exempt users, the redirects work as before. Tested on
both drivers inside `AbilityScoping` (outside → 404 for both routes incl. a draft; a non-existent
id → 404; a no-territory user → 404 for everything; inside → 302 to the right edit URL; super →
302 for an outside entry). Non-entry ids on `cp/edit/{id}` (terms, assets) are left to Statamic.

**Phase 3.2 complete.** Every surface in the Phase 0.1 inventory now has an enforcement point and
a denial test: listing + search (3.1), policy layer / edit / update / publish / preview / revisions
/ nav page editor / picker data / command palette / built-in bulk actions (3.2.a), duplicate
(3.2.b), create-under-parent (3.2.c), structure tree GET (3.2.d) and PATCH + flat reorder (3.2.e),
nav tree GET and PATCH (3.2.f), relationship picker (3.2.g), users listing and user screens
(3.2.h), redirect oracles (3.2.i). REST and GraphQL remain out of scope by design (userless).

### Phase 3.3 — Exemptions pinned and documented (2026-09-12)

The exemption was already in code (`Enforcement\Exempt` for content: super users, `configure
collections` holders, no user; `Enforcement\UserVisibility` for the users screens: super users
and people in no territory). This task pins it as a *rule* and sweeps it: `tests/Support/
Exemptions` (5 scenarios × 2 drivers) states both rules directly, then takes **a super user who is
also assigned to a territory** — a site owner listed as head of a department — and a
`configure collections` holder likewise listed, and checks that membership never narrows what the
exemption grants: every ability on every page, the listing, the tree (nothing redacted), the
picker, the redirect oracles, the edit screen; creating under another department's parent and
moving that department under another. A control keeps the ordinary member alongside them as
confined as ever — the exemption is about the person, not the territory. One subtlety pinned: a
`configure collections` holder is exempt for *content* but, if listed in a territory, confined on
the users screens like any member (that permission is about collections, not people).

README gained "Who Delegated does not restrict": super users; `configure collections` holders
(with Statamic's own reason); people in no territory, on the users screens only, and why (a
non-super user administrator); console commands and queue jobs. `LICENSE.md` and `SECURITY.md`
already said the first two.

**Phase 3 complete.** 350 tests, both drivers.

### Phase 4.1 — CP screen: territories, assignments, membership (2026-09-12)

**How an addon renders a Statamic 6 page** (no sibling precedent; read from the built CP bundle):
the CP's Inertia resolver tries its own `pages/*.vue` first, then a registry exposed to addons as
`inertia` from `@statamic/cms/api` — `inertia.register('delegated/Territories', Component)` — and
applies the CP layout itself. `@statamic/cms/inertia` (different module, same word) is Inertia's
`router`/`useForm`/`Head`. `@statamic/cms/ui` is the UI kit (Header, Table*, Modal,
ConfirmationModal, Field, Input, Select, Badge, Dropdown*, EmptyState*…; props read from the
package's `.d.ts`). HTTP: `$axios` from the app's global properties, as the sibling found. The
Vite scaffold mirrors the sibling (`@statamic/cms/vite-plugin`, externals, `manifest.json` at the
build root); the build is committed and an `assets` CI job fails if it is stale. Publish into the
host with `vendor:publish --tag=delegated` — the tag is the addon slug; `--provider=…` also copies
config and migrations (done once by mistake, reverted).

**Built.** A **site-administration** screen: a new permission `manage territories` (registered in
a "Delegated" group; super users pass it; added to `ManagementPermissions` so a territory admin can
never be granted it — the invariant-4 dataset gained it too), a nav item under Users, CP routes
under `cp/delegated/territories` behind `can:manage territories`, `TerritoriesController` (Inertia
page + JSON index, store, update, destroy) and `AssignmentsController` (store = upsert, destroy),
writing through `TerritoryStore` (gained `createTerritory`/`deleteTerritory`) — **not** through
`TerritoryAdministration`: the Phase 2 invariants constrain territory admins, not site
administrators; 4.2's self-service will use the contract. `TerritoryRequest` refuses with a
message everything the database would refuse: slug format and uniqueness per site, site exists,
scope type, scope ref normalised then checked (branch starts with `/`, collection exists, term
exists and is `taxonomy::slug`), one territory per scope per site (with "assign more groups to it
instead"), parent exists, same site, not self, not a descendant; a missing territory is 404 before
it is 422. Every write invalidates the resolver, and the tests read ownership back through it.
The page: table of territories (site, scope badge, parent, member count), create/edit modal with
slug auto-derived from the name, members modal (list with level badges, add user/group at a level,
remove), delete confirmation that says what happens to nested territories and to the content.

**Tested** (`TerritoriesScreenTest`, 11): permission gate (HTML redirected away, JSON 403, manager
and super 200); Inertia component and props; listing with parent names and labelled assignments;
create + normalise + resolver sees it; ten validation cases incl. same slug allowed on another
site; collection and term scopes; update, self/descendant parent refused, missing → 404; delete
cascades assignments, children become roots, resolver forgets; assignments add/upsert/remove with
`granted_by`; bad subjects/levels 422; the committed build's manifest names a file that exists.

**Clicked through in the host (2026-09-12, after the cache fix below):** nav item present; empty
state; create modal with the slug auto-derived; create → row appears with site, scope badge and
`/cardiology`; members modal → add the one user as member → "1 assigned" with level badge →
remove → "0 assigned"; row menu → edit pre-filled → rename → saved; delete confirmation → empty
state again. No console errors. Cosmetic only: the members table's Remove button shows its text
despite `icon-only`; the Dropdown's default trigger is the "…" the kit provides.

### 2026-09-12 — Host CP down: "Statamic Pro is required to use multiple sites"

Not Delegated's doing: the host was converted to multisite (`resources/sites.yaml` gained `fr`,
`system.multisite => true`) by the sibling addon's session on the evening of 2026-09-11, and the
host's `.env` had `STATAMIC_PRO_ENABLED=false`, which Statamic's `CheckMultisite` middleware
refuses. Fixed by setting `STATAMIC_PRO_ENABLED=true` in the host `.env` (Pro is free for local
development, and Delegated requires it anyway); `GET /cp` answers again. Recorded in CLAUDE.md.
The Phase 4.1 "same slug on another site" test uses `Site::setSites()` inside the Testbench
sandbox only — checked that it cannot reach the host's file.

### 2026-09-12 — First real click-through: the resolver's cache broke on Laravel 13

Creating a territory in the host failed with `TerritoryResolver::cached(): Return value must be
of type TerritoryMap, __PHP_Incomplete_Class returned`. **Root cause:** Laravel 13's
`config/cache.php` ships `serializable_classes => false` (gadget-chain hardening) — every store
unserializes with `allowed_classes => false`, so a cached *object* comes back as an incomplete
class. The resolver cached the `TerritoryMap` object. The suite never saw it: Testbench's
skeleton does not set the key, and its `array` store does not serialise at all. **This would have
broken every real Laravel 13 site on first use** — the kind of bug the host click-through exists
to catch. Fix: the cache now holds plain arrays (`Territory::toArray/fromArray`,
`Assignment::toArray/fromArray`) and the map is rebuilt on read; a wrong-shaped value under the
key is replaced rather than trusted. Regression test runs with the host's setting **on a `file`
store** (the `array` store would have passed vacuously) and was shown to fail against the old
code. Host cache cleared; the resolver reads back from the host's file cache. 364 tests.

Also today: the host user's `password_hash` had been hand-edited to a plain 9-character value
(YAML would not parse — `@` cannot start a plain scalar). While re-hashing it in place the line was
lost and the backup deleted in the same command; the account had to be given a new password.
Lesson recorded: keep a backup until the result is verified, never in the same command.

### Phase 4.2 — Territory admin self-service (2026-09-12)

**Contract change first.** `setRoles` used to *replace* a user's roles, so an admin editing a
wholly-owned member would silently strip any role the admin does not hold. Keeping is not
granting, and dropping would be a demotion the admin has no standing for, so `setRoles` now
changes only the roles the actor holds and leaves the rest as they are (contract doc updated; the
invariant-1 file gained the test; asking *for* a role not held is still refused outright).

**Built.** `MyTerritoriesController` under `cp/delegated/my-territories`: no permission on the
routes — the controller admits anyone who administers at least one territory (and super users,
who see every territory), and **every write goes through `TerritoryAdministration`**, so the six
invariants decide; an `EscalationDenied` becomes a 403 whose JSON names the invariant, an
`InvalidArgumentException` a 422. Operations: rename (name only — the route accepts nothing
else, and the invariant would refuse scope anyway); add a member **by email address** (the
invariant-6 decision: the admin is not handed a list of everyone on the site) or a group by
handle, at a level, with roles; remove a member; set a wholly-owned member's roles. The JSON shows
each member with the roles the admin may change and the ones shown **locked** (held by the user,
not by the admin), marks shared and super users read-only, and marks the admin themselves.
`grantableRoles` is the admin's own roles minus any carrying a management permission (the server
refuses those regardless). Nav: "My territories" under Users, shown only to someone who
administers a territory — nav items gate on permissions, so the membership check is made in the
`Nav::extend` closure. Page `delegated/MyTerritories`: one panel per territory, members table
with level and role badges (locked roles with a lock icon), rename / add / roles modals; a 403's
message is shown as a toast, never as a success.

**Tested** (`MyTerritoriesScreenTest`, 10): gate (member and no-territory user 403, admin and
super 200); Inertia props with only the admin's territories and only grantable roles (a held
`edit roles` role is never offered); members with editable/locked/shared/self flags and no sign of
oncology's admin; add by email with a held role; refusals naming invariants 1, 4, 2 and 3 with
nothing written; unknown email/group → 422; add a group as admin; remove inside allowed, outside
refused; roles: held roles replaced, unheld kept, shared member refused with `USER_SHARED`;
rename allowed, scope untouched, other territory refused; super sees all. 375 tests.

**Browser pass not completed:** the CP session had expired in the new window and there is no
password to hand. A territory (`Cardiology`, `/cardiology`, id 2) with the `Test` user as admin is
seeded in the host for the check: open `http://statamic-dev.test/cp/delegated/my-territories`
as Test and try rename → add member by email (`(address removed)`) → roles → remove.
Delete the territory from the Territories screen afterwards.

### Phase 4.3 — Audit log, scoped per territory (2026-09-12)

**What is written.** `Audit\AuditLog` has three public methods — `record`, `forTerritories`,
`latest` — and a test pins that list plus the table's lack of `updated_at`, so append-only is
structural, not a convention. `TerritoryStore` records every one of its writes with the actor
passed in (controllers pass `User::current()->id()`; seeders may pass nothing → "the system"):
`territory.created/updated/deleted` (updated carries **only the keys that changed**; deleted
carries the full row plus its assignments list), `assignment.granted/changed/revoked` (nothing
for a no-op re-grant at the same level; a user's deletion revokes with `because: user deleted`).
`DelegatedAdministration` records `user.roles_set`, `user.updated` (changed attributes only) and
`user.deleted` (before the user is gone, so the row still has their email), **one row per
territory the user is in that the actor administers** — every territory for a super user, and a
null territory if none. Every invariant check runs inside `recordingRefusals`, so an
`EscalationDenied` leaves `refused.<operation>` with the invariant code and message in `after`
before it is rethrown; nothing else is written for a refused call (tested).

**Who reads what.** `Audit\AuditView` presents rows for a viewer. Site-wide: `GET
cp/delegated/audit` behind `manage territories`, names everyone. Per territory: `GET
cp/delegated/my-territories/{territory}/audit` for its admins and super users (404 for a
territory that does not exist, 403 for anyone else, including a `manage territories` holder who
does not administer it — they have the site-wide log). Spec §11's leak: a territory's rows can
name an actor from outside it (a site administrator reshaping it, another territory's admin being
refused), so the *actor* is shown by name only if they are in the viewer's invariant-6 visibility
now, otherwise as "someone outside your territory" with no id. The *target* of one of the
territory's own rows was in the territory when the row was written (that is what `territory_id`
on the row means), so they are named even after leaving; the target of a `refused.*` row may be
anyone, so it follows visibility. The viewer is "you"; a null actor "the system"; a missing user
"a user who no longer exists". Payloads carry ids and levels, never names, except `user.*`
before/after, which are the (then-visible) target's own attributes. The site-wide view redacts
nothing: a `manage territories` holder who is not super would otherwise see nobody named, since
their invariant-6 visibility is empty.

**UI.** One component, `AuditLogModal` (fetches a URL, lists when/who/what/change, refusals in
red with the message): "Activity" in the Territories header (site-wide) and per panel on My
territories. Assets rebuilt and published to the host; the store→view round trip was run in the
host through tinker (two renames of territory 2, read back as "you" — the host's `delegated_audit`
now has two rows). **Browser pass still pending** for the same reason as 4.2: no CP session and no
password to hand. The rows are there for whoever logs in.

**Tests** (`AuditLogTest`, 11): territory create/update/delete before-and-after; assignment
grant/change/revoke and no-op; administration's user rows per territory; user deletion order;
four refusals naming four invariants with nothing else written; append-only by reflection and
schema; site-wide gate (admin and member 403, manager and super 200); per-territory gate (admin
and super 200, member / other admin / manager 403, own-vs-other territory, unknown 404);
confinement to the territory newest-first with the removed target still named; outside actors
redacted for the admin and named for the manager, including "the system" for a null actor; a
refusal *by* an outsider shown to the territory with the outsider unnamed and their target named,
and a refusal aimed outside belonging to no territory. 386 tests, Pint clean.

**Caveat noted for `doctor` / docs:** the host's territory 2 predates audit writes, so its
creation has no row. A site that adds Delegated to existing territories seeded another way has
the same gap — the log starts when the addon does.

### Phase 4.4 — `delegated:doctor` (2026-09-12)

**Built.** `Doctor\Doctor::diagnose()` returns `Finding`s (check slug, severity problem / warning /
note, message, one detail line per thing); `Console\Doctor` prints them with Laravel's components
or as `--json`, exit 1 on any problem. Reads only. The checks, gathered from every earlier note
that said "for the doctor":

- *problem* `territory-scope-missing` — site, collection, taxonomy/term, or branch entry
  (`Entry::findByUri`) gone; the root branch `/` always exists. Spec §11's deleted-scope case.
- *problem* `configure-collections-holder` — non-super users with the permission, naming the role
  (Phase 0.1 architectural fact 1).
- *warning* `territory-parent-missing` — the map's documented "treated as top-level" case.
- *warning* `assignment-subject-missing` — user or group deleted outside Delegated.
- *warning* `territory-admin-with-management-permission` — an admin (direct or via a group) who
  holds any `ManagementPermissions::ALL` entry other than `super`/`configure collections`: not an
  escalation Delegated can stop, but the door invariant 4 keeps shut from the other side.
- *warning* `entry-outside-tree` — entries of a structured collection absent from the **stored**
  tree (`Tree::fileData()`, before `validateTree` re-attaches them at the root): the Phase 3.2.b
  duplicate-a-top-level-page wart.
- *warning* `tree-names-missing-entry` — the reverse: a stored tree naming an id not in the
  collection; Statamic drops it and everything under it on read, so a territory rooted there
  covers nothing. Found in the host, not planned: the host's `pages` tree says `entry: home` while
  the Home entry's id is a UUID, so both host pages are "outside the tree".
- *note* `territory-empty` — no members counting inherited membership from ancestors.
- *note* `entry-unowned` — per collection and site with at least one territory, "n of m entries
  belong to no territory" with up to five URIs; a site with no territories is one line.

**Host run:** `Cardiology` (#2) points at `/cardiology`, which does not exist in the host (the
seed from 4.2 named a branch the host never had) → the problem line; the broken `home` tree
reference above; `fr` has no territories. All real. Not fixed in the host — they are the user's
content, and 4.2's browser check may still want that territory.

**Tests** (`DoctorTest` 10, `Eloquent/DoctorTest` 1; 397 total): healthy fixture raises only the
stray-page warning and the unowned note, exit 0; five kinds of missing scope in one run and a
scope deleted after the fact (term and branch); dangling parent through a fake `TerritorySource`
(the FK forbids it and SQLite cannot drop the FK inside the test transaction); deleted user and
group; empty territories with inheritance honoured; `configure collections` holder with role
named, super not listed, exit 1; admins with `edit users` directly and through an admin group,
a member not reported; a tree naming a ghost; human output and `--json` shape. Two test-only
artefacts: Statamic memoises a user's permissions per request (`PermissionCache::clear()`) and
the Eloquent driver memoises `findByUri` (`Blink::flush()`) — neither matters to a CLI run.

### Phase 5.1 — CI matrix on Statamic minors (2026-09-12)

Packagist has 6.32.0 since the pin was set. Resolved it locally (`composer require
statamic/cms:~6.32.0 --with-all-dependencies` — only `statamic/cms` moved), ran the suite: 397
pass, and `npm run build` against 6.32's Vite plugin reproduces the committed bundle byte for
byte. So the pin is widened to `>=6.31.0 <6.33.0` — a range of verified minors, not a caret —
and the lock file resolves 6.32.0 (the host stays on 6.31.0; it has its own lock).

`tests.yml`: the `tests` job is now PHP {8.3, 8.4} × Statamic {6.31, 6.32}. Each leg runs
`composer require "statamic/cms:~<minor>.0" --with-all-dependencies` (narrowing within the pin;
`composer update --with` refuses a constraint outside composer.json's, and `require` replaces
it) and then greps `composer show` for exactly that minor before testing, so a leg cannot
silently test the wrong version. Simulated the 6.31 leg locally the same way: downgrade, 397
pass. A separate `next-minor` job with `continue-on-error: true` requires `^6.31` — whatever
the newest 6.x is — and is the spec §7.3 re-verification cue: when it goes red, run the Phase 0
inventory against that minor, fix what moved, add the minor to the matrix and the pin, and
record it in `DECISIONS.md` (5.2). It cannot fail the branch.

Not done: no run has happened on GitHub from here (no push in this session); the workflow was
checked for shape only. **Owner action:** push and watch the first matrix run.
