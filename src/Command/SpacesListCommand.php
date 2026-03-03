<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpacesListAction;
use Blokctl\Render;
use Storyblok\ManagementApi\Data\Enum\Region;
use Storyblok\ManagementApi\ManagementApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: "spaces:list",
    description: "List spaces with optional search filter",
),]
class SpacesListCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            "search",
            null,
            InputOption::VALUE_REQUIRED,
            "Search spaces by name",
        );
        $this->addOption(
            "owned-only",
            null,
            InputOption::VALUE_NONE,
            "Only show spaces owned by the authenticated user",
        );
        $this->addOption(
            "updated-before",
            null,
            InputOption::VALUE_REQUIRED,
            "Only show spaces last updated more than N days ago",
        );
        $this->addOption(
            "solo-only",
            null,
            InputOption::VALUE_NONE,
            "Only show spaces where the authenticated user is the only collaborator (implies --owned-only)",
        );
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
        $token = $_ENV["SECRET_KEY"] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException(
                "SECRET_KEY not found in environment. Check your .env file.",
            );
        }

        /** @var string|null $updatedBefore */
        $updatedBefore = $input->getOption("updated-before");
        $updatedBeforeDays = null;
        if ($updatedBefore !== null) {
            $days = (int) $updatedBefore;
            if ($days <= 0) {
                Render::error(
                    "--updated-before must be a positive number of days.",
                );
                return self::FAILURE;
            }

            $updatedBeforeDays = $days;
        }

        /** @var string|null $search */
        $search = $input->getOption("search");

        /** @var string|null $regionValue */
        $regionValue = $input->getOption("region");
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
        $action = new SpacesListAction($client);

        $result = $action->execute(
            search: $search,
            ownedOnly: (bool) $input->getOption("owned-only"),
            updatedBeforeDays: $updatedBeforeDays,
            soloOnly: (bool) $input->getOption("solo-only"),
        );

        foreach ($result->errors as $error) {
            Render::error($error);
        }

        Render::titleSection("Spaces");

        foreach ($result->spaces as $space) {
            $details = [];
            $details[] = "ID: " . $space->id();
            $details[] = $space->planDescription() ?? '';
            if ($space->isDemo()) {
                $details[] = "DEMO";
            }

            $details[] = "created: " . ($space->createdAt() ?? "-");
            $details[] = "updated: " . ($space->updatedAt() ?? "-");

            Render::labelValue($space->name(), implode(" | ", $details));
        }

        if ($result->count() === 0) {
            Render::log("No spaces found");
        } else {
            Render::log("Total: " . $result->count() . " space(s)");
        }

        return self::SUCCESS;
    }
}
