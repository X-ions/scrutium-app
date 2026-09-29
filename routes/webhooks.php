<?php

declare(strict_types=1);

use App\Http\Controllers\SocialHub\SocialHubApiController;
use App\Http\Controllers\SocialHub\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook routes
|--------------------------------------------------------------------------
|
| Provider webhooks are called by the platforms themselves, so they sit
| outside the session, CSRF and tenant middleware. The signature is verified
| against the raw body before anything is read out of the payload, and every
| event id is recorded so a redelivery is a no-op.
|
*/

Route::post('/webhooks/{provider}', [WebhookController::class, 'handle'])
    ->where('provider', 'facebook|instagram|youtube|tiktok|x|linkedin|pinterest')
    ->middleware('throttle:600,1')
    ->name('webhooks.receive');

/*
|--------------------------------------------------------------------------
| Read-only JSON
|--------------------------------------------------------------------------
|
| Capability and platform metadata for the composer. Mutations are not
| exposed here: they need CSRF and the session, so they live on the web routes.
|
*/

Route::middleware(['auth', 'tenant'])->prefix('api/socialhub')->name('api.socialhub.')->group(function (): void {
    Route::get('providers', [SocialHubApiController::class, 'providers'])->name('providers');
    Route::get('platforms', [SocialHubApiController::class, 'platforms'])->name('platforms');
});
