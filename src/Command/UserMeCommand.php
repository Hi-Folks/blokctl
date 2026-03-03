<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\User\UserMeAction;
use Blokctl\Render;
use Storyblok\ManagementApi\Data\Enum\Region;
use Storyblok\ManagementApi\ManagementApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'user:me',
    description: 'Display information about the authenticated user',
)]
class UserMeCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'region',
            'R',
            InputOption::VALUE_REQUIRED,
            'The Storyblok region (' . implode(', ', Region::values()) . ')',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $token = $_ENV['SECRET_KEY'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException(
                'SECRET_KEY not found in environment. Check your .env file.',
            );
        }

        /** @var string|null $regionValue */
        $regionValue = $input->getOption('region');
        $region = Region::EU;
        if ($regionValue !== null) {
            $region = Region::tryFrom(strtoupper($regionValue));
            if ($region === null) {
                Render::error(
                    'Invalid region "' . $regionValue . '". Valid regions: ' . implode(', ', Region::values()),
                );
                return self::FAILURE;
            }
        }

        $client = new ManagementApiClient($token, region: $region, shouldRetry: true);
        $result = (new UserMeAction($client))->execute();
        $user = $result->user;

        Render::title('Current user');
        Render::labelValue('ID', $user->id());
        Render::labelValue('User identifier', $user->userId());
        Render::labelValue('Friendly name', $user->friendlyName());
        Render::labelValue('Username', $user->username());
        Render::labelValue('First name', $user->firstname());
        Render::labelValue('Last name', $user->lastname());
        Render::labelValue('Email', $user->email());
        Render::labelValue('Timezone', $user->timezone());
        Render::labelValue('Language', $user->lang());
        Render::labelValue('Login strategy', $user->loginStrategy());
        Render::labelValue('Job role', $user->jobRole());
        Render::labelValue('Created at', $user->createdAt());
        Render::labelValueCondition(
            'Editor',
            $user->isEditor(),
            'Yes',
            'No',
        );
        Render::labelValueCondition(
            'SSO',
            $user->isSso(),
            'Yes',
            'No',
        );
        Render::labelValueCondition(
            'Organization',
            $user->hasOrganization(),
            $user->orgName() . ' (' . $user->orgRole() . ')',
            'No organization',
        );
        Render::labelValueCondition(
            'Partner program',
            $user->hasPartner(),
            $user->partnerRole() . ' (' . $user->partnerStatus() . ')',
            'Not a partner',
        );

        return self::SUCCESS;
    }
}
