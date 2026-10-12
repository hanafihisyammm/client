<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests wipe and rebuild their database, so they must never run against a real one.
     * This runs after the application has booted and before any database trait does its work.
     */
    protected function setUpTraits()
    {
        $this->refuseToRunAgainstARealDatabase();

        return parent::setUpTraits();
    }

    private function refuseToRunAgainstARealDatabase(): void
    {
        // A cached config (php artisan config:cache) makes Laravel ignore phpunit.xml and
        // every DB_* override, so the tests would silently use the database in .env
        if ($this->app->configurationIsCached()) {
            throw new RuntimeException(
                'Refusing to run tests: the configuration is cached, so they would use the real database from .env. '
                . 'Run "php artisan config:clear" first.'
            );
        }

        if (!$this->app->environment('testing')) {
            throw new RuntimeException(
                'Refusing to run tests: the environment is "' . $this->app->environment() . '", not "testing".'
            );
        }

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database !== ':memory:' && !str_contains(strtolower($database), 'test')) {
            throw new RuntimeException(
                "Refusing to run tests against the database \"{$database}\": its name does not contain \"test\". "
                . 'Run them with DB_CONNECTION=pgsql DB_DATABASE=client_test.'
            );
        }
    }
}
