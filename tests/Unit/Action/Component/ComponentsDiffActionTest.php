<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Component;

use Blokctl\Action\Component\ComponentsDiffAction;
use Blokctl\Action\Component\ComponentsDiffResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ComponentsDiffActionTest extends TestCase
{
    #[Test]
    public function execute_reports_components_present_in_one_space_only(): void
    {
        $result = $this->diff();

        $this->assertSame(['source-only'], $result->onlyInSource);
        $this->assertSame(['target-only'], $result->onlyInTarget);
        $this->assertTrue($result->hasDifferences());
    }

    #[Test]
    public function execute_ignores_ids_positions_and_group_uuids(): void
    {
        $result = $this->diff();

        $this->assertSame(['hero-section'], $result->identical);
        $this->assertSame(2, $result->countCompared());
    }

    #[Test]
    public function execute_reports_field_level_differences(): void
    {
        $changes = $this->changesByPath($this->diff(), 'banner');

        $this->assertSame(
            ['fields.background_color.tab', 'fields.subtitle', 'fields.text_alignment.default_value'],
            array_keys($changes),
        );

        $this->assertSame(ComponentsDiffResult::CHANGED, $changes['fields.text_alignment.default_value']['type']);
        $this->assertSame('center', $changes['fields.text_alignment.default_value']['source']);
        $this->assertSame('left', $changes['fields.text_alignment.default_value']['target']);

        $this->assertSame('Style', $changes['fields.background_color.tab']['source']);
        $this->assertSame('Design', $changes['fields.background_color.tab']['target']);

        $this->assertSame(ComponentsDiffResult::ONLY_IN_TARGET, $changes['fields.subtitle']['type']);
    }

    #[Test]
    public function execute_compares_preset_content_when_requested(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('diff-components-source'),
            $this->mockResponse('diff-components-target'),
            $this->mockResponse('diff-presets-source'),
            $this->mockResponse('diff-presets-target'),
        );

        $result = new ComponentsDiffAction($client)->execute(
            sourceSpaceId: '1',
            targetSpaceId: '2',
            componentNames: ['hero-section'],
            withPresets: true,
        );

        $this->assertTrue($result->presetsCompared);
        $changes = $this->changesByPath($result, 'hero-section');
        $this->assertSame(['presets.Default hero.content.headline'], array_keys($changes));
        $this->assertSame('Hello', $changes['presets.Default hero.content.headline']['source']);
        $this->assertSame('Hi there', $changes['presets.Default hero.content.headline']['target']);
    }

    #[Test]
    public function execute_limits_comparison_to_requested_components(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('diff-components-source'),
            $this->mockResponse('diff-components-target'),
        );

        $result = new ComponentsDiffAction($client)->execute('1', '2', ['source-only', 'hero-section']);

        $this->assertSame(['source-only'], $result->onlyInSource);
        $this->assertSame([], $result->onlyInTarget);
        $this->assertSame(['hero-section'], $result->identical);
        $this->assertSame([], $result->differences);
    }

    #[Test]
    public function execute_throws_when_requested_component_is_missing_in_both_spaces(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('diff-components-source'),
            $this->mockResponse('diff-components-target'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Component not found in either space: missing');

        new ComponentsDiffAction($client)->execute('1', '2', ['missing']);
    }

    #[Test]
    public function execute_uses_separate_target_client(): void
    {
        $sourceClient = $this->createMockClient($this->mockResponse('diff-components-source'));
        $targetClient = $this->createMockClient($this->mockResponse('diff-components-target'));

        $result = new ComponentsDiffAction($sourceClient, $targetClient)->execute('1', '2');

        $this->assertSame(['target-only'], $result->onlyInTarget);
    }

    #[Test]
    public function to_array_exposes_the_full_report(): void
    {
        $data = $this->diff()->toArray();

        $this->assertTrue($data['has_differences']);
        $this->assertFalse($data['presets_compared']);
        $this->assertSame(['source-only'], $data['only_in_source']);
        $this->assertIsArray($data['differences']);
        $this->assertArrayHasKey('banner', $data['differences']);
    }

    private function diff(): ComponentsDiffResult
    {
        $client = $this->createMockClient(
            $this->mockResponse('diff-components-source'),
            $this->mockResponse('diff-components-target'),
        );

        return new ComponentsDiffAction($client)->execute('1', '2');
    }

    /**
     * @return array<string, array{path: string, type: string, source: mixed, target: mixed}>
     */
    private function changesByPath(ComponentsDiffResult $result, string $component): array
    {
        $changes = [];
        foreach ($result->differences[$component] ?? [] as $change) {
            $changes[$change['path']] = $change;
        }

        ksort($changes);

        return $changes;
    }
}
