<?php

declare(strict_types=1);

namespace Tests\Unit\SpaceSetup;

use Blokctl\SpaceSetup\SpaceSetupConfigLoader;
use Blokctl\SpaceSetup\SpaceSetupConfigMerger;
use Blokctl\SpaceSetup\SpaceSetupConfigValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceSetupConfigMergerTest extends TestCase
{
    #[Test]
    public function merges_template_metadata_and_replaces_tags(): void
    {
        $merged = new SpaceSetupConfigMerger()->merge(
            ['template' => [
                'name' => 'Base Demo',
                'category' => 'general',
                'owner' => 'solutions-engineering',
                'tags' => ['base', 'multilingual'],
            ]],
            ['template' => [
                'name' => 'Retail Demo',
                'category' => 'commerce',
                'tags' => ['retail'],
            ]],
        );

        $this->assertSame([
            'name' => 'Retail Demo',
            'category' => 'commerce',
            'owner' => 'solutions-engineering',
            'tags' => ['retail'],
        ], $merged['template'] ?? null);
    }

    #[Test]
    public function merges_named_items_by_name_and_appends_new_ones(): void
    {
        $merged = new SpaceSetupConfigMerger()->merge(
            ['datasources' => [['slug' => 'themes', 'name' => 'Themes', 'entries' => [['name' => 'light', 'value' => '#fff'], ['name' => 'dark', 'value' => '#000']]]]],
            ['datasources' => [
                ['slug' => 'themes', 'entries' => [['name' => 'dark', 'value' => '#036'], ['name' => 'brand', 'value' => '#e30']]],
                ['slug' => 'countries'],
            ]],
        );

        $this->assertSame(['datasources' => [
            ['slug' => 'themes', 'name' => 'Themes', 'entries' => [['name' => 'light', 'value' => '#fff'], ['name' => 'dark', 'value' => '#036'], ['name' => 'brand', 'value' => '#e30']]],
            ['slug' => 'countries'],
        ]], $merged);
    }

    #[Test]
    public function merges_components_fields_and_component_field_entries(): void
    {
        $merged = new SpaceSetupConfigMerger()->merge(
            ['components' => [
                'ensure' => [['name' => 'promo', 'color' => '#f60', 'fields' => [['field' => 'title', 'type' => 'text'], ['field' => 'theme', 'type' => 'option']]]],
                'fields' => [['component' => 'page', 'field' => 'seo', 'type' => 'text'], ['component' => 'article', 'field' => 'seo', 'type' => 'text']],
            ]],
            ['components' => [
                'ensure' => [['name' => 'promo', 'color' => '#036', 'fields' => [['field' => 'title', 'required' => true]]]],
                'fields' => [['component' => 'article', 'field' => 'seo', 'translatable' => true]],
            ]],
        );

        $this->assertSame([
            ['name' => 'promo', 'color' => '#036', 'fields' => [['field' => 'title', 'type' => 'text', 'required' => true], ['field' => 'theme', 'type' => 'option']]],
        ], $this->at($merged, ['components', 'ensure']));
        $this->assertSame([
            ['component' => 'page', 'field' => 'seo', 'type' => 'text'],
            ['component' => 'article', 'field' => 'seo', 'type' => 'text', 'translatable' => true],
        ], $this->at($merged, ['components', 'fields']));
    }

    #[Test]
    public function concatenates_operations_and_replaces_plain_value_lists(): void
    {
        $merged = new SpaceSetupConfigMerger()->merge(
            [
                'stories' => ['insert_component' => [['slug' => 'home', 'component' => ['component' => 'hero']]], 'publish' => ['slugs' => ['home', 'about']]],
                'roles' => [['name' => 'Editors', 'permissions' => ['read_stories', 'save_stories']]],
            ],
            [
                'stories' => ['insert_component' => [['slug' => 'home', 'component' => ['component' => 'banner']]], 'publish' => ['slugs' => ['home']]],
                'roles' => [['name' => 'Editors', 'permissions' => ['read_stories']]],
            ],
        );

        $this->assertSame(
            [['slug' => 'home', 'component' => ['component' => 'hero']], ['slug' => 'home', 'component' => ['component' => 'banner']]],
            $this->at($merged, ['stories', 'insert_component']),
            'Two inserts on the same story are both kept.',
        );
        $this->assertSame(['home'], $this->at($merged, ['stories', 'publish', 'slugs']));
        $this->assertSame([['name' => 'Editors', 'permissions' => ['read_stories']]], $merged['roles'] ?? null);
    }

    #[Test]
    public function loader_merges_nested_extends_with_paths_relative_to_each_file(): void
    {
        $config = new SpaceSetupConfigLoader()->load(__DIR__ . '/../../Fixtures/composition/main.yaml');

        $this->assertArrayNotHasKey('extends', $config);
        $this->assertSame([
            ['name' => 'Editors', 'permissions' => ['read_stories', 'save_stories']],
            ['name' => 'Reviewers'],
        ], $config['roles'] ?? null);
        $this->assertSame(realpath(__DIR__ . '/../../Fixtures/composition/data/colors.csv'), $this->at($config, ['datasources', 0, 'entries_file']));
    }

    #[Test]
    public function loader_skips_extends_entries_whose_condition_is_false(): void
    {
        $config = new SpaceSetupConfigLoader()->load(__DIR__ . '/../../Fixtures/composition/conditional.yaml');

        $this->assertArrayNotHasKey('roles', $config);
        $this->assertArrayNotHasKey('datasources', $config);
    }

    #[Test]
    public function loader_uses_set_overrides_and_env_variables_in_conditions(): void
    {
        $_ENV['BLOKCTL_TEST_WITH_DATASOURCES'] = 'yes';

        try {
            $config = new SpaceSetupConfigLoader()->load(__DIR__ . '/../../Fixtures/composition/conditional.yaml', ['with_roles=true']);
        } finally {
            unset($_ENV['BLOKCTL_TEST_WITH_DATASOURCES']);
        }

        $this->assertSame([['name' => 'Editors', 'permissions' => ['read_stories']]], $this->at($config, ['roles']));
        $this->assertSame('colors', $this->at($config, ['datasources', 0, 'slug']));
    }

    #[Test]
    public function loader_uses_from_env_inputs_in_conditions(): void
    {
        $_ENV['BLOKCTL_TEST_WITH_ROLES'] = 'true';

        try {
            $config = new SpaceSetupConfigLoader()->load(__DIR__ . '/../../Fixtures/composition/conditional.yaml');
        } finally {
            unset($_ENV['BLOKCTL_TEST_WITH_ROLES']);
        }

        $this->assertSame([['name' => 'Editors', 'permissions' => ['read_stories']]], $this->at($config, ['roles']));
    }

    #[Test]
    public function loader_reports_conditions_that_use_undeclared_inputs(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'blokctl-when-') . '.yaml';
        file_put_contents($path, "version: 1\nextends:\n  - path: base.yaml\n    when: \${{ inputs.missing }}\n");

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Cannot evaluate the condition for extends base.yaml');
            new SpaceSetupConfigLoader()->load($path);
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function loader_rejects_extends_loops(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Setup configuration extends itself');

        new SpaceSetupConfigLoader()->load(__DIR__ . '/../../Fixtures/composition/loop-a.yaml');
    }

    #[Test]
    public function loader_reports_missing_base_files(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'blokctl-extends-') . '.yaml';
        file_put_contents($path, "version: 1\nextends: missing.yaml\n");

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Setup configuration file not found');
            new SpaceSetupConfigLoader()->load($path);
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function composition_example_validates(): void
    {
        $config = new SpaceSetupConfigLoader()->load('examples/composition/customer-acme.yaml');

        $result = new SpaceSetupConfigValidator()->validate($config);

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors));
        $this->assertSame('#003366', $this->at($config, ['components', 'ensure', 0, 'color']));
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
