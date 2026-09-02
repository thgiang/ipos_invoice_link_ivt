<?php

use App\Http\Controllers\SummaryReportController;
use App\Http\Controllers\TaxSurplusController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/stock-out');

Route::get('/stock-out', [SummaryReportController::class, 'stockOut'])->name('reports.stock-out');
Route::get('/stock-in', [SummaryReportController::class, 'stockIn'])->name('reports.stock-in');

// Tax book only, several months at once: what was bought and never used.
Route::get('/ton-du', TaxSurplusController::class)->name('reports.surplus');
