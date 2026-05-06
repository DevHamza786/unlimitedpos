<?php

use App\Http\Controllers\Api\WcOrderInboundController;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('wc-inbound')
    ->middleware(['api', 'wc.inbound', 'throttle:120,1'])
    ->group(function () {
        Route::get('/ping', [WcOrderInboundController::class, 'ping']);
        Route::post('/orders', [WcOrderInboundController::class, 'store']);
    });
