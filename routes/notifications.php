<?php

use Illuminate\Support\Facades\Route;

Route::view('/notifikasi', 'pages.inbox.index')->middleware('auth:web')->name('inbox.index');
Route::view('/admin/notifikasi', 'pages.admin.inbox.index')->middleware('auth:admin')->name('admin.inbox.index');
