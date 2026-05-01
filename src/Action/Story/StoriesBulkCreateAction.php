<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\ManagementApiClient;

final readonly class StoriesBulkCreateAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Walk a directory and create a story per JSON file.
     *
     * Each JSON file is interpreted in one of two formats:
     *   1. Content-only: the JSON is the content itself (must include a "component" key).
     *   2. Wrapper: { "name": "...", "slug": "...", "content": { "component": "...", ... } }.
     *
     * If the wrapper format is used, top-level "name" and "slug" override the filename-derived values.
     *
     * @throws \RuntimeException if the directory does not exist
     */
    public function execute(
        string $spaceId,
        string $directory,
        bool $recursive = false,
        int $parentId = 0,
        bool $publish = false,
        string $pattern = '*.json',
    ): StoriesBulkCreateResult {
        if (!is_dir($directory)) {
            throw new \RuntimeException('Directory not found: ' . $directory);
        }

        $files = $this->collectFiles($directory, $pattern, $recursive);
        sort($files);

        $storyCreateAction = new StoryCreateAction($this->client);

        $created = [];
        $errors = [];

        foreach ($files as $file) {
            try {
                $raw = $storyCreateAction->parseJsonFile($file);
                [$name, $slug, $content] = $this->extractFromJson($raw, $file);

                $result = $storyCreateAction->execute(
                    spaceId: $spaceId,
                    name: $name,
                    content: $content,
                    slug: $slug,
                    parentId: $parentId,
                    publish: $publish,
                );

                $created[] = [
                    'file' => $file,
                    'name' => $result->story->name(),
                    'slug' => $result->story->slug(),
                    'id' => (int) $result->story->id(),
                    'fullSlug' => $result->story->fullSlug(),
                ];
            } catch (\Throwable $throwable) {
                $errors[] = [
                    'file' => $file,
                    'error' => $throwable->getMessage(),
                ];
            }
        }

        return new StoriesBulkCreateResult(
            created: $created,
            errors: $errors,
        );
    }

    /**
     * Detect format and extract (name, slug, content) from a parsed JSON file.
     *
     * @param array<string, mixed> $raw
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    public function extractFromJson(array $raw, string $file): array
    {
        $filenameSlug = $this->filenameToSlug($file);
        $filenameName = $this->slugToName($filenameSlug);

        // Wrapper format: { "name": ..., "slug": ..., "content": { "component": ... } }
        if (
            isset($raw['content'])
            && is_array($raw['content'])
            && isset($raw['content']['component'])
        ) {
            /** @var array<string, mixed> $content */
            $content = $raw['content'];

            $name = isset($raw['name']) && is_string($raw['name']) && $raw['name'] !== ''
                ? $raw['name']
                : $filenameName;

            $slug = isset($raw['slug']) && is_string($raw['slug']) && $raw['slug'] !== ''
                ? $raw['slug']
                : $filenameSlug;

            return [$name, $slug, $content];
        }

        // Content-only format: the whole JSON is the content.
        return [$filenameName, $filenameSlug, $raw];
    }

    /**
     * Collect JSON files matching the pattern, optionally recursing.
     *
     * @return string[]
     */
    private function collectFiles(string $directory, string $pattern, bool $recursive): array
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);

        if (!$recursive) {
            $matches = glob($directory . DIRECTORY_SEPARATOR . $pattern);
            return $matches === false ? [] : array_values(array_filter($matches, is_file(...)));
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }

            if (!fnmatch($pattern, $item->getFilename())) {
                continue;
            }

            $files[] = $item->getPathname();
        }

        return $files;
    }

    private function filenameToSlug(string $file): string
    {
        return pathinfo($file, PATHINFO_FILENAME);
    }

    private function slugToName(string $slug): string
    {
        $name = str_replace(['-', '_'], ' ', $slug);
        $name = trim($name);

        return $name === '' ? $slug : ucwords($name);
    }
}
