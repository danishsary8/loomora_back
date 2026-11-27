<?php

use App\Http\Controllers\ApiDocsController;
use Illuminate\Support\Facades\Route;

// The API itself lives in routes/api.php. The only web pages are the live API reference.
Route::get('/docs', [ApiDocsController::class, 'page'])->name('docs');
Route::get('/docs/openapi.yaml', [ApiDocsController::class, 'spec'])->name('docs.spec');
