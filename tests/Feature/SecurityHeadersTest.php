<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Panel başlıkları: her yanıtta noindex; HSTS yalnız canlıda. */
class SecurityHeadersTest extends TestCase
{
    public function test_every_response_is_noindex_and_no_hsts_outside_production(): void
    {
        $response = $this->get('/up');

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->get('/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
