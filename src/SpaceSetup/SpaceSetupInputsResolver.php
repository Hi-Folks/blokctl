<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final class SpaceSetupInputsResolver
{
    /**
     * @param array<string, mixed> $config
     * @param string[] $overrides
     * @return array<string, mixed>
     */
    public function resolve(array $config, array $overrides): array
    {
        $definitions = $config['inputs'] ?? [];
        if (!is_array($definitions)) {
            $definitions = [];
        }

        $values = [];
        foreach ($definitions as $name => $definition) {
            if (!is_string($name)) {
                continue;
            }

            if (!is_array($definition)) {
                continue;
            }

            if (array_key_exists('default', $definition)) {
                $values[$name] = $definition['default'];
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
            if (!is_string($name)) {
                continue;
            }

            if (!is_array($definition)) {
                continue;
            }

            if (($definition['required'] ?? false) === true && !array_key_exists($name, $values)) {
                throw new \RuntimeException('Required setup input is missing: ' . $name);
            }
        }

        return $values;
    }

    /**
     * @return array{string, mixed}
     */
    private function parseOverride(string $override): array
    {
        $parts = explode('=', $override, 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            throw new \RuntimeException('Invalid --set value "' . $override . '". Expected NAME=VALUE.');
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
}
