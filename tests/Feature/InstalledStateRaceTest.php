<?php

namespace Tests\Feature;

use App\Classes\Cache;
use App\Services\Helper;
use Tests\TestCase;

class InstalledStateRaceTest extends TestCase
{
    public function test_cache_miss_returns_database_result_even_if_cache_disappears(): void
    {
        Cache::shouldReceive('get')->once()->with('ns-core-installed', null)->andReturn(null);
        Cache::shouldReceive('set')->once()->with('ns-core-installed', true, 60)->andReturn(true);
        $this->assertTrue(Helper::installed());
    }

    public function test_cached_installed_value_requires_only_one_read(): void
    {
        Cache::shouldReceive('get')->once()->with('ns-core-installed', null)->andReturn(true);
        $this->assertTrue(Helper::installed());
    }

    public function test_cached_uninstalled_state_is_preserved(): void
    {
        Cache::shouldReceive('get')->once()->with('ns-core-installed', null)->andReturn(false);
        $this->assertFalse(Helper::installed());
    }
}
