<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Tests\Support\ListingScoping;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => ListingScoping::setUp());

/*
 * Phase 3.1, Eloquent driver: the same scenarios as tests/Feature/Scoping,
 * with entries in the database.
 */

ListingScoping::scenarios();
