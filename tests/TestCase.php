<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Con RefreshDatabase se ejecuta DatabaseSeeder (permisos, roles y catálogos SUNAT; sin usuarios). */
    protected bool $seed = true;
}
