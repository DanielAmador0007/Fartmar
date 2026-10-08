<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Guard de seguridad: se ejecuta ANTES de los traits de BD
     * (RefreshDatabase, DatabaseMigrations), así que si por un error de
     * configuración las pruebas apuntaran a la BD de desarrollo, se aborta
     * antes de que `migrate:fresh` la borre.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Las pruebas deben usar una BD *_test; se intentó usar '{$database}'. Revise phpunit.xml."
            );
        }

        return $app;
    }
}
