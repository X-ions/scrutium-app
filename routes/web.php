<?php

use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::get('/hello', function () {
    return response('scrutium-ok', 200, ['Content-Type' => 'text/plain']);
});

Route::get('/locale/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');

Route::get('/', function () {
    return view('pages.dashboard.overview', ['title' => 'Overview']);
})->name('dashboard');

$pages = [
    'campaigns' => 'Campaigns',
    'influencers' => 'Influencers',
    'deliverables' => 'Deliverables',
    'content' => 'Content',
    'performance' => 'Performance',
    'reports' => 'Reports',
    'alerts' => 'Alerts',
    'integrations' => 'Integrations',
    'settings' => 'Settings',
];

foreach ($pages as $slug => $label) {
    Route::get('/'.$slug, function () use ($label) {
        return view('pages.scrutium.placeholder', [
            'title' => $label,
            'pageTitle' => $label,
        ]);
    })->name($slug);
}

Route::get('/profile', function () {
    return view('pages.profile', ['title' => 'Profile']);
})->name('profile');

Route::get('/signin', function () {
    return view('pages.auth.signin', ['title' => 'Sign In']);
})->name('signin');

Route::get('/signup', function () {
    return view('pages.auth.signup', ['title' => 'Sign Up']);
})->name('signup');

Route::get('/error-404', function () {
    return view('pages.errors.error-404', ['title' => 'Error 404']);
})->name('error-404');
