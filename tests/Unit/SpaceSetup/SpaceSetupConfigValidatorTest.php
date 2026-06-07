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
