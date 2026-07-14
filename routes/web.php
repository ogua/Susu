<?php

use App\Http\Controllers\SavingsAccountStatementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/savings-accounts/{account}/statement', SavingsAccountStatementController::class)
    ->middleware('auth')
    ->name('savings-accounts.statement');
