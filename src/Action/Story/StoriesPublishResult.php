<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

final readonly class StoriesPublishResult
{
    /** Will be published or unpublished (preflight). */
    public const string PLANNED = 'planned';

    /** Already in the requested state. */
    public const string SKIPPED = 'skipped';

    public const string PUBLISHED = 'published';

    public const string UNPUBLISHED = 'unpublished';

    public const string FAILED = 'failed';

    /**
     * @param bool $publish True to publish, false to unpublish
     * @param list<array{id: int, fullSlug: string, status: string, message: string}> $entries
     */
    public function __construct(
        public bool $publish,
        public array $entries,
    ) {}

    /**
     * @return list<array{id: int, fullSlug: string, status: string, message: string}>
     */
    public function entriesWithStatus(string $status): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn(array $entry): bool => $entry['status'] === $status,
        ));
    }

    public function hasFailures(): bool
    {
        return $this->entriesWithStatus(self::FAILED) !== [];
    }
}
