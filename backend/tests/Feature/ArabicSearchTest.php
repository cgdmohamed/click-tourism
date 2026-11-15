<?php

namespace Tests\Feature;

use App\Modules\Parties\Models\Customer;
use App\Support\Like;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class ArabicSearchTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private function find(string $term): array
    {
        $query = Customer::query();
        Like::contains($query, ['name_ar', 'name_en', 'code'], $term);

        return $query->pluck('name_ar')->all();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->customer()->update(['name_ar' => 'محمد مصطفى عبد الفتاح درويش', 'name_en' => 'Mohamed Mostafa Darwish']);
    }

    public function test_arabic_spelling_variants_match(): void
    {
        $this->assertCount(1, $this->find('محمد مصطفي'));
        $this->assertCount(1, $this->find('عبد الفتاح'));
        $this->assertCount(1, $this->find('احمد') + $this->find('محمد'));
    }

    public function test_every_word_must_match_but_in_any_order(): void
    {
        $this->assertCount(1, $this->find('درويش محمد'));
        $this->assertCount(1, $this->find('mohamed darwish'));
        $this->assertCount(0, $this->find('محمد سامي'));
    }

    public function test_wildcards_still_match_themselves_only(): void
    {
        $this->assertCount(0, $this->find('%'));
        $this->assertCount(0, $this->find('_'));
    }

    public function test_the_code_is_searchable(): void
    {
        $this->assertCount(1, $this->find('C-0001'));
    }
}
