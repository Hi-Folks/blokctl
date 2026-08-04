<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class SpaceLanguagesEnsureAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param string[] $languages
     */
    public function execute(string $spaceId, array $languages): SpaceLanguagesEnsureResult
    {
        $desiredLanguages = $this->normalizedLanguages($languages);
        if ($desiredLanguages === []) {
            throw new \RuntimeException('Space languages require at least one language code.');
        }

        $spaceApi = new SpaceApi($this->client);
        $space = $spaceApi->get($spaceId)->data()->toArray();
        $existingLanguages = $this->languageOptionsFromSpace($space);
        $mergedLanguages = $existingLanguages;
        $addedLanguages = [];

        foreach ($desiredLanguages as $language) {
            if ($this->hasLanguage($mergedLanguages, $language)) {
                continue;
            }

            $mergedLanguages[] = $this->newLanguageOption($language);
            $addedLanguages[] = $language;
        }

        if ($addedLanguages === []) {
            return new SpaceLanguagesEnsureResult(false, $this->languageCodesFromOptions($mergedLanguages), []);
        }

        $options = is_array($space['options'] ?? null) ? $space['options'] : [];
        $options['languages'] = $mergedLanguages;
        $response = $spaceApi->update($spaceId, Space::forUpdate([
            'languages' => $mergedLanguages,
            'options' => $options,
        ]));
        if (!$response->isOk()) {
            throw new \RuntimeException('Failed to configure space languages: ' . $response->getErrorMessage());
        }

        $confirmedLanguages = $this->languageCodesFromOptions($this->languageOptionsFromSpace($spaceApi->get($spaceId)->data()->toArray()));
        $missingLanguages = array_values(array_diff($desiredLanguages, $confirmedLanguages));
        if ($missingLanguages !== []) {
            throw new \RuntimeException('Failed to configure space languages: Storyblok did not persist language(s): ' . implode(', ', $missingLanguages));
        }

        return new SpaceLanguagesEnsureResult(true, $this->languageCodesFromOptions($mergedLanguages), $addedLanguages);
    }

    /**
     * @param string[] $languages
     * @return string[]
     */
    private function normalizedLanguages(array $languages): array
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

    /**
     * @param array<mixed> $space
     * @return list<array{code: string, name: string, ai_translation_code?: string}>
     */
    private function languageOptionsFromSpace(array $space): array
    {
        $options = is_array($space['options'] ?? null) ? $space['options'] : [];
        $languages = $this->languageOptionsFromValue($options['languages'] ?? null);
        if ($languages !== []) {
            return $languages;
        }

        $fallbackLanguages = [];
        foreach ($this->fallbackLanguageCodesFromSpace($space) as $language) {
            $fallbackLanguages[] = [
                'code' => $language,
                'name' => $this->displayNameForLanguage($language),
                'ai_translation_code' => $this->aiTranslationCodeForLanguage($language),
            ];
        }

        return $fallbackLanguages;
    }

    /**
     * @return list<array{code: string, name: string, ai_translation_code?: string}>
     */
    private function languageOptionsFromValue(mixed $value): array
    {
        /** @var list<array{code: string, name: string, ai_translation_code?: string}> $languages */
        $languages = [];
        foreach (is_array($value) ? array_values($value) : [] as $language) {
            if (is_string($language) && $language !== '') {
                $languages[] = $this->newLanguageOption($language);
                continue;
            }

            if (!is_array($language)) {
                continue;
            }

            $code = $this->nullableStringValue($language['code'] ?? $language['lang'] ?? $language['language'] ?? null);
            if ($code !== null) {
                $languages[] = [
                    'code' => $code,
                    'name' => $this->nullableStringValue($language['name'] ?? null) ?? $this->displayNameForLanguage($code),
                    'ai_translation_code' => $this->nullableStringValue($language['ai_translation_code'] ?? null) ?? $this->aiTranslationCodeForLanguage($code),
                ];
            }
        }

        return $this->uniqueLanguageOptions($languages);
    }

    /**
     * @param array<mixed> $space
     * @return string[]
     */
    private function fallbackLanguageCodesFromSpace(array $space): array
    {
        $codes = [];
        foreach (['languages', 'language_codes'] as $key) {
            $codes = array_merge($codes, $this->languageCodesFromValue($space[$key] ?? null));
        }

        if (isset($space['settings']) && is_array($space['settings'])) {
            foreach (['languages', 'language_codes'] as $key) {
                $codes = array_merge($codes, $this->languageCodesFromValue($space['settings'][$key] ?? null));
            }
        }

        return array_values(array_unique($codes));
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
        foreach ($this->languageOptionsFromValue($value) as $language) {
            $codes[] = $language['code'];
        }

        return $codes;
    }

    /**
     * @param list<array{code: string, name: string, ai_translation_code?: string}> $languages
     * @return list<array{code: string, name: string, ai_translation_code?: string}>
     */
    private function uniqueLanguageOptions(array $languages): array
    {
        /** @var array<string, array{code: string, name: string, ai_translation_code?: string}> $unique */
        $unique = [];
        foreach ($languages as $language) {
            if (!isset($unique[$language['code']])) {
                $unique[$language['code']] = $language;
            }
        }

        return array_values($unique);
    }

    /**
     * @param list<array{code: string, name: string, ai_translation_code?: string}> $languages
     */
    private function hasLanguage(array $languages, string $language): bool
    {
        return array_any($languages, fn(array $existingLanguage): bool => ($existingLanguage['code'] ?? null) === $language);
    }

    /**
     * @param list<array{code: string, name: string, ai_translation_code?: string}> $languages
     * @return string[]
     */
    private function languageCodesFromOptions(array $languages): array
    {
        return array_map(static fn(array $language): string => $language['code'], $languages);
    }

    /**
     * @return array{code: string, name: string, ai_translation_code: string}
     */
    private function newLanguageOption(string $language): array
    {
        return [
            'code' => $language,
            'name' => $this->displayNameForLanguage($language),
            'ai_translation_code' => $this->aiTranslationCodeForLanguage($language),
        ];
    }

    private function displayNameForLanguage(string $language): string
    {
        $displayName = class_exists(\Locale::class)
            ? \Locale::getDisplayLanguage($language, 'en')
            : $language;

        return !is_string($displayName) || $displayName === '' ? $language : $displayName;
    }

    private function aiTranslationCodeForLanguage(string $language): string
    {
        $normalized = str_replace('_', '-', $language);

        return strtolower(explode('-', $normalized)[0]);
    }

    private function nullableStringValue(mixed $value): string|null
    {
        $value = is_scalar($value) ? (string) $value : '';
        return $value === '' ? null : $value;
    }
}
