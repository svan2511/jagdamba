<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;

Route::get('/', function () {
    $indexPath = public_path('index.html');
    if (File::exists($indexPath)) {
        return response(file_get_contents($indexPath))
            ->header('Content-Type', 'text/html');
    }
    return view('welcome');
});

Route::get('/{path}', function (string $path) {
    $filePath = public_path($path);

    if (File::exists($filePath) && is_file($filePath)) {
        return response()->file($filePath);
    }

    $indexPath = public_path('index.html');
    if (File::exists($indexPath)) {
        return response(file_get_contents($indexPath))
            ->header('Content-Type', 'text/html');
    }

    abort(404);
})->where('path', '.*');