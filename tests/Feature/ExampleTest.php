<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** Halaman utama butuh login, jadi tamu diarahkan ke halaman login yang bisa dibuka. */
    public function test_guests_are_redirected_to_a_working_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk();
    }
}
