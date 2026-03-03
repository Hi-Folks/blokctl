<?php

declare(strict_types=1);

namespace Blokctl\Action\AppProvision;

use Storyblok\ManagementApi\Data\Apps;

final readonly class AppProvisionInstallResult
{
    /**
     * @param array<string, string> $appOptions  [id => "name (slug)"] for interactive selection
     */
    public function __construct(
        public array $appOptions = [],
    ) {}
}
