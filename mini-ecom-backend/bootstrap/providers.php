<?php

use App\Providers\AppServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\View\ViewServiceProvider;

return [
    EventServiceProvider::class,
    ViewServiceProvider::class,
    AppServiceProvider::class,
];
