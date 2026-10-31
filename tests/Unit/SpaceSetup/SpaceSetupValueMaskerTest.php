<?php

declare(strict_types=1);

namespace Tests\Unit\SpaceSetup;

use Blokctl\SpaceSetup\SpaceSetupValueMasker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceSetupValueMaskerTest extends TestCase
{
    #[Test]
    public function masks_token_query_parameters(): void
    {
        $masker = new SpaceSetupValueMasker();

        $this->assertSame(
            'https://example.com/?token=********&path=/home',
            $masker->mask('https://example.com/?token=secret-token&path=/home'),
        );
        $this->assertSame(
            'https://example.com/?foo=bar&token=********',
            $masker->mask('https://example.com/?foo=bar&token=secret-token'),
        );
    }

    #[Test]
    public function leaves_values_without_tokens_unchanged(): void
    {
        $masker = new SpaceSetupValueMasker();

        $this->assertSame(
            'https://example.com/?path=/home',
            $masker->mask('https://example.com/?path=/home'),
        );
    }

    #[Test]
    public function masks_declared_secrets_recursively_and_inside_strings(): void
    {
        $masker = new SpaceSetupValueMasker(['secret-value', 123, 'x']);

        $this->assertSame('Authorization: Bearer ********', $masker->mask('Authorization: Bearer secret-value'));
        $this->assertSame([
            'token' => '********',
            'number' => '********',
            'short_exact' => '********',
            'short_embedded' => 'prefix-x',
        ], $masker->maskValue([
            'token' => 'secret-value',
            'number' => 123,
            'short_exact' => 'x',
            'short_embedded' => 'prefix-x',
        ]));
    }
}
