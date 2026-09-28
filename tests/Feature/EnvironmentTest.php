<?php

namespace Tests\Feature;

use Tests\TestCase;

class EnvironmentTest extends TestCase
{
    public function test_environment_is_isolated_from_container_settings(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('cache.limiter'));
        $this->assertSame('array', config('session.driver'));
    }
}
