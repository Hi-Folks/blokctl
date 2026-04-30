<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoriesBulkCreateAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StoriesBulkCreateActionTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blokctl_bulk_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function execute_creates_a_story_per_content_only_json_file(): void
    {
        file_put_contents(
            $this->tempDir . '/about.json',
            json_encode(['component' => 'page', 'title' => 'About'], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->tempDir . '/team-members.json',
            json_encode(['component' => 'page', 'title' => 'Team'], JSON_THROW_ON_ERROR),
        );

        $client = $this->createMockClient(
            $this->mockResponse('one-story'),
            $this->mockResponse('one-story'),
        );

        $action = new StoriesBulkCreateAction($client);
        $result = $action->execute('680', $this->tempDir);

        $this->assertSame(2, $result->count());
        $this->assertSame(0, $result->errorCount());
        $this->assertSame('about', $result->created[0]['file'] ? pathinfo($result->created[0]['file'], PATHINFO_FILENAME) : '');
    }

    #[Test]
    public function execute_supports_wrapper_format(): void
    {
        file_put_contents(
            $this->tempDir . '/raw.json',
            json_encode([
                'name' => 'Custom Name',
                'slug' => 'custom-slug',
                'content' => ['component' => 'page', 'title' => 'x'],
            ], JSON_THROW_ON_ERROR),
        );

        $client = $this->createMockClient(
            $this->mockResponse('one-story'),
        );

        $action = new StoriesBulkCreateAction($client);
        $result = $action->execute('680', $this->tempDir);

        $this->assertSame(1, $result->count());
        $this->assertSame(0, $result->errorCount());
    }

    #[Test]
    public function execute_collects_errors_and_continues(): void
    {
        // Missing "component" key → fails without hitting the API
        file_put_contents(
            $this->tempDir . '/broken.json',
            json_encode(['title' => 'no component'], JSON_THROW_ON_ERROR),
        );
        // Valid file → reaches the API
        file_put_contents(
            $this->tempDir . '/ok.json',
            json_encode(['component' => 'page'], JSON_THROW_ON_ERROR),
        );

        $client = $this->createMockClient(
            $this->mockResponse('one-story'),
        );

        $action = new StoriesBulkCreateAction($client);
        $result = $action->execute('680', $this->tempDir);

        $this->assertSame(1, $result->count());
        $this->assertSame(1, $result->errorCount());
        $this->assertStringContainsString('component', $result->errors[0]['error']);
    }

    #[Test]
    public function execute_throws_when_directory_missing(): void
    {
        $client = $this->createMockClient();

        $action = new StoriesBulkCreateAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Directory not found');

        $action->execute('680', '/nonexistent/' . bin2hex(random_bytes(4)));
    }

    #[Test]
    public function execute_walks_subdirectories_when_recursive(): void
    {
        mkdir($this->tempDir . '/sub', 0o777, true);
        file_put_contents(
            $this->tempDir . '/top.json',
            json_encode(['component' => 'page'], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->tempDir . '/sub/nested.json',
            json_encode(['component' => 'page'], JSON_THROW_ON_ERROR),
        );

        $client = $this->createMockClient(
            $this->mockResponse('one-story'),
            $this->mockResponse('one-story'),
        );

        $action = new StoriesBulkCreateAction($client);
        $result = $action->execute('680', $this->tempDir, recursive: true);

        $this->assertSame(2, $result->count());
    }

    #[Test]
    public function execute_skips_subdirectories_when_not_recursive(): void
    {
        mkdir($this->tempDir . '/sub', 0o777, true);
        file_put_contents(
            $this->tempDir . '/top.json',
            json_encode(['component' => 'page'], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->tempDir . '/sub/nested.json',
            json_encode(['component' => 'page'], JSON_THROW_ON_ERROR),
        );

        $client = $this->createMockClient(
            $this->mockResponse('one-story'),
        );

        $action = new StoriesBulkCreateAction($client);
        $result = $action->execute('680', $this->tempDir);

        $this->assertSame(1, $result->count());
    }

    #[Test]
    public function extract_from_json_uses_filename_when_content_only(): void
    {
        $client = $this->createMockClient();
        $action = new StoriesBulkCreateAction($client);

        [$name, $slug, $content] = $action->extractFromJson(
            ['component' => 'page'],
            '/tmp/team-members.json',
        );

        $this->assertSame('Team Members', $name);
        $this->assertSame('team-members', $slug);
        $this->assertSame(['component' => 'page'], $content);
    }

    #[Test]
    public function extract_from_json_prefers_wrapper_name_and_slug(): void
    {
        $client = $this->createMockClient();
        $action = new StoriesBulkCreateAction($client);

        [$name, $slug, $content] = $action->extractFromJson(
            [
                'name' => 'Wrapped Name',
                'slug' => 'wrapped-slug',
                'content' => ['component' => 'page'],
            ],
            '/tmp/ignored.json',
        );

        $this->assertSame('Wrapped Name', $name);
        $this->assertSame('wrapped-slug', $slug);
        $this->assertSame(['component' => 'page'], $content);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
