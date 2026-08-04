<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class SpaceLanguageCheckAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function isInstalled(string $spaceId, string $lang): bool
    {
        return in_array($lang, $this->installedLanguages($spaceId), true);
    }

    public function requireInstalled(string $spaceId, string $lang): void
    {
        if ($this->isInstalled($spaceId, $lang)) {
            return;
        }

        throw new \RuntimeException('Language "' . $lang . '" is not enabled for this space.');
    }

    /**
     * @return string[]
     */
    public function installedLanguages(string $spaceId): array
    {
        $space = new SpaceApi($this->client)->get($spaceId)->data()->toArray();
        $languages = [];
        foreach (['languages', 'language_codes'] as $key) {
            $languages = array_merge($languages, $this->languageCodesFromValue($space[$key] ?? null));
        }

        if (isset($space['settings']) && is_array($space['settings'])) {
            foreach (['languages', 'language_codes'] as $key) {
                $languages = array_merge($languages, $this->languageCodesFromValue($space['settings'][$key] ?? null));
            }
        }

        if (isset($space['options']) && is_array($space['options'])) {
            $languages = array_merge($languages, $this->languageCodesFromValue($space['options']['languages'] ?? null));
        }

        return array_values(array_unique($languages));
    }

    /**
     * @return string[]
     */
    private function languageCodesFromValue(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        $codes = [];
        foreach (is_array($value) ? array_values($value) : [] as $language) {
            if (is_string($language) && $language !== '') {
                $codes[] = $language;
                continue;
            }

            if (!is_array($language)) {
                continue;
            }

            foreach (['code', 'lang', 'language'] as $key) {
                if (isset($language[$key]) && is_scalar($language[$key]) && (string) $language[$key] !== '') {
                    $codes[] = (string) $language[$key];
                    break;
                }
            }
        }

        return $codes;
    }
}
