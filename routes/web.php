<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/docs', 'docs.swagger')->name('docs.swagger');

Route::get('/docs/openapi.yaml', function () {
    return response(file_get_contents(base_path('docs/openapi.yaml')), 200, [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]);
})->name('docs.openapi');
