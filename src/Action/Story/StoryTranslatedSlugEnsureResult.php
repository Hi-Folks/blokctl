<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Story;

final readonly class StoryTranslatedSlugEnsureResult
{
    public function __construct(
        public Story $story,
        public bool $changed,
        public string $storyId,
        public string $lang,
        public string $translatedSlug,
    ) {}
}
