<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::inertia('dashboard', 'admin/Dashboard')->name('dashboard');
});