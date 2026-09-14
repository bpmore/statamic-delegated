<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Tests\Support\AbilityScoping;
use Bpmore\StatamicDelegated\Tests\Support\DuplicateScoping;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => AbilityScoping::setUp());

/*
 * Phase 3.2.b, Eloquent driver: duplicating asks about the source.
 */

DuplicateScoping::scenarios();
