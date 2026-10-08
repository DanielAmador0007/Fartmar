<?php

/*
| Reglas de arquitectura relacionadas con seguridad (CLAUDE.md §5 y §7):
| los permisos viven en Policies, no en ifs de rol en controladores, y no
| quedan funciones de depuración que puedan volcar datos de pacientes.
*/

arch('los controladores no chequean roles (los permisos van en Policies)')
    ->expect('App\Http\Controllers')
    ->not->toUse(['App\Enums\Role']);

arch('no hay funciones de depuración en el código de la aplicación')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'error_log'])
    ->not->toBeUsed()
    ->ignoring('Tests');
