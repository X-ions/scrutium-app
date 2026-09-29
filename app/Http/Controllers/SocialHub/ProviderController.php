<?php

declare(strict_types=1);

namespace App\Http\Controllers\SocialHub;

use App\Http\Controllers\Controller;
use App\Services\Social\SocialProviderRegistry;
use Illuminate\View\View;

/**
 * Exposes what each provider can actually do.
 *
 * The UI reads this to decide which checkboxes, buttons and fields to show, so
 * a platform that cannot do something is visibly limited rather than failing
 * after the user has already written the content.
 */
final class ProviderController extends Controller
{
    public function __construct(private readonly SocialProviderRegistry $registry) {}

    public function index(): View
    {
        return view('pages.socialhub.providers.index', [
            'title' => 'Platform capabilities',
            'providers' => $this->registry->describe(),
        ]);
    }
}
