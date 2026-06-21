<?php

declare(strict_types=1);

namespace Tests\Unit\SpaceSetup;

use Blokctl\SpaceSetup\SpaceSetupConfigLoader;
use Blokctl\SpaceSetup\SpaceSetupConfigValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceSetupConfigValidatorTest extends TestCase
{
    #[Test]
    public function validates_example_yaml_configuration(): void
    {
        $config = new SpaceSetupConfigLoader()->load('examples/demo-space.yaml');

        $result = new SpaceSetupConfigValidator()->validate($config);

        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function validates_multi_country_example_yaml_configuration(): void
    {
        $config = new SpaceSetupConfigLoader()->load('examples/multi-country-space.yaml');

        $result = new SpaceSetupConfigValidator()->validate($config);

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors));
    }

    #[Test]
    public function validates_assets_example_yaml_configuration(): void
    {
        $config = new SpaceSetupConfigLoader()->load('examples/assets-space.yaml');

        $result = new SpaceSetupConfigValidator()->validate($config);

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors));
    }

    #[Test]
    public function rejects_configuration_without_version(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'apps' => [
                'install' => ['backups'],
            ],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertErrorsContain($result->errors, 'version');
    }

    #[Test]
    public function rejects_unknown_properties(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'apps' => [
                'continue_on_eror' => true,
                'install' => ['backups'],
            ],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertErrorsContain($result->errors, '$.apps');
        $this->assertErrorsContain($result->errors, 'continue_on_eror');
    }

    #[Test]
    public function rejects_missing_required_component_field_type(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'components' => [
                'fields' => [
                    [
                        'component' => 'article-page',
                        'field' => 'SEO',
                    ],
                ],
            ],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertErrorsContain($result->errors, '$.components.fields[0]');
        $this->assertErrorsContain($result->errors, 'type');
    }

    #[Test]
    public function rejects_invalid_property_types(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'demo_mode' => [
                'remove' => 'yes',
            ],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertErrorsContain($result->errors, '$.demo_mode.remove');
        $this->assertErrorsContain($result->errors, 'boolean');
    }

    #[Test]
    public function rejects_enabling_and_removing_demo_mode(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'space' => [
                'name' => 'Customer Demo',
                'duplicate_from' => '286863409930127',
                'demo' => true,
            ],
            'demo_mode' => [
                'remove' => true,
            ],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertErrorsContain($result->errors, '$.space.demo');
        $this->assertErrorsContain($result->errors, 'demo_mode.remove');
    }

    #[Test]
    public function rejects_space_duplication_without_a_name(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'space' => [
                'duplicate_from' => '286863409930127',
            ],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertErrorsContain($result->errors, '$.space');
        $this->assertErrorsContain($result->errors, 'name');
    }

    #[Test]
    public function accepts_space_configuration_without_duplication(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'space' => [
                'name' => 'Existing Customer Demo',
            ],
            'apps' => [
                'install' => ['backups'],
            ],
        ]);

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function accepts_explicit_blank_space_creation(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'space' => [
                'create_new' => true,
                'name' => 'Customer Demo',
            ],
        ]);

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function rejects_blank_space_creation_without_a_name(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'space' => [
                'create_new' => true,
            ],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertErrorsContain($result->errors, '$.space');
        $this->assertErrorsContain($result->errors, 'required');
    }

    #[Test]
    public function rejects_blank_space_creation_with_duplication(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'space' => [
                'create_new' => true,
                'name' => 'Customer Demo',
                'duplicate_from' => '286863409930127',
            ],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertErrorsContain($result->errors, '$.space');
        $this->assertErrorsContain($result->errors, 'must not match schema');
    }

    #[Test]
    public function accepts_space_readiness_settings(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'space' => [
                'name' => 'Customer Demo',
                'duplicate_from' => '286863409930127',
                'readiness' => [
                    'timeout_seconds' => 180,
                    'poll_interval_seconds' => 3,
                ],
            ],
        ]);

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function rejects_non_positive_space_readiness_settings(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'space' => [
                'name' => 'Customer Demo',
                'duplicate_from' => '286863409930127',
                'readiness' => [
                    'timeout_seconds' => 0,
                ],
            ],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertErrorsContain($result->errors, '$.space.readiness.timeout_seconds');
        $this->assertErrorsContain($result->errors, 'greater than or equal to 1');
    }

    #[Test]
    public function accepts_reconcile_execution_mode(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'execution' => [
                'mode' => 'reconcile',
                'continue_on_error' => false,
            ],
        ]);

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function accepts_multi_country_provisioning_configuration(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'folders' => [
                'ensure' => [
                    ['name' => 'Global', 'slug' => 'global'],
                    ['name' => 'Italy', 'slug' => 'italy'],
                ],
            ],
            'stories' => [
                'move' => [
                    [
                        'select' => [
                            'parent' => 'root',
                            'include_folders' => true,
                            'exclude_slugs' => ['site-config'],
                        ],
                        'to_folder' => 'global',
                    ],
                ],
            ],
            'apps' => [
                'install' => [
                    ['slug' => 'dimensions', 'id' => 24],
                ],
            ],
            'dimensions' => [
                'folders' => [
                    ['slug' => 'global'],
                    ['slug' => 'italy', 'ai_translation_code' => 'it'],
                ],
            ],
        ]);

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors));
    }

    #[Test]
    public function accepts_local_asset_directory_upload_configuration(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'assets' => [
                'upload_directory' => [
                    [
                        'source' => './demo-assets/brand',
                        'target_folder' => 'Brand',
                        'recursive' => true,
                        'include' => ['*.png', '*.jpg'],
                        'on_existing' => 'skip',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors));
    }

    #[Test]
    public function accepts_asset_conversion_to_global_configuration(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'assets' => [
                'convert_to_global' => [
                    [
                        'asset_ids' => [123, 456],
                        'target_shared_folder_id' => 987,
                    ],
                    [
                        'source_folder_name' => 'Brand',
                        'target_shared_folder_id' => 987,
                        'filters' => [
                            'filetype' => 'image',
                            'extensions' => ['jpg', 'png', 'webp'],
                            'tags' => ['approved'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors));
    }

    #[Test]
    public function accepts_storyblok_ai_configuration(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'ai' => [
                'enabled' => true,
                'inherit_org_configuration' => false,
            ],
        ]);

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors));
    }

    #[Test]
    public function accepts_ai_translation_configuration(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'ai_translation' => [
                'disclaimer_id' => 173657768407244,
            ],
        ]);

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors));
    }

    #[Test]
    public function accepts_story_update_and_create_configuration(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'stories' => [
                'update' => [
                    [
                        'slug' => 'home',
                        'components' => [
                            [
                                'path' => 'content.body[0]',
                                'component' => 'hero-section',
                                'fields' => [
                                    'eyebrow' => 'Welcome to',
                                ],
                            ],
                            [
                                'path' => 'content.body[0].headline[0]',
                                'component' => 'headline-segment',
                                'fields' => [
                                    'text' => '${{ inputs.customer_name }} Demo Space!',
                                ],
                            ],
                        ],
                    ],
                ],
                'create' => [
                    [
                        'name' => 'Landing Page',
                        'slug' => 'landing',
                        'content' => [
                            'component' => 'default-page',
                            'body' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors));
    }

    #[Test]
    public function accepts_specific_workflow_assignments(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'workflow' => [
                'assign' => [
                    [
                        'stories' => [
                            'slugs' => ['home', 'about'],
                            'ids' => [123],
                        ],
                        'workflow' => 'Default',
                        'stage' => 'Drafting',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result->isValid(), implode(' | ', $result->errors));
    }

    #[Test]
    public function rejects_story_creation_with_parent_id_and_parent_slug(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'stories' => [
                'create' => [
                    [
                        'name' => 'Landing Page',
                        'parent_id' => 123,
                        'parent_slug' => 'campaigns',
                        'content' => [
                            'component' => 'default-page',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertErrorsContain($result->errors, '$.stories.create[0]');
        $this->assertErrorsContain($result->errors, 'must not match schema');
    }

    #[Test]
    public function rejects_unsupported_existing_asset_behavior(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'assets' => [
                'upload_directory' => [
                    [
                        'source' => './demo-assets/brand',
                        'target_folder' => 'Brand',
                        'on_existing' => 'replace',
                    ],
                ],
            ],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertErrorsContain($result->errors, '$.assets.upload_directory[0].on_existing');
    }

    #[Test]
    public function rejects_story_moves_without_a_root_selector(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'stories' => [
                'move' => [
                    [
                        'select' => [
                            'parent' => 'anywhere',
                        ],
                        'to_folder' => 'global',
                    ],
                ],
            ],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertErrorsContain($result->errors, '$.stories.move[0].select.parent');
    }

    #[Test]
    public function rejects_unsupported_execution_modes(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'execution' => [
                'mode' => 'replace',
            ],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertErrorsContain($result->errors, '$.execution.mode');
        $this->assertErrorsContain($result->errors, 'const');
    }

    #[Test]
    public function accepts_namespaced_expressions_before_resolution(): void
    {
        $result = new SpaceSetupConfigValidator()->validate([
            'version' => 1,
            'inputs' => [
                'enabled' => [
                    'default' => true,
                ],
                'position' => [
                    'default' => 3,
                ],
            ],
            'preview' => [
                'enabled' => '${{ inputs.enabled }}',
                'default' => 'https://${{ env.FRONTEND_HOST }}/?space=${{ space.id }}',
            ],
            'components' => [
                'fields' => [
                    [
                        'component' => 'page',
                        'field' => 'title',
                        'type' => 'text',
                        'pos' => '${{ inputs.position }}',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result->isValid());
    }

    /**
     * @param string[] $errors
     */
    private function assertErrorsContain(array $errors, string $expected): void
    {
        foreach ($errors as $error) {
            if (str_contains($error, $expected)) {
                $this->addToAssertionCount(1);
                return;
            }
        }

        $this->fail(
            'Failed asserting that validation errors contain "'
            . $expected
            . '". Errors: '
            . implode(' | ', $errors),
        );
    }
}
