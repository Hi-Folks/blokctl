<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Story\StoryFieldSetAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\text;

#[AsCommand(
    name: 'story:field-set',
    description: 'Set a content field value on a story',
)]
class StoryFieldSetCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('field', InputArgument::OPTIONAL, 'Field name (e.g. headline, body, image)')
            ->addArgument('value', InputArgument::OPTIONAL, 'Field value (string, JSON, local file path, or URL)')
            ->addOption('by-slug', null, InputOption::VALUE_REQUIRED, 'Find story by full slug (e.g. articles/my-article)')
            ->addOption('by-id', null, InputOption::VALUE_REQUIRED, 'Find story by numeric ID')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Value type: text (default), json, asset');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $storySlug */
        $storySlug = $input->getOption('by-slug');
        /** @var string|null $storyId */
        $storyId = $input->getOption('by-id');
        /** @var string|null $fieldName */
        $fieldName = $input->getArgument('field');
        /** @var string|null $rawValue */
        $rawValue = $input->getArgument('value');
        /** @var string|null $type */
        $type = $input->getOption('type');

        $type ??= 'text';

        if (!in_array($type, ['text', 'json', 'asset'], true)) {
            $output->writeln('<error>Invalid --type: ' . $type . '. Use text, json, or asset</error>');
            return self::FAILURE;
        }

        if ($storySlug && $storyId) {
            $output->writeln('<error>Provide only one of --by-slug or --by-id</error>');
            return self::FAILURE;
        }

        $noInteraction = (bool) $input->getOption('no-interaction');

        // Prompt for story lookup if not provided
        if (!$storySlug && !$storyId && !$noInteraction) {
            $storySlug = text(
                label: 'Enter the story slug',
                placeholder: 'E.g. articles/my-article',
                required: true,
            );
        }

        if (!$storySlug && !$storyId) {
            $output->writeln('<error>Provide one of --by-slug or --by-id</error>');
            return self::FAILURE;
        }

        // Prompt for field name if not provided
        if (empty($fieldName) && !$noInteraction) {
            $fieldName = text(
                label: 'Enter the field name',
                placeholder: 'E.g. headline, body, image',
                required: true,
            );
        }

        if (empty($fieldName)) {
            $output->writeln('<error>Field name is required</error>');
            return self::FAILURE;
        }

        // Prompt for value if not provided
        if ($rawValue === null && !$noInteraction) {
            $rawValue = text(
                label: match ($type) {
                    'asset' => 'Enter the image path or URL',
                    'json' => 'Enter the JSON value',
                    default => 'Enter the field value',
                },
                placeholder: match ($type) {
                    'asset' => 'E.g. /path/to/image.jpg or https://example.com/image.jpg',
                    'json' => '{"key": "value"}',
                    default => 'E.g. My headline text',
                },
                required: true,
            );
        }

        if ($rawValue === null) {
            $output->writeln('<error>Field value is required</error>');
            return self::FAILURE;
        }

        // Parse value based on type
        $fieldValue = $rawValue;
        if ($type === 'json') {
            try {
                $fieldValue = json_decode($rawValue, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                Render::error('Invalid JSON value: ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        try {
            $result = (new StoryFieldSetAction($this->client))->execute(
                spaceId: $this->spaceId,
                fieldName: $fieldName,
                fieldValue: $fieldValue,
                storySlug: $storySlug,
                storyId: $storyId,
                isAsset: $type === 'asset',
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Field Updated');
        Render::labelValue('Story', $result->story->name());
        Render::labelValue('Slug', $result->story->slug());
        Render::labelValue('Field', $result->fieldName);
        Render::labelValue('Value', is_string($result->newValue) ? $result->newValue : (string) json_encode($result->newValue));
        if ($result->previousValue !== null) {
            Render::labelValue(
                'Previous',
                is_string($result->previousValue) ? $result->previousValue : (string) json_encode($result->previousValue),
            );
        }

        return self::SUCCESS;
    }
}
