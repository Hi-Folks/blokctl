<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Story\StoryVersionsAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[AsCommand(
    name: 'story:versions',
    description: 'List versions of a story',
)]
class StoryVersionsCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('by-slug', null, InputOption::VALUE_REQUIRED, 'Find story by full slug')
            ->addOption('by-id', null, InputOption::VALUE_REQUIRED, 'Find story by numeric ID')
            ->addOption('by-uuid', null, InputOption::VALUE_REQUIRED, 'Find story by UUID')
            ->addOption('show-content', null, InputOption::VALUE_NONE, 'Include full content of each version')
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Page number', '1')
            ->addOption('per-page', null, InputOption::VALUE_REQUIRED, 'Results per page (max 100)', '25');
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

        /** @var string $pageStr */
        $pageStr = $input->getOption('page');
        /** @var string $perPageStr */
        $perPageStr = $input->getOption('per-page');

        try {
            $result = (new StoryVersionsAction($this->client))->execute(
                spaceId: $this->spaceId,
                id: $id,
                slug: $slug,
                uuid: $uuid,
                showContent: (bool) $input->getOption('show-content'),
                page: (int) $pageStr,
                perPage: (int) $perPageStr,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Story Versions');
        Render::labelValue('Story ID', $result->storyId);
        Render::labelValue('Versions found', (string) $result->count());

        if ($result->count() === 0) {
            Render::log('No versions found for this story.');
            return self::SUCCESS;
        }

        foreach ($result->versions as $version) {
            /** @var array<string, mixed> $version */
            $versionId = isset($version['id']) && is_scalar($version['id']) ? strval($version['id']) : '?';
            Render::titleSection(
                'Version #' . $versionId,
            );

            /** @var string $createdAt */
            $createdAt = $version['created_at'] ?? 'unknown';
            Render::labelValue('Created at', $createdAt);

            /** @var string $status */
            $status = $version['status'] ?? 'unknown';
            Render::labelValue('Status', $status);

            if (isset($version['user']) && is_array($version['user'])) {
                /** @var array<string, string> $user */
                $user = $version['user'];
                $authorName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
                if ($authorName !== '') {
                    Render::labelValue('Author', $authorName);
                }
            }

            if (isset($version['release_id']) && is_scalar($version['release_id']) && $version['release_id'] !== '') {
                Render::labelValue('Release ID', strval($version['release_id']));
            }

            if ($input->getOption('show-content') && isset($version['content'])) {
                $output->writeln((string) json_encode($version['content'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

        return self::SUCCESS;
    }
}
