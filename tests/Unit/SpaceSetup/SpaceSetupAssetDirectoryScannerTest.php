<?php

declare(strict_types=1);

namespace Tests\Unit\SpaceSetup;

use Blokctl\SpaceSetup\SpaceSetupAssetDirectoryScanner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceSetupAssetDirectoryScannerTest extends TestCase
{
    #[Test]
    public function discovers_matching_files_relative_to_the_config_directory(): void
    {
        $directory = $this->temporaryDirectory();
        mkdir($directory . '/assets/products', recursive: true);
        file_put_contents($directory . '/assets/logo.png', 'logo');
        file_put_contents($directory . '/assets/notes.txt', 'notes');
        file_put_contents($directory . '/assets/products/shoe.jpg', 'shoe');

        try {
            $files = new SpaceSetupAssetDirectoryScanner()->scan(
                $directory,
                './assets',
                recursive: true,
                includePatterns: ['*.png', 'products/*.jpg'],
            );
        } finally {
            $this->removeDirectory($directory);
        }

        $this->assertSame(
            ['logo.png', 'products/shoe.jpg'],
            array_column($files, 'relative_path'),
        );
        $this->assertSame(
            ['', 'products'],
            array_column($files, 'relative_directory'),
        );
    }

    #[Test]
    public function non_recursive_discovery_ignores_nested_files(): void
    {
        $directory = $this->temporaryDirectory();
        mkdir($directory . '/assets/nested', recursive: true);
        file_put_contents($directory . '/assets/logo.png', 'logo');
        file_put_contents($directory . '/assets/nested/photo.jpg', 'photo');

        try {
            $files = new SpaceSetupAssetDirectoryScanner()->scan(
                $directory,
                'assets',
                recursive: false,
                includePatterns: [],
            );
        } finally {
            $this->removeDirectory($directory);
        }

        $this->assertSame(['logo.png'], array_column($files, 'relative_path'));
    }

    #[Test]
    public function rejects_a_missing_source_directory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Asset source directory not found');

        new SpaceSetupAssetDirectoryScanner()->scan(
            sys_get_temp_dir(),
            'missing-assets',
            recursive: true,
            includePatterns: [],
        );
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/blokctl-assets-' . bin2hex(random_bytes(8));
        mkdir($directory);
        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
