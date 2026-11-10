<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DemoStatusTest extends TestCase
{
    public function test_demo_status_is_disabled_without_demo_configuration(): void
    {
        config()->set('demo.enabled', false);
        Cache::put('demo:window', [
            'generation' => 'demo-session',
            'next_reset_at' => '2026-11-10T18:00:00+02:00',
        ]);

        $this->getJson('/api/v1/demo/status')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.generation', null)
            ->assertJsonPath('data.next_reset_at', null);
    }

    public function test_demo_status_exposes_current_reset_window_but_no_secrets(): void
    {
        config()->set('demo.enabled', true);
        config()->set('demo.reset_seconds', 600);
        Cache::put('demo:window', [
            'generation' => 'demo-window-123',
            'next_reset_at' => '2026-11-10T18:00:00+02:00',
        ]);

        $this->getJson('/api/v1/demo/status')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.reset_seconds', 600)
            ->assertJsonPath('data.generation', 'demo-window-123')
            ->assertJsonPath('data.next_reset_at', '2026-11-10T18:00:00+02:00');
    }
}
