<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceReadinessAction;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class SpaceReadinessActionTest extends TestCase
{
    #[Test]
    public function returns_immediately_when_space_has_no_pending_tasks(): void
    {
        $client = $this->createMockClient($this->spaceResponse(false));

        $result = new SpaceReadinessAction($client)->execute('123456');

        $this->assertSame(1, $result->attempts);
    }

    #[Test]
    public function polls_until_pending_tasks_are_complete(): void
    {
        $now = 0.0;
        $client = $this->createMockClient(
            $this->spaceResponse(true),
            $this->spaceResponse(true),
            $this->spaceResponse(false),
        );

        $result = new SpaceReadinessAction(
            client: $client,
            clock: static function () use (&$now): float {
                return $now;
            },
            sleep: static function (int $microseconds) use (&$now): void {
                $now += $microseconds / 1_000_000;
            },
        )->execute('123456', timeoutSeconds: 10, pollIntervalSeconds: 2);

        $this->assertSame(3, $result->attempts);
        $this->assertEqualsWithDelta(4.0, $result->elapsedSeconds, PHP_FLOAT_EPSILON);
    }

    #[Test]
    public function fails_when_pending_tasks_exceed_timeout(): void
    {
        $now = 0.0;
        $client = $this->createMockClient(
            $this->spaceResponse(true),
            $this->spaceResponse(true),
            $this->spaceResponse(true),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('still has pending tasks after 3 seconds');

        new SpaceReadinessAction(
            client: $client,
            clock: static function () use (&$now): float {
                return $now;
            },
            sleep: static function (int $microseconds) use (&$now): void {
                $now += $microseconds / 1_000_000;
            },
        )->execute('123456', timeoutSeconds: 3, pollIntervalSeconds: 2);
    }

    #[Test]
    public function fails_when_api_does_not_expose_pending_tasks(): void
    {
        $client = $this->createMockClient(new MockResponse(json_encode([
            'space' => [
                'id' => 123456,
                'name' => 'Duplicated space',
            ],
        ], JSON_THROW_ON_ERROR)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not contain has_pending_tasks');

        new SpaceReadinessAction($client)->execute('123456');
    }

    private function spaceResponse(bool $hasPendingTasks): MockResponse
    {
        return new MockResponse(json_encode([
            'space' => [
                'id' => 123456,
                'name' => 'Duplicated space',
                'has_pending_tasks' => $hasPendingTasks,
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
