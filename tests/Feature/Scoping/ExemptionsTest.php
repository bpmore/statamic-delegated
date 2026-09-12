<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Tests\Support\Exemptions;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => Exemptions::setUp());

/*
 * Phase 3.3, flat-file driver: who is not restricted, and that membership never narrows it.
 */

Exemptions::scenarios();
