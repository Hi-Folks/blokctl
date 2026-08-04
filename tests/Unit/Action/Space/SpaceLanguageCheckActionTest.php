<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceLanguageCheckAction;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class SpaceLanguageCheckActionTest extends TestCase
{
    #[Test]
    public function detects_installed_language(): void
    {
        $action = new SpaceLanguageCheckAction($this->createMockClient(
            new MockResponse($this->spaceJson(['it', 'de'])),
        ));

        $this->assertTrue($action->isInstalled('680', 'it'));
    }

    #[Test]
    public function detects_installed_language_from_space_options(): void
    {
        $action = new SpaceLanguageCheckAction($this->createMockClient(
            new MockResponse($this->spaceJson([], optionLanguages: [
                ['code' => 'it', 'name' => 'Italian'],
                ['code' => 'de', 'name' => 'German'],
            ])),
        ));

        $this->assertTrue($action->isInstalled('680', 'it'));
    }

    #[Test]
    public function throws_when_required_language_is_missing(): void
    {
        $action = new SpaceLanguageCheckAction($this->createMockClient(
            new MockResponse($this->spaceJson(['de'])),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Language "it" is not enabled for this space.');

        $action->requireInstalled('680', 'it');
    }

    /**
     * @param string[] $languages
     * @param list<array{code: string, name: string}> $optionLanguages
     */
    private function spaceJson(array $languages, array $optionLanguages = []): string
    {
        return json_encode([
            'space' => [
                'id' => 680,
                'name' => 'Example Space',
                'languages' => array_map(static fn(string $code): array => [
                    'code' => $code,
                ], $languages),
                'options' => [
                    'languages' => $optionLanguages,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
