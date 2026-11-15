<?php

namespace Tests\Feature;

use App\Modules\Assistant\Services\ProviderManager;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The disposable demo switches the assistant on by itself when it is given a provider key. */
class DemoAssistantTest extends TestCase
{
    use RefreshDatabase;

    private function isolatedDemo(): void
    {
        $connection = config('database.default');
        config([
            'demo.enabled' => true, 'app.env' => 'demo',
            "database.connections.{$connection}.host" => 'demo-db',
            "database.connections.{$connection}.database" => 'demo_erp',
        ]);
    }

    public function test_assistant_is_usable_after_seeding_when_the_demo_has_a_key(): void
    {
        $this->isolatedDemo();
        config(['services.openai.key' => 'sk-demo-key-0123456789abcdef']);

        (new DemoDataSeeder(true))->run();

        $this->assertTrue(app(ProviderManager::class)->usable());
    }

    public function test_assistant_stays_off_in_the_demo_without_a_key(): void
    {
        $this->isolatedDemo();
        config(['services.openai.key' => '']);

        (new DemoDataSeeder(true))->run();

        $this->assertFalse(app(ProviderManager::class)->usable());
    }

    public function test_a_real_installation_is_never_switched_on_by_the_seeder(): void
    {
        config(['demo.enabled' => false, 'services.openai.key' => 'sk-real-key-0123456789abcdef']);

        (new DemoDataSeeder(true))->run();

        $this->assertFalse(app(ProviderManager::class)->enabled());
        $this->assertFalse(app(ProviderManager::class)->accepted());
    }
}
