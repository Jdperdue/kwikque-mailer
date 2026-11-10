<?php

declare(strict_types=1);

it('redirects the root to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});
