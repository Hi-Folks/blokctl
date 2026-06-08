<?php

declare(strict_types=1);

namespace Tests\Unit;

use Blokctl\ManagementAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ManagementAccessTokenTest extends TestCase
{
    #[Test]
    public function returns_the_configured_token(): void
    {
        $this->assertSame(
            'management-token',
            ManagementAccessToken::fromEnvironment([
                ManagementAccessToken::ENV_NAME => 'management-token',
            ]),
        );
    }

    /**
     * @param array<string, mixed> $environment
     */
    #[Test]
    #[DataProvider('missingTokenEnvironments')]
    public function explains_how_to_configure_a_missing_token(array $environment): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storyblok Management API access token is missing.');
        $this->expectExceptionMessage('SECRET_KEY=your-scoped-personal-access-token');
        $this->expectExceptionMessage(ManagementAccessToken::SCOPED_TOKEN_URL);
        $this->expectExceptionMessage(ManagementAccessToken::DOCUMENTATION_URL);

        ManagementAccessToken::fromEnvironment($environment);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function missingTokenEnvironments(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty' => [[ManagementAccessToken::ENV_NAME => '']];
        yield 'whitespace' => [[ManagementAccessToken::ENV_NAME => '  ']];
        yield 'non-string' => [[ManagementAccessToken::ENV_NAME => 123]];
    }
}
