<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

use Symfony\Component\Yaml\Yaml;

final class SpaceSetupConfigLoader
{
    /**
     * @return array<string, mixed>
     */
    public function load(string $path): array
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
