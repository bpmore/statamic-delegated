<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Tests\Support\AbilityScoping;
use Bpmore\StatamicDelegated\Tests\Support\CreationScoping;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => AbilityScoping::setUp());

/*
 * Phase 3.2.c, flat-file driver: creating asks where the entry would land.
 */

CreationScoping::scenarios();
