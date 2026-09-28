<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::group(['middleware' => ['ngrok.headers', 'verify.shopify', 'web']], function () {
    Route::get('/', [\App\Http\Controllers\HomeController::class, 'home'])->name('home');

    Route::middleware([
        'auth:sanctum',
        'verified',
    ])->group(function () {
        Route::get('/dashboard', function () {
            return Inertia::render('Dashboard');
        })->name('dashboard');
    });
});
