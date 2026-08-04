<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Story\StoryTranslatedSlugInput;
use Blokctl\Action\Story\StoryTranslatedSlugsEnsureAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'story:translated-slug-set',
    description: 'Set one or more translated slugs on a story',
)]
class StoryTranslatedSlugSetCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('by-slug', null, InputOption::VALUE_REQUIRED, 'Find story by full slug (e.g. about)')
            ->addOption('by-id', null, InputOption::VALUE_REQUIRED, 'Find story by numeric ID')
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Language code for a single translated slug')
            ->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Translated slug for a single translated slug')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Translated label for a single translated slug')
            ->addOption('published', null, InputOption::VALUE_REQUIRED, 'Published flag for a single translated slug: true|false|yes|no|1|0|on|off')
            ->addOption('translation', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Batch translation as lang;slug;name;published. Repeat for batch updates.');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $storySlug */
        $storySlug = $input->getOption('by-slug');
        /** @var string|null $storyId */
        $storyId = $input->getOption('by-id');

        if ($storySlug && $storyId) {
            Render::error('Provide only one of --by-slug or --by-id.');
            return self::FAILURE;
        }

        if (!$storySlug && !$storyId) {
            Render::error('Provide one of --by-slug or --by-id.');
            return self::FAILURE;
        }

        try {
            $translations = $this->translations($input);

            $result = new StoryTranslatedSlugsEnsureAction($this->client)->execute(
                spaceId: $this->spaceId,
                storySlug: $storySlug,
                storyId: $storyId,
                translations: $translations,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title($result->changed ? 'Translated Slugs Updated' : 'Translated Slugs Already Match');
        Render::labelValue('Story ID', $result->storyId);
        Render::labelValue('Changed', (string) $result->changedCount);
        foreach ($result->translations as $translation) {
            Render::labelValue($translation->lang, $translation->translatedSlug);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<StoryTranslatedSlugInput>
     */
    private function translations(InputInterface $input): array
    {
        $translationValues = $this->stringListOption($input->getOption('translation'));
        /** @var string|null $lang */
        $lang = $input->getOption('lang');
        /** @var string|null $slug */
        $slug = $input->getOption('slug');
        /** @var string|null $name */
        $name = $input->getOption('name');
        /** @var string|null $published */
        $published = $input->getOption('published');

        $hasSingleTranslation = $lang !== null || $slug !== null || $name !== null || $published !== null;
        if ($translationValues !== [] && $hasSingleTranslation) {
            throw new \RuntimeException('Use either repeated --translation values or --lang with --slug, not both.');
        }

        if ($translationValues !== []) {
            return $this->batchTranslations($translationValues);
        }

        if ($lang === null || $slug === null) {
            throw new \RuntimeException('Provide --lang and --slug, or at least one --translation value.');
        }

        return [
            new StoryTranslatedSlugInput(
                lang: trim($lang),
                translatedSlug: $slug,
                name: $name,
                published: $published === null ? null : $this->boolValue($published, '--published'),
            ),
        ];
    }

    /**
     * @param list<string> $translationValues
     * @return list<StoryTranslatedSlugInput>
     */
    private function batchTranslations(array $translationValues): array
    {
        $translations = [];
        foreach ($translationValues as $value) {
            $translation = $this->translationFromRecord($value);
            if (isset($translations[$translation->lang])) {
                throw new \RuntimeException('Duplicate --translation value for language: ' . $translation->lang);
            }

            $translations[$translation->lang] = $translation;
        }

        return array_values($translations);
    }

    private function translationFromRecord(string $value): StoryTranslatedSlugInput
    {
        $parts = str_getcsv($value, ';', '"', '\\');
        if (count($parts) < 2 || count($parts) > 4) {
            throw new \RuntimeException('--translation must use the format lang;slug[;name[;published]].');
        }

        $lang = trim((string) $parts[0]);
        $slug = trim((string) $parts[1]);
        $name = isset($parts[2]) ? trim($parts[2]) : null;
        if ($name === '') {
            $name = null;
        }

        $published = null;
        if (isset($parts[3]) && trim($parts[3]) !== '') {
            $published = $this->boolValue(trim($parts[3]), '--translation published');
        }

        if ($lang === '' || $slug === '') {
            throw new \RuntimeException('--translation must use the format lang;slug[;name[;published]].');
        }

        return new StoryTranslatedSlugInput($lang, $slug, $name, $published);
    }

    /**
     * @return list<string>
     */
    private function stringListOption(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    private function boolValue(string $value, string $optionName): bool
    {
        $normalized = strtolower($value);
        if (!in_array($normalized, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            throw new \RuntimeException('Invalid ' . $optionName . ' value: ' . $value);
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
