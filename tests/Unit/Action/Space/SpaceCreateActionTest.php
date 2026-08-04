<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceCreateAction;
use PHPUnit\Framework\Attributes\Test;
use Storyblok\ManagementApi\ManagementApiClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class SpaceCreateActionTest extends TestCase
{
    #[Test]
    public function execute_creates_a_space(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-space-created'),
        );

        $result = new SpaceCreateAction($client)->execute(
            name: 'NEW SPACE FROM TEMPLATE',
            isDemo: true,
        );

        $this->assertFalse($result->duplicated);
        $this->assertNull($result->duplicateFrom);
        $this->assertSame('123456789', $result->space->id());
        $this->assertSame('NEW SPACE FROM TEMPLATE', $result->space->name());
        $this->assertTrue($result->space->isDemo());
    }

    #[Test]
    public function execute_duplicates_a_space_with_expected_payload(): void
    {
        $response = new MockResponse($this->mockData('one-space-created'));
        $client = ManagementApiClient::initTest(new MockHttpClient([$response]));

        $result = new SpaceCreateAction($client)->execute(
            name: 'NEW SPACE FROM TEMPLATE',
            duplicateFrom: '286863409930127',
            isDemo: true,
            inOrg: true,
        );

        $this->assertTrue($result->duplicated);
        $this->assertSame('286863409930127', $result->duplicateFrom);
        $this->assertSame('123456789', $result->space->id());
        $body = $response->getRequestOptions()['body'];
        $this->assertIsString($body);
        parse_str($body, $payload);

        $this->assertSame([
            'space' => [
                'name' => 'NEW SPACE FROM TEMPLATE',
                'is_demo' => '1',
            ],
            'dup_id' => '286863409930127',
            'in_org' => '1',
        ], $payload);
    }

    #[Test]
    public function execute_includes_validation_error_details_when_space_creation_fails(): void
    {
        $client = ManagementApiClient::initTest(new MockHttpClient([
            new MockResponse(
                json_encode([
                    'base' => [
                        'You reached the maximum number of spaces you can create.',
                    ],
                ], JSON_THROW_ON_ERROR),
                ['http_code' => 422],
            ),
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create space: You reached the maximum number of spaces you can create.');

        new SpaceCreateAction($client)->execute(
            name: 'NEW SPACE FROM TEMPLATE',
            duplicateFrom: '286863409930127',
            inOrg: true,
        );
    }
}
