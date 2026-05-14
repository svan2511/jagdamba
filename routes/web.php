<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;

Route::get('/', function () {
    $indexPath = public_path('build/index.html');
    if (File::exists($indexPath)) {
        $html = file_get_contents($indexPath);
        // Fix asset paths - change /assets/ to /build/assets/
        $html = str_replace('/assets/', '/build/assets/', $html);
        $html = str_replace('/favicon.svg', '/build/favicon.svg', $html);
        return response($html)
            ->header('Content-Type', 'text/html');
    }
    return view('welcome');
});

Route::get('/build/{path}', function (string $path) {
    $filePath = public_path('build/' . $path);
    if (File::exists($filePath) && is_file($filePath)) {
        return response()->file($filePath);
    }
    abort(404);
})->where('path', '.*');

Route::get('/{path}', function (string $path) {
    // For SPA routes, serve index.html with fixed paths
    $indexPath = public_path('build/index.html');
    if (File::exists($indexPath)) {
        $html = file_get_contents($indexPath);
        $html = str_replace('/assets/', '/build/assets/', $html);
        $html = str_replace('/favicon.svg', '/build/favicon.svg', $html);
        return response($html)
            ->header('Content-Type', 'text/html');
    }

    abort(404);
})->where('path', '.*');