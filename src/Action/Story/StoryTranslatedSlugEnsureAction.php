<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\ManagementApiClient;

final readonly class StoryTranslatedSlugEnsureAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param string[] $knownEnabledLanguages
     */
    public function execute(
        string $spaceId,
        string|null $storySlug,
        string|null $storyId,
        string $lang,
        string $translatedSlug,
        string|null $name = null,
        bool|null $published = null,
        array $knownEnabledLanguages = [],
    ): StoryTranslatedSlugEnsureResult {
        $result = new StoryTranslatedSlugsEnsureAction($this->client)->execute(
            spaceId: $spaceId,
            storySlug: $storySlug,
            storyId: $storyId,
            translations: [
                new StoryTranslatedSlugInput($lang, $translatedSlug, $name, $published),
            ],
            knownEnabledLanguages: $knownEnabledLanguages,
        );

        $translation = $result->translations[0];

        return new StoryTranslatedSlugEnsureResult(
            story: $result->story,
            changed: $result->changed,
            storyId: $result->storyId,
            lang: $translation->lang,
            translatedSlug: $translation->translatedSlug,
        );
    }
}
