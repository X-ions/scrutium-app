<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliverableController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\InfluencerController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScoringController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/workspace-name-availability', [AuthController::class, 'checkWorkspaceName'])
    ->middleware('throttle:30,1')
    ->name('workspace.name.availability');

Route::middleware('guest')->group(function (): void {
    Route::get('/signin', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::get('/signup', [AuthController::class, 'showRegister'])->name('signup');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register');

    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:password-reset')->name('password.update');
});

Route::middleware(['auth'])->group(function (): void {
    Route::get('/verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/verify-email', [EmailVerificationController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware('signed')->name('verification.verify');
});

// Unverified accounts may sign in and browse. The non-dismissible
// verification modal in layouts/app.blade.php is the gate: it blocks
// interaction until the address is confirmed, and it reappears on
// every request so it cannot be dismissed or routed around.
Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/locale/{locale}', [LocaleController::class, 'switch'])
        ->whereIn('locale', array_keys(LocaleController::SUPPORTED_LOCALES))
        ->name('locale.switch');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::prefix('/security')->name('security.')->group(function (): void {
        Route::get('/', [SecurityController::class, 'index'])->name('index');
        Route::get('/devices', [SecurityController::class, 'devices'])->name('devices');
        Route::get('/sessions', [SecurityController::class, 'sessions'])->name('sessions');
        Route::get('/devices/confirm/{token}', [SecurityController::class, 'reviewDeviceConfirmation'])
            ->middleware('signed')
            ->name('devices.review');
        Route::post('/devices/confirm/{token}', [SecurityController::class, 'confirmDevice'])->name('devices.confirm');
        Route::post('/devices/{device}/trust', [SecurityController::class, 'trustDevice'])->name('devices.trust');
        Route::post('/devices/{device}/revoke', [SecurityController::class, 'revokeDevice'])->name('devices.revoke');
        Route::post('/devices/{device}/block', [SecurityController::class, 'blockDevice'])->name('devices.block');
        Route::post('/sessions/{session}/revoke', [SecurityController::class, 'revokeSession'])->name('sessions.revoke');
        Route::post('/sessions/revoke-others', [SecurityController::class, 'revokeOtherSessions'])->name('sessions.revoke-others');
        Route::put('/notifications', [SecurityController::class, 'updatePreferences'])->name('notifications.update');
        Route::post('/events/{event}/acknowledge', [SecurityController::class, 'acknowledge'])->name('events.acknowledge');
    });

    Route::middleware('operate')->group(function (): void {
        Route::get('/discover/campaign-tools', [CampaignController::class, 'tools'])->name('campaign-tools');
        Route::post('/discover/campaign-tools/apply', [CampaignController::class, 'applyTools'])->name('campaign-tools.apply');
        Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns');
        Route::get('/campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
        Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
        Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
        Route::patch('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
        Route::post('/campaigns/{campaign}/creators', [CampaignController::class, 'addCreator'])->name('campaigns.creators.store');
        Route::delete('/campaigns/{campaign}/creators/{influencer}', [CampaignController::class, 'removeCreator'])->name('campaigns.creators.destroy');
        Route::post('/campaigns/{campaign}/deliverables', [CampaignController::class, 'storeDeliverable'])->name('campaigns.deliverables.store');

        Route::get('/influencers', [InfluencerController::class, 'index'])->name('influencers');
        Route::post('/influencers', [InfluencerController::class, 'store'])->name('influencers.store');
        Route::patch('/influencers/{influencer}/vetting', [InfluencerController::class, 'updateVetting'])->name('influencers.vetting');

        Route::get('/deliverables', [DeliverableController::class, 'index'])->name('deliverables');
        Route::get('/deliverables/{deliverable}', [DeliverableController::class, 'show'])->name('deliverables.show');
        Route::post('/deliverables/{deliverable}/submit', [DeliverableController::class, 'submit'])->name('deliverables.submit');
        Route::post('/deliverables/{deliverable}/approve', [DeliverableController::class, 'approve'])->name('deliverables.approve');
        Route::post('/deliverables/{deliverable}/reject', [DeliverableController::class, 'reject'])->name('deliverables.reject');
        Route::delete('/deliverables/{deliverable}', [DeliverableController::class, 'destroy'])->name('deliverables.destroy');

        Route::get('/content', [ContentController::class, 'index'])->name('content');
        Route::get('/performance', [PerformanceController::class, 'index'])->name('performance');
        Route::get('/scoring', [ScoringController::class, 'index'])->name('scoring');
        Route::patch('/scoring/{config}', [ScoringController::class, 'updateConfig'])->name('scoring.config');
        Route::post('/scoring/recalculate', [ScoringController::class, 'recalculate'])->name('scoring.recalculate');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports');
        Route::post('/reports', [ReportController::class, 'store'])->name('reports.store');
        Route::post('/reports/{report}/publish', [ReportController::class, 'publish'])->name('reports.publish');
        Route::get('/reports/{report}/download', [ReportController::class, 'download'])->name('reports.download');
        Route::get('/alerts', [AlertController::class, 'index'])->name('alerts');
        Route::post('/alerts', [AlertController::class, 'store'])->name('alerts.store');
        Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');
        Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve'])->name('alerts.resolve');
        Route::get('/alerts/subscriptions', [AlertController::class, 'subscriptions'])->name('alerts.subscriptions');
        Route::patch('/alerts/subscriptions/{subscription}', [AlertController::class, 'updateSubscription'])->name('alerts.subscriptions.update');
        Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations');
        Route::post('/integrations', [IntegrationController::class, 'store'])->name('integrations.store');
        Route::post('/integrations/{integration}/connect', [IntegrationController::class, 'connect'])->name('integrations.connect');
        Route::post('/integrations/{integration}/disconnect', [IntegrationController::class, 'disconnect'])->name('integrations.disconnect');
    });

    Route::middleware('workspace-admin')->group(function (): void {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
        Route::patch('/settings/workspace', [SettingsController::class, 'updateWorkspace'])->name('settings.workspace');
        Route::post('/settings/members', [SettingsController::class, 'storeMember'])->name('settings.members.store');
        Route::patch('/settings/members/{member}', [SettingsController::class, 'updateMember'])->name('settings.members.update');
        Route::post('/settings/subscriptions', [SettingsController::class, 'storeSubscription'])->name('settings.subscriptions.store');
    });
});

Route::view('/error-404', 'pages.errors.error-404', ['title' => 'Error 404'])->name('error-404');
