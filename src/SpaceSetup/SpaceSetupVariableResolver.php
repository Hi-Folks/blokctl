<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final readonly class SpaceSetupVariableResolver
{
    private const string EXPRESSION_PATTERN = '/\$\{\{\s*([A-Za-z_][A-Za-z0-9_.]*)\s*}}/';

    /**
     * @param array<string, mixed> $contexts
     */
    public function __construct(
        private array $contexts,
    ) {}

    public function resolve(mixed $value, string $path = '$'): mixed
    {
        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $key => $item) {
                $resolved[$key] = $this->resolve($item, $this->childPath($path, $key));
            }

            return $resolved;
        }

        if (!is_string($value)) {
            return $value;
        }

        if (preg_match('/^\$\{\{\s*([A-Za-z_][A-Za-z0-9_.]*)\s*}}$/', $value, $matches) === 1) {
            return $this->resolveExpression($matches[1], $path);
        }

        return preg_replace_callback(
            self::EXPRESSION_PATTERN,
            function (array $matches) use ($path): string {
                $resolved = $this->resolveExpression($matches[1], $path);
                if (!is_scalar($resolved) && $resolved !== null) {
                    throw new SpaceSetupVariableResolutionException(
                        $path,
                        $matches[1],
                        'Embedded expression must resolve to a scalar value',
                    );
                }

                return $resolved === null ? '' : (string) $resolved;
            },
            $value,
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function resolveConfig(array $config): array
    {
        $resolved = $this->resolve($config);
        if (!is_array($resolved)) {
            throw new \RuntimeException('Resolved setup configuration must contain an object at the top level.');
        }

        foreach (array_keys($resolved) as $key) {
            if (!is_string($key)) {
                throw new \RuntimeException('Resolved setup configuration must contain string top-level keys.');
            }
        }

        /** @var array<string, mixed> $resolved */
        return $resolved;
    }

    public function containsExpression(mixed $value, string $expression): bool
    {
        if (is_array($value)) {
            return array_any($value, fn($item): bool => $this->containsExpression($item, $expression));
        }

        return is_string($value)
            && preg_match(
                '/\$\{\{\s*' . preg_quote($expression, '/') . '\s*}}/',
                $value,
            ) === 1;
    }

    private function resolveExpression(string $expression, string $path): mixed
    {
        $segments = explode('.', $expression);
        $contextName = array_shift($segments);
        if ($contextName === null || !array_key_exists($contextName, $this->contexts)) {
            throw new SpaceSetupVariableResolutionException(
                $path,
                $expression,
                'Unknown variable context',
            );
        }

        $value = $this->contexts[$contextName];
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                throw new SpaceSetupVariableResolutionException(
                    $path,
                    $expression,
                    'Unknown variable',
                );
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function childPath(string $path, int|string $key): string
    {
        if (is_int($key)) {
            return $path . '[' . $key . ']';
        }

        return $path . '.' . $key;
    }
}
