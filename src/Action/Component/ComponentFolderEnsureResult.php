<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

final readonly class ComponentFolderEnsureResult
{
    /**
     * @param array<string, mixed> $folder The last folder of the path, as returned by the Management API
     * @param list<string> $created Paths of the folders that were created
     */
    public function __construct(
        public array $folder,
        public array $created,
    ) {}

    public function changed(): bool
    {
        return $this->created !== [];
    }

    public function uuid(): string
    {
        $uuid = $this->folder['uuid'] ?? null;

        return is_string($uuid) ? $uuid : '';
    }
}
