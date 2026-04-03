<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Asset\AssetsListAction;
use Blokctl\Render;
use Storyblok\ManagementApi\Data\Asset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'assets:list',
    description: 'List assets with optional search filter',
)]
class AssetsListCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'search',
                null,
                InputOption::VALUE_REQUIRED,
                'Search assets by filename',
            )
            ->addOption(
                'page',
                'p',
                InputOption::VALUE_REQUIRED,
                'Page number',
                '1',
            )
            ->addOption(
                'per-page',
                null,
                InputOption::VALUE_REQUIRED,
                'Results per page (max 1000)',
                '25',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $search */
        $search = $input->getOption('search');
        /** @var string $pageOption */
        $pageOption = $input->getOption('page');
        /** @var string $perPageOption */
        $perPageOption = $input->getOption('per-page');

        try {
            $result = (new AssetsListAction($this->client))->execute(
                spaceId: $this->spaceId,
                search: $search,
                page: (int) $pageOption,
                perPage: (int) $perPageOption,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        if ($result->count() === 0) {
            Render::log('No assets found');
            return self::SUCCESS;
        }

        Render::titleSection(
            'Assets (page ' . $pageOption .
            ', showing ' . $result->count() . ')',
        );

        /** @var Asset $asset */
        foreach ($result->assets as $asset) {
            $details = [];
            $details[] = 'id: ' . $asset->id();
            $contentType = $asset->contentType();
            if ($contentType !== '') {
                $details[] = $contentType;
            }

            $size = $asset->contentLength();
            if ($size !== null && $size > 0) {
                $details[] = $this->formatBytes($size);
            }

            $createdAt = $asset->createdAt();
            if ($createdAt !== null && $createdAt !== '') {
                $details[] = $createdAt;
            }

            Render::labelValue(
                $asset->filename(),
                implode(' | ', $details),
            );
        }

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }
}
