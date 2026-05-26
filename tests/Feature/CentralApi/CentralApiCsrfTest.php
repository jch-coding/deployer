<?php

test('app layout includes csrf token meta tag', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('name="csrf-token"', false);
});
