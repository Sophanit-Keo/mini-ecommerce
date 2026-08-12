<?php

use Illuminate\Support\Facades\Route;

// A closure route cannot be serialized by `php artisan route:cache`. Keeping the placeholder
// page as a declarative view route preserves its behavior while allowing production deployments
// to compile the complete route table.
Route::view('/', 'welcome');
