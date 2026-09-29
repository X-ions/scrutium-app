<?php

declare(strict_types=1);

namespace App\Services\Social\OAuth;

use App\Exceptions\Social\AuthenticationException;
use App\Exceptions\Social\ProviderNotConfiguredException;
use App\Exceptions\Social\TokenRevokedException;
use App\Services\Social\Data\AuthRequest;
use App\Services\Social\Data\AuthSession;
use App\Services\Social\Data\TokenSet;
use App\Services\Social\ProviderHttpClient;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Shared OAuth 2.0 authorization-code flow for providers that use it.
 *
 * Handles the begin leg (state + PKCE, authorize URL) and the callback leg
 * (server-side token exchange). The client secret is only ever read here and
 * sent in the exchange POST body — never placed in a URL, log, or exception.
 */
trait UsesOAuth2AuthorizationCode
{
    protected function oauthAuthorizeUrl(AuthRequest $request): string
    {
        $oauth = $this->oauthConfig();

        $query = [
            'client_id' => $oauth['client_id'],
            'redirect_uri' => $request->redirectUri ?? $oauth['redirect_uri'] ?? null,
            'response_type' => 'code',
            'state' => $request->state,
        ];

        $scopes = Scopes::intersect(
            $request->scopes !== [] ? $request->scopes : (array) ($oauth['default_scopes'] ?? []),
            (array) ($oauth['scopes'] ?? []),
        );

        if ($scopes !== []) {
            $query['scope'] = implode(' ', $scopes);
        }

        if ($request->codeVerifier !== null) {
            $query['code_challenge'] = $this->codeChallenge($request->codeVerifier);
            $query['code_challenge_method'] = 'S256';
        }

        $separator = str_contains($oauth['authorize_url'], '?') ? '&' : '?';

        return $oauth['authorize_url'].$separator.http_build_query($query);
    }

    /**
     * Exchange an authorization code for tokens, server-side only.
     *
     * @return array<string, mixed>
     *
     * @throws AuthenticationException
     */
    protected function exchangeAuthorizationCode(AuthRequest $request, ProviderHttpClient $http): array
    {
        $oauth = $this->oauthConfig();
        $clientSecret = $oauth['client_secret'];

        $payload = [
            'client_id' => $oauth['client_id'],
            'client_secret' => $clientSecret,
            'redirect_uri' => $request->redirectUri ?? $oauth['redirect_uri'] ?? null,
            'code' => $request->code,
        ];

        if ($request->codeVerifier !== null && $request->codeVerifier !== '') {
            $payload['code_verifier'] = $request->codeVerifier;
        }

        $response = $http->withoutThrottle()->postForm($oauth['token_url'], $payload);

        if (! $response->successful()) {
            throw AuthenticationException::denied(
                $this->providerKey(),
                $this->safeTokenError($response->json()),
            );
        }

        return (array) $response->json();
    }

    /**
     * @param  array<string, mixed>  $token
     */
    protected function tokenSetFrom(array $token, ?string $providerKey = null): TokenSet
    {
        $expiresIn = isset($token['expires_in']) ? (int) $token['expires_in'] : null;
        $expiresAt = $expiresIn !== null
            ? (new DateTimeImmutable)->modify(sprintf('+%d seconds', $expiresIn))
            : null;

        $rawScopes = $token['scope'] ?? $token['scopes'] ?? null;

        return new TokenSet(
            accessToken: (string) ($token['access_token'] ?? ''),
            refreshToken: isset($token['refresh_token']) ? (string) $token['refresh_token'] : null,
            idToken: isset($token['id_token']) ? (string) $token['id_token'] : null,
            tokenType: (string) ($token['token_type'] ?? 'Bearer'),
            expiresIn: $expiresIn,
            expiresAt: $expiresAt,
            scopes: $rawScopes !== null ? Scopes::normalise((string) $rawScopes) : [],
            metadata: $this->tokenMetadata($token),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function refreshAccessToken(
        string $refreshToken,
        ProviderHttpClient $http,
        ?string $providerKey = null,
    ): TokenSet {
        $oauth = $this->oauthConfig();

        $response = $http->withoutThrottle()->postForm($oauth['token_url'], [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $oauth['client_id'],
            'client_secret' => $oauth['client_secret'],
        ]);

        if (! $response->successful()) {
            $body = $response->json();

            // A failed refresh means the grant itself is dead, not that the user
            // declined. Marking it as revoked stops the refresh loop retrying a
            // grant the platform has already withdrawn, which is what every
            // platform documents for invalid_grant.
            if ($this->isRevokedGrantError($body)) {
                throw new TokenRevokedException(
                    $providerKey ?? $this->providerKey(),
                    null,
                    ['provider_code' => 'invalid_grant'],
                );
            }

            throw AuthenticationException::denied(
                $providerKey ?? $this->providerKey(),
                $this->safeTokenError($body),
            );
        }

        $body = (array) $response->json();
        $body['refresh_token'] ??= $refreshToken;

        return $this->tokenSetFrom($body, $providerKey);
    }

    /**
     * OAuth error codes that mean the stored grant is permanently unusable.
     */
    protected function isRevokedGrantError(mixed $body): bool
    {
        if (! is_array($body)) {
            return false;
        }

        $error = $body['error'] ?? null;

        if (is_array($error)) {
            $error = $error['error'] ?? $error['code'] ?? null;
        }

        return in_array($error, ['invalid_grant', 'unsupported_grant_type'], true);
    }

    protected function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    protected function oauthConfig(): array
    {
        $oauth = (array) config(sprintf('socialhub.providers.%s.oauth', $this->providerKey()), []);

        $clientId = (string) ($oauth['client_id'] ?? '');
        $authorizeUrl = (string) ($oauth['authorize_url'] ?? '');
        $tokenUrl = (string) ($oauth['token_url'] ?? '');

        if ($clientId === '' || $authorizeUrl === '' || $tokenUrl === '') {
            throw new ProviderNotConfiguredException(
                $this->providerKey(),
                'OAuth client_id, authorize_url or token_url is missing from config/socialhub.php',
            );
        }

        $oauth['client_id'] = $clientId;
        $oauth['authorize_url'] = $authorizeUrl;
        $oauth['token_url'] = $tokenUrl;
        $oauth['client_secret'] = (string) ($oauth['client_secret'] ?? '');

        return $oauth;
    }

    /**
     * @param  array<string, mixed>  $token
     * @return array<string, mixed>
     */
    protected function tokenMetadata(array $token): array
    {
        return array_filter([
            'refresh_expires_in' => isset($token['refresh_token_expires_in']) ? (int) $token['refresh_token_expires_in'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Summarise an OAuth error response without echoing upstream detail that
     * might contain submitted credentials.
     */
    protected function safeTokenError(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $error = $body['error'] ?? null;

        if (is_string($error) && $error !== '') {
            return $error;
        }

        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        return null;
    }

    protected function newState(): string
    {
        return bin2hex(random_bytes(32));
    }

    protected function newCodeVerifier(): string
    {
        return Str::random(96);
    }

    protected function newStateExpiry(): DateTimeInterface
    {
        return (new DateTimeImmutable)->modify('+10 minutes');
    }

    protected function beginSession(AuthRequest $request, string $authorizationUrl): AuthSession
    {
        return new AuthSession(
            provider: $this->providerKey(),
            authorizationUrl: $authorizationUrl,
            state: $request->state ?? $this->newState(),
            codeVerifier: $request->codeVerifier,
            redirectUri: $request->redirectUri,
            scopes: Scopes::intersect(
                $request->scopes !== [] ? $request->scopes : $this->oauthConfig()['default_scopes'],
                $this->oauthConfig()['scopes'],
            ),
        );
    }
}
