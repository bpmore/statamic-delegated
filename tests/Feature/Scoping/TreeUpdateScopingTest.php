<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Tests\Support\TreeUpdateScoping;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => TreeUpdateScoping::setUp());

/*
 * Phase 3.2.e, flat-file driver: tree saves are merged, moves confined.
 */

TreeUpdateScoping::scenarios();
