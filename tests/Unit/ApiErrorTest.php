<?php

declare(strict_types=1);

namespace Tests\Unit;

use Blokctl\ApiError;
use PHPUnit\Framework\Attributes\Test;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\Response\StoryblokResponseInterface;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class ApiErrorTest extends TestCase
{
    #[Test]
    public function message_includes_field_errors_from_the_body(): void
    {
        $message = ApiError::message($this->failedResponse(
            '{"name":["has already been taken"],"color":["has already been taken"]}',
            422,
        ));

        $this->assertSame('name: has already been taken; color: has already been taken', $message);
    }

    #[Test]
    public function message_shows_base_and_error_messages_without_field_name(): void
    {
        $this->assertSame(
            'You reached the maximum number of spaces you can create.',
            ApiError::message($this->failedResponse('{"base":["You reached the maximum number of spaces you can create."]}', 422)),
        );
        $this->assertSame('Not allowed', ApiError::message($this->failedResponse('{"error":"Not allowed"}', 403)));
        $this->assertSame('Invalid request', ApiError::message($this->failedResponse('["Invalid request"]', 422)));
    }

    #[Test]
    public function message_falls_back_to_client_message_without_details(): void
    {
        $this->assertStringStartsWith('404 - Not Found', ApiError::message($this->failedResponse('', 404)));
        $this->assertStringStartsWith('500 - Server error', ApiError::message($this->failedResponse('<html>oops</html>', 500)));
    }

    private function failedResponse(string $body, int $status): StoryblokResponseInterface
    {
        $client = $this->createMockClient(new MockResponse($body, ['http_code' => $status]));

        return new ManagementApi($client)->get('spaces/1');
    }
}
