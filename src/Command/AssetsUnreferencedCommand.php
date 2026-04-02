<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Asset\AssetsUnreferencedAction;
use Blokctl\Render;
use Storyblok\ManagementApi\Data\Asset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'assets:unreferenced',
    description: 'List assets not referenced in any story',
)]
class AssetsUnreferencedCommand extends AbstractCommand
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            $result = (new AssetsUnreferencedAction($this->client))->execute(
                spaceId: $this->spaceId,
                region: $this->region,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::titleSection(
            'Assets: ' . $result->totalAssets . ' total, '
            . $result->referencedCount . ' referenced, '
            . $result->unreferencedCount() . ' unreferenced'
            . ' (scanned ' . $result->storiesAnalyzed . ' stories)',
        );

        if ($result->unreferencedCount() === 0) {
            Render::log('All assets are referenced in at least one story.');
            return self::SUCCESS;
        }

        /** @var Asset $asset */
        foreach ($result->unreferencedAssets as $asset) {
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
