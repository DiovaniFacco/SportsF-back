<?php

use App\Http\Controllers\Api\AmenityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CourtBlockoutController;
use App\Http\Controllers\Api\CourtController;
use App\Http\Controllers\Api\CourtPricingController;
use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\OperatingHourController;
use App\Http\Controllers\Api\SportController;
use App\Http\Controllers\Api\VenueController;
use App\Http\Controllers\Api\VenueReviewController;
use App\Http\Middleware\CheckRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Healthcheck
    Route::get('/health', HealthCheckController::class);

    // Authentication Public Routes (Rate Limited to 6 requests/minute)
    Route::middleware('throttle:6,1')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/google', [AuthController::class, 'googleAuth']);
    });

    // Public Categories & Metadata Routes
    Route::get('/sports', [SportController::class, 'index']);
    Route::get('/amenities', [AmenityController::class, 'index']);
    Route::get('/cities', [VenueController::class, 'cities']);

    // Public Venues, Courts & Reviews Routes
    Route::get('/venues', [VenueController::class, 'index']);
    Route::get('/venues/{venue}', [VenueController::class, 'show']);
    Route::get('/venues/{venue}/reviews', [VenueReviewController::class, 'index']);
    Route::get('/venues/{venue}/courts', [CourtController::class, 'index']);
    Route::get('/courts/{court}', [CourtController::class, 'show']);
    Route::get('/courts/{court}/operating-hours', [OperatingHourController::class, 'index']);
    Route::get('/courts/{court}/available-slots', [OperatingHourController::class, 'availableSlots']);

    // Protected Routes (Sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        // Auth Management
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Submit Venue Review
        Route::post('/venues/{venue}/reviews', [VenueReviewController::class, 'store']);

        // Venues & Courts Management (Gestor / Admin)
        Route::middleware(CheckRole::class . ':' . User::ROLE_GESTOR . ',' . User::ROLE_ADMIN)->group(function () {
            Route::post('/venues', [VenueController::class, 'store']);
            Route::put('/venues/{venue}', [VenueController::class, 'update']);
            Route::delete('/venues/{venue}', [VenueController::class, 'destroy']);

            Route::post('/venues/{venue}/courts', [CourtController::class, 'store']);
            Route::put('/courts/{court}', [CourtController::class, 'update']);
            Route::delete('/courts/{court}', [CourtController::class, 'destroy']);

            Route::post('/courts/{court}/operating-hours', [OperatingHourController::class, 'store']);
            Route::delete('/operating-hours/{operatingHour}', [OperatingHourController::class, 'destroy']);

            // Pricing Rules (Horário Nobre) & Special Prices (Feriados)
            Route::get('/courts/{court}/pricing-rules', [CourtPricingController::class, 'indexRules']);
            Route::post('/courts/{court}/pricing-rules', [CourtPricingController::class, 'storeRule']);
            Route::delete('/pricing-rules/{pricingRule}', [CourtPricingController::class, 'destroyRule']);

            Route::get('/courts/{court}/special-prices', [CourtPricingController::class, 'indexSpecialPrices']);
            Route::post('/courts/{court}/special-prices', [CourtPricingController::class, 'storeSpecialPrice']);
            Route::delete('/special-prices/{specialPrice}', [CourtPricingController::class, 'destroySpecialPrice']);

            // Court Blockouts (Bloqueio de Agenda)
            Route::get('/blockouts', [CourtBlockoutController::class, 'index']);
            Route::get('/courts/{court}/blockouts', [CourtBlockoutController::class, 'index']);
            Route::post('/courts/{court}/blockouts', [CourtBlockoutController::class, 'store']);
            Route::delete('/blockouts/{blockout}', [CourtBlockoutController::class, 'destroy']);
        });

        // Bookings Management
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::get('/bookings/{booking}', [BookingController::class, 'show']);
        Route::patch('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
    });
});
