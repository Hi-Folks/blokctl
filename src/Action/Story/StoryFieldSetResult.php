<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Story;

final readonly class StoryFieldSetResult
{
    public function __construct(
        public Story $story,
        public string $fieldName,
        public mixed $newValue,
        public mixed $previousValue,
    ) {}
}
