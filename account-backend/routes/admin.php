<?php

use App\Http\Controllers\Api\Admin\OrgAdminController;
use App\Http\Controllers\Api\Admin\PlanAdminController;
use App\Http\Controllers\Api\Admin\ProductAdminController;
use App\Http\Controllers\Api\Admin\UserAdminController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/admin by bootstrap/app.php, behind auth:sanctum + superadmin.
// This file is the entire "company admin" API surface — everything a future
// standalone admin service would need to take with it.

Route::get('users', [UserAdminController::class, 'index']);
Route::get('orgs', [OrgAdminController::class, 'index']);

Route::get('products', [ProductAdminController::class, 'index']);
Route::post('products', [ProductAdminController::class, 'store']);
Route::get('products/{product}', [ProductAdminController::class, 'show']);
Route::post('products/{product}/stripe-sync', [ProductAdminController::class, 'stripeSync']);
Route::put('products/{product}', [ProductAdminController::class, 'update']);
Route::delete('products/{product}', [ProductAdminController::class, 'destroy']);
Route::get('products/{product}/plans', [PlanAdminController::class, 'forProduct']);

Route::get('plans', [PlanAdminController::class, 'index']);
Route::post('plans', [PlanAdminController::class, 'store']);
Route::put('plans/{plan}', [PlanAdminController::class, 'update']);
Route::post('plans/{plan}/stripe-sync', [PlanAdminController::class, 'stripeSync']);
Route::delete('plans/{plan}', [PlanAdminController::class, 'destroy']);
