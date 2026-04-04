<?php

use Illuminate\Support\Facades\Route;

// 3D model viewer — standalone page, no SPA wrapper
Route::get('/3d-viewer', function () {
    return view('viewer-3d');
});

// Serve the Vue SPA for all non-API web routes — Vue Router handles the rest
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api/).*');
