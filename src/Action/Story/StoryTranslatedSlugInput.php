<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

final readonly class StoryTranslatedSlugInput
{
    public function __construct(
        public string $lang,
        public string $translatedSlug,
        public string|null $name = null,
        public bool|null $published = null,
    ) {}
}
