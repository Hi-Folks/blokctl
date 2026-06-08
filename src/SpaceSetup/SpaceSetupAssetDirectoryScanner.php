<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final readonly class SpaceSetupAssetDirectoryScanner
{
    /**
     * @param string[] $includePatterns
     *
     * @return array<int, array{path: string, relative_path: string, relative_directory: string, filename: string}>
     */
    public function scan(
        string $configDirectory,
        string $source,
        bool $recursive,
        array $includePatterns,
    ): array {
        $sourcePath = $this->resolveSourcePath($configDirectory, $source);
        if (!is_dir($sourcePath)) {
            throw new \RuntimeException('Asset source directory not found: ' . $sourcePath);
        }

        $files = [];
        $iterator = $recursive
            ? new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS),
            )
            : new \IteratorIterator(
                new \DirectoryIterator($sourcePath),
            );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $relativePath = ltrim(substr($path, strlen($sourcePath)), DIRECTORY_SEPARATOR);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            if (!$this->matches($relativePath, $includePatterns)) {
                continue;
            }

            $relativeDirectory = dirname($relativePath);
            $files[] = [
                'path' => $path,
                'relative_path' => $relativePath,
                'relative_directory' => $relativeDirectory === '.' ? '' : $relativeDirectory,
                'filename' => $file->getFilename(),
            ];
        }

        usort(
            $files,
            static fn(array $left, array $right): int => $left['relative_path'] <=> $right['relative_path'],
        );

        return $files;
    }

    private function resolveSourcePath(string $configDirectory, string $source): string
    {
        if ($source === '') {
            throw new \RuntimeException('Asset upload directory source is required.');
        }

        if (str_starts_with($source, DIRECTORY_SEPARATOR)) {
            return rtrim($source, DIRECTORY_SEPARATOR);
        }

        return rtrim($configDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $source;
    }

    /**
     * @param string[] $includePatterns
     */
    private function matches(string $relativePath, array $includePatterns): bool
    {
        if ($includePatterns === []) {
            return true;
        }

        $filename = basename($relativePath);
        return array_any(
            $includePatterns,
            fn(string $pattern): bool => fnmatch($pattern, $relativePath) || fnmatch($pattern, $filename),
        );
    }
}
