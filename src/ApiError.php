<?php

declare(strict_types=1);

namespace Blokctl;

use Storyblok\ManagementApi\Response\StoryblokResponseInterface;

/**
 * Readable error messages for failed Management API responses.
 *
 * The client's getErrorMessage() only reads an "error" key and otherwise returns a
 * generic text per status code. Validation errors (422) carry the real reason in
 * the body, for example {"name": ["has already been taken"]}. This reads the body
 * without throwing and includes those details.
 */
final class ApiError
{
    public static function message(StoryblokResponseInterface $response): string
    {
        $fallback = $response->getErrorMessage();

        try {
            $body = $response->getResponseBody();
        } catch (\Throwable) {
            return $fallback;
        }

        $details = self::details($body);
        if ($details === []) {
            return $fallback;
        }

        return implode('; ', $details);
    }

    /**
     * Error messages found in a response body: "base" messages and plain strings as-is,
     * field errors as "field: message".
     *
     * @return list<string>
     */
    public static function details(string $body): array
    {
        if ($body === '') {
            return [];
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (is_string($payload)) {
            return $payload === '' ? [] : [$payload];
        }

        if (!is_array($payload)) {
            return [];
        }

        $messages = [];
        foreach ($payload as $field => $errors) {
            foreach (is_array($errors) ? $errors : [$errors] as $error) {
                if (!is_string($error) || $error === '') {
                    continue;
                }

                $messages[] = is_int($field) || $field === 'base' || $field === 'error'
                    ? $error
                    : $field . ': ' . $error;
            }
        }

        return $messages;
    }
}
