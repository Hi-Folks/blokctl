<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceRoleEnsureAction;
use Blokctl\Action\Space\SpaceRoleEnsureResult;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\text;

#[AsCommand(
    name: 'space:role-ensure',
    description: 'Create a custom space role, or update its declared properties',
)]
class SpaceRoleEnsureCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Role name (the role is matched by exact name)')
            ->addOption('subtitle', null, InputOption::VALUE_REQUIRED, 'Role description')
            ->addOption('permission', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Permission key, e.g. read_stories (repeatable)')
            ->addOption('allow', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Story or folder the role can access, by full slug or ID (repeatable)')
            ->addOption('block', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Story or folder the role cannot access, by full slug or ID (repeatable)')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Language code the role can edit, e.g. default (repeatable)');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $name */
        $name = $input->getOption('name');
        if (empty($name) && !$input->getOption('no-interaction')) {
            $name = text(
                label: 'What is the role name?',
                placeholder: 'E.g. Editors - North Region',
                required: 'The role name is required.',
            );
        }

        if (empty($name)) {
            Render::error('Role name is required. Provide it with --name.');
            return self::FAILURE;
        }

        /** @var string|null $subtitle */
        $subtitle = $input->getOption('subtitle');

        try {
            $result = new SpaceRoleEnsureAction($this->client)->execute(
                spaceId: $this->spaceId,
                name: $name,
                subtitle: $subtitle,
                permissions: $this->listOption($input, 'permission'),
                allowedPaths: $this->listOption($input, 'allow'),
                blockedPaths: $this->listOption($input, 'block'),
                allowedLanguages: $this->listOption($input, 'language'),
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title(match ($result->status) {
            SpaceRoleEnsureResult::CREATED => 'Space Role Created',
            SpaceRoleEnsureResult::UPDATED => 'Space Role Updated',
            default => 'Space Role Already Matches',
        });
        Render::labelValue('Space ID', $this->spaceId);
        Render::labelValue('Role', htmlspecialchars($name, ENT_QUOTES));
        Render::labelValue('Role ID', (string) $result->roleId());
        if ($result->changedFields !== []) {
            Render::labelValue('Changed', implode(', ', $result->changedFields));
        }

        return self::SUCCESS;
    }

    /**
     * A repeatable option as a list, or null when it was not provided (not declared).
     *
     * @return list<string>|null
     */
    private function listOption(InputInterface $input, string $name): ?array
    {
        /** @var list<string> $values */
        $values = $input->getOption($name);

        return $values === [] ? null : $values;
    }
}
