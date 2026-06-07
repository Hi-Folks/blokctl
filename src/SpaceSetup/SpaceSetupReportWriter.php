<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final readonly class SpaceSetupReportWriter
{
    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed>|null $duplication
     */
    public function write(
        string $path,
        SpaceSetupReporter $reporter,
        bool $dryRun,
        array $target,
        array|null $duplication = null,
    ): void {
        $masker = new SpaceSetupValueMasker();
        $operations = array_map(
            static fn(SpaceSetupOperationResult $result): array => [
                'status' => strtolower($result->status->value),
                'label' => $result->label,
                'detail' => $result->detail === null ? null : $masker->mask($result->detail),
            ],
            $reporter->results(),
        );

        $report = [
            'schema_version' => 1,
            'status' => $reporter->hasFailures() ? 'failed' : ($dryRun ? 'planned' : 'completed'),
            'dry_run' => $dryRun,
            'target' => $target,
            'operations' => $operations,
            'summary' => $reporter->counts(),
        ];

        if ($duplication !== null) {
            $report['duplication'] = $duplication;
        }

        $directory = dirname($path);
        if ($directory !== '.' && !is_dir($directory)) {
            throw new \RuntimeException('Unable to write setup report: directory does not exist: ' . $directory);
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException('Unable to write setup report: ' . $path);
        }
    }
}
