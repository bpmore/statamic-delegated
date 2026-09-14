<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Tests\Support\AbilityScoping;
use Bpmore\StatamicDelegated\Tests\Support\TreeScoping;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => AbilityScoping::setUp());

/*
 * Phase 3.2.d, flat-file driver: the structure tree is authorised and pruned.
 */

TreeScoping::scenarios();
