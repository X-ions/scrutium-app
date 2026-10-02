<?php

declare(strict_types=1);

namespace App\Http\Controllers\SocialHub;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Http\Controllers\Controller;
use App\Jobs\Engagement\SyncAccountCommentsJob;
use App\Models\Integration;
use App\Models\SocialAccount;
use App\Services\Engagement\TokenRefreshService;
use App\Services\Social\Data\AuthRequest;
use App\Services\Social\OAuth\AccountConnectionService;
use App\Services\Social\OAuth\OAuthCoordinator;
use App\Services\Social\SocialProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

final class SocialAccountController extends Controller
{
    public function __construct(
        private readonly SocialProviderRegistry $registry,
        private readonly OAuthCoordinator $oauth,
        private readonly AccountConnectionService $connections,
        private readonly TokenRefreshService $tokens,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', SocialAccount::class);

        return view('pages.socialhub.accounts.index', [
            'title' => 'Social accounts',
            'accounts' => SocialAccount::query()
                ->with('token')
                ->orderBy('provider')
                ->get()
                ->map(fn (SocialAccount $account) => $account->publicStatus())
                ->values(),
            'providers' => $this->registry->describe(),
        ]);
    }

    /**
     * Begin the OAuth handshake. The only thing persisted here is the
     * single-use state row; no token exists yet.
     */
    public function connect(Request $request, string $provider): RedirectResponse
    {
        $this->authorize('connect', SocialAccount::class);

        $platform = SocialPlatform::tryFrom($provider);

        if ($platform === null) {
            return back()->with('error', 'Unknown social network.');
        }

        try {
            $session = $this->oauth->begin(
                $platform->value,
                route('socialhub.accounts.callback', $platform->value),
                (int) $request->user()->tenant_id,
            );
        } catch (Throwable $exception) {
            Log::warning('socialhub.oauth.connect_failed', [
                'provider' => $provider,
                'exception' => $exception::class,
            ]);

            return back()->with('error', $this->messageFor($exception));
        }

        return redirect()->away($session->authorizationUrl);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->authorize('connect', SocialAccount::class);

        $platform = SocialPlatform::tryFrom($provider);

        if ($platform === null) {
            return redirect()
                ->route('socialhub.accounts.index')
                ->with('error', 'Unknown social network.');
        }

        $authRequest = new AuthRequest(
            provider: $platform->value,
            code: $request->string('code')->toString() ?: null,
            state: $request->string('state')->toString() ?: null,
            error: $request->string('error')->toString() ?: null,
            errorDescription: $request->string('error_description')->toString() ?: null,
            redirectUri: route('socialhub.accounts.callback', $platform->value),
            query: $request->query(),
        );

        try {
            $session = $this->oauth->handleCallback($authRequest);
            $account = $this->connections->persist($session, (int) $request->user()->tenant_id, (int) $request->user()->id);
            $this->syncWorkspaceIntegration($account, $session, $request->user()->tenant_id);
        } catch (Throwable $exception) {
            Log::warning('socialhub.oauth.callback_failed', [
                'provider' => $provider,
                'exception' => $exception::class,
            ]);

            return redirect()
                ->route('partnerintegrations')
                ->with('error', $this->messageFor($exception));
        }

        return redirect()
            ->route('partnerintegrations')
            ->with('success', sprintf('%s is connected and ready to sync.', $account->provider?->label() ?? $platform->label()));
    }

    public function refresh(Request $request, SocialAccount $account): RedirectResponse
    {
        $this->authorize('refresh', $account);

        $outcome = $this->tokens->refresh($account);

        return back()->with(
            $outcome === 'refreshed' ? 'status' : 'error',
            match ($outcome) {
                'refreshed' => 'Authorization refreshed.',
                'revoked' => 'The network revoked this authorization. Reconnect the account.',
                'expired' => 'The stored credentials could not be renewed. Reconnect the account.',
                'skipped' => 'Nothing to refresh for this account.',
                default => 'The network did not accept the refresh. It will be retried automatically.',
            },
        );
    }

    public function sync(Request $request, SocialAccount $account): RedirectResponse
    {
        $this->authorize('sync', $account);

        SyncAccountCommentsJob::dispatch($account->id);

        return back()->with('status', 'A fresh sync has been queued for this account.');
    }

    public function update(Request $request, SocialAccount $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $validated = $request->validate([
            'is_default' => ['sometimes', 'boolean'],
            'internal_name' => ['nullable', 'string', 'max:255'],
        ]);

        if ($request->boolean('is_default')) {
            SocialAccount::query()
                ->whereKeyNot($account->id)
                ->update(['is_default' => false]);
        }

        $account->update([
            'is_default' => $request->boolean('is_default'),
            'metadata' => array_merge((array) $account->metadata, array_filter([
                'internal_name' => $validated['internal_name'] ?? null,
            ])),
        ]);

        return back()->with('status', 'Account updated.');
    }

    public function disconnect(Request $request, SocialAccount $account): RedirectResponse
    {
        $this->authorize('disconnect', $account);

        try {
            $this->registry->get((string) $account->provider->value)->disconnect($account);
        } catch (Throwable $exception) {
            // The local credentials go regardless. A failed upstream revoke call
            // is logged, but leaving a dead token on disk is the worse outcome.
            Log::warning('socialhub.oauth.disconnect_failed_upstream', [
                'account_id' => $account->id,
                'exception' => $exception::class,
            ]);
        }

        $account->token?->delete();
        $account->markStatus(SocialAccountStatus::Disconnected, null);
        $account->update(['is_default' => false]);

        return redirect()
            ->route('socialhub.accounts.index')
            ->with('status', 'Account disconnected and its stored credentials deleted.');
    }

    /**
     * Keep the legacy workspace integration model in sync with the OAuth-based
     * connected account record so the integrations dashboard reflects the real
     * connection status immediately after the provider callback.
     */
    private function syncWorkspaceIntegration(SocialAccount $account, object $session, int $tenantId): void
    {
        $provider = $account->provider?->value ?? $session->provider ?? null;

        if ($provider === null) {
            return;
        }

        $integration = Integration::query()->firstOrNew([
            'tenant_id' => $tenantId,
            'provider' => $provider,
        ]);

        $credentials = $integration->credentials ?? [];
        $tokens = $session->tokens ?? null;

        if ($tokens !== null) {
            $credentials['access_token'] = $tokens->accessToken;
            if ($tokens->refreshToken !== null && $tokens->refreshToken !== '') {
                $credentials['refresh_token'] = $tokens->refreshToken;
            }
        }

        $integration->fill([
            'name' => $account->provider_display_name ?: config('integrations.providers.'.$provider.'.name', ucfirst($provider)),
            'scope' => 'workspace',
            'credentials' => $credentials,
            'auto_verify' => false,
            'last_error' => null,
        ]);

        $integration->save();
        $integration->markConnected();
    }

    /**
     * Turn an exception into something a social media manager can act on. The
     * technical detail stays in the log.
     */
    private function messageFor(Throwable $exception): string
    {
        if (method_exists($exception, 'getUserFacingError')) {
            $facing = $exception->getUserFacingError();

            if ($facing !== null) {
                return $facing->userMessage;
            }
        }

        return config('app.debug')
            ? $exception->getMessage()
            : 'The social network could not complete that request. Check the account status and try again.';
    }
}
