<?php

use Tests\TestCase;

/*
| Todas las pruebas Feature y Concurrency usan el TestCase de Laravel.
| La base de datos de pruebas es PostgreSQL (fartmar_test), ver phpunit.xml.
| Cada archivo declara su propio trait de BD; la prueba de concurrencia NO debe
| usar RefreshDatabase (la transacción envolvente ocultaría el problema).
*/
pest()->extend(TestCase::class)->in('Feature', 'Concurrency');
