# PROGRESS — Delegated

**Spec:** `delegated-build-spec.md` · **Package:** `bpmore/statamic-delegated` · price unresolved (spec §9)
**Rule:** do the next unfinished task only, then update this file, verify the build, and commit.

> **This is a security product.** A missed enforcement point means a department saw content
> it was not entitled to see. Every check needs a test proving access is *denied*, not only
> that the happy path works. Read spec §1 before starting.

## Phase 0 — Reconnaissance (no code)
- [x] **Enforcement surface inventory** (spec §5): for each CP surface, log against a restricted test user whether `EntryPolicy` is consulted and how a restriction would apply. Entry listing, edit screen, direct URL, bulk actions, structure view, global search, command palette, REST, GraphQL, Live Preview, revisions, navigation editing. Write findings into Notes.
- [ ] Read `doefom/restrict` source; record how it hooks the listing and what Statamic 6 + Eloquent would need
- [ ] **Spike query scoping** on both drivers — can the entry query be scoped by territory before execution, on flat file and on Eloquent? Record the answer; it determines the architecture
- [ ] Do not write an enforcement check until all three are in Notes

## Phase 1 — Foundations
- [ ] Scaffold; confirm `statamic/cms: ^6.0` (pinned to a tested range, not open-ended) and `php: ^8.2`
- [ ] `SECURITY.md` with a contact and stated response time — before any listing exists
- [ ] Migrations: `delegated_territories`, `delegated_assignments`, `delegated_audit`
- [ ] Territory resolution service with caching, unit-tested, no CP booted

## Phase 2 — Escalation invariants (tests before features)
- [ ] Cannot grant a role they do not hold
- [ ] Cannot add a user to a territory they do not administer
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
