<?php

declare(strict_types=1);

namespace Tests\Unit\SpaceSetup;

use Blokctl\SpaceSetup\SpaceSetupContentChecker;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class SpaceSetupContentCheckerTest extends TestCase
{
    public function testItReportsWhetherTheConfigContainsCheckableStoryOperations(): void
    {
        $this->assertFalse(new SpaceSetupContentChecker(['stories' => ['move' => []]])->hasContentToCheck());
        $this->assertTrue(new SpaceSetupContentChecker(['stories' => ['insert_component' => [['component' => []]]]])->hasContentToCheck());
    }

    public function testItChecksFieldsTypesRequiredFieldsAndNestedBlocks(): void
    {
        $result = new SpaceSetupContentChecker([
            'components' => ['ensure' => [
                ['name' => 'page', 'fields' => [
                    ['field' => 'title', 'type' => 'text', 'required' => true],
                    ['field' => 'body', 'type' => 'bloks', 'properties' => [
                        'restrict_components' => true,
                        'component_whitelist' => ['hero'],
                    ]],
                ]],
                ['name' => 'hero', 'fields' => [
                    ['field' => 'headline', 'type' => 'text', 'required' => true],
                ]],
            ]],
            'stories' => ['create' => [[
                'name' => 'Home',
                'content' => [
                    'component' => 'page',
                    'titel' => 'Hello',
                    'body' => [
                        ['component' => 'hero', 'headline' => 42],
                        ['component' => 'cta'],
                    ],
                ],
            ]]],
        ])->withOnlyConfiguredComponents()->check();

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('did you mean "title"?', implode("\n", $result->errors));
        $this->assertStringContainsString('required field "title"', implode("\n", $result->errors));
        $this->assertStringContainsString('needs text', implode("\n", $result->errors));
        $this->assertStringContainsString('component "cta" is not allowed', implode("\n", $result->errors));
        $this->assertStringContainsString('unknown component "cta"', implode("\n", $result->errors));
    }

    public function testStandaloneFieldsAndPartialUpdatesAreChecked(): void
    {
        $result = new SpaceSetupContentChecker([
            'components' => ['fields' => [
                ['component' => 'page', 'field' => 'title', 'type' => 'text', 'required' => true],
                ['component' => 'page', 'field' => 'featured', 'type' => 'boolean'],
            ]],
            'stories' => ['update' => [[
                'slug' => 'home',
                'components' => [[
                    'path' => 'content',
                    'component' => 'page',
                    'fields' => ['featured' => 'yes'],
                ]],
            ]]],
        ])->check();

        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('needs true or false', $result->errors[0]);
        $this->assertStringNotContainsString('required field', $result->errors[0]);
    }

    public function testItReadsContentFilesRelativeToTheConfig(): void
    {
        $directory = sys_get_temp_dir() . '/blokctl-content-check-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/story.json', '{"component":"page","title":"Hello"}');

        try {
            $result = new SpaceSetupContentChecker([
                'components' => ['ensure' => [[
                    'name' => 'page',
                    'fields' => [['field' => 'title', 'type' => 'text']],
                ]]],
                'stories' => ['create' => [['name' => 'Home', 'content_file' => 'story.json']]],
            ])->withOnlyConfiguredComponents()->check($directory);

            $this->assertTrue($result->isValid());
        } finally {
            unlink($directory . '/story.json');
            rmdir($directory);
        }
    }

    public function testUnknownComponentsAreWarningsUntilSpaceDefinitionsAreLoaded(): void
    {
        $config = ['stories' => ['create' => [[
            'name' => 'Home',
            'content' => ['component' => 'page', 'title' => 'Hello'],
        ]]]];

        $local = new SpaceSetupContentChecker($config)->check();
        $this->assertTrue($local->isValid());
        $this->assertSame(['page'], $local->uncheckedComponents);

        $response = new MockResponse(json_encode(['components' => [[
            'name' => 'page',
            'schema' => ['title' => ['type' => 'text'], 'settings' => ['type' => 'tab']],
        ]]], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        $withSpace = new SpaceSetupContentChecker($config)
            ->withSpace($this->createMockClient($response), '680')
            ->check();

        $this->assertTrue($withSpace->isValid());
        $this->assertSame([], $withSpace->uncheckedComponents);
    }

    public function testRuntimeDirectivesAreNotTypeChecked(): void
    {
        $result = new SpaceSetupContentChecker([
            'components' => ['ensure' => [[
                'name' => 'page',
                'fields' => [
                    ['field' => 'title', 'type' => 'text'],
                    ['field' => 'image', 'type' => 'asset'],
                ],
            ]]],
            'stories' => ['create' => [[
                'name' => 'Home',
                'content' => [
                    'component' => 'page',
                    'title' => '${{ inputs.title }}',
                    'image' => ['asset' => ['_ref' => 'logo']],
                ],
            ]]],
        ])->withOnlyConfiguredComponents()->check();

        $this->assertTrue($result->isValid());
    }
}
