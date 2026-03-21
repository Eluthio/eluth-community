<?php

use Illuminate\Support\Facades\Route;

// Serve the Vue SPA for all web routes — Vue Router handles the rest
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
