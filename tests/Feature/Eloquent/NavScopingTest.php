<?php

declare(strict_types=1);

use Bpmore\StatamicDelegated\Tests\Support\NavScoping;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => NavScoping::setUp());

/*
 * Phase 3.2.f, Eloquent driver: the navigation tree is authorised, redacted, and cannot link outside.
 */

NavScoping::scenarios();
