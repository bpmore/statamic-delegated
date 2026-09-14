<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Actions;

use Statamic\Actions\DuplicateEntry as CoreDuplicateEntry;

/**
 * Enforcement point: the duplicate action (Phase 0.1, bulk actions ⚠️).
 *
 * Statamic's own DuplicateEntry authorises on `create` in the collection
 * and never looks at the entry being copied. Under a territory model that
 * is a two-request read of anything: duplicate an entry you cannot see, then
 * open the copy. This subclass registers under the same handle — a later
 * registration replaces an earlier one — and adds the missing question:
 * may the user view the source? The Gate answers that from the resolver.
 */
class DuplicateEntry extends CoreDuplicateEntry
{
    public function authorize($user, $item)
    {
        return $user->can('view', $item) && parent::authorize($user, $item);
    }
}
