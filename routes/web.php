<?php

use App\Http\Controllers\GitHubWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/webhook/github', GitHubWebhookController::class);
