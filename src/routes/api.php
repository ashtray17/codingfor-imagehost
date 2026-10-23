<?php

use Illuminate\Support\Facades\Route;
use Modules\Codingfor\Imagehost\Http\Controllers\User\ImageController;

Route::prefix('images')
    ->middleware(['optional.sanctum'])
    ->name('images.')
    ->group(function () {
        Route::get('/', [ImageController::class, 'index'])
            ->middleware('throttle:120,1')
            ->name('index');
        Route::post('/', [ImageController::class, 'upload'])
            ->middleware('throttle:codingfor-imagehost-upload')
            ->name('upload');
        Route::post('/uploads', [ImageController::class, 'begin'])
            ->middleware(['auth:sanctum', 'throttle:codingfor-imagehost-upload'])
            ->name('uploads.begin');
        Route::post('/uploads/{id}/chunks', [ImageController::class, 'chunk'])
            ->whereNumber('id')
            ->middleware(['auth:sanctum', 'throttle:codingfor-imagehost-chunk'])
            ->name('uploads.chunk');
        Route::post('/uploads/{id}/complete', [ImageController::class, 'complete'])
            ->whereNumber('id')
            ->middleware(['auth:sanctum', 'throttle:codingfor-imagehost-upload'])
            ->name('uploads.complete');
        Route::get('/status', [ImageController::class, 'batchStatus'])
            ->middleware('throttle:120,1')
            ->name('status.batch');
        Route::get('/{id}', [ImageController::class, 'show'])
            ->whereNumber('id')
            ->middleware('throttle:120,1')
            ->name('show');
        Route::delete('/{id}', [ImageController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('throttle:120,1')
            ->name('destroy');
    });

Route::get('/i/{hash}.thumb.webp', [ImageController::class, 'serveThumb'])
    ->middleware('throttle:600,1')
    ->where('hash', '[a-zA-Z0-9]{12}')
    ->name('serve.thumb');

Route::get('/i/{hash}.webp', [ImageController::class, 'serveDisplay'])
    ->middleware('throttle:600,1')
    ->where('hash', '[a-zA-Z0-9]{12}')
    ->name('serve.display');
