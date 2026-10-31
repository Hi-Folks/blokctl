<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

use Symfony\Component\Yaml\Yaml;

final class SpaceSetupConfigLoader
{
    /**
     * Load a setup configuration. Files listed in `extends` are loaded first (paths
     * relative to the file that lists them), merged in order, and the file itself is
     * merged last. The returned configuration has no `extends` key.
     *
     * An `extends` entry can be `{path, when}`: the file is used only when `when` is true.
     * `when` is a boolean or a `${{ inputs.NAME }}` / `${{ env.NAME }}` expression; inputs
     * come from the main file's `inputs` defaults and the `--set` overrides.
     *
     * @param list<string> $overrides Input overrides as NAME=VALUE (the --set values)
     * @return array<string, mixed>
     *
     * @throws \RuntimeException if a file cannot be read, `extends` contains a loop, or a condition cannot be resolved
     */
    public function load(string $path, array $overrides = []): array
    {
        $inputs = $this->mainInputs($this->read($path), $overrides);

        return $this->loadWithExtends($path, [], $inputs);
    }

    /**
     * @param list<string> $stack Files being loaded, to detect loops
     * @param array<string, mixed> $inputs Inputs available to `when` conditions
     * @return array<string, mixed>
     */
    private function loadWithExtends(string $path, array $stack, array $inputs): array
    {
        $data = $this->read($path);
        $realPath = (string) realpath($path);
        if (in_array($realPath, $stack, true)) {
            throw new \RuntimeException('Setup configuration extends itself: ' . implode(' -> ', [...$stack, $realPath]));
        }

        $extends = $data['extends'] ?? [];
        unset($data['extends']);
        $extends = is_string($extends) ? [$extends] : $extends;
        if (!is_array($extends)) {
            throw new \RuntimeException('extends must be a file path or a list of file paths in ' . $path);
        }

        if ($extends === []) {
            return $data;
        }

        $merger = new SpaceSetupConfigMerger();
        $merged = [];
        foreach ($extends as $entry) {
            $basePath = is_array($entry) ? ($entry['path'] ?? null) : $entry;
            if (!is_string($basePath) || $basePath === '') {
                throw new \RuntimeException('extends must contain file paths or {path, when} entries in ' . $path);
            }

            if (is_array($entry) && array_key_exists('when', $entry) && !$this->isTrue($entry['when'], $inputs, $basePath)) {
                continue;
            }

            $resolved = str_starts_with($basePath, DIRECTORY_SEPARATOR) ? $basePath : dirname($realPath) . DIRECTORY_SEPARATOR . $basePath;
            $base = $this->absolutePaths($this->loadWithExtends($resolved, [...$stack, $realPath], $inputs), dirname((string) realpath($resolved)));
            /** @var array<string, mixed> $merged */
            $merged = $merger->merge($merged, $base);
        }

        /** @var array<string, mixed> $result */
        $result = $merger->merge($merged, $data);

        return $result;
    }

    /**
     * Inputs for `when` conditions: defaults and from_env values declared in the main
     * file, then CLI overrides. Other overrides are checked later by the inputs resolver.
     *
     * @param array<string, mixed> $config
     * @param list<string> $overrides
     * @return array<string, mixed>
     */
    private function mainInputs(array $config, array $overrides): array
    {
        $definitions = is_array($config['inputs'] ?? null) ? $config['inputs'] : [];
        $inputs = [];
        foreach ($definitions as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }

            if (array_key_exists('default', $definition)) {
                $inputs[$name] = $definition['default'];
            }

            $environmentName = $definition['from_env'] ?? null;
            if (is_string($environmentName)) {
                $environmentValue = getenv($environmentName);
                if ($environmentValue !== false) {
                    $inputs[$name] = $environmentValue;
                } elseif (array_key_exists($environmentName, $_ENV)) {
                    $inputs[$name] = $_ENV[$environmentName];
                }
            }
        }

        foreach ($overrides as $override) {
            [$name, $value] = array_pad(explode('=', $override, 2), 2, null);
            if (is_string($name) && array_key_exists($name, $definitions) && $value !== null) {
                try {
                    $inputs[$name] = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $inputs[$name] = $value;
                }
            }
        }

        return $inputs;
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function isTrue(mixed $condition, array $inputs, string $file): bool
    {
        if (is_string($condition)) {
            $environment = getenv();
            $environment = array_merge(is_array($environment) ? $environment : [], $_ENV);

            // An environment variable that is not set means "off"; an undeclared input is an error.
            if (preg_match('/^\$\{\{\s*env\.([A-Za-z_][A-Za-z0-9_]*)\s*}}$/', trim($condition), $matches) === 1 && !array_key_exists($matches[1], $environment)) {
                return false;
            }

            try {
                $condition = new SpaceSetupVariableResolver([
                    'inputs' => $inputs,
                    'env' => $environment,
                ])->resolve($condition);
            } catch (\RuntimeException $runtimeException) {
                throw new \RuntimeException(sprintf(
                    'Cannot evaluate the condition for extends %s: %s Conditions can use inputs declared in the main file and env variables.',
                    $file,
                    $runtimeException->getMessage(),
                ), 0, $runtimeException);
            }
        }

        if (is_bool($condition)) {
            return $condition;
        }

        return is_scalar($condition) && in_array(strtolower(trim((string) $condition)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Make the file paths of a base file absolute, because the setup resolves relative
     * paths against the main configuration file.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function absolutePaths(array $config, string $directory): array
    {
        if (is_array($config['datasources'] ?? null)) {
            $config['datasources'] = $this->absolutePathsIn($config['datasources'], 'entries_file', $directory);
        }

        if (is_array($config['stories'] ?? null) && is_array($config['stories']['create'] ?? null)) {
            $stories = $config['stories'];
            $stories['create'] = $this->absolutePathsIn($config['stories']['create'], 'content_file', $directory);
            $config['stories'] = $stories;
        }

        if (is_array($config['assets'] ?? null) && is_array($config['assets']['upload_directory'] ?? null)) {
            $assets = $config['assets'];
            $assets['upload_directory'] = $this->absolutePathsIn($config['assets']['upload_directory'], 'source', $directory);
            $config['assets'] = $assets;
        }

        return $config;
    }

    /**
     * @param array<mixed> $items
     * @return array<mixed>
     */
    private function absolutePathsIn(array $items, string $key, string $directory): array
    {
        foreach ($items as $index => $item) {
            $path = is_array($item) ? ($item[$key] ?? null) : null;
            if (is_array($item) && is_string($path) && $path !== '' && !str_starts_with($path, DIRECTORY_SEPARATOR)) {
                $joined = $directory . DIRECTORY_SEPARATOR . $path;
                $item[$key] = realpath($joined) ?: $joined;
                $items[$index] = $item;
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Setup configuration file not found: ' . $path);
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json') {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException('Unable to read setup configuration file: ' . $path);
            }

            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } else {
            $data = Yaml::parseFile($path);
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Setup configuration must contain an object at the top level.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
