<?php

namespace App\Domain\Inventory;

use Carbon\CarbonImmutable;

/**
 * "Hoy" del negocio para vencimientos (S-13): fecha en America/Bogota
 * (config fartmar.business_timezone), a las 00:00. Respeta Carbon::setTestNow
 * (y $this->travelTo en pruebas).
 */
final class BusinessDate
{
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('fartmar.business_timezone', 'America/Bogota'))->startOfDay();
    }
}
