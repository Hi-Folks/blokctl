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
     * @return array<string, mixed>
     *
     * @throws \RuntimeException if a file cannot be read or `extends` contains a loop
     */
    public function load(string $path): array
    {
        return $this->loadWithExtends($path, []);
    }

    /**
     * @param list<string> $stack Files being loaded, to detect loops
     * @return array<string, mixed>
     */
    private function loadWithExtends(string $path, array $stack): array
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
        foreach ($extends as $basePath) {
            if (!is_string($basePath) || $basePath === '') {
                throw new \RuntimeException('extends must contain file paths in ' . $path);
            }

            $resolved = str_starts_with($basePath, DIRECTORY_SEPARATOR) ? $basePath : dirname($realPath) . DIRECTORY_SEPARATOR . $basePath;
            $base = $this->absolutePaths($this->loadWithExtends($resolved, [...$stack, $realPath]), dirname((string) realpath($resolved)));
            /** @var array<string, mixed> $merged */
            $merged = $merger->merge($merged, $base);
        }

        /** @var array<string, mixed> $result */
        $result = $merger->merge($merged, $data);

        return $result;
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
