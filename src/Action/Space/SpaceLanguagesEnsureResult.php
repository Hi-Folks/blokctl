<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

final readonly class SpaceLanguagesEnsureResult
{
    /**
     * @param string[] $languages
     * @param string[] $addedLanguages
     */
    public function __construct(
        public bool $changed,
        public array $languages,
        public array $addedLanguages,
    ) {}
}
