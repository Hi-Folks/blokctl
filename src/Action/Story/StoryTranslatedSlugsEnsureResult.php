<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Story;

final readonly class StoryTranslatedSlugsEnsureResult
{
    /**
     * @param list<StoryTranslatedSlugInput> $translations
     */
    public function __construct(
        public Story $story,
        public bool $changed,
        public string $storyId,
        public array $translations,
        public int $changedCount,
    ) {}
}
