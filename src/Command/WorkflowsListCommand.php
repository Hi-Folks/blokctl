<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Workflow\WorkflowsListAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'workflows:list',
    description: 'List workflows and their stages',
)]
class WorkflowsListCommand extends AbstractCommand
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $result = (new WorkflowsListAction($this->client))->execute($this->spaceId);

        if ($result->count() === 0) {
            Render::log('No workflows found');
            return self::SUCCESS;
        }

        foreach ($result->workflows as $workflow) {
            $label = $workflow['name'];
            if ($workflow['isDefault']) {
                $label .= ' (default)';
            }

            Render::titleSection($label . ' — ID: ' . $workflow['id']);

            foreach ($workflow['stages'] as $stage) {
                Render::labelValue(
                    $stage['name'],
                    'ID: ' . $stage['id'],
                );
            }
        }

        return self::SUCCESS;
    }
}
