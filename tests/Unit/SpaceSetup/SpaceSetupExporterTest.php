<?php

declare(strict_types=1);

namespace Tests\Unit\SpaceSetup;

use Blokctl\SpaceSetup\SpaceSetupConfigValidator;
use Blokctl\SpaceSetup\SpaceSetupExportResult;
use Blokctl\SpaceSetup\SpaceSetupExporter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceSetupExporterTest extends TestCase
{
    #[Test]
    public function exports_components_with_folder_path_tabs_and_properties(): void
    {
        $result = new SpaceSetupExporter($this->createMockClient(
            $this->mockResponse('list-components-export'),
        ))->export('680', [SpaceSetupExporter::COMPONENTS]);

        $this->assertSame([
            'version' => 1,
            'components' => ['ensure' => [
                [
                    'name' => 'promo-banner',
                    'display_name' => 'Promo banner',
                    'type' => 'nestable',
                    'folder' => 'Marketing/Banners',
                    'color' => '#ff6600',
                    'preview_field' => 'title',
                    'fields' => [
                        ['field' => 'title', 'type' => 'text', 'tab' => 'Content', 'required' => true, 'properties' => ['maximum' => 80]],
                        ['field' => 'size', 'type' => 'option', 'default_value' => 's', 'options' => [['name' => 'Small', 'value' => 's'], ['name' => 'Large', 'value' => 'l']]],
                        ['field' => 'theme', 'type' => 'option', 'source' => 'internal', 'datasource_slug' => 'colors', 'properties' => ['use_uuid' => true]],
                    ],
                ],
                ['name' => 'page', 'type' => 'content_type'],
            ]],
        ], $result->config);
        $this->assertSame([], $result->warnings);
        $this->assertTrue(new SpaceSetupConfigValidator()->validate($result->config)->isValid());
    }

    #[Test]
    public function exports_only_selected_components_and_fails_on_unknown_names(): void
    {
        $result = new SpaceSetupExporter($this->createMockClient(
            $this->mockResponse('list-components-export'),
        ))->export('680', [SpaceSetupExporter::COMPONENTS], components: ['page']);

        $this->assertSame([['name' => 'page', 'type' => 'content_type']], $this->at($result->config, ['components', 'ensure']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Component not found: missing');

        new SpaceSetupExporter($this->createMockClient(
            $this->mockResponse('list-components-export'),
        ))->export('680', [SpaceSetupExporter::COMPONENTS], components: ['missing']);
    }

    #[Test]
    public function exports_datasources_inline_or_as_csv_files(): void
    {
        $responses = fn(): array => [
            $this->mockResponse('list-datasources'),
            $this->mockResponse('list-datasource-entries-it'),
            $this->mockResponse('list-datasource-entries'),
        ];

        $inline = new SpaceSetupExporter($this->createMockClient(...$responses()))
            ->export('680', [SpaceSetupExporter::DATASOURCES], datasources: ['colors']);
        $this->assertSame([[
            'slug' => 'colors',
            'name' => 'Colors',
            'dimensions' => ['it' => 'Italian'],
            'entries' => [
                ['name' => 'red', 'value' => 'Red', 'dimensions' => ['it' => 'Rosso']],
                ['name' => 'blue', 'value' => 'Blue'],
            ],
        ]], $inline->config['datasources'] ?? null);

        $csv = new SpaceSetupExporter($this->createMockClient(...$responses()))
            ->export('680', [SpaceSetupExporter::DATASOURCES], datasources: ['colors'], entriesDirectory: 'datasources');
        $this->assertSame('datasources/colors.csv', $this->at($csv->config, ['datasources', 0, 'entries_file']));
        $this->assertSame(['datasources/colors.csv' => "name,value,it\nred,Red,Rosso\nblue,Blue,\n"], $csv->files);
    }

    #[Test]
    public function exports_roles_with_story_slugs(): void
    {
        $result = new SpaceSetupExporter($this->createMockClient(
            $this->mockResponse('list-space-roles'),
            $this->mockResponse('list-stories-folder-north'),
        ))->export('680', [SpaceSetupExporter::ROLES]);

        $this->assertSame([
            [
                'name' => 'Editors - North',
                'subtitle' => 'North region editors',
                'permissions' => ['read_stories', 'save_stories', 'view_content', 'view_folders'],
                'allowed_paths' => ['regions/north'],
            ],
            ['name' => 'Page Readers', 'permissions' => ['read_stories', 'save_stories']],
        ], $result->config['roles'] ?? null);
    }

    #[Test]
    public function exports_workflows_with_role_and_stage_names(): void
    {
        $result = new SpaceSetupExporter($this->createMockClient(
            $this->mockResponse('list-space-roles'),
            $this->mockResponse('list-workflows-custom'),
            $this->mockResponse('list-workflow-stages-article'),
            $this->mockResponse('list-workflow-stages-article'),
        ))->export('680', [SpaceSetupExporter::WORKFLOWS]);

        $this->assertSame(['name' => 'Article review', 'content_types' => ['article-page'], 'stages' => [
            ['name' => 'Writing', 'color' => '#babcb6', 'default' => true, 'roles' => ['Editors - North'], 'next' => ['Approved']],
            ['name' => 'Approved', 'color' => '#1bb300', 'allow_publish' => true, 'roles' => 'all', 'next' => 'all', 'after_publish' => 'Writing'],
        ]], $this->at($result->config, ['workflow', 'ensure', 1]));
        $this->assertSame('Default', $this->at($result->config, ['workflow', 'ensure', 0, 'name']));
        $this->assertNull($this->at($result->config, ['workflow', 'ensure', 0, 'content_types']), 'The default workflow has no content types.');
    }

    #[Test]
    public function reports_progress_for_each_step(): void
    {
        $messages = [];
        new SpaceSetupExporter(
            $this->createMockClient(
                $this->mockResponse('list-datasources'),
                $this->mockResponse('list-datasource-entries-it'),
                $this->mockResponse('list-datasource-entries'),
            ),
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        )->export('680', [SpaceSetupExporter::DATASOURCES], datasources: ['colors']);

        $this->assertSame(['Reading datasources...', '  Datasource 1/1: colors', 'Datasources: 1 exported.'], $messages);
    }

    #[Test]
    public function rejects_unknown_sections(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown section: stories');

        new SpaceSetupExporter($this->createMockClient())->export('680', ['stories']);
    }

    #[Test]
    public function yaml_output_lists_warnings_as_comments(): void
    {
        $yaml = new SpaceSetupExportResult(['version' => 1], ['Role X: not exported: field_permissions.'], [])->toYaml('Exported from space 680');

        $this->assertStringContainsString("# Exported from space 680\n# WARNING: Role X: not exported: field_permissions.\n\nversion: 1\n", $yaml);
    }

    /**
     * @param array<mixed> $data
     * @param list<int|string> $path
     */
    private function at(array $data, array $path): mixed
    {
        $current = $data;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
