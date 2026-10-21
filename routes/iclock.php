<?php

/**
 * ZKTeco K40 Pro ADMS push endpoints (plain text).
 * Devices call these paths directly — not under /api.
 */

use App\Http\Controllers\ZktecoAdmsController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST'], '/iclock/cdata', [ZktecoAdmsController::class, 'cdata']);
Route::match(['GET', 'POST'], '/iclock/getrequest', [ZktecoAdmsController::class, 'getRequest']);
Route::match(['GET', 'POST'], '/iclock/devicecmd', [ZktecoAdmsController::class, 'deviceCmd']);
Route::match(['GET', 'POST'], '/iclock/push', [ZktecoAdmsController::class, 'push']);
Route::match(['GET', 'POST'], '/iclock/registry', [ZktecoAdmsController::class, 'cdata']);
