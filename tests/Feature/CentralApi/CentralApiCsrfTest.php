<?php

use Inertia\Testing\AssertableInertia as Assert;

test('app layout includes csrf token meta tag', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('name="csrf-token"', false);
});

test('inertia shares csrf token for client-side requests', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('csrf_token'));
});
