<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Blokctl\Action\AppProvision\AppProvisionInstalledCheckAction;
use Blokctl\Action\Space\SpaceLanguageCheckAction;
use Storyblok\ManagementApi\Data\Story;
use Storyblok\ManagementApi\Data\TranslatedSlug;
use Storyblok\ManagementApi\Data\TranslatedSlugData;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

final readonly class StoryTranslatedSlugsEnsureAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param list<StoryTranslatedSlugInput> $translations
     * @param string[] $knownEnabledLanguages
     */
    public function execute(
        string $spaceId,
        string|null $storySlug,
        string|null $storyId,
        array $translations,
        array $knownEnabledLanguages = [],
    ): StoryTranslatedSlugsEnsureResult {
        if (($storySlug === null && $storyId === null) || ($storySlug !== null && $storyId !== null)) {
            throw new \RuntimeException('Translated slug entries require exactly one of story_slug or story_id.');
        }

        if ($translations === []) {
            throw new \RuntimeException('Translated slug entries require at least one translation.');
        }

        $normalizedTranslations = [];
        foreach ($translations as $translation) {
            $lang = trim($translation->lang);
            $translatedSlug = $this->normalizedSlug($translation->translatedSlug);
            if ($lang === '' || $translatedSlug === '') {
                throw new \RuntimeException('Translated slug entries require lang and translated_slug.');
            }

            if (isset($normalizedTranslations[$lang])) {
                throw new \RuntimeException('Duplicate translated slug entry for language: ' . $lang);
            }

            $normalizedTranslations[$lang] = new StoryTranslatedSlugInput(
                lang: $lang,
                translatedSlug: $translatedSlug,
                name: $translation->name,
                published: $translation->published,
            );
        }

        new AppProvisionInstalledCheckAction($this->client)->requireInstalled($spaceId, 'translatable-slugs');

        $installedLanguages = array_values(array_unique(array_merge(
            new SpaceLanguageCheckAction($this->client)->installedLanguages($spaceId),
            $this->normalizedLanguageCodes($knownEnabledLanguages),
        )));
        foreach (array_keys($normalizedTranslations) as $lang) {
            if (!in_array($lang, $installedLanguages, true)) {
                throw new \RuntimeException('Language "' . $lang . '" is not enabled for this space.');
            }
        }

        $storyApi = new StoryApi($this->client, $spaceId);
        $resolvedStoryId = $storyId ?? $this->requireStoryIdBySlug($storyApi, $this->normalizedSlug((string) $storySlug));
        $story = Story::make($storyApi->get($resolvedStoryId)->data()->toArray());
        $attributes = [];

        foreach ($normalizedTranslations as $translation) {
            $existing = $this->translatedSlugForLanguage($story, $translation->lang);
            if ($this->translatedSlugMatches($existing, $translation->translatedSlug, $translation->name, $translation->published)) {
                continue;
            }

            $attributes[] = $existing !== null && is_scalar($existing['id'] ?? null)
                ? TranslatedSlug::update((string) $existing['id'], slug: $translation->translatedSlug, name: $translation->name, published: $translation->published)
                : TranslatedSlug::create($translation->lang, $translation->translatedSlug, $translation->name, $translation->published);
        }

        if ($attributes === []) {
            return new StoryTranslatedSlugsEnsureResult(
                story: $story,
                changed: false,
                storyId: $resolvedStoryId,
                translations: array_values($normalizedTranslations),
                changedCount: 0,
            );
        }

        $story->setTranslatedSlugsAttributes($attributes);
        $response = $storyApi->update($resolvedStoryId, $story);
        if (!$response->isOk()) {
            throw new \RuntimeException('Failed to update translated slug: ' . $response->getErrorMessage());
        }

        return new StoryTranslatedSlugsEnsureResult(
            story: Story::make($response->data()->toArray()),
            changed: true,
            storyId: $resolvedStoryId,
            translations: array_values($normalizedTranslations),
            changedCount: count($attributes),
        );
    }

    private function requireStoryIdBySlug(StoryApi $storyApi, string $slug): string
    {
        $stories = $storyApi->page(new StoriesParams(withSlug: $slug))->data();
        if (count($stories) !== 1) {
            throw new \RuntimeException('Story not found with slug: ' . $slug);
        }

        /** @var array{id: int|string} $story */
        $story = $stories[0];

        return (string) $story['id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function translatedSlugForLanguage(Story $story, string $lang): array|null
    {
        foreach ($story->translatedSlugs() as $translatedSlug) {
            $data = $translatedSlug instanceof TranslatedSlugData
                ? $translatedSlug->toArray()
                : $translatedSlug;

            if (is_array($data) && ($data['lang'] ?? null) === $lang) {
                /** @var array<string, mixed> $data */
                return $data;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $existing
     */
    private function translatedSlugMatches(
        array|null $existing,
        string $desiredSlug,
        string|null $name,
        bool|null $published,
    ): bool {
        if ($existing === null) {
            return false;
        }

        if ($this->translatedSlugValue($existing) !== $desiredSlug) {
            return false;
        }

        if ($name !== null && ($existing['name'] ?? null) !== $name) {
            return false;
        }

        return $published === null || $this->boolValue($existing['published'] ?? false) === $published;
    }

    /**
     * @param array<string, mixed> $translatedSlug
     */
    private function translatedSlugValue(array $translatedSlug): string
    {
        $slug = $this->nullableStringValue($translatedSlug['slug'] ?? null);
        if ($slug !== null) {
            return $this->normalizedSlug($slug);
        }

        $path = $this->nullableStringValue($translatedSlug['path'] ?? null);
        if ($path === null) {
            return '';
        }

        $parts = explode('/', $this->normalizedSlug($path));
        return end($parts);
    }

    private function normalizedSlug(string $slug): string
    {
        return trim($slug, '/');
    }

    /**
     * @param string[] $languages
     * @return string[]
     */
    private function normalizedLanguageCodes(array $languages): array
    {
        $normalized = [];
        foreach ($languages as $language) {
            $language = trim($language);
            if ($language !== '') {
                $normalized[] = $language;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function nullableStringValue(mixed $value): string|null
    {
        $value = is_scalar($value) ? (string) $value : '';
        return $value === '' ? null : $value;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return is_scalar($value) && (bool) $value;
    }
}
