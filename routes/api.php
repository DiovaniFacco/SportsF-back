<?php

use App\Http\Controllers\Api\AccessTrackingController;
use App\Http\Controllers\Api\AmenityController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CourtBlockoutController;
use App\Http\Controllers\Api\CourtController;
use App\Http\Controllers\Api\CourtPricingController;
use App\Http\Controllers\Api\FixedScheduleController;
use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\OperatingHourController;
use App\Http\Controllers\Api\SportController;
use App\Http\Controllers\Api\VenueController;
use App\Http\Controllers\Api\VenueCoverUploadController;
use App\Http\Controllers\Api\VenueReviewController;
use App\Http\Controllers\Api\VenueStaffController;
use App\Http\Middleware\CheckRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Healthcheck
    Route::get('/health', HealthCheckController::class);

    // Authentication Public Routes (throttle nomeado: rajada por IP + cota por conta/dia)
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    });

    // Throttle dedicado anti-spam de e-mails de recuperação
    Route::middleware('throttle:forgot')->group(function () {
        Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    });

    // Public Categories & Metadata Routes
    Route::get('/sports', [SportController::class, 'index']);
    Route::get('/amenities', [AmenityController::class, 'index']);
    Route::get('/cities', [VenueController::class, 'cities']);

    // Analytics de acesso — coleta pública (token opcional identifica o usuário logado).
    Route::post('/analytics/track', [AccessTrackingController::class, 'store'])
        ->middleware('throttle:analytics');

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
        Route::put('/auth/me', [AuthController::class, 'updateProfile']);
        Route::put('/auth/password', [AuthController::class, 'changePassword']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);

        // Submit Venue Review
        Route::post('/venues/{venue}/reviews', [VenueReviewController::class, 'store']);

        // Venues & Users Management - Admin Only
        Route::middleware(CheckRole::class . ':' . User::ROLE_ADMIN)->group(function () {
            Route::get('/users', [AuthController::class, 'indexUsers']);
            Route::post('/venues', [VenueController::class, 'store']);
            Route::delete('/venues/{venue}', [VenueController::class, 'destroy']);

            // Funcionários do complexo (vínculo por complexo) - só Admin gerencia
            Route::get('/venues/{venue}/staff', [VenueStaffController::class, 'index']);
            Route::post('/venues/{venue}/staff', [VenueStaffController::class, 'store']);
            Route::delete('/venues/{venue}/staff/{user}', [VenueStaffController::class, 'destroy']);
        });

        // Venues & Courts Management (Gestor / Admin)
        // Funcionário NÃO entra aqui: não pode editar complexo nem criar/editar/excluir quadra.
        Route::middleware(CheckRole::class . ':' . User::ROLE_GESTOR . ',' . User::ROLE_ADMIN)->group(function () {
            Route::put('/venues/{venue}', [VenueController::class, 'update']);
            Route::post('/venues/cover-upload', VenueCoverUploadController::class);

            // Amenities Management
            Route::post('/amenities', [AmenityController::class, 'store']);
            Route::delete('/amenities/{amenity}', [AmenityController::class, 'destroy']);

            Route::post('/venues/{venue}/courts', [CourtController::class, 'store']);
            Route::put('/courts/{court}', [CourtController::class, 'update']);
            Route::delete('/courts/{court}', [CourtController::class, 'destroy']);
        });

        // Operação da agenda (Gestor / Admin / Funcionário vinculado)
        // Funcionário PODE: horários, preços, bloqueios, fixos.
        // A verificação do vínculo por complexo acontece dentro dos controllers/services.
        Route::middleware(CheckRole::class . ':' . User::ROLE_GESTOR . ',' . User::ROLE_ADMIN . ',' . User::ROLE_FUNCIONARIO)->group(function () {
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

            // Fixed Schedules (Horários Fixos semanais recorrentes)
            Route::get('/fixed-schedules', [FixedScheduleController::class, 'index']);
            Route::get('/courts/{court}/fixed-schedules', [FixedScheduleController::class, 'index']);
            Route::post('/courts/{court}/fixed-schedules', [FixedScheduleController::class, 'store']);
            Route::get('/fixed-schedules/{fixedSchedule}', [FixedScheduleController::class, 'show']);
            Route::put('/fixed-schedules/{fixedSchedule}', [FixedScheduleController::class, 'update']);
            Route::delete('/fixed-schedules/{fixedSchedule}', [FixedScheduleController::class, 'destroy']);
            Route::post('/fixed-schedules/{fixedSchedule}/exceptions', [FixedScheduleController::class, 'skip']);
            Route::delete('/fixed-schedules/{fixedSchedule}/exceptions/{exception}', [FixedScheduleController::class, 'unskip']);
            Route::get('/fixed-schedules/{fixedSchedule}/occurrences', [FixedScheduleController::class, 'occurrences']);
        });

        // Bookings Management
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::post('/bookings/batch', [BookingController::class, 'storeBatch']);
        Route::get('/bookings/{booking}', [BookingController::class, 'show']);
        Route::patch('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
        Route::patch('/bookings/{booking}/payment', [BookingController::class, 'updatePayment']);

        // Métricas de acesso (tráfego) — Gestor (seus complexos) e Admin (global).
        Route::middleware(CheckRole::class . ':' . User::ROLE_GESTOR . ',' . User::ROLE_ADMIN)->group(function () {
            Route::get('/analytics/overview', [AnalyticsController::class, 'overview']);
            Route::get('/venues/{venue}/analytics', [AnalyticsController::class, 'venueOverview']);
        });
    });
});
