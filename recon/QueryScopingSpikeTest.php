<?php

/**
 * Phase 0.3 spike: can the entry query be scoped by territory *before execution*, on both the
 * flat-file (Stache) driver and the Eloquent driver? Findings are recorded in PROGRESS.md.
 *
 * Not addon code and not part of the addon's test suite. Runs from a host site with the Stache
 * and user/role paths sandboxed into `fixtures/` next to this file. Prints one line per probe to
 * STDERR and asserts nothing; PROGRESS.md is the interpretation.
 *
 * Run twice from the host site (statamic/eloquent-driver must be installed there; its config need
 * not be published — the spike flips only `entries` to Eloquent, in-process, on the in-memory
 * SQLite from phpunit.xml):
 *
 *   cd /path/to/host-site
 *   SPIKE_DRIVER=file     STATAMIC_PRO_ENABLED=true vendor/bin/phpunit -c phpunit.xml <this file> --no-progress 2>&1 | sed -n '/SPIKE/,/=====/p'
 *   SPIKE_DRIVER=eloquent STATAMIC_PRO_ENABLED=true vendor/bin/phpunit -c phpunit.xml <this file> --no-progress 2>&1 | sed -n '/SPIKE/,/=====/p'
 */

namespace Spike;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Statamic\Contracts\Auth\RoleRepository;
use Statamic\Contracts\Auth\UserGroupRepository;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Search;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Hooks\CP\EntriesIndexQuery;
use Tests\TestCase;

class QueryScopingSpikeTest extends TestCase
{
    protected string $fx;

    protected static array $log = [];

    protected static string $driver = 'file';

    // ------------------------------------------------------------ boot

    public function createApplication(): Application
    {
        static::$driver = getenv('SPIKE_DRIVER') ?: 'file';

        $app = require '/Users/bpmore/Herd/statamic-dev/bootstrap/app.php';

        if (static::$driver === 'eloquent') {
            // The driver decides per-repository bindings in register(), from config that is
            // normally published. Set it after LoadConfiguration and before RegisterProviders.
            $app->beforeBootstrapping(RegisterProviders::class, function ($app) {
                $app['config']->set('statamic.eloquent-driver.entries', [
                    'driver' => 'eloquent',
                    'model' => \Statamic\Eloquent\Entries\UuidEntryModel::class,
                    'entry' => \Statamic\Eloquent\Entries\Entry::class,
                    'map_data_to_columns' => false,
                ]);
            });
        }

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fx = __DIR__.'/fixtures';
        app('files')->deleteDirectory($this->fx);
        mkdir($this->fx, 0777, true);

        Stache::stores()->each(fn ($s) => $s->directory($this->fx.'/'.$s->key()));
        app(RoleRepository::class)->path($this->fx.'/roles.yaml');
        app(UserGroupRepository::class)->path($this->fx.'/groups.yaml');

        config([
            'statamic.users.two_factor_enabled' => false,
            'statamic.users.elevated_sessions_enabled' => false,
            'statamic.search.drivers.local.path' => $this->fx.'/search',
            'statamic.search.indexes.pages_idx' => ['driver' => 'local', 'searchables' => 'collection:pages', 'fields' => ['title']],
        ]);

        Stache::clear();

        if (static::$driver === 'eloquent') {
            $this->artisan('migrate', [
                '--path' => '/Users/bpmore/Herd/statamic-dev/vendor/statamic/eloquent-driver/database/migrations/entries/2024_03_07_100000_create_entries_table_with_string_ids.php',
                '--realpath' => true,
            ]);
        }

        $this->buildFixtures();
    }

    protected function tearDown(): void
    {
        app('files')->deleteDirectory($this->fx);
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        fwrite(STDERR, "\n\n==== SPIKE RESULTS (driver: ".static::$driver.") ====\n");
        foreach (static::$log as $line) {
            fwrite(STDERR, $line."\n");
        }
        fwrite(STDERR, "=======================\n\n");
        parent::tearDownAfterClass();
    }

    protected function log(string $probe, string $result): void
    {
        static::$log[] = sprintf('%-34s %s', $probe, $result);
    }

    // ------------------------------------------------------------ fixtures

    protected function buildFixtures(): void
    {
        Taxonomy::make('departments')->title('Departments')->save();
        Term::make('cardiology')->taxonomy('departments')->data(['title' => 'Cardiology'])->save();
        Term::make('oncology')->taxonomy('departments')->data(['title' => 'Oncology'])->save();

        $pages = Collection::make('pages')->title('Pages')->routes('{parent_uri}/{slug}')
            ->structureContents(['root' => true])->searchIndex('pages_idx');
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
            ['cardiologyish', 'cardiology-alumni', 'Cardiology Alumni (sibling, not a descendant)', true],
        ] as [$id, $slug, $title, $published]) {
            Entry::make()->id($id)->collection('pages')->slug($slug)->published($published)
                ->data(['title' => $title])->save();
        }

        $pages->structure()->in('default')->tree([
            ['entry' => 'home'],
            ['entry' => 'card', 'children' => [['entry' => 'trials', 'children' => [['entry' => 'phase2']]]]],
            ['entry' => 'onc', 'children' => [['entry' => 'chemo'], ['entry' => 'oncdraft']]],
            ['entry' => 'cardiologyish'],
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

        Role::make('member')->title('Member')->permissions(['access cp', 'view pages entries', 'view news entries'])->save();
        User::make()->id('u-member')->email('member@example.com')->assignRole('member')->save();
    }

    // ------------------------------------------------------------ predicates under test

    /** Structure branch: the branch entry itself plus every descendant, by URI prefix. */
    public function branch($q, string $collection, string $uri)
    {
        return $q->where(function ($q) use ($collection, $uri) {
            $q->where('collection', $collection)->where(function ($q) use ($uri) {
                $q->where('uri', $uri)->orWhere('uri', 'like', $uri.'/%');
            });
        });
    }

    protected function titles($items): string
    {
        $t = collect($items)->map(fn ($e) => $e->get('title'))->sort()->values();

        return $t->isEmpty() ? '(none)' : $t->join(' | ');
    }

    // ------------------------------------------------------------ probes

    public function test_builder_class_and_sanity()
    {
        $this->log('builder class', get_class(Entry::query()));
        $this->log('sanity uris', 'card='.Entry::find('card')->uri().' phase2='.Entry::find('phase2')->uri().' alumni='.Entry::find('cardiologyish')->uri().' n1='.Entry::find('n1')->uri());
        $this->log('sanity total entries', (string) Entry::query()->count());
    }

    public function test_branch_scope_by_uri_prefix()
    {
        $q = $this->branch(Entry::query(), 'pages', '/cardiology');
        $this->log('branch /cardiology get()', $this->titles($q->get()));
        $this->log('branch /cardiology count()', (string) $this->branch(Entry::query(), 'pages', '/cardiology')->count());

        // Pagination with a scope applied before execution: page sizes and totals must agree.
        $p1 = $this->branch(Entry::query(), 'pages', '/cardiology')->orderBy('title')->paginate(2, ['*'], 'page', 1);
        $p2 = $this->branch(Entry::query(), 'pages', '/cardiology')->orderBy('title')->paginate(2, ['*'], 'page', 2);
        $this->log('branch paginate(2) p1/p2/total', count($p1->items()).'/'.count($p2->items()).'/'.$p1->total().' => p1: '.$this->titles($p1->items()).' ; p2: '.$this->titles($p2->items()));

        // Case sensitivity of `like` differs per driver — record it.
        $this->log('branch /CARDIOLOGY (case)', $this->titles($this->branch(Entry::query(), 'pages', '/CARDIOLOGY')->get()));
    }

    public function test_union_of_territories_across_collections()
    {
        // NAIVE form. Two territories for one user: branch /cardiology in pages, plus all of news.
        $q = Entry::query()->where(function ($q) {
            $this->branch($q, 'pages', '/cardiology');
            $q->orWhere('collection', 'news');
        });
        $this->log('union NAIVE branch OR coll', $this->titles($q->get()));

        // NAIVE form, three territories: branch, collection, taxonomy term.
        $q = Entry::query()->where(function ($q) {
            $this->branch($q, 'pages', '/oncology');
            $q->orWhere(function ($q) {
                $q->where('collection', 'news')->whereTaxonomy('departments::cardiology');
            });
        });
        $this->log('union NAIVE branch OR (coll+term)', $this->titles($q->get()));

        // COMPILED form: top-level scan-set on `collection` (Stache needs it), then an OR group
        // whose members never use `collection`/`whereTaxonomy` (Stache drops both inside nesting).
        // Whole-collection membership uses the driver's real column: `collectionHandle` on Stache,
        // `collection` on Eloquent. Taxonomy membership uses whereJsonContains, which both support.
        $collCol = static::$driver === 'eloquent' ? 'collection' : 'collectionHandle';
        $q = Entry::query()->whereIn('collection', ['pages', 'news'])->where(function ($q) use ($collCol) {
            $q->where(function ($q) {
                $q->where('uri', '/cardiology')->orWhere('uri', 'like', '/cardiology/%');
            });
            $q->orWhere($collCol, 'news');
        });
        $this->log('union COMPILED branch OR coll', $this->titles($q->get()).' count='.(clone $q)->count());

        $q = Entry::query()->whereIn('collection', ['pages', 'news'])->where(function ($q) use ($collCol) {
            $q->where(function ($q) {
                $q->where('uri', '/oncology')->orWhere('uri', 'like', '/oncology/%');
            });
            $q->orWhere(function ($q) use ($collCol) {
                $q->where($collCol, 'news')->whereJsonContains('departments', 'cardiology');
            });
        });
        $this->log('union COMPILED branch OR (coll+term)', $this->titles($q->get()));

        // PORTABLE fallback: resolve the term territory to an ID list first, then OR it in.
        $termIds = Entry::query()->where('collection', 'news')->whereTaxonomy('departments::cardiology')->get()->map->id()->all();
        $q = Entry::query()->whereIn('collection', ['pages', 'news'])->where(function ($q) use ($termIds) {
            $q->where(function ($q) {
                $q->where('uri', '/oncology')->orWhere('uri', 'like', '/oncology/%');
            });
            $q->orWhereIn('id', $termIds);
        });
        $this->log('union PORTABLE branch OR idlist', $this->titles($q->get()).' termIds='.implode(',', $termIds));

        // Pagination across a compiled union: page sizes and total must agree.
        $mk = fn () => Entry::query()->whereIn('collection', ['pages', 'news'])->where(function ($q) use ($collCol) {
            $q->where(function ($q) {
                $q->where('uri', '/cardiology')->orWhere('uri', 'like', '/cardiology/%');
            });
            $q->orWhere($collCol, 'news');
        })->orderBy('title');
        $p1 = $mk()->paginate(3, ['*'], 'page', 1);
        $p2 = $mk()->paginate(3, ['*'], 'page', 2);
        $p3 = $mk()->paginate(3, ['*'], 'page', 3);
        $this->log('union COMPILED paginate(3)', count($p1->items()).'/'.count($p2->items()).'/'.count($p3->items()).' total='.$p1->total());
    }

    public function test_taxonomy_term_scope()
    {
        $this->log('term departments::oncology', $this->titles(Entry::query()->where('collection', 'news')->whereTaxonomy('departments::oncology')->get()));
        $this->log('termIn [cardiology, oncology]', $this->titles(Entry::query()->where('collection', 'news')->whereTaxonomyIn(['departments::cardiology', 'departments::oncology'])->get()));
    }

    public function test_parent_scope()
    {
        // Direct children only. Stache has a `parent` index; Eloquent has no such column.
        $r = Entry::query()->where('collection', 'pages')->whereIn('parent', ['card', 'trials'])->get();
        $this->log('whereIn parent [card,trials]', $this->titles($r));
    }

    public function test_id_list_scope_as_fallback()
    {
        // The always-works fallback: resolve the allowed ID set from the tree, then whereIn('id').
        $tree = Collection::findByHandle('pages')->structure()->in('default');
        $ids = collect([$tree->find('card')])->merge($tree->find('card')->flattenedPages())->map->reference()->all();
        $this->log('whereIn id (from tree)', $this->titles(Entry::query()->whereIn('id', $ids)->get()).' ids='.implode(',', $ids));
    }

    public function test_listing_endpoint_through_official_hook()
    {
        // Hookable::runHooks() rebinds hook closures to the hookable instance, so `$this` inside
        // the closure is the EntriesIndexQuery, not whoever registered it. Capture explicitly.
        $self = $this;
        EntriesIndexQuery::hook('query', function ($payload, $next) use ($self) {
            $self->branch($payload->query, 'pages', '/cardiology');

            return $next($payload);
        });

        $user = User::find('u-member');
        $p1 = $this->actingAs($user)->getJson('/cp/collections/pages/entries?perPage=2&page=1');
        $p2 = $this->actingAs($user)->getJson('/cp/collections/pages/entries?perPage=2&page=2');
        $rows = fn ($r) => collect($r->json('data') ?? [])->pluck('title')->join(' | ');
        $exc = fn ($r) => $r->exception ? ' EXC: '.substr($r->exception->getMessage(), 0, 120) : '';
        $this->log('listing hook p1', $p1->status().' rows='.count($p1->json('data') ?? []).' total='.$p1->json('meta.total').' => '.$rows($p1).$exc($p1));
        $this->log('listing hook p2', $p2->status().' rows='.count($p2->json('data') ?? []).' total='.$p2->json('meta.total').' => '.$rows($p2).$exc($p2));

        // The search path: this collection has a search index, so indexQuery() returns a
        // Statamic\Search\QueryBuilder. Does the same predicate still apply?
        Search::index('pages_idx')->update();
        $s = $this->actingAs($user)->getJson('/cp/collections/pages/entries?search=cardiology');
        $this->log('listing hook + search index', $s->status().' rows='.count($s->json('data') ?? []).' total='.($s->json('meta.total') ?? '?').' => '.$rows($s).($s->exception ? ' EXC: '.substr($s->exception->getMessage(), 0, 120) : ''));
    }

    public function test_hook_does_not_reach_relationship_picker()
    {
        $hits = 0;
        EntriesIndexQuery::hook('query', function ($payload, $next) use (&$hits) {
            $hits++;

            return $next($payload);
        });

        $config = base64_encode(json_encode(['type' => 'entries', 'collections' => ['pages']]));
        $r = $this->actingAs(User::find('u-member'))->getJson('/cp/fieldtypes/relationship?config='.$config.'&site=default');
        $this->log('picker: hook fired?', $r->status().' hookHits='.$hits.' rows='.count($r->json('data') ?? []));
    }

    public function test_cost_of_scoping_vs_per_row_policy()
    {
        // A bigger branch to make the difference visible: 1,500 entries under /oncology.
        $tree = Collection::findByHandle('pages')->structure()->in('default');
        $children = [];
        for ($i = 0; $i < 1500; $i++) {
            Entry::make()->id("bulk{$i}")->collection('pages')->slug("bulk-{$i}")->published(true)->data(['title' => "Bulk {$i}"])->save();
            $children[] = ['entry' => "bulk{$i}"];
        }
        $t = $tree->tree();
        $t[2]['children'] = array_merge($t[2]['children'], $children);
        $tree->tree($t)->save();
        // Prime any lazy index / cache so both measurements start warm.
        Entry::query()->where('collection', 'pages')->count();
        $this->branch(Entry::query(), 'pages', '/cardiology')->count();

        $t0 = hrtime(true);
        $n = $this->branch(Entry::query(), 'pages', '/cardiology')->count();
        $scoped = (hrtime(true) - $t0) / 1e6;

        // restrict-style: hydrate everything, decide per row in PHP.
        $t0 = hrtime(true);
        $m = Entry::query()->where('collection', 'pages')->get()->filter(fn ($e) => str_starts_with((string) $e->uri(), '/cardiology'))->count();
        $perRow = (hrtime(true) - $t0) / 1e6;

        $total = Entry::query()->where('collection', 'pages')->count();
        $this->log('cost: scoped count()', sprintf('%d rows of %d in %.1f ms', $n, $total, $scoped));
        $this->log('cost: hydrate+filter (restrict)', sprintf('%d rows of %d in %.1f ms', $m, $total, $perRow));
    }
}
