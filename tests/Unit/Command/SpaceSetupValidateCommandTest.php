<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Blokctl\Command\SpaceSetupValidateCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

final class SpaceSetupValidateCommandTest extends TestCase
{
    public function testJsonOutputIsStableForValidConfiguration(): void
    {
        $path = $this->configFile(<<<'YAML'
            version: 1
            components:
              ensure:
                - name: page
                  fields:
                    - field: title
                      type: text
            stories:
              create:
                - name: Home
                  content:
                    component: page
                    title: Hello
            YAML);

        try {
            $tester = new CommandTester(new SpaceSetupValidateCommand());
            $this->assertSame(0, $tester->execute(['--config' => $path, '--json' => true]));
            $result = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(['valid' => true, 'errors' => [], 'warnings' => []], $result);
        } finally {
            unlink($path);
        }
    }

    public function testJsonOutputContainsContentErrors(): void
    {
        $path = $this->configFile(<<<'YAML'
            version: 1
            components:
              ensure:
                - name: page
                  fields:
                    - field: title
                      type: text
            stories:
              create:
                - name: Home
                  content:
                    component: page
                    headline: Hello
            YAML);

        try {
            $tester = new CommandTester(new SpaceSetupValidateCommand());
            $this->assertSame(1, $tester->execute(['--config' => $path, '--json' => true]));
            $result = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertIsArray($result);
            $this->assertFalse($result['valid']);
            $this->assertIsArray($result['errors']);
            $this->assertIsString($result['errors'][0]);
            $this->assertStringContainsString('unknown field "headline"', $result['errors'][0]);
        } finally {
            unlink($path);
        }
    }

    private function configFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'blokctl-config-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create temporary config file.');
        }

        file_put_contents($path, $contents);

        return $path;
    }
}
