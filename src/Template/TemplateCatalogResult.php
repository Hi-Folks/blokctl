<?php

declare(strict_types=1);

namespace Blokctl\Template;

final readonly class TemplateCatalogResult
{
    /**
     * @param list<TemplateCatalogEntry> $templates
     * @param list<array{path: string, error: string}> $errors
     */
    public function __construct(
        public array $templates,
        public array $errors = [],
    ) {}

    /** @return array{templates: list<array<string, mixed>>, errors: list<array{path: string, error: string}>} */
    public function toArray(): array
    {
        return [
            'templates' => array_map(
                static fn(TemplateCatalogEntry $template): array => $template->toArray(),
                $this->templates,
            ),
            'errors' => $this->errors,
        ];
    }
}
