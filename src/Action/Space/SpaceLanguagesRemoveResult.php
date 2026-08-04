<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

final readonly class SpaceLanguagesRemoveResult
{
    /**
     * @param string[] $languages
     * @param string[] $removedLanguages
     */
    public function __construct(
        public bool $changed,
        public array $languages,
        public array $removedLanguages,
    ) {}
}
