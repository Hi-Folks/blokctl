<?php

declare(strict_types=1);

namespace Blokctl\Template;

use Blokctl\SpaceSetup\SpaceSetupConfigLoader;
use Blokctl\SpaceSetup\SpaceSetupConfigValidator;
use Symfony\Component\Yaml\Yaml;

final class TemplateCatalog
{
    private const array EXTENSIONS = ['json', 'yaml', 'yml'];

    private const array IGNORED_DIRECTORIES = [
        '.git',
        '.build',
        '.idea',
        '.phpunit.cache',
        'node_modules',
        'tmp',
        'vendor',
    ];

    public function scan(string $directory = '.', bool $recursive = false): TemplateCatalogResult
    {
        $realDirectory = realpath($directory);
        if ($realDirectory === false || !is_dir($realDirectory)) {
            throw new \RuntimeException('Template directory does not exist: ' . $directory);
        }

        $templates = [];
        $errors = [];
        foreach ($this->files($realDirectory, $recursive) as $file) {
            try {
                $raw = $this->parse($file);
                if (!$this->looksLikeSetupConfig($raw)) {
                    continue;
                }

                $config = new SpaceSetupConfigLoader()->load($file);
                if (!is_array($config['template'] ?? null)) {
                    continue;
                }

                $templates[] = $this->entry($file, $raw, $config);
            } catch (\Throwable $throwable) {
                $errors[] = [
                    'path' => $this->displayPath($file),
                    'error' => $throwable->getMessage(),
                ];
            }
        }

        usort($templates, static fn(TemplateCatalogEntry $left, TemplateCatalogEntry $right): int => strnatcasecmp($left->name(), $right->name()));

        return new TemplateCatalogResult($templates, $errors);
    }

    public function load(string $file): TemplateCatalogEntry
    {
        $realFile = realpath($file);
        if ($realFile === false || !is_file($realFile)) {
            throw new \RuntimeException('Template file does not exist: ' . $file);
        }

        if (!in_array(strtolower(pathinfo($realFile, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            throw new \RuntimeException('Template file must use .yaml, .yml, or .json: ' . $file);
        }

        $raw = $this->parse($realFile);
        $config = new SpaceSetupConfigLoader()->load($realFile);
        if (!is_array($config['template'] ?? null)) {
            throw new \RuntimeException('Setup config does not declare template metadata: ' . $file);
        }

        return $this->entry($realFile, $raw, $config);
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $config
     */
    private function entry(string $file, array $raw, array $config): TemplateCatalogEntry
    {
        $validation = new SpaceSetupConfigValidator()->validate($config);
        if (!$validation->isValid()) {
            throw new \RuntimeException('Invalid setup config: ' . implode(' | ', $validation->errors));
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $config['template'];
        $inputs = is_array($config['inputs'] ?? null)
            ? array_values(array_filter(array_keys($config['inputs']), is_string(...)))
            : [];

        return new TemplateCatalogEntry(
            path: $this->displayPath($file),
            metadata: $metadata,
            inputs: $inputs,
            targetMode: $this->targetMode($config),
            extends: $this->extendsPaths($raw['extends'] ?? null),
        );
    }

    /** @return list<string> */
    private function files(string $directory, bool $recursive): array
    {
        $files = [];
        if ($recursive) {
            $iterator = new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                static fn(\SplFileInfo $file): bool => !$file->isDir() || !in_array($file->getFilename(), self::IGNORED_DIRECTORIES, true),
            );
            $entries = new \RecursiveIteratorIterator($iterator);
        } else {
            $entries = new \IteratorIterator(new \DirectoryIterator($directory));
        }

        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }

            $path = $entry->getPathname();
            if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                $files[] = $path;
            }
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
    }

    /** @return array<string, mixed> */
    private function parse(string $file): array
    {
        try {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'json') {
                $contents = file_get_contents($file);
                if ($contents === false) {
                    throw new \RuntimeException('Unable to read file.');
                }

                $parsed = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $parsed = Yaml::parseFile($file);
            }
        } catch (\Throwable $throwable) {
            throw new \RuntimeException('Unable to parse ' . $this->displayPath($file) . ': ' . $throwable->getMessage(), $throwable->getCode(), previous: $throwable);
        }

        if (!is_array($parsed) || array_is_list($parsed)) {
            throw new \RuntimeException('Template config must be an object: ' . $this->displayPath($file));
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /** @param array<string, mixed> $config */
    private function looksLikeSetupConfig(array $config): bool
    {
        return array_key_exists('version', $config)
            || array_key_exists('template', $config)
            || array_key_exists('extends', $config);
    }

    /** @param array<string, mixed> $config */
    private function targetMode(array $config): string
    {
        $space = is_array($config['space'] ?? null) ? $config['space'] : [];
        if (($space['create_new'] ?? false) === true) {
            return 'create';
        }

        if (is_string($space['duplicate_from'] ?? null) || is_int($space['duplicate_from'] ?? null)) {
            return 'duplicate';
        }

        return 'existing';
    }

    /** @return list<string> */
    private function extendsPaths(mixed $extends): array
    {
        if (is_string($extends)) {
            return [$extends];
        }

        if (!is_array($extends)) {
            return [];
        }

        $paths = [];
        foreach ($extends as $entry) {
            $path = is_string($entry) ? $entry : (is_array($entry) ? ($entry['path'] ?? null) : null);
            if (is_string($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function displayPath(string $file): string
    {
        $realFile = realpath($file) ?: $file;
        $cwd = getcwd();
        if (is_string($cwd) && str_starts_with($realFile, rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return substr($realFile, strlen(rtrim($cwd, DIRECTORY_SEPARATOR)) + 1);
        }

        return $realFile;
    }
}
