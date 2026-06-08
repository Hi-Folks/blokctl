<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final class SpaceSetupTargetResolver
{
    public const string DRY_RUN_SPACE_ID = 'NEW_SPACE_ID';

    /**
     * @param \Closure(string): string $create
     * @param \Closure(string, string): string $duplicate
     */
    public function resolve(
        string|null $existingSpaceId,
        bool $createNew,
        string|null $duplicateFrom,
        string|null $newSpaceName,
        bool $dryRun,
        \Closure $create,
        \Closure $duplicate,
    ): string {
        $hasExistingSpace = $existingSpaceId !== null && $existingSpaceId !== '';
        $hasDuplicateSource = $duplicateFrom !== null && $duplicateFrom !== '';
        $targetModeCount = (int) $hasExistingSpace + (int) $createNew + (int) $hasDuplicateSource;

        if ($targetModeCount > 1) {
            throw new \RuntimeException(
                'Use exactly one target mode: --space-id (-S), space.create_new: true, or space.duplicate_from.',
            );
        }

        if ($targetModeCount === 0) {
            throw new \RuntimeException(
                'Provide --space-id (-S), configure space.create_new: true, or configure space.duplicate_from.',
            );
        }

        if ($hasExistingSpace) {
            return $existingSpaceId;
        }

        if ($newSpaceName === null || $newSpaceName === '') {
            throw new \RuntimeException(
                $createNew
                    ? 'space.name is required when using space.create_new: true.'
                    : 'space.name is required when using space.duplicate_from.',
            );
        }

        if ($dryRun) {
            return self::DRY_RUN_SPACE_ID;
        }

        if ($createNew) {
            return $create($newSpaceName);
        }

        if ($duplicateFrom === null || $duplicateFrom === '') {
            throw new \RuntimeException('Unable to resolve the source space ID.');
        }

        return $duplicate($duplicateFrom, $newSpaceName);
    }
}
