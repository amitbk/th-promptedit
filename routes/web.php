<?php

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('generations/{generation:uuid}/image', [GenerationController::class, 'image'])->name('generations.image');
});

require __DIR__.'/settings.php';
