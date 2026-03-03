<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceDeleteAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;

#[AsCommand(
    name: 'space:delete',
    description: 'Delete a Storyblok space (owner-only, no other collaborators)',
)]
class SpaceDeleteCommand extends AbstractCommand
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $action = new SpaceDeleteAction($this->client);

        try {
            $result = $action->preflight($this->spaceId);
        } catch (\Exception $exception) {
            Render::error('Error fetching space details: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $space = $result->space;
        $collaborators = $result->collaborators;

        // Display space recap
        Render::title(
            sprintf('SPACE: %s (%s)', $space->name(), $this->spaceId),
        );
        Render::labelValue('Space ID', $space->id());
        Render::labelValue('Name', $space->name());
        Render::labelValue('Plan', $space->planDescription());
        Render::labelValueCondition(
            'Demo/example space mode?',
            $space->isDemo(),
            'DEMO/Example space',
            'NOT a DEMO/Example space',
        );

        // Display owner
        Render::titleSection('Owner');
        Render::labelValueCondition(
            'Space ownership',
            $result->isOwner,
            'You are the owner (ID: ' . $space->ownerId() . ')',
            'The owner is user ID: ' . $space->ownerId(),
        );

        // Display collaborators
        Render::titleSection('Collaborators (' . $collaborators->count() . ')');
        /** @var \Storyblok\ManagementApi\Data\Collaborator $collaborator */
        foreach ($collaborators as $collaborator) {
            Render::labelValue(
                $collaborator->friendlyName(),
                $collaborator->role() . ' (' . $collaborator->realEmail() . ')',
            );
        }

        // Safety checks
        if (!$result->isOwner) {
            Render::error(
                'You are not the owner of this space. Only the owner can delete a space.',
            );
            return self::FAILURE;
        }

        if (!$result->isSolo) {
            Render::error(
                'This space has ' . $collaborators->count() . ' collaborators. '
                . 'You can only delete a space when you are the sole collaborator.',
            );
            return self::FAILURE;
        }

        // Confirmation
        $noInteraction = $input->getOption('no-interaction');

        $confirmed = $noInteraction || confirm(
            label: 'Are you sure you want to DELETE space "' . $space->name() . '" (' . $space->id() . ')? This action cannot be undone.',
            default: false,
            yes: 'Yes, delete it',
            no: 'No, keep it',
            required: true,
            hint: 'This will permanently delete the space and all its content.',
        );

        if (!$confirmed) {
            Render::log('Space deletion cancelled.');
            return self::SUCCESS;
        }

        // Delete
        try {
            $action->execute($this->spaceId, $result);
            Render::log('Space "' . $space->name() . '" (' . $space->id() . ') has been deleted.');
        } catch (\Exception $exception) {
            Render::error('Error deleting space: ' . $exception->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
