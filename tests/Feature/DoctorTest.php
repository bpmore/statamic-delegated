<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Doctor\Doctor;
use Bpmore\StatamicDelegated\Doctor\Finding;
use Bpmore\StatamicDelegated\Resolution\TerritoryResolver;
use Bpmore\StatamicDelegated\Resolution\TerritorySource;
use Bpmore\StatamicDelegated\Storage\TerritoryStore;
use Bpmore\StatamicDelegated\Territories\Level;
use Bpmore\StatamicDelegated\Territories\ScopeType;
use Bpmore\StatamicDelegated\Territories\Territory;
use Bpmore\StatamicDelegated\Tests\Support\Content;
use Bpmore\StatamicDelegated\Tests\Support\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Statamic\Auth\PermissionCache;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

uses(RefreshDatabase::class);

/*
 * Phase 4.4: `delegated:doctor`. Each check is driven from a clean fixture
 * into the state it exists to catch, and the healthy fixture is shown to
 * raise nothing but the expected notes. Exit code 1 only for a problem.
 */

beforeEach(function () {
    Content::build();
    Site::role('editor', ['access cp', 'view pages entries']);
    Site::role('configurer', ['access cp', 'configure collections']);
    Site::role('user-admin', ['access cp', 'edit users']);
    Site::user('root', super: true);
    Site::user('head', roles: ['editor']);
    Site::user('staff', roles: ['editor']);
    Site::group('cardiology-editors');

    $this->cardiology = Site::territory('cardiology', '/cardiology');
    Site::assign($this->cardiology, 'user:head', 'admin');
    Site::assign($this->cardiology, 'group:cardiology-editors');
});

/** @return array<string, Finding> keyed by check */
function findings(): array
{
    return collect(app(Doctor::class)->diagnose())->keyBy->check->all();
}

function checks(): array
{
    return array_keys(findings());
}

it('reports only the expected notes for a healthy site', function () {
    $f = findings();

    // The fixture's stray page is outside the tree by design; everything else is as it should be.
    expect(array_keys($f))->toEqualCanonicalizing(['entry-outside-tree', 'entry-unowned'])
        ->and($f['entry-outside-tree']->severity)->toBe(Finding::WARNING)
        ->and($f['entry-outside-tree']->details)->toHaveCount(1)
        ->and($f['entry-outside-tree']->details[0])->toContain("'stray'")
        ->and($f['entry-unowned']->severity)->toBe(Finding::NOTE)
        // 9 pages, 3 under /cardiology; 4 news, none in a territory.
        ->and($f['entry-unowned']->details[0])->toStartWith('pages/default: 6 of 9 entries')
        ->and($f['entry-unowned']->details[1])->toStartWith('news/default: 4 of 4 entries');

    $this->artisan('delegated:doctor')->assertExitCode(0);
});

it('flags a territory whose branch, collection, term or site no longer exists', function () {
    Site::territory('news', 'news', 'collection');
    Site::territory('onc-news', 'departments::oncology', 'taxonomy_term');
    Site::territory('gone-branch', '/radiology');
    Site::territory('gone-collection', 'events', 'collection');
    Site::territory('gone-term', 'departments::neurology', 'taxonomy_term');
    Site::territory('gone-taxonomy', 'wards::a1', 'taxonomy_term');
    Site::territory('gone-site', '/cardiology', site: 'nowhere');

    $f = findings();

    expect($f['territory-scope-missing']->severity)->toBe(Finding::PROBLEM)
        ->and($f['territory-scope-missing']->details)->toHaveCount(5)
        ->and(strtolower(implode("\n", $f['territory-scope-missing']->details)))
        ->toContain("'gone-branch'", 'nothing is at /radiology')
        ->toContain("'gone-collection'", "collection 'events' does not exist")
        ->toContain("'gone-term'", "term 'neurology' does not exist in taxonomy 'departments'")
        ->toContain("'gone-taxonomy'", "taxonomy 'wards' does not exist")
        ->toContain("'gone-site'", "site 'nowhere' does not exist")
        ->not->toContain("'cardiology'", "'news'", "'onc-news'");

    $this->artisan('delegated:doctor')->assertExitCode(1);
});

it('flags a territory whose scope was deleted after the fact', function () {
    Term::find('departments::oncology'); // exists now
    Site::territory('onc-news', 'departments::oncology', 'taxonomy_term');
    expect(checks())->not->toContain('territory-scope-missing');

    Term::find('departments::oncology')->delete();
    Entry::find('card')->delete();

    $details = strtolower(implode("\n", findings()['territory-scope-missing']->details));
    expect($details)->toContain("'onc-news'", "term 'oncology' does not exist")
        ->toContain("'cardiology'", 'nothing is at /cardiology');
});

it('flags a dangling parent id', function () {
    // The FK forbids this where it is enforced, and SQLite cannot drop it
    // inside the test transaction; a database without the FK, or a
    // hand-edited row, is what the check is for. Feed the map directly.
    $source = new class(app(TerritorySource::class)) implements TerritorySource
    {
        public function __construct(private readonly TerritorySource $real) {}

        public function territories(): array
        {
            return array_merge($this->real->territories(), [
                new Territory(999, 'Trials', 'trials', 'default', ScopeType::StructureBranch, '/cardiology/trials', parentId: 998),
            ]);
        }

        public function assignments(): array
        {
            return $this->real->assignments();
        }
    };
    $resolver = new TerritoryResolver($source, app('cache')->store('array'));

    $f = collect((new Doctor($resolver))->diagnose())->keyBy->check->all()['territory-parent-missing'];
    expect($f->severity)->toBe(Finding::WARNING)
        ->and($f->details)->toHaveCount(1)
        ->and($f->details[0])->toContain("'Trials' (#999", 'parent #998');
});

it('flags assignments to a user or group that no longer exists', function () {
    expect(checks())->not->toContain('assignment-subject-missing');

    User::find('head')->delete();
    UserGroup::find('cardiology-editors')->delete();

    $f = findings()['assignment-subject-missing'];
    expect($f->severity)->toBe(Finding::WARNING)
        ->and(strtolower(implode("\n", $f->details)))->toContain("user 'head' no longer exists", "group 'cardiology-editors' no longer exists", "'cardiology'");
});

it('notes a territory with nobody in it, unless a parent territory is staffed', function () {
    $oncology = Site::territory('oncology', '/oncology');
    Site::territory('chemo', '/oncology/chemo', parentId: $oncology);
    Site::territory('trials', '/cardiology/trials', parentId: $this->cardiology);

    $f = findings()['territory-empty'];
    expect($f->severity)->toBe(Finding::NOTE)
        ->and($f->details)->toHaveCount(2)
        ->and(strtolower(implode("\n", $f->details)))->toContain("'oncology'", "'chemo'")->not->toContain("'trials'", "'cardiology'");
});

it('flags a non-super user who holds configure collections, naming the role', function () {
    expect(checks())->not->toContain('configure-collections-holder');

    Site::user('config', roles: ['configurer']);

    $f = findings()['configure-collections-holder'];
    expect($f->severity)->toBe(Finding::PROBLEM)
        ->and($f->details)->toHaveCount(1)
        ->and($f->details[0])->toContain("user 'config'", 'role configurer');

    // A super user holds everything and is not the point.
    expect(implode("\n", $f->details))->not->toContain("'root'");
    $this->artisan('delegated:doctor')->assertExitCode(1);
});

it('warns of a territory admin who holds a management permission, through a user or a group', function () {
    expect(checks())->not->toContain('territory-admin-with-management-permission');

    User::find('head')->assignRole('user-admin')->save();
    Site::user('grouped', roles: ['user-admin'], groups: ['cardiology-editors']);
    app(TerritoryStore::class)->putAssignment($this->cardiology, 'group', 'cardiology-editors', Level::Admin, null);
    // A member, not an admin: not reported.
    User::find('staff')->assignRole('user-admin')->save();
    Site::assign($this->cardiology, 'user:staff');

    // Statamic memoises a user's permissions for the request; the check above primed head's.
    app(PermissionCache::class)->clear();

    $f = findings()['territory-admin-with-management-permission'];
    expect($f->severity)->toBe(Finding::WARNING)
        ->and($f->details)->toHaveCount(2)
        ->and(implode("\n", $f->details))->toContain("user 'head'", "user 'grouped'", '`edit users`')->not->toContain("'staff'");
});

it('warns of a stored tree that names an entry which does not exist', function () {
    expect(checks())->not->toContain('tree-names-missing-entry');

    $tree = Collection::find('pages')->structure()->in('default');
    $tree->tree(array_merge($tree->fileData()['tree'], [['entry' => 'ghost']]))->save();

    $f = findings()['tree-names-missing-entry'];
    expect($f->severity)->toBe(Finding::WARNING)
        ->and($f->details)->toHaveCount(1)
        ->and($f->details[0])->toContain("'ghost'");
});

it('prints findings for a person and JSON for a script', function () {
    Site::user('config', roles: ['configurer']);

    $this->artisan('delegated:doctor')
        ->expectsOutputToContain('Non-super users who bypass every entry check')
        ->expectsOutputToContain("user 'config'")
        ->expectsOutputToContain('1 problem(s)')
        ->assertExitCode(1);

    expect(Artisan::call('delegated:doctor', ['--json' => true]))->toBe(1);
    $out = json_decode(trim(Artisan::output()), true);

    expect($out['ok'])->toBeFalse()
        ->and(collect($out['findings'])->pluck('check')->all())->toContain('configure-collections-holder', 'entry-unowned')
        ->and($out['findings'][0])->toHaveKeys(['check', 'severity', 'message', 'details']);
});
