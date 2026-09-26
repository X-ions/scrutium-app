<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliverableController;
use App\Http\Controllers\InfluencerController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScoringController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TourController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::get('/build', function () {
    return response()->json([
        'build' => config('app.build'),
        'laravel' => app()->version(),
        'php' => PHP_VERSION,
    ]);
});

Route::get('/health/db', function () {
    $diagnostics = [
        'build' => config('app.build'),
        'driver' => config('database.default'),
        'pdo_pgsql' => extension_loaded('pdo_pgsql'),
        'db_neon_endpoint_env' => getenv('DB_NEON_ENDPOINT') ?: null,
        'configured_neon_endpoint' => config('database.connections.pgsql.neon_endpoint'),
        'db_host' => config('database.connections.pgsql.host'),
        'db_port' => config('database.connections.pgsql.port'),
        'db_database' => config('database.connections.pgsql.database'),
    ];

    try {
        $diagnostics['connector'] = get_class(app('db.factory')->createConnector(config('database.connections.pgsql')));
    } catch (Throwable $e) {
        $diagnostics['connector'] = 'unavailable: '.$e->getMessage();
    }

    try {
        DB::select('select 1 as ok');
        $diagnostics['ok'] = true;
        $diagnostics['tenants'] = Schema::hasTable('tenants');
        $diagnostics['users'] = Schema::hasTable('users');
    } catch (Throwable $e) {
        report($e);

        $diagnostics['ok'] = false;
        $diagnostics['error'] = $e->getMessage();
    }

    // Never let diagnostics themselves take the endpoint down.
    return response()->json($diagnostics, $diagnostics['ok'] ? 200 : 500);
});

Route::middleware('guest')->group(function (): void {
    Route::get('/signin', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::get('/signup', [AuthController::class, 'showRegister'])->name('signup');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register');
});

Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/locale/{locale}', [LocaleController::class, 'switch'])
        ->whereIn('locale', array_keys(LocaleController::SUPPORTED_LOCALES))
        ->name('locale.switch');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/how-it-works', [TourController::class, 'index'])->name('how-it-works');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::middleware('operate')->group(function (): void {
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
        Route::patch('/integrations/{integration}/credentials', [IntegrationController::class, 'updateCredentials'])->name('integrations.credentials');
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
