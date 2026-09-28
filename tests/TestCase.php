<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        // Refuse destructive migration traits if host settings or cached config leak in.
        if (! $app->environment('testing')
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || filled($app['config']->get('database.connections.sqlite.url'))
            || $app['config']->get('cache.default') !== 'array'
            || $app['config']->get('cache.limiter') !== 'array') {
            throw new LogicException('Tests require testing environment, in-memory SQLite, and array cache/limiter. Clear cached configuration before running.');
        }

        return $app;
    }
}
