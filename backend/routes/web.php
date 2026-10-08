<?php

use Illuminate\Support\Facades\Route;

// Backend solo-API: la interfaz de usuario es la SPA de /frontend.
Route::get('/', fn () => response()->json(['service' => 'fartmar-api']));
