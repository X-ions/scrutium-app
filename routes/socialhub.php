<?php

declare(strict_types=1);

use App\Http\Controllers\SocialHub\AnalyticsController;
use App\Http\Controllers\SocialHub\CalendarController;
use App\Http\Controllers\SocialHub\CommentsController;
use App\Http\Controllers\SocialHub\DashboardController;
use App\Http\Controllers\SocialHub\MediaController;
use App\Http\Controllers\SocialHub\NotificationController;
use App\Http\Controllers\SocialHub\PostController;
use App\Http\Controllers\SocialHub\ProviderController;
use App\Http\Controllers\SocialHub\SocialAccountController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SocialHub routes
|--------------------------------------------------------------------------
|
| Everything here sits behind the authenticated, tenant-resolved middleware
| group. Tenant isolation is enforced by the models' global scopes and by the
| policies each controller authorizes against — a route without a matching
| policy check is a bug, so route-model binding is deliberately avoided for
| records that carry tenant ownership and ids are resolved explicitly.
|
*/

Route::middleware(['auth', 'tenant'])->prefix('socialhub')->name('socialhub.')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Publishing
    Route::get('posts', [PostController::class, 'index'])->name('posts.index');
    Route::get('posts/create', [PostController::class, 'create'])->name('posts.create');
    Route::post('posts', [PostController::class, 'store'])->name('posts.store');
    Route::get('posts/{post}', [PostController::class, 'show'])->name('posts.show');
    Route::get('posts/{post}/edit', [PostController::class, 'edit'])->name('posts.edit');
    Route::put('posts/{post}', [PostController::class, 'update'])->name('posts.update');
    Route::post('posts/{post}/publish', [PostController::class, 'publish'])->middleware('throttle:30,1')->name('posts.publish');
    Route::put('posts/{post}/schedule', [PostController::class, 'schedule'])->name('posts.schedule');
    Route::post('posts/{post}/cancel', [PostController::class, 'cancel'])->name('posts.cancel');
    Route::post('posts/{post}/duplicate', [PostController::class, 'duplicate'])->name('posts.duplicate');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');

    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar');
    Route::get('drafts', [PostController::class, 'index'])->defaults('status', 'draft')->name('drafts');

    // Media
    Route::get('media', [MediaController::class, 'index'])->name('media.index');
    Route::post('media', [MediaController::class, 'store'])->middleware('throttle:60,1')->name('media.store');
    Route::post('media/bulk-delete', [MediaController::class, 'bulkDestroy'])->name('media.bulk-destroy');
    Route::get('media/{mediaAsset}', [MediaController::class, 'show'])->name('media.show');
    Route::get('media/{mediaAsset}/download', [MediaController::class, 'download'])->name('media.download');
    Route::patch('media/{mediaAsset}', [MediaController::class, 'update'])->name('media.update');
    Route::delete('media/{mediaAsset}', [MediaController::class, 'destroy'])->name('media.destroy');

    // Connected accounts and platform capabilities
    Route::get('accounts', [SocialAccountController::class, 'index'])->name('accounts.index');
    Route::get('accounts/{provider}/connect', [SocialAccountController::class, 'connect'])->middleware('throttle:10,1')->name('accounts.connect');
    Route::get('accounts/{provider}/callback', [SocialAccountController::class, 'callback'])->name('accounts.callback');
    // The placeholder is named to match the controller's parameter. Laravel's
    // implicit model binding resolves by parameter *name*, so a mismatch here
    // silently hands the controller a brand-new empty model instead of a 404.
    Route::get('accounts/{account}/refresh', [SocialAccountController::class, 'refresh'])->name('accounts.refresh');
    Route::post('accounts/{account}/sync', [SocialAccountController::class, 'sync'])->name('accounts.sync');
    Route::patch('accounts/{account}', [SocialAccountController::class, 'update'])->name('accounts.update');
    Route::delete('accounts/{account}', [SocialAccountController::class, 'disconnect'])->name('accounts.disconnect');

    Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');

    // Engagement
    Route::get('comments', [CommentsController::class, 'index'])->name('comments.index');
    Route::post('comments/sync', [CommentsController::class, 'sync'])->name('comments.sync');
    Route::get('comments/{comment}', [CommentsController::class, 'show'])->name('comments.show');
    Route::post('comments/{comment}/reply', [CommentsController::class, 'reply'])->middleware('throttle:60,1')->name('comments.reply');

    // Analytics
    Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('analytics/content', [AnalyticsController::class, 'content'])->name('analytics.content');
    Route::get('analytics/accounts', [AnalyticsController::class, 'accounts'])->name('analytics.accounts');
    Route::get('analytics/posts/{post}', [AnalyticsController::class, 'post'])->name('analytics.post');

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
});
