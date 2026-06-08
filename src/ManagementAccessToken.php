<?php

declare(strict_types=1);

namespace Blokctl;

final class ManagementAccessToken
{
    public const string ENV_NAME = 'SECRET_KEY';

    public const string DOCUMENTATION_URL = 'https://www.storyblok.com/docs/concepts/access-tokens#management-api-access-tokens';

    public const string SCOPED_TOKEN_URL = 'https://www.storyblok.com/cl/scoped-personal-access-tokens';

    /**
     * @param array<string, mixed> $environment
     */
    public static function fromEnvironment(array $environment): string
    {
        $token = $environment[self::ENV_NAME] ?? null;
        if (!is_string($token) || trim($token) === '') {
            throw new \RuntimeException(self::missingTokenMessage());
        }

        return $token;
    }

    private static function missingTokenMessage(): string
    {
        return implode(PHP_EOL . PHP_EOL, [
            'Storyblok Management API access token is missing.',
            'Set the ' . self::ENV_NAME . ' environment variable or add it to your .env file:' . PHP_EOL
                . '  ' . self::ENV_NAME . '=your-scoped-personal-access-token',
            'Create a scoped Personal Access Token with Management API access:' . PHP_EOL
                . '  ' . self::SCOPED_TOKEN_URL,
            'Management API access token documentation:' . PHP_EOL
                . '  ' . self::DOCUMENTATION_URL,
        ]);
    }
}
