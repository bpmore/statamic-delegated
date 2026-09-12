<?php

declare(strict_types=1);

namespace Bpmore\StatamicDelegated\Tests\Support;

use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Search;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * The content every enforcement test is judged against. One structured
 * collection with two departments, a sibling that shares a prefix, an
 * entry never placed in the tree, and a flat collection tagged by department.
 *
 *   /                      home
 *   /cardiology            card
 *   /cardiology/trials     trials
 *   /cardiology/trials/phase-2   phase2
 *   /oncology              onc
 *   /oncology/chemo        chemo
 *   /oncology/secret-draft oncdraft   (unpublished)
 *   /cardiology-alumni     alumni     (a sibling, not a descendant)
 *   /stray                 stray      (never placed in the tree — Statamic re-attaches
 *                                      it at the root on read, so it is a root-level page)
 *
 *   news: n1 [cardiology], n2 [oncology], n3 [cardiology, oncology], n4 []
 */
final class Content
{
    public const PAGES = ['home', 'card', 'trials', 'phase2', 'onc', 'chemo', 'oncdraft', 'alumni', 'stray'];

    public const CARDIOLOGY = ['card', 'trials', 'phase2'];

    public const ONCOLOGY = ['onc', 'chemo', 'oncdraft'];

    public const NEWS = ['n1', 'n2', 'n3', 'n4'];

    public static function build(): void
    {
        Taxonomy::make('departments')->title('Departments')->save();
        Term::make('cardiology')->taxonomy('departments')->data(['title' => 'Cardiology'])->save();
        Term::make('oncology')->taxonomy('departments')->data(['title' => 'Oncology'])->save();

        $pages = Collection::make('pages')->title('Pages')->routes('{parent_uri}/{slug}')
            ->structureContents(['root' => true]);
        $pages->save();

        Collection::make('news')->title('News')->routes('news/{slug}')->taxonomies(['departments'])->save();

        foreach ([
            ['home', 'home', 'Home', true],
            ['card', 'cardiology', 'Cardiology', true],
            ['trials', 'trials', 'Cardiology Trials', true],
            ['phase2', 'phase-2', 'Cardiology Trials Phase 2', true],
            ['onc', 'oncology', 'Oncology', true],
            ['chemo', 'chemo', 'Oncology Chemo', true],
            ['oncdraft', 'secret-draft', 'Oncology Secret Draft', false],
            ['alumni', 'cardiology-alumni', 'Cardiology Alumni', true],
            ['stray', 'stray', 'Stray Orphan', true],
        ] as [$id, $slug, $title, $published]) {
            Entry::make()->id($id)->collection('pages')->slug($slug)->published($published)
                ->data(['title' => $title])->save();
        }

        $pages->structure()->in('default')->tree([
            ['entry' => 'home'],
            ['entry' => 'card', 'children' => [['entry' => 'trials', 'children' => [['entry' => 'phase2']]]]],
            ['entry' => 'onc', 'children' => [['entry' => 'chemo'], ['entry' => 'oncdraft']]],
            ['entry' => 'alumni'],
        ])->save();

        foreach ([
            ['n1', 'heart-news', 'Heart News', ['cardiology']],
            ['n2', 'onc-news', 'Onc News', ['oncology']],
            ['n3', 'both-news', 'Both News', ['cardiology', 'oncology']],
            ['n4', 'untagged-news', 'Untagged News', []],
        ] as [$id, $slug, $title, $terms]) {
            Entry::make()->id($id)->collection('news')->slug($slug)->published(true)
                ->data(['title' => $title, 'departments' => $terms])->save();
        }
    }

    /** Give the pages collection a local search index and build it. */
    public static function indexPages(): void
    {
        config(['statamic.search.indexes.pages_idx' => ['driver' => 'local', 'searchables' => 'collection:pages', 'fields' => ['title']]]);
        Collection::findByHandle('pages')->searchIndex('pages_idx')->save();
        Search::index('pages_idx')->update();
    }
}
