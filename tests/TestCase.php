<?php

namespace Tests;

use App\Models\Setting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Setting has a process-static request cache (audit L12); flush it so a
        // value written in one test can't leak into the next.
        Setting::flushCache();
    }
}
