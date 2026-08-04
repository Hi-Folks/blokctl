<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceLanguagesEnsureAction;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class SpaceLanguagesEnsureActionTest extends TestCase
{
    #[Test]
    public function adds_missing_languages_while_preserving_existing_ones(): void
    {
        $updateResponse = new MockResponse($this->spaceJson(['de', 'it']));
        $action = new SpaceLanguagesEnsureAction($this->createMockClient(
            new MockResponse($this->spaceJson(['de'])),
            $updateResponse,
            new MockResponse($this->spaceJson(['de', 'it'])),
        ));

        $result = $action->execute('680', ['it', 'de']);

        $this->assertTrue($result->changed);
        $this->assertSame(['de', 'it'], $result->languages);
        $this->assertSame(['it'], $result->addedLanguages);
        $payload = $this->requestJsonPayload($updateResponse);
        $this->assertSame('de', $this->valueAtPath($payload, ['space', 'options', 'languages', 0, 'code']));
        $this->assertSame('German', $this->valueAtPath($payload, ['space', 'options', 'languages', 0, 'name']));
        $this->assertSame('it', $this->valueAtPath($payload, ['space', 'options', 'languages', 1, 'code']));
        $this->assertSame('Italian', $this->valueAtPath($payload, ['space', 'options', 'languages', 1, 'name']));
        $this->assertSame('it', $this->valueAtPath($payload, ['space', 'options', 'languages', 1, 'ai_translation_code']));
        $this->assertSame('it', $this->valueAtPath($payload, ['space', 'languages', 1, 'code']));
    }

    #[Test]
    public function skips_when_languages_already_exist(): void
    {
        $action = new SpaceLanguagesEnsureAction($this->createMockClient(
            new MockResponse($this->spaceJson(['de', 'it'])),
        ));

        $result = $action->execute('680', ['it']);

        $this->assertFalse($result->changed);
        $this->assertSame(['de', 'it'], $result->languages);
        $this->assertSame([], $result->addedLanguages);
    }

    /**
     * @param string[] $languages
     */
    private function spaceJson(array $languages): string
    {
        return json_encode([
            'space' => [
                'id' => 680,
                'name' => 'Example Space',
                'options' => [
                    'branch_deployed_hook' => '',
                    'languages' => array_map(static fn(string $code): array => [
                        'code' => $code,
                        'name' => match ($code) {
                            'de' => 'German',
                            'it' => 'Italian',
                            default => $code,
                        },
                    ], $languages),
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<mixed>
     */
    private function requestJsonPayload(MockResponse $response): array
    {
        $body = $response->getRequestOptions()['body'];
        $this->assertIsString($body);
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<mixed> $payload
     * @param list<int|string> $path
     */
    private function valueAtPath(array $payload, array $path): mixed
    {
        $current = $payload;
        foreach ($path as $segment) {
            $this->assertIsArray($current);
            $this->assertArrayHasKey($segment, $current);
            $current = $current[$segment];
        }

        return $current;
    }
}
