<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Sequence;
use App\Modules\Core\Services\SequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class DocumentNumberingTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private function secondBranch(): Branch
    {
        return Branch::query()->create([
            'code' => 'AL-EX',
            'name_ar' => 'الإسكندرية',
            'name_en' => 'Alexandria',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);
    }

    public function test_numbers_include_the_branch_code_and_are_unique_across_branches(): void
    {
        $this->buildAccounting();
        $other = $this->secondBranch();
        $sequences = app(SequenceService::class);
        $year = now()->year;

        $hq = $sequences->next('journal', $this->branch);
        $alex = $sequences->next('journal', $other);

        $this->assertSame("JV-HQ-{$year}-000001", $hq);
        $this->assertSame("JV-ALEX-{$year}-000001", $alex);
        $this->assertNotSame($hq, $alex);
        $this->assertSame("JV-HQ-{$year}-000002", $sequences->next('journal', $this->branch));
    }

    public function test_existing_sequence_rows_keep_counting_and_keep_their_stored_prefix(): void
    {
        $this->buildAccounting();
        Sequence::query()->create([
            'document_type' => 'ADJ', 'branch_id' => $this->branch->id, 'year' => now()->year,
            'prefix' => 'AD', 'next_value' => 8, 'gap_policy' => 'allow',
        ]);

        $number = app(SequenceService::class)->next('ADJ', $this->branch);

        $this->assertSame('AD-HQ-'.now()->year.'-000008', $number);
    }

    public function test_voucher_series_use_the_full_configured_prefix_for_new_sequences(): void
    {
        $this->buildAccounting();
        $sequences = app(SequenceService::class);
        $year = now()->year;

        foreach (['RCV', 'PAY', 'TV', 'ADJ', 'RF'] as $series) {
            $this->assertSame("{$series}-HQ-{$year}-000001", $sequences->next($series, $this->branch));
        }
        $this->assertDatabaseHas('sequences', ['document_type' => 'ADJ', 'prefix' => 'ADJ']);
    }

    public function test_legacy_unknown_document_types_keep_the_two_letter_prefix(): void
    {
        $this->buildAccounting();

        $this->assertStringStartsWith('XY-HQ-', app(SequenceService::class)->next('xyz_custom', $this->branch));
    }
}
