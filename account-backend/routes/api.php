<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\OrgController;
use App\Http\Controllers\Api\OrgInviteController;
use App\Http\Controllers\Api\OrgMemberController;
use App\Http\Controllers\Api\OrgSubscriptionController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductUserStatusController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProfileSubscriptionController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\TokenController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::get('handoff', [AuthController::class, 'handoff']);
    Route::post('verify-email/{token}', [AuthController::class, 'verifyEmail']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('resend-verification', [AuthController::class, 'resendVerification']);
        Route::post('handoff/complete', [AuthController::class, 'handoffComplete']);
    });
});

Route::post('verify-token', [TokenController::class, 'verify']);

// Product server-to-server (guarded by X-Product-Secret, not Sanctum).
Route::post('product/user-status', [ProductUserStatusController::class, 'show']);

// Public; verified by the Stripe-Signature header (CSRF-exempt in bootstrap/app.php).
Route::post('stripe/webhook', [StripeWebhookController::class, 'handle']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('products', [ProductController::class, 'index']);
    Route::get('plans', [PlanController::class, 'index']);

    Route::put('profile', [ProfileController::class, 'update']);
    Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::get('profile/subscription', [ProfileSubscriptionController::class, 'show']);
    Route::put('profile/subscription', [ProfileSubscriptionController::class, 'update']);
    Route::post('profile/billing/portal', [BillingController::class, 'profilePortal']);

    Route::get('billing/checkout-sessions/{sessionId}', [BillingController::class, 'checkoutSession']);

    Route::get('orgs', [OrgController::class, 'index']);
    Route::post('orgs', [OrgController::class, 'store']);
    Route::get('orgs/{slug}', [OrgController::class, 'show']);
    Route::put('orgs/{slug}', [OrgController::class, 'update']);
    Route::delete('orgs/{slug}', [OrgController::class, 'destroy']);
    Route::post('orgs/{slug}/logo', [OrgController::class, 'uploadLogo']);
    Route::post('orgs/{slug}/leave', [OrgMemberController::class, 'leave']);

    Route::get('orgs/{slug}/members', [OrgMemberController::class, 'index']);
    Route::delete('orgs/{slug}/members/{userId}', [OrgMemberController::class, 'destroy']);
    Route::put('orgs/{slug}/members/{userId}/role', [OrgMemberController::class, 'updateRole']);
    Route::put('orgs/{slug}/members/{userId}/products', [OrgMemberController::class, 'updateProducts']);
    Route::get('orgs/{slug}/seats', [OrgMemberController::class, 'seats']);

    Route::get('orgs/{slug}/subscription', [OrgSubscriptionController::class, 'show']);
    Route::put('orgs/{slug}/subscription', [OrgSubscriptionController::class, 'update']);
    Route::post('orgs/{slug}/subscription/preview', [OrgSubscriptionController::class, 'preview']);
    Route::post('orgs/{slug}/billing/portal', [BillingController::class, 'orgPortal']);

    Route::get('orgs/{slug}/invites', [OrgInviteController::class, 'index']);
    Route::post('orgs/{slug}/invites', [OrgInviteController::class, 'store']);
    Route::delete('orgs/{slug}/invites/{id}', [OrgInviteController::class, 'destroy']);

    Route::get('invites/{token}', [OrgInviteController::class, 'show']);
    Route::post('invites/{token}/accept', [OrgInviteController::class, 'accept']);
});

// The /api/admin/* surface is registered separately in bootstrap/app.php
// (routes/admin.php) so it stays a clean, liftable boundary.
