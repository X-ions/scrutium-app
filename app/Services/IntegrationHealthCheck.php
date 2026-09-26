<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IntegrationHealthCheck
{
    /**
     * @return array<string, string>
     */
    public static function providers(): array
    {
        return [
            'instagram' => 'Instagram',
            'tiktok' => 'TikTok',
            'youtube' => 'YouTube',
            'x' => 'X',
            'linkedin' => 'LinkedIn',
            'shopify' => 'Shopify',
            'ga4' => 'Google Analytics 4',
        ];
    }

    public function check(Integration $integration): string
    {
        $credentials = $integration->credentials ?? [];
        $token = $credentials['access_token'] ?? null;

        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('Add a valid access token before testing this connection.');
        }

        try {
            $response = match ($integration->provider) {
                'instagram' => $this->client($token)
                    ->get('https://graph.instagram.com/me', ['fields' => 'id,user_id,username']),
                'tiktok' => $this->client($token)
                    ->get('https://open.tiktokapis.com/v2/user/info/', ['fields' => 'open_id,display_name']),
                'youtube' => $this->client($token)
                    ->get('https://www.googleapis.com/youtube/v3/channels', ['part' => 'id,snippet', 'mine' => 'true']),
                'x' => $this->client($token)
                    ->get('https://api.x.com/2/users/me'),
                'linkedin' => $this->client($token)
                    ->get('https://api.linkedin.com/v2/userinfo'),
                'shopify' => $this->checkShopify($credentials, $token),
                'ga4' => $this->client($token)
                    ->get('https://analyticsadmin.googleapis.com/v1beta/accounts'),
                default => throw new RuntimeException('This integration provider is not supported.'),
            };
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach the provider. Check its availability and try again.');
        }

        if ($response->unauthorized()) {
            throw new RuntimeException('The provider rejected this token. Check that it is current and has the required API scopes.');
        }

        if ($response->forbidden()) {
            throw new RuntimeException('The token is missing permission for this provider API. Review the required API scopes and access level.');
        }

        if ($response->failed()) {
            throw new RuntimeException('The provider returned an error (HTTP '.$response->status().'). Check API access and try again.');
        }

        $payload = $response->json();
        if (! is_array($payload) || ! $this->hasAccountData($integration->provider, $payload)) {
            throw new RuntimeException('The provider did not return an accessible account. Check the token scopes and account access.');
        }

        return 'Connection verified with '.$this->providerAccountLabel($integration->provider, $payload).'.';
    }

    private function client(string $token): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($token)
            ->connectTimeout(5)
            ->timeout(15);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function checkShopify(array $credentials, string $token): \Illuminate\Http\Client\Response
    {
        $shop = strtolower(trim((string) ($credentials['shop_domain'] ?? '')));

        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop)) {
            throw new RuntimeException('Enter the store’s myshopify.com domain before testing this connection.');
        }

        return Http::acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $token])
            ->connectTimeout(5)
            ->timeout(15)
            ->get("https://{$shop}/admin/api/2026-01/shop.json");
    }

    /** @param array<string, mixed> $payload */
    private function hasAccountData(string $provider, array $payload): bool
    {
        return match ($provider) {
            'instagram' => filled($payload['id'] ?? $payload['user_id'] ?? null),
            'tiktok' => filled(data_get($payload, 'data.user.open_id')),
            'youtube' => is_array($payload['items'] ?? null) && count($payload['items']) > 0,
            'x' => filled(data_get($payload, 'data.id')),
            'linkedin' => filled($payload['sub'] ?? null),
            'shopify' => filled(data_get($payload, 'shop.id')),
            'ga4' => is_array($payload['accounts'] ?? null) && count($payload['accounts']) > 0,
            default => false,
        };
    }

    /** @param array<string, mixed> $payload */
    private function providerAccountLabel(string $provider, array $payload): string
    {
        $label = match ($provider) {
            'instagram' => data_get($payload, 'username'),
            'tiktok' => data_get($payload, 'data.user.display_name'),
            'youtube' => data_get($payload, 'items.0.snippet.title'),
            'x' => data_get($payload, 'data.username'),
            'linkedin' => data_get($payload, 'name'),
            'shopify' => data_get($payload, 'shop.name'),
            'ga4' => count($payload['accounts'] ?? []).' accessible account(s)',
            default => null,
        };

        return filled($label) ? (string) $label : (self::providers()[$provider] ?? 'provider account');
    }
}