<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Tests\Support\AbilityScoping;
use Bpmore\StatamicDelegated\Tests\Support\PickerScoping;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => AbilityScoping::setUp());

/*
 * Phase 3.2.g, flat-file driver: the relationship picker is scoped.
 */

PickerScoping::scenarios();
