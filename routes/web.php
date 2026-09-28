<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/docs');

Route::view('/docs', 'docs')->name('docs');

Route::get('/openapi.yaml', function () {
    return response(file_get_contents(public_path('openapi.yaml')), 200, [
        'Content-Type' => 'application/yaml',
    ]);
})->name('openapi');
