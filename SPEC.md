# Delegated — Build Spec

**For:** handoff to Claude Code
**Date:** September 11, 2026
**Product:** `bpmore/statamic-delegated` — departmental content ownership and permissions for Statamic 6
**Price:** unresolved — see §9. The $299 in the opportunities file predates the flat-$99 line.
**Statamic:** `^6.0` · **PHP:** `^8.2` · **Database required**

---

## 1. Read this before anything else

**This is the first product in the line where a bug is a security vulnerability rather than a wrong report.**

A11y Report producing a wrong number is embarrassing. Delegated failing to hide one entry means a department saw content it was not entitled to see — on a product sold to hospitals and universities, purchased specifically because they have information they need to compartmentalise. That changes three things:

- **The testing bar.** Every enforcement point needs a test proving access is *denied*, not just that the happy path works. Negative tests are the product.
- **You need a security contact and a disclosure policy** in the README before this lists. `SECURITY.md`, an email, a stated response time.
- **Version pinning matters more than elsewhere.** See §7.

If that responsibility isn't something you want, this is the product to skip. Nothing else on the roadmap carries it.

---

## 2. What Statamic already gives you, and what it doesn't

**Gives you:**

- Capability permissions with wildcard expansion per resource — `view {collection} entries`, `edit {collection} entries`, and equivalents for sites, containers and taxonomies
- Roles aggregating permissions; roles attached to users or user groups
- **Author-scoped permissions** — own entries versus others' — which require an `author` field in the blueprint
- `Permission::register()`, `Permission::group()` and `->children()` for addon-registered permissions
- Multi-site gating (`access french site` combined with the collection permission)
- **Super users bypass all checks entirely**

**Doesn't give you, and this is the product:**

- **Tree scoping.** There is no way to say "this group may edit everything under `/cardiology` and nothing else." Permissions stop at the collection.
- **Delegated administration.** No concept of a departmental admin who manages their own people without being able to reach anyone else's.
- **Listing filtration by policy.** Which brings us to the prior art.

---

## 3. Prior art — read `doefom/restrict` first

[`doefom/restrict`](https://github.com/doefom/restrict) is a free addon that "applies your `EntryPolicy`'s `view` method to entry listings in the control panel." It exists because Statamic checks policies when you *open* an entry but not when it *lists* them, so without something like it a restricted user sees rows they can't open.

Three things to take from it:

1. **It validates the need.** Someone built and maintained an addon for exactly this plumbing, which means the gap is real and felt.
2. **It is the hardest part of your build, already sketched.** Read the source before writing your own.
3. **It is stale and limited.** Statamic 4 and 5 only, and explicitly **incompatible with the Eloquent driver** — flat file only. It also does not do tree scoping, does not touch user management, and does not restrict the front end or the API.

So: not a competitor. A component you're replacing with something broader, on a version it doesn't support, in a place it left unfinished.

---

## 4. The three pillars

### Territory

A named scope, assigned to a user group.

- **Structure branch** — everything under `/cardiology` in the pages structure. The primary case.
- **Collection** — an entire collection, for teams that own a content type rather than a section.
- **Taxonomy term** — everything tagged `department:cardiology`, for sites that aren't tree-shaped.

Territories nest. `/cardiology/clinical-trials` inherits from `/cardiology` unless separately assigned. Resolution walks *up* until it finds an owning territory, then falls back to the site default.

### Enforcement

Every place a user could reach content outside their territory. See §5 — this is the whole engineering problem.

### Delegated administration

A **territory admin** can add and remove members within their own territory, and nothing else. Explicit invariants, each with a test:

- Cannot grant a role they do not themselves hold
- Cannot add a user to a territory they do not administer
- Cannot edit, demote or delete a super user
- Cannot create or modify roles
- Cannot widen their own territory
- Cannot see users who have no assignment overlapping their territory

**Privilege escalation is the failure mode that ends this product.** Write those six as tests before writing the feature.

---

## 5. The enforcement surface — this is the spec

**Task 1 is not code. It is an inventory.** Go through Statamic 6 and record, for each surface, whether it currently consults `EntryPolicy`, and how a restriction would have to be applied. Put the findings in `PROGRESS.md` Notes before writing anything.

At minimum:

| Surface | Question |
|---|---|
| Entry listing | Does it check policies? (No — this is what `restrict` fixes.) |
| Entry edit screen | Policy checked on open? |
| Direct URL to an entry ID | Guarded, or only the listing hides it? |
| Bulk actions | Are actions filtered to the visible selection, or can a crafted request act on anything? |
| Structure / tree view | Shows branches outside territory? |
| Global search | Indexes and returns restricted entries? |
| Command palette (new in 6) | Same question, newer surface, easier to miss |
| REST API | Separate auth path entirely |
| GraphQL | Same |
| Live Preview | Renders arbitrary entries? |
| Revisions | Can a restricted user read revision history? |
| Assets | Out of scope for v1 — see §8 |
| Navigation editing | Can a user move a branch they don't own? |

**Every "no" on that list is a hole in a security product.** The inventory is more valuable than any single check, and it is the artifact to keep updated when Statamic releases a minor.

### Scope the query, do not filter the results

The obvious implementation — fetch a page of entries, drop the ones the user can't see — is wrong in a way that shows up immediately at scale:

- Page one returns 9 rows, page two returns 14, because filtering happens after pagination
- Total counts are wrong, so pagination controls lie
- On a 40,000-entry collection you load and discard enormous amounts of data

Scope the **query** by territory before it runs. This is harder with the flat-file Stache than with Eloquent, and it is the likely reason `restrict` filters instead. **Spike both drivers** before committing to an approach, and if flat-file genuinely cannot be scoped, say so in the docs rather than shipping something that degrades quietly on large sites.

---

## 6. Data model

```
delegated_territories
  id, name, slug, site
  scope_type(structure_branch|collection|taxonomy_term)
  scope_ref            -- branch URI, collection handle, or term id
  parent_id(nullable), created_at

delegated_assignments
  id, territory_id
  subject_type(user|group), subject_id
  level(member|admin)
  granted_by, granted_at
  unique(territory_id, subject_type, subject_id)

delegated_audit          -- append-only
  id, actor_id, action, target_type, target_id
  territory_id, before(json), after(json), created_at
```

**Resolution is on the hot path.** It runs per row on every listing render. Cache aggressively — territory tree in memory per request, user's effective territories cached per session, invalidated on assignment change. A naive implementation that walks the structure tree per row will make the CP unusable on a large site.

---

## 7. Brittleness, and how to live with it

Policy internals and control-panel listing internals are not a stable public API the way fieldtypes are. This addon reaches into both. Three mitigations, all non-optional:

1. **Pin a tested Statamic range** in `composer.json` — not open-ended `^6.0`. Widen it only after testing a new minor.
2. **Integration tests against multiple Statamic minors in CI.** A matrix axis on `statamic/cms` version, not just PHP.
3. **A documented re-verification step** on every Statamic minor release, and a `DECISIONS.md` entry recording which version was last verified.

The product's promise is that content stays compartmentalised. A silent break after a routine `composer update` is the worst outcome available, and it's the realistic one if none of the above exists.

---

## 8. Out of scope for v1

**Assets.** A department owning `/cardiology` plausibly owns cardiology images, but asset containers aren't tree-shaped and the model doesn't map cleanly. v2.

**Front-end visibility.** Delegated restricts the control panel. It does not hide content from site visitors — that's a different problem with different failure modes, and conflating them would let someone assume a security property they don't have. Say this explicitly in the README, the way `restrict` does.

**Form submissions, globals, users outside territories, and anything requiring core changes.**

**Constraining super users.** They bypass everything by design. Don't try; document it.

---

## 9. Pricing — an actual decision, not a default

The opportunities file says $299. Everything you've shipped is $99.

Arguments for breaking the flat line here: the buyer is an institution rather than an individual, the support burden is materially higher than anything else in the range, the version-verification commitment in §7 is ongoing work, and the value — whether a large org can adopt Statamic at all — is not comparable to a reporting addon.

Argument against: you have a clean, simple story right now, and $299 next to three $99 products invites the question of why.

**Recommendation: $249, and only if §7 is genuinely honoured.** Charging enterprise money for something that silently breaks on a minor upgrade is worse than not shipping it. If the version-matrix CI isn't going to happen, price it at $99 and set expectations accordingly.

Decide before the listing copy is written, not after.

---

## 10. First session for Code

1. **The enforcement surface inventory** (§5). Not code. Go through each surface in a real Statamic 6 install with a restricted test user and record what actually happens. Write it into Notes.
2. **Read `doefom/restrict`'s source** and record in Notes how it hooks the listing, and what it would take to make that work on Statamic 6 and the Eloquent driver.
3. **Spike query scoping** on both drivers. Can the entry query be scoped by territory before execution, on flat file and on Eloquent? This determines the whole architecture.
4. Only then: scaffold, migrations, the territory resolution service with caching, unit-tested with no CP.
5. **The six escalation invariants from §4 as failing tests**, before any user-management feature exists.

Do not write a single enforcement check until steps 1–3 are in Notes.

---

## 11. Open questions

- **Groups or users?** Assign territories to user groups only, or to individuals too? Recommend groups only in v1 — individual assignment is how permission systems rot.
- **Multiple territories per user.** Someone in two departments. The model allows it; confirm resolution is a union and that the listing query handles an OR across branches without collapsing.
- **What happens to an entry whose territory is deleted?** Fall back to site default, and surface it in a doctor command.
- **Does a territory admin see the audit log for their own territory?** Probably yes, scoped. Confirm it can't leak actor names from outside.
- **Interaction with Lifecycle.** Territories and content owners are adjacent concepts. If both ship, an owner probably *is* a territory member. Worth designing the seam rather than discovering it.

---

## Sources

- [Statamic permissions](https://statamic.dev/control-panel/permissions) — capability model, wildcard handles, author permissions, super user bypass
- [Registering custom permissions](https://statamic.dev/extending/permissions) — `Permission::register`, groups, children
- [`doefom/restrict`](https://statamic.com/addons/doefom/restrict) · [source](https://github.com/doefom/restrict) — free, Statamic 4–5, flat file only
- [Statamic users](https://statamic.dev/users)
