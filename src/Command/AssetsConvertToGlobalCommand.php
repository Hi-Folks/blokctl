<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Asset\AssetsConvertToGlobalAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'assets:convert-to-global',
    description: 'Convert space assets into shared assets in the global asset library',
)]
class AssetsConvertToGlobalCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('asset-id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Space asset ID to convert. Can be repeated.')
            ->addOption('asset-ids', null, InputOption::VALUE_REQUIRED, 'Comma-separated space asset IDs to convert.')
            ->addOption('source-folder-id', null, InputOption::VALUE_REQUIRED, 'Convert assets from this source asset folder ID.')
            ->addOption('source-folder-name', null, InputOption::VALUE_REQUIRED, 'Convert assets from the source asset folder with this name.')
            ->addOption('target-shared-folder-id', null, InputOption::VALUE_REQUIRED, 'Target shared/global asset folder ID.')
            ->addOption('filetype', null, InputOption::VALUE_REQUIRED, 'Filter folder assets by content type family, for example image or video.')
            ->addOption('extension', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter folder assets by file extension. Can be repeated.')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter folder assets by tag. Can be repeated.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List matching assets without converting them.')
            ->addOption('continue-on-error', null, InputOption::VALUE_NONE, 'Continue converting remaining assets after a conversion failure.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $targetSharedFolderId = $this->positiveIntOption($input, 'target-shared-folder-id');
            $sourceFolderId = $this->nullablePositiveIntOption($input, 'source-folder-id');
            $assetIds = $this->assetIds($input);
            /** @var string|null $sourceFolderName */
            $sourceFolderName = $input->getOption('source-folder-name');
            /** @var string|null $filetype */
            $filetype = $input->getOption('filetype');
            /** @var list<string> $extensions */
            $extensions = $input->getOption('extension');
            /** @var list<string> $tags */
            $tags = $input->getOption('tag');

            $result = new AssetsConvertToGlobalAction($this->client)->execute(
                spaceId: $this->spaceId,
                targetSharedFolderId: $targetSharedFolderId,
                assetIds: $assetIds,
                sourceFolderId: $sourceFolderId,
                sourceFolderName: $sourceFolderName,
                filetype: $filetype,
                extensions: $this->cleanStrings($extensions),
                tags: $this->cleanStrings($tags),
                dryRun: (bool) $input->getOption('dry-run'),
                continueOnError: (bool) $input->getOption('continue-on-error'),
            );
        } catch (\Throwable $throwable) {
            Render::error($throwable->getMessage());
            return self::FAILURE;
        }

        Render::titleSection($result->dryRun ? 'Assets selected for conversion' : 'Assets converted to global library');
        Render::labelValue('Matched assets', (string) $result->total());
        if (!$result->dryRun) {
            Render::labelValue('Converted assets', (string) $result->converted());
            Render::labelValue('Failed assets', (string) $result->failed());
        }

        foreach ($result->assetIds as $assetId) {
            Render::log(($result->dryRun ? 'Would convert asset: ' : 'Asset: ') . $assetId);
        }

        foreach ($result->errors as $error) {
            Render::error($error);
        }

        return $result->failed() > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function assetIds(InputInterface $input): array
    {
        /** @var list<string> $repeated */
        $repeated = $input->getOption('asset-id');
        /** @var string|null $csv */
        $csv = $input->getOption('asset-ids');

        $raw = $repeated;
        if ($csv !== null && trim($csv) !== '') {
            array_push($raw, ...explode(',', $csv));
        }

        $ids = [];
        foreach ($raw as $value) {
            $value = trim($value);
            if ($value === '') {
                throw new \InvalidArgumentException('Asset IDs cannot be empty.');
            }

            if (!ctype_digit($value)) {
                throw new \InvalidArgumentException('Asset IDs must be positive integers.');
            }

            $ids[(int) $value] = (int) $value;
        }

        return array_values($ids);
    }

    private function positiveIntOption(InputInterface $input, string $name): int
    {
        /** @var string|null $value */
        $value = $input->getOption($name);
        if ($value === null || trim($value) === '') {
            throw new \InvalidArgumentException('--' . $name . ' is required.');
        }

        if (!ctype_digit($value) || (int) $value < 1) {
            throw new \InvalidArgumentException('--' . $name . ' must be a positive integer.');
        }

        return (int) $value;
    }

    private function nullablePositiveIntOption(InputInterface $input, string $name): ?int
    {
        /** @var string|null $value */
        $value = $input->getOption($name);
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (!ctype_digit($value) || (int) $value < 1) {
            throw new \InvalidArgumentException('--' . $name . ' must be a positive integer.');
        }

        return (int) $value;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function cleanStrings(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn(string $value): string => trim(ltrim($value, '.')),
            $values,
        ), static fn(string $value): bool => $value !== ''));
    }
}
