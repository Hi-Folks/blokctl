<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Component;

use Blokctl\Action\Component\ComponentFieldAddAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ComponentFieldAddActionTest extends TestCase
{
    #[Test]
    public function preflight_finds_component_and_validates(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-components'),    // ComponentApi->all
            $this->mockResponse('one-article-page'),   // ComponentApi->get
        );

        $action = new ComponentFieldAddAction($client);
        $result = $action->preflight('680', 'article-page', 'new_field');

        $this->assertSame('article-page', $result->component->name());
        $this->assertArrayNotHasKey('new_field', $result->schema);
    }

    #[Test]
    public function preflight_throws_when_component_not_found(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-components'), // ComponentApi->all
        );

        $action = new ComponentFieldAddAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Component "nonexistent" not found');

        $action->preflight('680', 'nonexistent', 'field');
    }

    #[Test]
    public function preflight_throws_when_field_already_exists(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-components'),    // ComponentApi->all
            $this->mockResponse('one-article-page'),   // ComponentApi->get (has 'title' field)
        );

        $action = new ComponentFieldAddAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Field "title" already exists');

        $action->preflight('680', 'article-page', 'title');
    }

    #[Test]
    public function execute_adds_core_field_with_new_tab(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-components'),    // ComponentApi->all
            $this->mockResponse('one-article-page'),   // ComponentApi->get
            $this->mockResponse('one-article-page'),   // ComponentApi->update response
        );

        $action = new ComponentFieldAddAction($client);
        $preflight = $action->preflight('680', 'article-page', 'subtitle');

        $action->execute('680', $preflight, 'subtitle', 'text', 'New Tab');

        $schema = $preflight->component->getSchema();
        $this->assertArrayHasKey('subtitle', $schema);
        $this->assertSame('text', $schema['subtitle']['type']);
    }

    #[Test]
    public function execute_adds_custom_field_to_existing_tab(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-components'),    // ComponentApi->all
            $this->mockResponse('one-article-page'),   // ComponentApi->get (has "SEO" tab)
            $this->mockResponse('one-article-page'),   // ComponentApi->update response
        );

        $action = new ComponentFieldAddAction($client);
        $preflight = $action->preflight('680', 'article-page', 'seo_field');

        $action->execute('680', $preflight, 'seo_field', 'custom', 'SEO', 'sb-ai-seo');

        $schema = $preflight->component->getSchema();
        $this->assertArrayHasKey('seo_field', $schema);
        $this->assertSame('custom', $schema['seo_field']['type']);
        $this->assertSame('sb-ai-seo', $schema['seo_field']['field_type']);
        $this->assertSame([], $schema['seo_field']['options']);

        // Verify the field was added to the existing SEO tab's keys
        $seoTab = $schema['tab-57951678-9946-4960-bbcb-41136af8180a'];
        $this->assertContains('seo_field', $seoTab['keys']);
    }
}
