# Security policy

Delegated is a security product. Its one promise is that a department cannot see
or change content it was not given. A bug that breaks that promise is not a
defect to be scheduled; it is a vulnerability, and it is handled as one.

If you have found a way for a control-panel user to reach anything outside their
territory, or for a territory admin to do anything the six invariants below say
they cannot, please report it privately. Do not open a public issue.

## How to report

**Preferred:** GitHub private vulnerability reporting, at
<https://github.com/bpmore/statamic-delegated/security/advisories/new>. It keeps
the report, the discussion and the fix in one private place, and it can credit
you in the published advisory.

**Otherwise:** email <(address removed)> with `[statamic-delegated
security]` in the subject. If you would rather not send details over plain
email, say so in the first message and we will arrange another channel.

A useful report says which Statamic version and which Delegated version, what
the user's role and territory were, what they did, and what they saw that they
should not have. A failing test in the style of `tests/Feature` is the best
report there is, but it is not required.

## What to expect

This addon is maintained by one person. The times below are commitments, not
aspirations, and they are set so that they can be kept.

| Step | Within |
|---|---|
| Acknowledgement that the report was received | **3 business days** |
| Confirmation of whether it reproduces, and a severity | **7 calendar days** of acknowledgement |
| Fix, or a documented mitigation, for a confirmed report that lets a user see or change content outside their territory | **30 calendar days** of confirmation |
| Fix for any other confirmed report | next release, and no later than **90 days** |

If a deadline is going to slip you will hear that before it does, with a reason
and a new date. A confirmed cross-territory exposure that cannot be fixed inside
30 days gets a published mitigation (usually: which permission to remove, or
which feature to switch off) so that sites can protect themselves while the fix
is finished.

Fixed vulnerabilities are published as a GitHub security advisory, with credit to
the reporter unless they ask otherwise, and noted in `CHANGELOG.md`. Reporters
are asked to hold public disclosure until the fix has been released, and in any
case for no more than 90 days from the first report.

There is no bug bounty. Reports are answered by the person who wrote the code,
which is the most useful thing on offer.

## What counts

In scope — report it:

- A signed-in control-panel user who is not a super user and does not hold
  `configure collections` can see, edit, publish, delete, duplicate, move,
  preview, or read the revision history of an entry outside every territory
  they belong to, through any control-panel surface: listings, the tree, search,
  the command palette, bulk actions, relationship pickers, navigation editing,
  direct URLs, or a crafted request.
- A territory admin can do any of the six things they must not: grant a role
  they do not hold; add a user to a territory they do not administer; edit,
  demote or delete a super user; create or modify roles; widen their own
  territory; or see users with no assignment overlapping their territory.
- Territory scoping behaves differently on the flat-file and Eloquent drivers
  in a way that lets either one show more than the other.
- A tree move, a duplicate, or an entry created under a chosen parent lands
  content in a territory the acting user did not own at the start of the
  request.
- Anything in the audit log that lets a territory admin learn about actors or
  entries outside their territory.

Out of scope, by design — documented in `LICENSE.md` and the README, and worth a
normal issue if the documentation is unclear:

- Content visible to site visitors. Delegated restricts the control panel, not
  the front end.
- Content reachable through Statamic's REST API or GraphQL API. Both
  authenticate with a shared token, not a user, and Delegated does not and
  cannot scope them.
- Anything a super user, or a holder of `configure collections`, can do.
  Statamic treats both as unrestricted and so does Delegated.
- Assets. Not covered in v1.
- Behaviour on a Statamic version outside the range in `composer.json`. That
  said, please report it anyway: it tells us what the next verification needs
  to check before the range is widened.

Vulnerabilities in Statamic itself should go to Statamic:
<https://github.com/statamic/cms/security/policy>.

## Supported versions

Until 1.0 ships, `main` is the only supported line and there are no releases to
patch; a fix lands on `main`. From 1.0, the most recent minor release receives
security fixes, and the previous minor for 90 days after the newer one ships.

The Statamic versions a given release is verified against are the ones its
`composer.json` constraint allows. That range is widened only after the
enforcement-surface inventory in `PROGRESS.md` has been re-run against the new
minor.
