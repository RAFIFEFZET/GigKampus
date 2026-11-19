<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $databaseConnected = true;

    try {
        DB::selectOne('select 1');
    } catch (QueryException $exception) {
        report($exception);
        $databaseConnected = false;
    }

    return response()->view('status', compact('databaseConnected'), $databaseConnected ? 200 : 503);
})->name('status');
