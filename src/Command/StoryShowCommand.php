<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Story\StoryShowAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[AsCommand(
    name: 'story:show',
    description: 'Display the JSON of a story by slug, ID, or UUID',
)]
class StoryShowCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('by-slug', null, InputOption::VALUE_REQUIRED, 'Find story by full slug')
            ->addOption('by-id', null, InputOption::VALUE_REQUIRED, 'Find story by numeric ID')
            ->addOption('by-uuid', null, InputOption::VALUE_REQUIRED, 'Find story by UUID')
            ->addOption('only-story', null, InputOption::VALUE_NONE, 'Output only the story property instead of the full response');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $slug */
        $slug = $input->getOption('by-slug');
        /** @var string|null $id */
        $id = $input->getOption('by-id');
        /** @var string|null $uuid */
        $uuid = $input->getOption('by-uuid');

        $provided = array_filter([$slug, $id, $uuid]);
        if (count($provided) > 1) {
            $output->writeln('<error>Provide only one of --by-slug, --by-id, or --by-uuid</error>');
            return self::FAILURE;
        }

        if ($provided === [] && !$input->getOption('no-interaction')) {
            $method = (string) select(
                label: 'How do you want to find the story?',
                options: [
                    'slug' => 'By slug',
                    'id' => 'By ID',
                    'uuid' => 'By UUID',
                ],
                default: 'slug',
            );
            $value = text(
                label: match ($method) {
                    'slug' => 'Enter the full slug',
                    'id' => 'Enter the story ID',
                    'uuid' => 'Enter the story UUID',
                    default => 'Enter the value',
                },
                placeholder: match ($method) {
                    'slug' => 'E.g. about or articles/my-article',
                    'id' => 'E.g. 123456789',
                    'uuid' => 'E.g. a1b2c3d4-e5f6-...',
                    default => '',
                },
                required: true,
            );
            match ($method) {
                'slug' => $slug = $value,
                'id' => $id = $value,
                'uuid' => $uuid = $value,
                default => null,
            };
        }

        if (empty($slug) && empty($id) && empty($uuid)) {
            $output->writeln('<error>Provide one of --by-slug, --by-id, or --by-uuid</error>');
            return self::FAILURE;
        }

        try {
            $result = (new StoryShowAction($this->client))->execute(
                spaceId: $this->spaceId,
                id: $id,
                slug: $slug,
                uuid: $uuid,
            );
        } catch (\RuntimeException $runtimeException) {
            $output->writeln('<error>' . $runtimeException->getMessage() . '</error>');
            return self::FAILURE;
        }

        if ($input->getOption('only-story')) {
            $output->writeln((string) $result->story->toJson());
        } else {
            $output->writeln((string) json_encode($result->fullResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
