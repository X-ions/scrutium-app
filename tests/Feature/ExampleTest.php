<?php

test('guests are sent to sign in', function () {
    $this->get('/')->assertRedirect(route('login'));
    $this->get('/signin')->assertOk();
});
