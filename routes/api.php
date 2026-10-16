<?php

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
