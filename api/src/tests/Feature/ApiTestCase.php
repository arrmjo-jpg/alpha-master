<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** MySQL-backed API tests with the permission catalog seeded. */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    /** Runs DatabaseSeeder (permission catalog sync) after each refresh. */
    protected bool $seed = true;
}
