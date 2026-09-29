<?php

declare(strict_types=1);

namespace App\Services\Social\OAuth;

use App\Exceptions\Social\AuthenticationException;
use App\Services\Social\Contracts\OauthStateStore;
use App\Services\Social\Data\AuthRequest;
use App\Services\Social\Data\AuthSession;
use App\Services\Social\SocialProviderRegistry;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Drives the state/PKCE half of the OAuth handshake for every provider.
 *
 * The state is generated with `random_bytes`, stored through the
 * {@see OauthStateStore} with a 10-minute TTL, and compared with `hash_equals`
 * on the way back. The code exchange itself always happens server-side inside
 * the provider — `client_secret` never leaves this process and never appears in
 * a redirect, log line, or exception message.
 */
class OAuthCoordinator
{
    public const STATE_TTL_MINUTES = 10;

    public function __construct(
        private readonly SocialProviderRegistry $registry,
        private readonly OauthStateStore $states,
        private readonly Repository $config,
    ) {}

    /**
     * Begin leg: mint state (+ PKCE verifier when the provider declares support)
     * and return the provider's authorize URL.
     *
     * @param  list<string>  $requestedScopes
     *
     * @throws AuthenticationException
     */
    public function begin(
        string $provider,
        string $redirectUri,
        int $tenantId,
        array $requestedScopes = [],
    ): AuthSession {
        $descriptor = $this->registry->describeOne($provider);
        $oauth = $descriptor->oauth;

        $clientId = (string) ($oauth['client_id'] ?? '');
        $authorizeUrl = (string) ($oauth['authorize_url'] ?? '');

        if ($clientId === '' || $authorizeUrl === '') {
            throw new AuthenticationException(
                sprintf('Provider "%s" has no OAuth client configured.', $provider),
            );
        }

        $state = $this->generateState();
        $scopes = $this->resolveScopes($provider, $requestedScopes, $oauth);
        $codeVerifier = ($oauth['pkce'] ?? false) === true ? $this->generateCodeVerifier() : null;
        $expiresAt = (new DateTimeImmutable)->add(new DateInterval('PT'.self::STATE_TTL_MINUTES.'M'));

        $this->states->put(
            $provider,
            $state,
            $codeVerifier ?? '',
            $redirectUri,
            $scopes,
            $tenantId,
            $expiresAt,
        );

        $session = $this->registry->get($provider)->authenticate(new AuthRequest(
            provider: $provider,
            redirectUri: $redirectUri,
            state: $state,
            codeVerifier: $codeVerifier,
        ));

        if (! $session->hasAuthorizationUrl()) {
            throw new AuthenticationException(
                sprintf('Provider "%s" did not return an authorization URL.', $provider),
            );
        }

        return new AuthSession(
            provider: $provider,
            authorizationUrl: $session->authorizationUrl,
            state: $state,
            codeVerifier: $codeVerifier,
            redirectUri: $redirectUri,
            scopes: $scopes,
            tokens: null,
            profile: $session->profile,
            stateExpiresAt: $expiresAt,
        );
    }

    /**
     * Callback leg: consume the single-use state, verify it, then hand the code
     * to the provider for a server-side token exchange.
     *
     * @throws AuthenticationException
     */
    public function handleCallback(AuthRequest $request): AuthSession
    {
        if ($request->wasDenied()) {
            throw AuthenticationException::denied($request->provider, $request->errorDescription);
        }

        if ($request->code === null || $request->state === null) {
            throw AuthenticationException::invalidState($request->provider);
        }

        $record = $this->states->pull($request->provider, $request->state);

        if ($record === null) {
            throw AuthenticationException::invalidState($request->provider);
        }

        $redirectUri = $request->redirectUri ?? $record['redirect_url'];

        if ($redirectUri !== null && $request->redirectUri !== null
            && ! hash_equals($redirectUri, $request->redirectUri)) {
            throw new AuthenticationException(
                sprintf('The callback redirect URI does not match the one used to start the "%s" flow.', $request->provider),
            );
        }

        $codeVerifier = $record['code_verifier'] !== '' ? $record['code_verifier'] : null;

        if ($codeVerifier !== null && $request->codeVerifier !== null
            && ! hash_equals($codeVerifier, $request->codeVerifier)) {
            throw AuthenticationException::invalidState($request->provider);
        }

        return $this->registry->get($request->provider)->authenticate(new AuthRequest(
            provider: $request->provider,
            code: $request->code,
            state: $request->state,
            redirectUri: $redirectUri,
            codeVerifier: $codeVerifier,
            query: $request->query,
        ));
    }

    /**
     * 32 bytes of CSPRNG output, hex encoded.
     */
    public function generateState(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * RFC 7636 code verifier: 43-128 characters of unreserved alphabet.
     */
    public function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    public function stateTtlSeconds(): int
    {
        return self::STATE_TTL_MINUTES * 60;
    }

    /**
     * @param  list<string>  $requestedScopes
     * @param  array<string, mixed>  $oauth
     * @return list<string>
     */
    private function resolveScopes(string $provider, array $requestedScopes, array $oauth): array
    {
        $declared = (array) ($oauth['scopes'] ?? []);
        $default = (array) ($oauth['default_scopes'] ?? []);

        $requested = $requestedScopes === [] ? $default : $requestedScopes;

        $resolved = Scopes::intersect($requested, $declared);

        if ($resolved === []) {
            $resolved = Scopes::normalise($default);
        }

        return $resolved === [] ? Scopes::normalise($requested) : $resolved;
    }
}
