<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Tests\Support\ListingScoping;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => ListingScoping::setUp());

/*
 * Phase 3.1, flat-file driver: the control panel entry listing is scoped to
 * the user's territories before the query runs.
 */

ListingScoping::scenarios();
