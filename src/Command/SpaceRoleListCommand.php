<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceRoleListAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'space:role-list',
    description: 'List the custom space roles',
)]
class SpaceRoleListCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addOption(
            'search',
            null,
            InputOption::VALUE_REQUIRED,
            'Filter roles by name',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $search */
        $search = $input->getOption('search');

        try {
            $result = new SpaceRoleListAction($this->client)->execute($this->spaceId, $search);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        if ($result->count() === 0) {
            Render::log('No custom space roles found');
            return self::SUCCESS;
        }

        Render::title('Space roles (' . $result->count() . ')');
        foreach ($result->roles as $role) {
            Render::titleSection($this->escape($role['role'] ?? ''));
            Render::labelValue('ID', $this->escape($role['id'] ?? ''));
            if (($role['subtitle'] ?? '') !== '' && ($role['subtitle'] ?? null) !== null) {
                Render::labelValue('Subtitle', $this->escape($role['subtitle']));
            }

            Render::labelValue('Permissions', $this->escape($this->listValue($role['permissions'] ?? [])));
            Render::labelValue('Allowed paths', $this->escape($this->pathsValue($role, 'allowed_paths', 'resolved_allowed_paths', 'all')));
            Render::labelValue('Blocked paths', $this->escape($this->pathsValue($role, 'blocked_paths', 'resolved_blocked_paths', 'none')));
            Render::labelValue('Allowed languages', $this->escape($this->listValue($role['allowed_languages'] ?? [])));
        }

        return self::SUCCESS;
    }

    /**
     * Paths as "slug (ID)" when the API returns resolved slugs, otherwise IDs.
     *
     * @param array<string, mixed> $role
     */
    private function pathsValue(array $role, string $idsKey, string $slugsKey, string $whenEmpty): string
    {
        $ids = is_array($role[$idsKey] ?? null) ? array_values($role[$idsKey]) : [];
        $slugs = is_array($role[$slugsKey] ?? null) ? array_values($role[$slugsKey]) : [];
        if ($ids === []) {
            return $whenEmpty;
        }

        $paths = [];
        foreach ($ids as $index => $id) {
            $slug = $slugs[$index] ?? null;
            $paths[] = is_string($slug) && $slug !== ''
                ? $slug . ' (' . $this->stringValue($id) . ')'
                : $this->stringValue($id);
        }

        return implode(', ', $paths);
    }

    private function listValue(mixed $value): string
    {
        $items = is_array($value) ? array_map($this->stringValue(...), $value) : [];

        return $items === [] ? 'all' : implode(', ', $items);
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars($this->stringValue($value), ENT_QUOTES);
    }
}
