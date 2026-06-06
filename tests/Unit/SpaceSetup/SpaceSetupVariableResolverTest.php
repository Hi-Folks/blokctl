<?php

declare(strict_types=1);

namespace Tests\Unit\SpaceSetup;

use Blokctl\SpaceSetup\SpaceSetupVariableResolutionException;
use Blokctl\SpaceSetup\SpaceSetupVariableResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceSetupVariableResolverTest extends TestCase
{
    #[Test]
    public function recursively_resolves_namespaced_expressions(): void
    {
        $resolver = new SpaceSetupVariableResolver([
            'inputs' => [
                'customer_name' => 'Acme',
            ],
            'space' => [
                'id' => '123456',
            ],
        ]);

        $resolved = $resolver->resolve([
            'headline' => 'Welcome to ${{ inputs.customer_name }}',
            'nested' => [
                'space_id' => '${{ space.id }}',
            ],
        ]);

        $this->assertSame([
            'headline' => 'Welcome to Acme',
            'nested' => [
                'space_id' => '123456',
            ],
        ], $resolved);
    }

    #[Test]
    public function preserves_native_type_for_full_value_expression(): void
    {
        $resolver = new SpaceSetupVariableResolver([
            'inputs' => [
                'enabled' => true,
                'position' => 4,
                'tags' => ['Demo', 'Customer'],
            ],
        ]);

        $resolved = $resolver->resolve([
            'enabled' => '${{ inputs.enabled }}',
            'position' => '${{ inputs.position }}',
            'tags' => '${{ inputs.tags }}',
        ]);

        $this->assertSame([
            'enabled' => true,
            'position' => 4,
            'tags' => ['Demo', 'Customer'],
        ], $resolved);
    }

    #[Test]
    public function reports_path_for_unknown_variable(): void
    {
        $resolver = new SpaceSetupVariableResolver([
            'inputs' => [],
        ]);

        try {
            $resolver->resolve([
                'components' => [
                    'fields' => [
                        ['field' => '${{ inputs.missing }}'],
                    ],
                ],
            ]);
            $this->fail('Expected variable resolution to fail.');
        } catch (SpaceSetupVariableResolutionException $spaceSetupVariableResolutionException) {
            $this->assertSame('$.components.fields[0].field', $spaceSetupVariableResolutionException->path);
            $this->assertSame('inputs.missing', $spaceSetupVariableResolutionException->expression);
        }
    }

    #[Test]
    public function rejects_non_scalar_value_embedded_in_text(): void
    {
        $resolver = new SpaceSetupVariableResolver([
            'inputs' => [
                'tags' => ['Demo'],
            ],
        ]);

        $this->expectException(SpaceSetupVariableResolutionException::class);
        $this->expectExceptionMessage('Embedded expression must resolve to a scalar value');

        $resolver->resolve('Tags: ${{ inputs.tags }}');
    }

    #[Test]
    public function detects_expression_usage_recursively(): void
    {
        $resolver = new SpaceSetupVariableResolver([]);

        $this->assertTrue($resolver->containsExpression(
            ['preview' => ['url' => 'token=${{ space.preview_token }}']],
            'space.preview_token',
        ));
        $this->assertFalse($resolver->containsExpression(
            ['preview' => ['url' => 'https://example.com']],
            'space.preview_token',
        ));
    }
}
