<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_home_page_redirects_to_the_login_page(): void
    {
        $response = $this->get('/');

        // The home page has no content of its own: it sends every visitor to the sign-in page.
        $response->assertRedirect('/login');
    }
}
