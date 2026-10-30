<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Component\ComponentsDiffAction;
use Blokctl\Action\Component\ComponentsDiffResult;
use Blokctl\ManagementAccessToken;
use Blokctl\Render;
use Storyblok\ManagementApi\Data\Enum\Region;
use Storyblok\ManagementApi\ManagementApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\text;

#[AsCommand(
    name: 'components:diff',
    description: 'Compare components, fields, default values, and presets between two spaces',
)]
class ComponentsDiffCommand extends AbstractCommand
{
    private const int MAX_VALUE_LENGTH = 160;

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'target-space',
                'T',
                InputOption::VALUE_REQUIRED,
                'The Space ID to compare against (the source is --space-id)',
            )
            ->addOption(
                'target-region',
                null,
                InputOption::VALUE_REQUIRED,
                'The region of the target space (defaults to --region)',
            )
            ->addOption(
                'component',
                'c',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Limit the comparison to this component (repeatable)',
            )
            ->addOption(
                'with-presets',
                null,
                InputOption::VALUE_NONE,
                'Also compare component presets and their content',
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output the comparison as JSON',
            )
            ->addOption(
                'exit-code',
                null,
                InputOption::VALUE_NONE,
                'Exit with code 1 when differences are found',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $targetSpaceId */
        $targetSpaceId = $input->getOption('target-space');
        if (empty($targetSpaceId) && !$input->getOption('no-interaction')) {
            $targetSpaceId = text(
                label: 'What is the target Space ID to compare against?',
                placeholder: 'E.g. 288455164961039',
                required: 'The target Space ID is required.',
            );
        }

        if (empty($targetSpaceId)) {
            Render::error('Target Space ID is required. Provide it with --target-space (-T).');
            return self::FAILURE;
        }

        /** @var list<string> $componentNames */
        $componentNames = $input->getOption('component');
        $withPresets = (bool) $input->getOption('with-presets');
        $asJson = (bool) $input->getOption('json');

        try {
            $result = new ComponentsDiffAction(
                $this->client,
                $this->targetClient($input),
            )->execute(
                sourceSpaceId: $this->spaceId,
                targetSpaceId: $targetSpaceId,
                componentNames: $componentNames,
                withPresets: $withPresets,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        if ($asJson) {
            $output->writeln((string) json_encode(
                $result->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->render($result, $output);
        }

        return $input->getOption('exit-code') && $result->hasDifferences()
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * Build a separate client when the target space lives in another region.
     */
    private function targetClient(InputInterface $input): ?ManagementApiClient
    {
        /** @var string|null $targetRegionValue */
        $targetRegionValue = $input->getOption('target-region');
        if ($targetRegionValue === null || strtoupper($targetRegionValue) === $this->region) {
            return null;
        }

        $targetRegion = Region::tryFrom(strtoupper($targetRegionValue));
        if ($targetRegion === null) {
            throw new \RuntimeException(
                'Invalid target region "' . $targetRegionValue . '". Valid regions: ' . implode(', ', Region::values()),
            );
        }

        return new ManagementApiClient(
            ManagementAccessToken::fromEnvironment($_ENV),
            region: $targetRegion,
            shouldRetry: true,
        );
    }

    private function render(ComponentsDiffResult $result, OutputInterface $output): void
    {
        Render::title('Components diff');
        Render::labelValue('Source space', $result->sourceSpaceId);
        Render::labelValue('Target space', $result->targetSpaceId);
        Render::labelValueCondition('Presets compared', $result->presetsCompared);

        if ($result->onlyInSource !== []) {
            Render::titleSection('Only in source (' . count($result->onlyInSource) . ')');
            foreach ($result->onlyInSource as $name) {
                Render::operation('SOURCE ONLY', $this->escape($name), 'red');
            }
        }

        if ($result->onlyInTarget !== []) {
            Render::titleSection('Only in target (' . count($result->onlyInTarget) . ')');
            foreach ($result->onlyInTarget as $name) {
                Render::operation('TARGET ONLY', $this->escape($name), 'blue');
            }
        }

        foreach ($result->differences as $name => $changes) {
            Render::titleSection($this->escape($name) . ' (' . count($changes) . ' differences)');
            foreach ($changes as $change) {
                match ($change['type']) {
                    ComponentsDiffResult::ONLY_IN_SOURCE => Render::operation(
                        'SOURCE ONLY',
                        $this->escape($change['path']),
                        'red',
                        $this->format($change['source']),
                    ),
                    ComponentsDiffResult::ONLY_IN_TARGET => Render::operation(
                        'TARGET ONLY',
                        $this->escape($change['path']),
                        'blue',
                        $this->format($change['target']),
                    ),
                    default => Render::operation(
                        'CHANGED',
                        $this->escape($change['path']),
                        'yellow',
                        'source: ' . $this->format($change['source']) . ' | target: ' . $this->format($change['target']),
                    ),
                };
            }
        }

        Render::titleSection('Summary');
        Render::labelValue('Components compared', (string) $result->countCompared());
        Render::labelValue('Identical', (string) count($result->identical));
        Render::labelValue('With differences', (string) count($result->differences));
        Render::labelValue('Only in source', (string) count($result->onlyInSource));
        Render::labelValue('Only in target', (string) count($result->onlyInTarget));

        if ($output->isVerbose() && $result->identical !== []) {
            Render::titleSection('Identical components');
            foreach ($result->identical as $name) {
                Render::operation('IDENTICAL', $this->escape($name), 'gray');
            }
        }
    }

    private function format(mixed $value): string
    {
        $encoded = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (mb_strlen($encoded) > self::MAX_VALUE_LENGTH) {
            $encoded = mb_substr($encoded, 0, self::MAX_VALUE_LENGTH) . '…';
        }

        return $this->escape($encoded);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
