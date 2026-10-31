<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final class SpaceSetupInputsResolver
{
    /**
     * @param array<string, mixed> $config
     * @param list<string> $overrides
     * @param array<string, mixed>|null $environment
     * @return array<string, mixed>
     */
    public function resolve(array $config, array $overrides, array|null $environment = null): array
    {
        return $this->resolveWithSecrets($config, $overrides, $environment)->values;
    }

    /**
     * Resolve inputs and retain the values that must be masked from all output.
     * Precedence is CLI override, from_env, then default.
     *
     * @param array<string, mixed> $config
     * @param list<string> $overrides
     * @param array<string, mixed>|null $environment
     */
    public function resolveWithSecrets(
        array $config,
        array $overrides,
        array|null $environment = null,
        bool $requireRequired = true,
    ): SpaceSetupInputsResult {
        $definitions = $config['inputs'] ?? [];
        if (!is_array($definitions)) {
            $definitions = [];
        }

        $environment ??= $this->environment();
        $values = [];
        $secretValues = [];
        foreach ($definitions as $name => $definition) {
            if (!is_string($name)) {
                continue;
            }

            if (!is_array($definition)) {
                continue;
            }

            if (array_key_exists('default', $definition)) {
                $values[$name] = $definition['default'];
                if (($definition['secret'] ?? false) === true) {
                    $secretValues[] = $definition['default'];
                }
            }

            $environmentName = $definition['from_env'] ?? null;
            if (is_string($environmentName) && array_key_exists($environmentName, $environment)) {
                $values[$name] = $environment[$environmentName];
            }
        }

        foreach ($overrides as $override) {
            [$name, $value] = $this->parseOverride($override);
            if (!array_key_exists($name, $definitions)) {
                throw new \RuntimeException('Unknown setup input: ' . $name);
            }

            $values[$name] = $value;
        }

        foreach ($definitions as $name => $definition) {
            if (is_string($name) && is_array($definition) && ($definition['secret'] ?? false) === true && array_key_exists($name, $values)) {
                $secretValues[] = $values[$name];
            }
        }

        foreach ($definitions as $name => $definition) {
            if (!is_string($name)) {
                continue;
            }

            if (!is_array($definition)) {
                continue;
            }

            if ($requireRequired && ($definition['required'] ?? false) === true && !array_key_exists($name, $values)) {
                $environmentName = $definition['from_env'] ?? null;
                $source = is_string($environmentName) ? ' (environment variable ' . $environmentName . ')' : '';
                throw new \RuntimeException('Required setup input is missing: ' . $name . $source);
            }
        }

        return new SpaceSetupInputsResult($values, $this->uniqueValues($secretValues));
    }

    /**
     * @return array{string, mixed}
     */
    private function parseOverride(string $override): array
    {
        $parts = explode('=', $override, 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            throw new \RuntimeException('Invalid --set value. Expected NAME=VALUE.');
        }

        return [$parts[0], $this->parseValue($parts[1])];
    }

    private function parseValue(string $value): mixed
    {
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }
    }

    /** @return array<string, mixed> */
    private function environment(): array
    {
        $environment = getenv();

        return array_merge(is_array($environment) ? $environment : [], $_ENV);
    }

    /**
     * @param list<mixed> $values
     * @return list<mixed>
     */
    private function uniqueValues(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            if (!array_any($unique, static fn(mixed $known): bool => $known === $value)) {
                $unique[] = $value;
            }
        }

        return $unique;
    }
}
