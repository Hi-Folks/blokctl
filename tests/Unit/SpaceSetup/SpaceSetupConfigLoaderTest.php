<?php

declare(strict_types=1);

namespace Tests\Unit\SpaceSetup;

use Blokctl\SpaceSetup\SpaceSetupConfigLoader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceSetupConfigLoaderTest extends TestCase
{
    #[Test]
    public function loads_yaml_configuration(): void
    {
        $config = (new SpaceSetupConfigLoader())->load('examples/demo-space.yaml');

        $preview = $config['preview'] ?? null;
        $this->assertIsArray($preview);
        $this->assertTrue($preview['enabled']);
        $this->assertSame(
            'https://storyblok-demo-default-se.netlify.app/?token={{ preview_token }}&path=',
            $preview['default'],
        );

        $components = $config['components'] ?? null;
        $this->assertIsArray($components);
        $fields = $components['fields'] ?? null;
        $this->assertIsArray($fields);
        $firstField = $fields[0] ?? null;
        $this->assertIsArray($firstField);
        $this->assertSame('article-page', $firstField['component']);
    }

    #[Test]
    public function loads_json_configuration(): void
    {
        $path = $this->temporaryConfigFile('{"apps":{"install":["backups"]}}', '.json');

        try {
            $config = (new SpaceSetupConfigLoader())->load($path);
        } finally {
            unlink($path);
        }

        $apps = $config['apps'] ?? null;
        $this->assertIsArray($apps);
        $this->assertSame(['backups'], $apps['install']);
    }

    #[Test]
    public function rejects_missing_configuration_file(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Setup configuration file not found');

        (new SpaceSetupConfigLoader())->load('missing-space-setup.yaml');
    }

    #[Test]
    public function rejects_scalar_top_level_configuration(): void
    {
        $path = $this->temporaryConfigFile('invalid');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Setup configuration must contain an object at the top level');

        try {
            (new SpaceSetupConfigLoader())->load($path);
        } finally {
            unlink($path);
        }
    }

    private function temporaryConfigFile(string $content, string $suffix = '.yaml'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'blokctl-space-setup-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create temporary config file.');
        }

        $configPath = $path . $suffix;
        rename($path, $configPath);
        file_put_contents($configPath, $content);

        return $configPath;
    }
}
