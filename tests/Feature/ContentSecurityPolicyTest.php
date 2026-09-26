<?php

it('emits a nonce based content security policy without inline script allowances', function () {
    $response = $this->get('/signin')->assertOk();

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeNull();

    expect($csp)->toContain("'unsafe-eval'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->not->toContain('report-uri')
        ->and($csp)->not->toContain('report-to');

    preg_match('/script-src ([^;]+)/', $csp, $matches);
    $scriptSrc = $matches[1];

    expect($scriptSrc)->toStartWith("'self' 'nonce-")
        ->and($scriptSrc)->toContain("'unsafe-eval'")
        ->and($scriptSrc)->not->toContain("'unsafe-inline'");

    preg_match("/'nonce-([^']+)'/", $scriptSrc, $matches);
    $nonce = $matches[1];

    expect($response->getContent())->toContain('<script nonce="'.$nonce.'"');
});
