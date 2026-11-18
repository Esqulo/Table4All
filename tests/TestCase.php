<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests wipe the database (RefreshDatabase). Before any test touches it, make sure
     * it is SQLite in memory or a dedicated "*_testing" database: a misconfigured run
     * once erased the development data.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! ($connection === 'sqlite' && $database === ':memory:') && ! str_ends_with($database, '_testing')) {
            throw new RuntimeException(
                "Refusing to run tests against [{$connection}] database [{$database}]: "
                .'use SQLite :memory: (phpunit.xml) or a "*_testing" database (phpunit.pgsql.xml).'
            );
        }

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
