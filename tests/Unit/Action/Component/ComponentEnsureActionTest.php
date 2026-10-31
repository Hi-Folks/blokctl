<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Component;

use Blokctl\Action\Component\ComponentEnsureAction;
use Blokctl\Action\Component\ComponentEnsureResult;
use Blokctl\Action\Component\ComponentFolderEnsureAction;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class ComponentEnsureActionTest extends TestCase
{
    #[Test]
    public function execute_creates_component_with_type_folder_and_empty_schema(): void
    {
        $create = $this->mockResponse('one-component-promo', 201);
        $result = new ComponentEnsureAction($this->createMockClient(
            $this->mockResponse('list-components-promo'),
            $this->mockResponse('list-component-groups'),
            $create,
        ))->execute('680', 'hero-banner', displayName: 'Hero', type: ComponentEnsureAction::CONTENT_TYPE, folder: 'Marketing/Banners');

        $this->assertSame(ComponentEnsureResult::CREATED, $result->status);
        $this->assertSame(
            '{"component":{"name":"hero-banner","is_root":true,"is_nestable":false,"display_name":"Hero","component_group_uuid":"uuid-banners","schema":{}}}',
            $this->requestBody($create),
        );
    }

    #[Test]
    public function execute_keeps_matching_component_unchanged(): void
    {
        $result = new ComponentEnsureAction($this->createMockClient(
            $this->mockResponse('list-components-promo'),
            $this->mockResponse('list-component-groups'),
        ))->execute('680', 'promo-banner', 'Promo banner', ComponentEnsureAction::NESTABLE, 'Marketing/Banners', '#ff6600', previewField: 'title');

        $this->assertSame(ComponentEnsureResult::UNCHANGED, $result->status);
        $this->assertSame(800, $result->componentId());
    }

    #[Test]
    public function execute_updates_only_changed_settings_without_schema(): void
    {
        $update = $this->mockResponse('one-component-promo');
        $result = new ComponentEnsureAction($this->createMockClient(
            $this->mockResponse('list-components-promo'),
            $update,
        ))->execute('680', 'promo-banner', displayName: 'Promo', type: ComponentEnsureAction::UNIVERSAL, color: '#ff6600');

        $this->assertSame(['display_name', 'is_root'], $result->changedFields);
        $this->assertStringEndsWith('/v1/spaces/680/components/800', $update->getRequestUrl());
        $this->assertSame(['component' => ['display_name' => 'Promo', 'is_root' => true]], json_decode($this->requestBody($update), true));
    }

    #[Test]
    public function execute_rejects_invalid_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid component type "block". Use content_type, nestable, or universal.');

        new ComponentEnsureAction($this->createMockClient())->execute('680', 'promo-banner', type: 'block');
    }

    #[Test]
    public function folder_ensure_creates_missing_folders_under_their_parent(): void
    {
        $create = $this->mockResponse('one-component-group', 201);
        $result = new ComponentFolderEnsureAction($this->createMockClient(
            $this->mockResponse('list-component-groups'),
            $create,
        ))->execute('680', 'Marketing/Campaigns');

        $this->assertSame(['Marketing/Campaigns'], $result->created);
        $this->assertSame('uuid-campaigns', $result->uuid());
        $this->assertSame(['component_group' => ['name' => 'Campaigns', 'parent_id' => 700]], json_decode($this->requestBody($create), true));
    }

    #[Test]
    public function folder_ensure_reuses_an_existing_path(): void
    {
        $result = new ComponentFolderEnsureAction($this->createMockClient(
            $this->mockResponse('list-component-groups'),
        ))->execute('680', ' Marketing / Banners ');

        $this->assertFalse($result->changed());
        $this->assertSame('uuid-banners', $result->uuid());
    }

    #[Test]
    public function folder_ensure_rejects_a_name_used_elsewhere_before_creating_anything(): void
    {
        $action = new ComponentFolderEnsureAction($this->createMockClient(
            $this->mockResponse('list-component-groups'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Component folder "Campaigns/SEO" already exists in another location.');

        $action->execute('680', 'Campaigns/SEO');
    }

    private function requestBody(MockResponse $response): string
    {
        $body = $response->getRequestOptions()['body'] ?? '';

        return is_string($body) ? $body : '';
    }
}
