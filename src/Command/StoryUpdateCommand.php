<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Story\StoryUpdateAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'story:update',
    description: "Update a story's content from simplified JSON",
)]
class StoryUpdateCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('by-slug', null, InputOption::VALUE_REQUIRED, 'Find story by full slug (e.g. articles/my-article)')
            ->addOption('by-id', null, InputOption::VALUE_REQUIRED, 'Find story by numeric ID')
            ->addOption('content-file', null, InputOption::VALUE_REQUIRED, 'Path to a JSON file with content fields')
            ->addOption('content', null, InputOption::VALUE_REQUIRED, 'Inline JSON string with content fields')
            ->addOption('publish', null, InputOption::VALUE_NONE, 'Publish the story after updating');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $storySlug */
        $storySlug = $input->getOption('by-slug');
        /** @var string|null $storyId */
        $storyId = $input->getOption('by-id');
        /** @var string|null $contentFile */
        $contentFile = $input->getOption('content-file');
        /** @var string|null $contentJson */
        $contentJson = $input->getOption('content');
        $publish = (bool) $input->getOption('publish');

        if ($storySlug && $storyId) {
            $output->writeln('<error>Provide only one of --by-slug or --by-id</error>');
            return self::FAILURE;
        }

        if (!$storySlug && !$storyId) {
            $output->writeln('<error>Provide one of --by-slug or --by-id</error>');
            return self::FAILURE;
        }

        if ($contentFile && $contentJson) {
            $output->writeln('<error>Provide only one of --content-file or --content</error>');
            return self::FAILURE;
        }

        if (!$contentFile && !$contentJson) {
            $output->writeln('<error>Provide --content-file or --content</error>');
            return self::FAILURE;
        }

        $action = new StoryUpdateAction($this->client);

        try {
            // Parse content
            $content = $contentFile !== null ? $action->parseJsonFile($contentFile) : $action->parseJson((string) $contentJson);

            $result = $action->execute(
                spaceId: $this->spaceId,
                content: $content,
                storySlug: $storySlug,
                storyId: $storyId,
                publish: $publish,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Story Updated');
        Render::labelValue('Name', $result->story->name());
        Render::labelValue('Slug', $result->story->slug());
        Render::labelValue('ID', $result->story->id());

        /** @var string $key */
        foreach (array_keys($result->appliedContent) as $key) {
            Render::labelValue('  ' . $key, 'updated');
        }

        if ($publish) {
            Render::labelValue('Published', 'Yes');
        }

        return self::SUCCESS;
    }
}
