<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Story\StoryCreateAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\text;

#[AsCommand(
    name: 'story:create',
    description: 'Create a story with content from JSON',
)]
class StoryCreateCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Story name')
            ->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Story slug (auto-generated from name if omitted)')
            ->addOption('content-file', null, InputOption::VALUE_REQUIRED, 'Path to a JSON file with content fields')
            ->addOption('content', null, InputOption::VALUE_REQUIRED, 'Inline JSON string with content fields')
            ->addOption('parent-slug', null, InputOption::VALUE_REQUIRED, 'Parent folder slug')
            ->addOption('parent-id', null, InputOption::VALUE_REQUIRED, 'Parent folder numeric ID (default: 0 for root)')
            ->addOption('publish', null, InputOption::VALUE_NONE, 'Publish the story immediately after creation');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $name */
        $name = $input->getArgument('name');
        /** @var string|null $slug */
        $slug = $input->getOption('slug');
        /** @var string|null $contentFile */
        $contentFile = $input->getOption('content-file');
        /** @var string|null $contentJson */
        $contentJson = $input->getOption('content');
        /** @var string|null $parentSlug */
        $parentSlug = $input->getOption('parent-slug');
        /** @var string|null $parentIdRaw */
        $parentIdRaw = $input->getOption('parent-id');
        $publish = (bool) $input->getOption('publish');

        if ($contentFile && $contentJson) {
            $output->writeln('<error>Provide only one of --content-file or --content</error>');
            return self::FAILURE;
        }

        if ($parentSlug && $parentIdRaw) {
            $output->writeln('<error>Provide only one of --parent-slug or --parent-id</error>');
            return self::FAILURE;
        }

        if (empty($name) && !$input->getOption('no-interaction')) {
            $name = text(
                label: 'Enter the story name',
                placeholder: 'E.g. My Article, About Us',
                required: true,
            );
        }

        if (empty($name)) {
            $output->writeln('<error>Story name is required</error>');
            return self::FAILURE;
        }

        if (!$contentFile && !$contentJson) {
            if ($input->getOption('no-interaction')) {
                $output->writeln('<error>Provide --content-file or --content</error>');
                return self::FAILURE;
            }

            $contentJson = text(
                label: 'Enter the content as JSON',
                placeholder: '{"component": "page", "title": "Hello"}',
                required: true,
            );
        }

        $action = new StoryCreateAction($this->client);

        try {
            // Parse content
            $content = $contentFile !== null ? $action->parseJsonFile($contentFile) : $action->parseJson((string) $contentJson);

            // Resolve parent folder
            $parentId = 0;
            if ($parentSlug !== null) {
                $parentId = $action->resolveParentBySlug($this->spaceId, $parentSlug);
            } elseif ($parentIdRaw !== null) {
                $parentId = (int) $parentIdRaw;
            }

            $result = $action->execute(
                spaceId: $this->spaceId,
                name: $name,
                content: $content,
                slug: $slug,
                parentId: $parentId,
                publish: $publish,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Story Created');
        Render::labelValue('Name', $result->story->name());
        Render::labelValue('Slug', $result->story->slug());
        Render::labelValue('ID', $result->story->id());
        Render::labelValue('Full slug', $result->story->fullSlug());
        if ($publish) {
            Render::labelValue('Published', 'Yes');
        }

        return self::SUCCESS;
    }
}
