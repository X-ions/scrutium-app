<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Vercel serves one deployment from several hostnames: the project domain, the
 * per-deployment preview domain, and any aliases. If the app trusts
 * X-Forwarded-Host it renders absolute URLs for a host the browser is not
 * actually on, so a form submits cross-origin without its session cookie and
 * every login fails with a 419 Page Expired.
 *
 * These tests drive a real request through the HTTP kernel so the whole
 * middleware stack is exercised, rather than asserting on config values.
 */
class TrustedProxyHeadersTest extends TestCase
{
    /**
     * @param  array<string, string>  $forwarded
     * @return array{action: ?string, html: string, hsts: bool}
     */
    private function requestSignIn(array $forwarded = []): array
    {
        $request = Request::create(
            'https://scrutium-app.vercel.app/signin',
            'GET',
            server: $forwarded + [
                'HTTP_HOST' => 'scrutium-app.vercel.app',
                'REMOTE_ADDR' => '127.0.0.1',
                'SERVER_PORT' => '443',
            ]
        );

        $response = $this->app->make(Kernel::class)->handle($request);
        $html = (string) $response->getContent();

        return [
            'action' => preg_match('/<form[^>]+action="([^"]+)"/', $html, $matches) === 1
                ? html_entity_decode($matches[1])
                : null,
            'html' => $html,
            'hsts' => $response->headers->has('Strict-Transport-Security'),
        ];
    }

    public function test_a_forwarded_host_cannot_move_the_sign_in_form_off_origin(): void
    {
        $result = $this->requestSignIn([
            'HTTP_X_FORWARDED_HOST' => 'scrutium.vercel.app',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->assertNotNull($result['action'], 'The sign-in page should render a form.');
        $this->assertStringStartsWith(
            'https://scrutium-app.vercel.app/',
            (string) $result['action'],
            'The form must post back to the host the browser is on, not the forwarded host. Got: '.$result['action']
        );
    }

    public function test_an_unexpected_forwarded_host_never_leaks_into_markup(): void
    {
        $result = $this->requestSignIn([
            'HTTP_X_FORWARDED_HOST' => 'attacker.example.com',
        ]);

        $this->assertStringNotContainsString(
            'attacker.example.com',
            $result['html'],
            'A forwarded host must never leak into rendered markup.'
        );
    }

    public function test_forwarded_proto_is_still_trusted_so_hsts_is_emitted(): void
    {
        // Behind the Vercel edge the app only ever sees plain HTTP, so without a
        // trusted X-Forwarded-Proto, $request->isSecure() is false and
        // Strict-Transport-Security is never sent.
        $this->assertTrue(
            $this->requestSignIn(['HTTP_X_FORWARDED_PROTO' => 'https'])['hsts'],
            'X-Forwarded-Proto must still be trusted so HSTS can be emitted.'
        );
    }

    public function test_dynamic_pages_are_never_publicly_cacheable(): void
    {
        // A cacheable HTML response is stripped of Set-Cookie by the edge, which
        // costs the browser its session and turns every POST into a 419.
        $response = $this->get('/signin')->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringNotContainsString(
            'public',
            $cacheControl,
            "Sign-in must not be publicly cacheable, got: {$cacheControl}"
        );
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }
}
