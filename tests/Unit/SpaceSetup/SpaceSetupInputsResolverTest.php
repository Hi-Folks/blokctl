<?php

declare(strict_types=1);

namespace Tests\Unit\SpaceSetup;

use Blokctl\SpaceSetup\SpaceSetupInputsResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceSetupInputsResolverTest extends TestCase
{
    #[Test]
    public function resolves_defaults_and_cli_overrides(): void
    {
        $inputs = new SpaceSetupInputsResolver()->resolve([
            'inputs' => [
                'customer_name' => [
                    'required' => true,
                ],
                'enabled' => [
                    'default' => false,
                ],
                'count' => [
                    'default' => 1,
                ],
            ],
        ], [
            'customer_name=Acme',
            'enabled=true',
            'count=5',
        ]);

        $this->assertSame([
            'enabled' => true,
            'count' => 5,
            'customer_name' => 'Acme',
        ], $inputs);
    }

    #[Test]
    public function rejects_missing_required_input(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Required setup input is missing: customer_name');

        new SpaceSetupInputsResolver()->resolve([
            'inputs' => [
                'customer_name' => [
                    'required' => true,
                ],
            ],
        ], []);
    }

    #[Test]
    public function rejects_unknown_override(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown setup input: missing');

        new SpaceSetupInputsResolver()->resolve([
            'inputs' => [],
        ], ['missing=value']);
    }

    #[Test]
    public function rejects_invalid_override_format(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected NAME=VALUE');

        new SpaceSetupInputsResolver()->resolve([
            'inputs' => [],
        ], ['invalid']);
    }

    #[Test]
    public function resolves_environment_values_between_defaults_and_cli_overrides(): void
    {
        $resolver = new SpaceSetupInputsResolver();
        $config = [
            'inputs' => [
                'api_key' => [
                    'default' => 'default-key',
                    'from_env' => 'DEMO_API_KEY',
                    'secret' => true,
                ],
            ],
        ];

        $fromEnvironment = $resolver->resolveWithSecrets($config, [], ['DEMO_API_KEY' => 'environment-key']);
        $fromCli = $resolver->resolveWithSecrets($config, ['api_key=cli-key'], ['DEMO_API_KEY' => 'environment-key']);

        $this->assertSame(['api_key' => 'environment-key'], $fromEnvironment->values);
        $this->assertContains('default-key', $fromEnvironment->secretValues);
        $this->assertContains('environment-key', $fromEnvironment->secretValues);
        $this->assertSame(['api_key' => 'cli-key'], $fromCli->values);
        $this->assertContains('cli-key', $fromCli->secretValues);
    }

    #[Test]
    public function required_environment_input_reports_its_variable_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Required setup input is missing: api_key (environment variable DEMO_API_KEY)');

        new SpaceSetupInputsResolver()->resolve([
            'inputs' => [
                'api_key' => [
                    'required' => true,
                    'from_env' => 'DEMO_API_KEY',
                ],
            ],
        ], [], []);
    }
}
