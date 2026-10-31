<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

use Symfony\Component\Yaml\Yaml;

final readonly class SpaceSetupExportResult
{
    /**
     * @param array<string, mixed> $config Setup configuration in the space:setup format
     * @param list<string> $warnings Settings that the setup format cannot express
     * @param array<string, string> $files Extra files to write (path relative to the config => content), e.g. datasource CSVs
     */
    public function __construct(
        public array $config,
        public array $warnings,
        public array $files,
    ) {}

    /**
     * The configuration as YAML, with a header and the warnings as comments.
     */
    public function toYaml(string $header = ''): string
    {
        $lines = ['# yaml-language-server: $schema=https://raw.githubusercontent.com/Hi-Folks/blokctl/main/space-setup-schema.json'];
        if ($header !== '') {
            $lines[] = '# ' . $header;
        }

        foreach ($this->warnings as $warning) {
            $lines[] = '# WARNING: ' . $warning;
        }

        return implode("\n", $lines) . "\n\n" . Yaml::dump($this->config, 10, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_COMPACT_NESTED_MAPPING);
    }
}
