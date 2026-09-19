<?php

/**
 * Reconnaissance probe for the Delegated enforcement-surface inventory (PROGRESS.md, Notes).
 *
 * Not addon code and not part of the addon's test suite. It runs against a booted Statamic
 * (core only, through Testbench) with every Stache store and the user/role/group paths
 * redirected into a `fixtures/` directory next to this file. It makes
 * no assertions — it prints a result line per surface to STDERR; the inventory in
 * PROGRESS.md is the interpretation. Re-run it on every Statamic minor (Phase 5) and diff.
 *
 * Findings it produced against statamic/cms 6.31.0 are recorded in PROGRESS.md.
 *
 * Two personas per surface:
 *   viewer  — a CP user with `access cp` only. EntryPolicy denies everything.
 *             Tells us whether the surface consults the policy at all.
 *   member  — a CP user with every `pages` permission, plus a Gate::before that
 *             denies any ability whose subject is an entry under /oncology.
 *             Simulates a territory-aware policy layer: what still leaks?
 *
 * Run from this repository, against the vendored statamic/cms and without Delegated's
 * provider (see ProbeTestCase):
 *   vendor/bin/phpunit -c phpunit.xml recon/DelegatedInventoryProbeTest.php --no-progress 2>&1 \
 *     | sed -n '/PROBE RESULTS/,/=======/p'
 *
 * Findings against 6.32.0 (2026-09-14) are recorded in PROGRESS.md Phase 5.4, diffed against 6.31.0;
 * against 6.33.0 (2026-09-19) under "Statamic 6.33.0", diffed against 6.32.0.
 *
 * The probe records whether the `page` blueprint (or the fallback) has an `author` field,
 * because that switches EntryPolicy into its "other authors" mode.
 */

namespace Probe;

require_once __DIR__.'/ProbeTestCase.php';

use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Auth\RoleRepository;
use Statamic\Contracts\Auth\UserGroupRepository;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Role;
use Statamic\Facades\Search;
use Statamic\Facades\Stache;
use Statamic\Facades\User;

class DelegatedInventoryProbeTest extends ProbeTestCase
{
    protected string $fx;

    protected static array $log = [];

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
            'statamic.revisions.path' => $this->fx.'/revisions',
            'statamic.search.drivers.local.path' => $this->fx.'/search',
            'statamic.api.resources.collections' => true,
            'statamic.graphql.resources.collections' => true,
            'statamic.api.cache' => false,
        ]);

        Stache::clear();

        $this->buildFixtures();
    }

    protected function tearDown(): void
    {
        app('files')->deleteDirectory($this->fx);
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        fwrite(STDERR, "\n\n==== PROBE RESULTS ====\n");
        foreach (static::$log as $line) {
            fwrite(STDERR, $line."\n");
        }
        fwrite(STDERR, "=======================\n\n");
        parent::tearDownAfterClass();
    }

    protected function log(string $surface, string $persona, string $result): void
    {
        static::$log[] = sprintf('%-22s %-8s %s', $surface, $persona, $result);
    }

    // ------------------------------------------------------------ fixtures

    protected function buildFixtures(): void
    {
        $c = Collection::make('pages')
            ->title('Pages')
            ->routes('{parent_uri}/{slug}')
            ->structureContents(['root' => true])
            ->revisionsEnabled(true);
        $c->save();

        foreach ([
            ['home', 'home', 'Home', true],
            ['card', 'cardiology', 'Cardiology', true],
            ['trials', 'trials', 'Cardiology Trials', true],
            ['onc', 'oncology', 'Oncology', true],
            ['chemo', 'chemo', 'Oncology Chemo', true],
            ['oncdraft', 'secret-draft', 'Oncology Secret Draft', false],
        ] as [$id, $slug, $title, $published]) {
            Entry::make()->id($id)->collection('pages')->slug($slug)->published($published)
                ->data(['title' => $title, 'content' => "Body of {$title}"])->save();
        }

        $c->structure()->in('default')->tree([
            ['entry' => 'home'],
            ['entry' => 'card', 'children' => [['entry' => 'trials']]],
            ['entry' => 'onc', 'children' => [['entry' => 'chemo'], ['entry' => 'oncdraft']]],
        ])->save();

        Nav::make()->handle('main')->title('Main')->save();
        Nav::find('main')->makeTree('default', [
            ['id' => 'n-card', 'entry' => 'card'],
            ['id' => 'n-onc', 'entry' => 'onc', 'children' => [['id' => 'n-chemo', 'entry' => 'chemo']]],
        ])->save();

        Role::make('viewer')->title('Viewer')->permissions(['access cp', 'view main nav', 'edit main nav'])->save();
        Role::make('member')->title('Member')->permissions([
            'access cp',
            'view pages entries', 'edit pages entries', 'create pages entries',
            'delete pages entries', 'publish pages entries', 'reorder pages entries',
            'edit other authors pages entries', 'delete other authors pages entries', 'publish other authors pages entries',
            'view main nav', 'edit main nav',
        ])->save();

        User::make()->id('u-viewer')->email('viewer@example.com')->assignRole('viewer')->save();
        User::make()->id('u-member')->email('member@example.com')->assignRole('member')->save();
    }

    protected function viewer()
    {
        return User::find('u-viewer');
    }

    /** Full collection permissions + simulated territory: anything under /oncology is denied. */
    protected function member()
    {
        Gate::before(function ($user, $ability, $arguments) {
            foreach ($arguments as $arg) {
                if ($arg instanceof EntryContract && str_starts_with((string) $arg->uri(), '/oncology')) {
                    return false;
                }
            }

            return null;
        });

        return User::find('u-member');
    }

    protected function err($r): string
    {
        return $r->exception ? ' EXC: '.get_class($r->exception).': '.substr($r->exception->getMessage(), 0, 140) : '';
    }

    protected function titles($json): string
    {
        $rows = collect($json['data'] ?? $json['pages'] ?? $json)->map(function ($r) {
            return $r['title'] ?? $r['entry_title'] ?? $r['text'] ?? $r['name'] ?? null;
        })->filter()->values();

        return $rows->isEmpty() ? '(no rows)' : $rows->join(', ');
    }

    protected function flattenTree(array $pages): array
    {
        $out = [];
        foreach ($pages as $p) {
            $out[] = $p['entry_title'] ?? $p['title'] ?? $p['entry'] ?? '?';
            $out = array_merge($out, $this->flattenTree($p['children'] ?? []));
        }

        return $out;
    }

    // ------------------------------------------------------------ surfaces

    public function test_entry_listing()
    {
        $this->log('sanity', '-', 'onc uri='.Entry::find('onc')->uri().' chemo uri='.Entry::find('chemo')->uri().' blueprint='.Entry::find('onc')->blueprint()->handle().' hasAuthor='.var_export(Entry::find('onc')->blueprint()->hasField('author'), true));
        $r = $this->actingAs($this->viewer())->getJson('/cp/collections/pages/entries');
        $this->log('listing', 'viewer', $r->status().' '.($r->status() === 200 ? $this->titles($r->json()) : ''));

        $r = $this->actingAs($this->member())->getJson('/cp/collections/pages/entries');
        $this->log('listing', 'member', $r->status().' rows='.count($r->json('data') ?? []).' total='.($r->json('meta.total') ?? '?').' => '.$this->titles($r->json()));
    }

    public function test_entry_edit_screen_and_direct_url()
    {
        $r = $this->actingAs($this->viewer())->getJson('/cp/collections/pages/entries/onc');
        $this->log('edit-screen', 'viewer', (string) $r->status());

        $r = $this->actingAs($this->member())->getJson('/cp/collections/pages/entries/onc');
        $this->log('edit-screen', 'member', $r->status().' (oncology, out of territory)');

        $r = $this->actingAs($this->member())->getJson('/cp/collections/pages/entries/card');
        $this->log('edit-screen', 'member', $r->status().' (cardiology, in territory)');

        // Direct URL variants: wrong collection in path, slug redirect route, cp/edit/{id} redirect.
        Collection::make('blog')->title('Blog')->save();
        $r = $this->actingAs($this->member())->getJson('/cp/collections/blog/entries/onc');
        $this->log('direct-url', 'member', $r->status().' wrong-collection path');

        $r = $this->actingAs($this->member())->get('/cp/collections/pages/entries/onc/any-slug');
        $this->log('direct-url', 'member', $r->status().' slug-redirect route -> '.($r->headers->get('Location') ?? '-'));

        $r = $this->actingAs($this->member())->get('/cp/edit/onc');
        $this->log('direct-url', 'member', $r->status().' cp/edit/{id} -> '.($r->headers->get('Location') ?? '-'));

        $r = $this->actingAs($this->viewer())->get('/cp/edit/onc');
        $this->log('direct-url', 'viewer', $r->status().' cp/edit/{id} -> '.($r->headers->get('Location') ?? '-'));
    }

    public function test_entry_update_publish_and_delete_endpoints()
    {
        $r = $this->actingAs($this->member())->patchJson('/cp/collections/pages/entries/onc', ['title' => 'Hacked', 'slug' => 'onc']);
        $this->log('update', 'member', $r->status().' PATCH oncology'.$this->err($r));
        $r = $this->actingAs($this->member())->patchJson('/cp/collections/pages/entries/card', ['title' => 'Cardiology', 'slug' => 'cardiology']);
        $this->log('update', 'member', $r->status().' PATCH cardiology (control)'.$this->err($r));

        $r = $this->actingAs($this->member())->postJson('/cp/collections/pages/entries/onc/publish', []);
        $this->log('publish', 'member', $r->status().' POST publish oncology');

        $r = $this->actingAs($this->member())->postJson('/cp/collections/pages/entries/onc/unpublish', []);
        $this->log('unpublish', 'member', $r->status().' POST unpublish oncology');
    }

    public function test_bulk_actions()
    {
        $r = $this->actingAs($this->viewer())->postJson('/cp/collections/pages/entries/actions/list', ['selections' => ['onc']]);
        $this->log('bulk-list', 'viewer', $r->status().' actions offered: '.collect($r->json())->pluck('handle')->join(',').' (empty means none)');

        $r = $this->actingAs($this->member())->postJson('/cp/collections/pages/entries/actions/list', ['selections' => ['onc']]);
        $this->log('bulk-list', 'member', $r->status().' actions offered for oncology: '.(collect($r->json())->pluck('handle')->join(',') ?: '(none)'));

        $r = $this->actingAs($this->member())->postJson('/cp/collections/pages/entries/actions/list', ['selections' => ['card']]);
        $this->log('bulk-list', 'member', $r->status().' actions offered for cardiology: '.(collect($r->json())->pluck('handle')->join(',') ?: '(none)'));

        foreach (['unpublish', 'delete', 'duplicate_entry'] as $action) {
            $before = Entry::all()->count();
            $r = $this->actingAs($this->member())->postJson('/cp/collections/pages/entries/actions', [
                'action' => $action,
                'selections' => ['onc'],
                'context' => ['view' => 'form', 'collection' => 'pages'],
                'values' => [],
            ]);
            $after = Entry::all()->count();
            $leak = isset($r->json()['data']['values']) ? ' RESPONSE CONTAINS ENTRY VALUES' : '';
            $this->log('bulk-run', 'member', $r->status().' '.$action.' oncology (view=form) entries '.$before.'->'.$after.$leak.$this->err($r));
        }

        // Crafted: run duplicate against an out-of-territory entry with list context.
        $before = Entry::all()->count();
        $r = $this->actingAs($this->member())->postJson('/cp/collections/pages/entries/actions', [
            'action' => 'duplicate_entry',
            'selections' => ['onc'],
            'context' => ['view' => 'list', 'collection' => 'pages'],
            'values' => [],
        ]);
        $after = Entry::all()->count();
        $this->log('bulk-run', 'member', $r->status().' duplicate oncology (view=list) entries '.$before.'->'.$after.$this->err($r));

        // Where did the copies land, and can the member now open them?
        foreach (Entry::query()->where('collection', 'pages')->get()->filter(fn ($e) => $e->get('duplicated_from') === 'onc') as $copy) {
            $inTree = (bool) Entry::find('onc')->structure()->in('default')->find($copy->id());
            $open = $this->actingAs($this->member())->getJson('/cp/collections/pages/entries/'.$copy->id());
            $this->log('bulk-run', 'member', 'copy '.$copy->id().' title="'.$copy->value('title').'" uri='.var_export($copy->uri(), true).' inTree='.var_export($inTree, true).' content="'.$copy->value('content').'" -> member GET edit '.$open->status());
        }

        // Crafted: viewer (no perms) tries the same.
        $r = $this->actingAs($this->viewer())->postJson('/cp/collections/pages/entries/actions', [
            'action' => 'unpublish', 'selections' => ['onc'], 'context' => ['view' => 'form'], 'values' => [],
        ]);
        $this->log('bulk-run', 'viewer', $r->status().' unpublish oncology');
    }

    public function test_structure_tree_view()
    {
        $r = $this->actingAs($this->viewer())->getJson('/cp/collections/pages/tree');
        $this->log('tree', 'viewer', $r->status().' '.($r->status() === 200 ? implode(' | ', $this->flattenTree($r->json('pages'))) : ''));

        $r = $this->actingAs($this->member())->getJson('/cp/collections/pages/tree');
        $this->log('tree', 'member', $r->status().' '.($r->status() === 200 ? implode(' | ', $this->flattenTree($r->json('pages'))) : ''));

        // Move /oncology under /cardiology as the member (out-of-territory branch).
        $r = $this->actingAs($this->member())->patchJson('/cp/collections/pages/tree', [
            'site' => 'default',
            'pages' => [
                ['id' => 'home', 'children' => []],
                ['id' => 'card', 'children' => [
                    ['id' => 'trials', 'children' => []],
                    ['id' => 'onc', 'children' => [['id' => 'chemo', 'children' => []], ['id' => 'oncdraft', 'children' => []]]],
                ]],
            ],
        ]);
        $uri = Entry::find('onc')->fresh()->uri();
        $this->log('tree-update', 'member', $r->status().' moved oncology under cardiology; new uri='.$uri);

        // Delete via tree endpoint's deletedEntries.
        $r = $this->actingAs($this->member())->patchJson('/cp/collections/pages/tree', [
            'site' => 'default',
            'deletedEntries' => ['chemo'],
            'pages' => [
                ['id' => 'home', 'children' => []],
                ['id' => 'card', 'children' => [['id' => 'trials', 'children' => []]]],
                ['id' => 'onc', 'children' => [['id' => 'oncdraft', 'children' => []]]],
            ],
        ]);
        $this->log('tree-update', 'member', $r->status().' deletedEntries=[chemo] -> chemo exists='.var_export((bool) Entry::find('chemo'), true));

        // Reorder endpoint (flat listing reorder).
        $r = $this->actingAs($this->member())->postJson('/cp/collections/pages/entries/reorder', [
            'ids' => ['onc', 'card', 'home'], 'page' => 1, 'perPage' => 3, 'site' => 'default',
        ]);
        $this->log('reorder', 'member', $r->status().' flat reorder incl. oncology'.$this->err($r));
    }

    public function test_global_search_and_command_palette()
    {
        Search::index('cp')->update();

        $r = $this->actingAs($this->viewer())->getJson('/cp/command-palette/search?q=Oncology');
        $this->log('palette-search', 'viewer', $r->status().' '.$this->titles($r->json()));

        $r = $this->actingAs($this->member())->getJson('/cp/command-palette/search?q=Oncology');
        $this->log('palette-search', 'member', $r->status().' '.$this->titles($r->json()));

        $r = $this->actingAs($this->member())->getJson('/cp/command-palette/search?q=Cardiology');
        $this->log('palette-search', 'member', $r->status().' '.$this->titles($r->json()));

        $r = $this->actingAs($this->member())->getJson('/cp/command-palette');
        $entryLinks = collect($r->json())->filter(fn ($c) => str_contains(json_encode($c), 'entries/'))->count();
        $this->log('palette-index', 'member', $r->status().' commands='.count($r->json()).' entry-level links='.$entryLinks);

        // Listing search (uses the collection's search index when configured; here the fallback LIKE query).
        $r = $this->actingAs($this->member())->getJson('/cp/collections/pages/entries?search=Oncology');
        $this->log('listing-search', 'member', $r->status().' '.$this->titles($r->json()));
    }

    public function test_rest_api_and_graphql_are_userless()
    {
        $r = $this->getJson('/api/collections/pages/entries');
        $this->log('rest-api', 'anon', $r->status().' '.($r->status() === 200 ? $this->titles($r->json()) : substr($r->getContent(), 0, 80)));

        $r = $this->getJson('/api/collections/pages/entries/oncdraft');
        $this->log('rest-api', 'anon', $r->status().' GET draft by id');

        $r = $this->postJson('/graphql', ['query' => '{ entries(collection: "pages") { data { id title } } }']);
        $rows = collect($r->json('data.entries.data') ?? [])->pluck('title')->join(', ');
        $this->log('graphql', 'anon', $r->status().' '.($rows ?: substr($r->getContent(), 0, 100)));

        $r = $this->postJson('/graphql', ['query' => '{ entries(collection: "pages", filter: { status: "any" }) { data { id title } } }']);
        $rows = collect($r->json('data.entries.data') ?? [])->pluck('title')->join(', ');
        $this->log('graphql', 'anon', $r->status().' status:any -> '.($rows ?: substr($r->getContent(), 0, 100)));
    }

    public function test_live_preview()
    {
        $r = $this->actingAs($this->viewer())->postJson('/cp/collections/pages/entries/onc/preview', ['preview' => ['title' => 'x']]);
        $this->log('live-preview', 'viewer', (string) $r->status());

        $r = $this->actingAs($this->member())->postJson('/cp/collections/pages/entries/onc/preview', ['preview' => ['title' => 'x']]);
        $this->log('live-preview', 'member', $r->status().' POST preview oncology');

        $r = $this->actingAs($this->member())->postJson('/cp/collections/pages/entries/card/preview', ['preview' => ['title' => 'x']]);
        $this->log('live-preview', 'member', $r->status().' POST preview cardiology'.($r->status() === 200 ? ' token issued' : ''));

        $r = $this->actingAs($this->member())->get('/cp/collections/pages/entries/onc/preview');
        $this->log('live-preview', 'member', $r->status().' GET popout shell oncology');
    }

    public function test_revisions()
    {
        Entry::find('onc')->createRevision(['message' => 'rev', 'user' => User::find('u-member')]);
        $ts = Entry::find('onc')->revisions()->first()->date()->timestamp;

        $r = $this->actingAs($this->viewer())->getJson('/cp/collections/pages/entries/onc/revisions');
        $this->log('revisions', 'viewer', (string) $r->status());

        $r = $this->actingAs($this->member())->getJson('/cp/collections/pages/entries/onc/revisions');
        $this->log('revisions', 'member', $r->status().' index oncology');

        $r = $this->actingAs($this->member())->getJson("/cp/collections/pages/entries/onc/revisions/{$ts}");
        $this->log('revisions', 'member', $r->status().' show oncology revision');

        $r = $this->actingAs($this->member())->postJson('/cp/collections/pages/entries/onc/restore-revision', ['revision' => $ts]);
        $this->log('revisions', 'member', $r->status().' restore oncology revision');

        $r = $this->actingAs($this->member())->getJson('/cp/collections/pages/entries/card/revisions');
        $this->log('revisions', 'member', $r->status().' index cardiology');
    }

    public function test_navigation_editing()
    {
        Role::make('nobody')->title('Nobody')->permissions(['access cp'])->save();
        User::make()->id('u-nobody')->email('nobody@example.com')->assignRole('nobody')->save();
        $r = $this->actingAs(User::find('u-nobody'))->getJson('/cp/navigation/main/tree');
        $this->log('nav-tree', 'nobody', $r->status().' (access cp only, no nav perms) '.($r->status() === 200 ? implode(' | ', $this->flattenTree($r->json('pages'))) : ''));
        $r = $this->actingAs(User::find('u-nobody'))->getJson('/cp/collections/pages/tree');
        $this->log('tree', 'nobody', $r->status().' (access cp only) '.($r->status() === 200 ? implode(' | ', $this->flattenTree($r->json('pages'))) : ''));
        $r = $this->actingAs(User::find('u-nobody'))->getJson('/cp/navigation/main');
        $this->log('nav-show', 'nobody', $r->status().' GET navigation/main page');
        $r = $this->actingAs(User::find('u-nobody'))->getJson('/cp/collections/pages');
        $this->log('coll-show', 'nobody', $r->status().' GET collections/pages page');

        $r = $this->actingAs($this->viewer())->getJson('/cp/navigation/main/tree');
        $this->log('nav-tree', 'viewer', $r->status().' '.($r->status() === 200 ? implode(' | ', $this->flattenTree($r->json('pages'))) : ''));

        $r = $this->actingAs($this->member())->getJson('/cp/navigation/main/tree');
        $this->log('nav-tree', 'member', $r->status().' '.($r->status() === 200 ? implode(' | ', $this->flattenTree($r->json('pages'))) : ''));

        // Move the oncology branch under cardiology, and add a new branch pointing at the secret draft.
        $r = $this->actingAs($this->member())->patchJson('/cp/navigation/main/tree', [
            'site' => 'default',
            'data' => [
                'n-new' => ['new' => true, 'entry' => 'oncdraft', 'values' => ['title' => null, 'url' => null], 'localizedFields' => []],
            ],
            'pages' => [
                ['id' => 'n-card', 'children' => [
                    ['id' => 'n-onc', 'children' => [['id' => 'n-chemo', 'children' => []]]],
                    ['id' => 'n-new', 'children' => []],
                ]],
            ],
        ]);
        $tree = Nav::find('main')->in('default')->tree();
        $this->log('nav-update', 'member', $r->status().' moved oncology + linked secret draft; tree now='.json_encode($tree));

        $r = $this->actingAs($this->member())->getJson('/cp/navigation/main/pages/n-onc/edit?site=default');
        $this->log('nav-page-edit', 'member', $r->status().' edit nav page linked to oncology');
    }

    public function test_relationship_fieldtype_picker()
    {
        $config = base64_encode(json_encode(['type' => 'entries', 'collections' => ['pages']]));

        $r = $this->actingAs($this->viewer())->getJson('/cp/fieldtypes/relationship?config='.$config.'&site=default');
        $this->log('entries-picker', 'viewer', $r->status().' '.$this->titles($r->json()));

        $r = $this->actingAs($this->member())->getJson('/cp/fieldtypes/relationship?config='.$config.'&site=default');
        $this->log('entries-picker', 'member', $r->status().' '.$this->titles($r->json()));

        $r = $this->actingAs($this->member())->postJson('/cp/fieldtypes/relationship/data', ['config' => $config, 'selections' => ['onc', 'card']]);
        $this->log('entries-picker-data', 'member', $r->status().' '.json_encode(collect($r->json('data'))->map(fn ($i) => ($i['title'] ?? '?').(isset($i['invalid']) && $i['invalid'] ? '(invalid)' : ''))->all()));
    }

    public function test_create_entry_under_out_of_territory_parent()
    {
        $r = $this->actingAs($this->member())->postJson('/cp/collections/pages/entries/default', [
            'title' => 'Sneaky', 'slug' => 'sneaky', '_blueprint' => 'page', '_parent' => 'onc', 'published' => true,
        ]);
        $uri = $r->status() === 200 ? Entry::find($r->json('data.id'))?->uri() : null;
        $this->log('create', 'member', $r->status().' create with _parent=oncology -> uri='.($uri ?? '-'));
    }
}
