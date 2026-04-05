<?php

use Illuminate\Support\Facades\Route;

// 3D model viewer — standalone page, no SPA wrapper
Route::get('/3d-viewer', function () {
    return view('viewer-3d');
});

// Serve the Vue SPA for all non-API web routes — Vue Router handles the rest.
// The popup registry is built from installed plugin manifests and inlined into the
// page so app.js can detect popup URLs synchronously before mounting anything.
Route::get('/{any?}', function () {
    $popupRegistry = \App\Models\Plugin::buildPopupRegistry();
    return view('app', ['popupRegistry' => $popupRegistry]);
})->where('any', '^(?!api/).*');
