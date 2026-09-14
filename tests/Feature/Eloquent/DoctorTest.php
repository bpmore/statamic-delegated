<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Doctor\Doctor;
use Bpmore\StatamicDelegated\Doctor\Finding;
use Bpmore\StatamicDelegated\Tests\Support\Content;
use Bpmore\StatamicDelegated\Tests\Support\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;

uses(RefreshDatabase::class);

/*
 * Phase 4.4, Eloquent driver: the entry checks read entries and trees the
 * same way on both drivers.
 */

beforeEach(function () {
    Content::build();
    Site::role('editor', ['access cp', 'view pages entries']);
    Site::user('head', roles: ['editor']);
    $this->cardiology = Site::territory('cardiology', '/cardiology');
    Site::assign($this->cardiology, 'user:head', 'admin');
});

it('finds the stray entry, the unowned counts and a deleted branch with entries in the database', function () {
    $f = collect(app(Doctor::class)->diagnose())->keyBy->check->all();

    expect(array_keys($f))->toEqualCanonicalizing(['entry-outside-tree', 'entry-unowned'])
        ->and($f['entry-outside-tree']->details[0])->toContain("'stray'")
        ->and($f['entry-unowned']->details[0])->toStartWith('pages/default: 6 of 9 entries')
        ->and($f['entry-unowned']->details[1])->toStartWith('news/default: 4 of 4 entries');

    Entry::find('card')->delete();
    // The driver memoises findByUri for the request; the first diagnosis primed it.
    Blink::flush();

    $f = collect(app(Doctor::class)->diagnose())->keyBy->check->all();

    expect($f['territory-scope-missing']->severity)->toBe(Finding::PROBLEM)
        ->and(strtolower($f['territory-scope-missing']->details[0]))->toContain("'cardiology'", 'nothing is at /cardiology');
});
