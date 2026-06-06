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
        $inputs = (new SpaceSetupInputsResolver())->resolve([
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

        (new SpaceSetupInputsResolver())->resolve([
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

        (new SpaceSetupInputsResolver())->resolve([
            'inputs' => [],
        ], ['missing=value']);
    }

    #[Test]
    public function rejects_invalid_override_format(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected NAME=VALUE');

        (new SpaceSetupInputsResolver())->resolve([
            'inputs' => [],
        ], ['invalid']);
    }
}
