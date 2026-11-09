<?php

namespace Tests\Unit;

use App\Modules\Core\Services\DecisionRegistry;
use PHPUnit\Framework\TestCase;

class DecisionRegistryTest extends TestCase
{
    /** @return array<string, array{type: string, title: string, blocks: string}> */
    private function documented(): array
    {
        $path = dirname(__DIR__, 3).'/docs/product/decisions-needed.md';

        if (! is_file($path)) {
            $this->markTestSkipped('docs/product/decisions-needed.md is not available in this checkout.');
        }

        $rows = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (! preg_match('/^\|\s*(DEC-\d{3})\s*\|/', $line)) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($line, " \t|")));

            $rows[$cells[0]] = [
                'type' => $cells[1],
                'title' => trim(str_replace('**', '', $cells[2])),
                'blocks' => end($cells),
            ];
        }

        return $rows;
    }

    public function test_the_registry_matches_decisions_needed_markdown(): void
    {
        $documented = $this->documented();
        $registry = [];

        foreach ((new DecisionRegistry)->all() as $decision) {
            $registry[$decision['id']] = $decision;
        }

        $this->assertNotEmpty($documented);
        $this->assertSame(array_keys($documented), array_keys($registry), 'Decision ids differ from the document.');

        foreach ($documented as $id => $row) {
            $this->assertSame($row['type'], $registry[$id]['type'], "{$id} type");
            $this->assertSame($row['title'], $registry[$id]['title_en'], "{$id} title");
            $this->assertSame($row['blocks'], $registry[$id]['blocks'], "{$id} blocks");
        }
    }

    public function test_every_decision_has_an_arabic_title_and_is_unresolved_by_default(): void
    {
        $registry = new DecisionRegistry;

        foreach ($registry->all() as $decision) {
            $this->assertNotSame('', trim($decision['title_ar']), "{$decision['id']} needs an Arabic title");
        }

        $this->assertCount(count($registry->all()), $registry->unresolved());
    }
}
