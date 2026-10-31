<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Story\StoriesPublishAction;
use Blokctl\Action\Story\StoriesPublishResult;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;

#[AsCommand(
    name: 'stories:publish',
    description: 'Publish selected stories (by full slug, ID, or folder)',
)]
class StoriesPublishCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('slug', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exact full slug of a story (repeatable)')
            ->addOption('id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Story ID (repeatable)')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Folder full slug: every story inside, including nested folders (repeatable)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the selected stories without changing them');
    }

    /**
     * True to publish, false to unpublish.
     */
    protected function publish(): bool
    {
        return true;
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var list<string> $slugs */
        $slugs = $input->getOption('slug');
        /** @var list<string> $ids */
        $ids = $input->getOption('id');
        /** @var list<string> $folders */
        $folders = $input->getOption('folder');
        $verb = $this->publish() ? 'publish' : 'unpublish';

        $action = new StoriesPublishAction($this->client);
        try {
            $preflight = $action->preflight($this->spaceId, $slugs, $ids, $folders, $this->publish());
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title(ucfirst($verb) . ' Stories');
        Render::labelValue('Space ID', $this->spaceId);
        $this->renderEntries($preflight);

        $planned = $preflight->entriesWithStatus(StoriesPublishResult::PLANNED);
        if ($planned === []) {
            Render::log(sprintf('Nothing to %s: every selected story is already in that state.', $verb));
            return self::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            Render::log('Dry run: no changes made.');
            return self::SUCCESS;
        }

        $confirmed = (bool) $input->getOption('no-interaction') || confirm(
            label: sprintf('%s %d story(ies)?', ucfirst($verb), count($planned)),
            default: false,
        );
        if (!$confirmed) {
            Render::log('Cancelled.');
            return self::SUCCESS;
        }

        $result = $action->execute($this->spaceId, $preflight);
        Render::titleSection('Result');
        $this->renderEntries($result);

        return $result->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    private function renderEntries(StoriesPublishResult $result): void
    {
        foreach ($result->entries as $entry) {
            $status = match ($entry['status']) {
                StoriesPublishResult::PLANNED => $result->publish ? 'will be published' : 'will be unpublished',
                StoriesPublishResult::SKIPPED => $result->publish ? 'already published' : 'not published, skipped',
                StoriesPublishResult::PUBLISHED => 'published',
                StoriesPublishResult::UNPUBLISHED => 'unpublished',
                default => 'failed: ' . $entry['message'],
            };
            Render::labelValue(htmlspecialchars($entry['fullSlug'], ENT_QUOTES), htmlspecialchars($status, ENT_QUOTES));
        }
    }
}
