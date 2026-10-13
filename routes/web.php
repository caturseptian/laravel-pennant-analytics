<?php

use CaturSeptian\PennantAnalytics\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('index');
Route::get('/features/{feature}', [DashboardController::class, 'show'])
    ->where('feature', '.*')
    ->name('show');
