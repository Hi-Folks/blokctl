<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\AppProvision\AppProvisionInstallAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\select;

#[AsCommand(
    name: "app:provision-install",
    description: "Install an app into a Storyblok space",
),]
class AppProvisionInstallCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument(
            "app-id",
            InputArgument::OPTIONAL,
            "The app ID to install (prompted interactively if omitted)",
        );
        $this->addOption(
            "by-slug",
            null,
            InputOption::VALUE_REQUIRED,
            "Find and install the app by its slug",
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $appId */
        $appId = $input->getArgument("app-id");
        /** @var string|null $bySlug */
        $bySlug = $input->getOption("by-slug");

        if ($appId && $bySlug) {
            Render::error("Provide either app-id or --by-slug, not both.");
            return self::FAILURE;
        }

        $action = new AppProvisionInstallAction($this->client);

        // Resolve slug to app ID
        if ($bySlug) {
            try {
                $appId = $action->resolveBySlug($this->spaceId, $bySlug);
            } catch (\RuntimeException $e) {
                Render::error($e->getMessage());
                return self::FAILURE;
            }
        }

        // Interactive selection
        if (empty($appId) && !$input->getOption("no-interaction")) {
            $result = $action->preflight($this->spaceId);

            if ($result->appOptions === []) {
                Render::error("No apps available for this space");
                return self::FAILURE;
            }

            $appId = (string) select(
                label: "Which app do you want to install?",
                options: $result->appOptions,
            );
        }

        if (empty($appId)) {
            Render::error(
                "App ID is required. Provide it as an argument, use --by-slug, or run interactively.",
            );
            return self::FAILURE;
        }

        try {
            $provision = $action->execute($this->spaceId, $appId);
            Render::titleSection("App installed");
            Render::labelValue("Name", $provision->name());
            Render::labelValue("App ID", $provision->appId());
            Render::labelValue("Slug", $provision->slug());
        } catch (\Exception $exception) {
            Render::error(
                "Failed to install app " . $appId . ": " . $exception->getMessage(),
            );
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
