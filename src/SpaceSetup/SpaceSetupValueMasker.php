<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final readonly class SpaceSetupValueMasker
{
    public const string PLACEHOLDER = '********';

    /** @var list<mixed> */
    private array $secretValues;

    /** @var list<string> */
    private array $embeddedSecrets;

    /** @param list<mixed> $secretValues */
    public function __construct(array $secretValues = [])
    {
        $this->secretValues = array_values(array_filter(
            $secretValues,
            static fn(mixed $value): bool => !in_array($value, [null, '', []], true),
        ));
        $strings = array_values(array_filter(
            $this->secretValues,
            static fn(mixed $value): bool => is_string($value) && strlen($value) >= 3 && $value !== self::PLACEHOLDER,
        ));
        usort($strings, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
        $this->embeddedSecrets = array_values(array_unique($strings));
    }

    public function mask(string $value): string
    {
        $masked = (string) preg_replace(
            '/([?&]token=)[^&\s]+/i',
            '$1' . self::PLACEHOLDER,
            $value,
        );

        return str_replace($this->embeddedSecrets, self::PLACEHOLDER, $masked);
    }

    public function maskValue(mixed $value): mixed
    {
        if (array_any($this->secretValues, static fn(mixed $secret): bool => $secret === $value)) {
            return self::PLACEHOLDER;
        }

        if (is_string($value)) {
            return $this->mask($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $masked = [];
        foreach ($value as $key => $item) {
            $masked[$key] = $this->maskValue($item);
        }

        return $masked;
    }
}
