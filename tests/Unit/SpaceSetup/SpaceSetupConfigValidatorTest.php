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
        $config = (new SpaceSetupConfigLoader())->load('examples/demo-space.yaml');

        $result = (new SpaceSetupConfigValidator())->validate($config);

        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function rejects_configuration_without_version(): void
    {
        $result = (new SpaceSetupConfigValidator())->validate([
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
        $result = (new SpaceSetupConfigValidator())->validate([
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
        $result = (new SpaceSetupConfigValidator())->validate([
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
        $result = (new SpaceSetupConfigValidator())->validate([
            'version' => 1,
            'demo_mode' => [
                'remove' => 'yes',
            ],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertErrorsContain($result->errors, '$.demo_mode.remove');
        $this->assertErrorsContain($result->errors, 'boolean');
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
