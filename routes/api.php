<?php

use App\Http\Controllers\BiometricsFeedController;
use App\Http\Controllers\StaffScheduleFeedController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Read-only staff schedule for the owner's office: the key first, so only a valid key
// is counted against its hourly limit.
Route::middleware(['schedule-feed.key', 'throttle:schedule-feed'])
    ->get('/staff-schedule', StaffScheduleFeedController::class)
    ->name('api.staff-schedule');

// The office PC biometrics agent (spec 012): the key first, so only a valid key is counted
// against its hourly limit. The status is read-only and carries no names or punches.
Route::middleware(['biometrics-feed.key', 'throttle:biometrics-feed'])
    ->prefix('biometrics')
    ->controller(BiometricsFeedController::class)
    ->group(function () {
        Route::post('/punches', 'punches')->name('api.biometrics.punches');
        Route::post('/heartbeat', 'heartbeat')->name('api.biometrics.heartbeat');
        Route::post('/users', 'users')->name('api.biometrics.users');
        Route::get('/status', 'status')->name('api.biometrics.status');
    });
