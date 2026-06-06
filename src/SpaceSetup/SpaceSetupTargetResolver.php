<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final class SpaceSetupTargetResolver
{
    public const string DRY_RUN_SPACE_ID = 'NEW_SPACE_ID';

    /**
     * @param \Closure(string, string): string $duplicate
     */
    public function resolve(
        string|null $existingSpaceId,
        string|null $duplicateFrom,
        string|null $newSpaceName,
        bool $dryRun,
        \Closure $duplicate,
    ): string {
        $hasExistingSpace = $existingSpaceId !== null && $existingSpaceId !== '';
        $hasDuplicateSource = $duplicateFrom !== null && $duplicateFrom !== '';

        if ($hasExistingSpace && $hasDuplicateSource) {
            throw new \RuntimeException('Use either --space-id (-S) or space.duplicate_from in the setup configuration, not both.');
        }

        if (!$hasExistingSpace && !$hasDuplicateSource) {
            throw new \RuntimeException('Provide an existing --space-id (-S) or configure space.duplicate_from and space.name.');
        }

        if ($existingSpaceId !== null && $existingSpaceId !== '') {
            return $existingSpaceId;
        }

        if ($newSpaceName === null || $newSpaceName === '') {
            throw new \RuntimeException('space.name is required when using space.duplicate_from.');
        }

        if ($dryRun) {
            return self::DRY_RUN_SPACE_ID;
        }

        if ($duplicateFrom === null || $duplicateFrom === '') {
            throw new \RuntimeException('Unable to resolve the source space ID.');
        }

        return $duplicate($duplicateFrom, $newSpaceName);
    }
}
