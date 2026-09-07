<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProvisionsBusinesses;
use Tests\TestCase;

/*
 * Feature test menyentuh database: seluruhnya memakai RefreshDatabase dan helper
 * provisioning bisnis.
 */
uses(TestCase::class, RefreshDatabase::class, ProvisionsBusinesses::class)->in('Feature');

/*
 * tests/Unit dibatasi pada logika murni (aritmetika uang, enum, value object) sehingga
 * tidak membutuhkan database maupun container aplikasi.
 */
