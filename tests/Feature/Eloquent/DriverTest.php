<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Tests\Support\Content;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Statamic\Eloquent\Entries\EntryQueryBuilder;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

uses(RefreshDatabase::class);

it('really is running entries on the Eloquent driver', function () {
    // Guards the whole directory: if the driver silently failed to take
    // over, every scenario here would be a second copy of the flat-file run.
    Content::build();

    expect(get_class(Entry::query()))->toBe(EntryQueryBuilder::class)
        ->and(DB::table('entries')->count())->toBe(13)
        ->and(DB::table('entries')->where('id', 'phase2')->value('uri'))->toBe('/cardiology/trials/phase-2')
        // The driver stores a route-derived URI for an entry outside its tree,
        // as if it sat at the root. The flat-file driver reports null. Both
        // Territory::containsUri and TerritoryScope are written around this.
        ->and(DB::table('entries')->where('id', 'stray')->value('uri'))->toBe('/stray')
        ->and(Entry::find('stray')->uri())->toBe('/stray');
});

it('keeps an entry\'s last URI after it is dropped from the tree, until it is saved again', function () {
    // Statamic's own behaviour, recorded because a territory predicate
    // reads that column: an entry taken out of the tree without being
    // deleted stays visible to its old department on this driver, and to
    // nobody on the flat-file driver. Not a cross-territory exposure — the
    // last URI was inside the old territory — but a difference to document.
    Content::build();
    $tree = Collection::findByHandle('pages')->structure()->in('default');
    $tree->tree([
        ['entry' => 'home'],
        ['entry' => 'card', 'children' => [['entry' => 'trials']]],
        ['entry' => 'onc', 'children' => [['entry' => 'chemo'], ['entry' => 'oncdraft']]],
        ['entry' => 'alumni'],
    ])->save();

    expect(DB::table('entries')->where('id', 'phase2')->value('uri'))->toBe('/cardiology/trials/phase-2');

    Entry::find('phase2')->save();

    expect(DB::table('entries')->where('id', 'phase2')->value('uri'))->toBe('/phase-2');
});
