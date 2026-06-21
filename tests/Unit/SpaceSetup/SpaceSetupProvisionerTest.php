<?php

declare(strict_types=1);

namespace Tests\Unit\SpaceSetup;

use Blokctl\SpaceSetup\SpaceSetupOperationResult;
use Blokctl\SpaceSetup\SpaceSetupOperationStatus;
use Blokctl\SpaceSetup\SpaceSetupProvisioner;
use Blokctl\SpaceSetup\SpaceSetupProvisioningException;
use Blokctl\SpaceSetup\SpaceSetupReporter;
use PHPUnit\Framework\Attributes\Test;
use Storyblok\ManagementApi\ManagementApiClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

use function Termwind\renderUsing;

final class SpaceSetupProvisionerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        renderUsing(new NullOutput());
    }

    protected function tearDown(): void
    {
        renderUsing(null);
        parent::tearDown();
    }

    #[Test]
    public function plans_preview_configuration(): void
    {
        $result = $this->singleResult([
            'preview' => [
                'default' => 'https://demo.example.com',
                'environments' => [
                    [
                        'name' => 'Local',
                        'url' => 'https://localhost:3000',
                    ],
                ],
            ],
        ]);

        $this->assertPlanned($result, 'Configure preview URLs');
        $this->assertSame('https://demo.example.com (1 configured environments)', $result->detail);
    }

    #[Test]
    public function plans_demo_mode_removal(): void
    {
        $result = $this->singleResult([
            'demo_mode' => [
                'remove' => true,
            ],
        ]);

        $this->assertPlanned($result, 'Remove demo mode');
    }

    #[Test]
    public function plans_workflow_assignment(): void
    {
        $result = $this->singleResult([
            'workflow' => [
                'assign_unstaged' => true,
                'stage_id' => 123,
            ],
        ]);

        $this->assertPlanned($result, 'Assign workflow stages');
    }

    #[Test]
    public function plans_each_app_installation(): void
    {
        $results = $this->provision([
            'apps' => [
                'install' => ['backups', 'releases_only'],
            ],
        ])->results();

        $this->assertCount(2, $results);
        $this->assertPlanned($results[0], 'Install app: backups');
        $this->assertPlanned($results[1], 'Install app: releases_only');
    }

    #[Test]
    public function plans_storyblok_ai_configuration(): void
    {
        $result = $this->singleResult([
            'ai' => [
                'enabled' => true,
                'inherit_org_configuration' => false,
            ],
        ]);

        $this->assertPlanned($result, 'Configure Storyblok AI');
    }

    #[Test]
    public function plans_ai_translation_disclaimer_configuration(): void
    {
        $result = $this->singleResult([
            'ai_translation' => [
                'disclaimer_id' => 173657768407244,
            ],
        ]);

        $this->assertPlanned($result, 'Configure AI Translation disclaimer');
    }

    #[Test]
    public function plans_multi_country_provisioning(): void
    {
        $results = $this->provision([
            'folders' => [
                'ensure' => [
                    ['name' => 'Global', 'slug' => 'global'],
                    ['name' => 'Italy', 'slug' => 'italy'],
                ],
            ],
            'stories' => [
                'move' => [
                    [
                        'select' => [
                            'parent' => 'root',
                            'include_folders' => true,
                            'exclude_slugs' => ['site-config'],
                        ],
                        'to_folder' => 'global',
                    ],
                ],
            ],
            'apps' => [
                'install' => [
                    ['slug' => 'dimensions', 'id' => 24],
                ],
            ],
            'dimensions' => [
                'folders' => [
                    ['slug' => 'global'],
                    ['slug' => 'italy', 'ai_translation_code' => 'it'],
                ],
            ],
        ])->results();

        $this->assertCount(5, $results);
        $this->assertPlanned($results[0], 'Ensure folder: global');
        $this->assertPlanned($results[1], 'Ensure folder: italy');
        $this->assertPlanned($results[2], 'Move selected root content to: global');
        $this->assertPlanned($results[3], 'Install app: dimensions');
        $this->assertPlanned($results[4], 'Configure Dimensions folders');
    }

    #[Test]
    public function plans_story_component_updates_and_story_creation(): void
    {
        $results = $this->provision([
            'stories' => [
                'update' => [
                    [
                        'slug' => 'home',
                        'components' => [
                            [
                                'path' => 'content.body[0]',
                                'component' => 'hero-section',
                                'fields' => ['eyebrow' => 'Welcome to'],
                            ],
                        ],
                    ],
                ],
                'create' => [
                    [
                        'name' => 'Landing Page',
                        'slug' => 'landing',
                        'content' => [
                            'component' => 'default-page',
                            'body' => [],
                        ],
                    ],
                ],
            ],
        ])->results();

        $this->assertCount(2, $results);
        $this->assertPlanned($results[0], 'Update story components: home');
        $this->assertSame('1 component update(s) planned.', $results[0]->detail);
        $this->assertPlanned($results[1], 'Create story: landing');
    }

    #[Test]
    public function plans_asset_uploads_before_story_creation(): void
    {
        $directory = $this->temporaryAssetDirectory([
            'hero.png' => 'hero',
        ]);

        try {
            $results = $this->provision([
                'assets' => [
                    'upload_directory' => [
                        [
                            'source' => $directory,
                            'target_folder' => 'Provisioned',
                        ],
                    ],
                ],
                'stories' => [
                    'create' => [
                        [
                            'name' => 'Auto Created',
                            'slug' => 'auto-created',
                            'content' => [
                                'component' => 'default-page',
                            ],
                        ],
                    ],
                ],
            ])->results();
        } finally {
            $this->removeDirectory($directory);
        }

        $this->assertCount(3, $results);
        $this->assertPlanned($results[0], 'Ensure asset folder: Provisioned');
        $this->assertPlanned($results[1], 'Upload asset: Provisioned/hero.png');
        $this->assertPlanned($results[2], 'Create story: auto-created');
    }

    #[Test]
    public function plans_workflow_assignment_after_story_creation(): void
    {
        $results = $this->provision([
            'stories' => [
                'create' => [
                    [
                        'name' => 'Auto Created',
                        'slug' => 'auto-created',
                        'content' => [
                            'component' => 'default-page',
                        ],
                    ],
                ],
            ],
            'workflow' => [
                'assign_unstaged' => true,
                'stage_id' => 653554,
            ],
        ])->results();

        $this->assertCount(2, $results);
        $this->assertPlanned($results[0], 'Create story: auto-created');
        $this->assertPlanned($results[1], 'Assign workflow stages');
    }

    #[Test]
    public function plans_specific_workflow_assignments(): void
    {
        $results = $this->provision([
            'workflow' => [
                'assign' => [
                    [
                        'stories' => [
                            'slugs' => ['home', 'about'],
                        ],
                        'workflow' => 'Default',
                        'stage' => 'Drafting',
                    ],
                ],
            ],
        ])->results();

        $this->assertCount(1, $results);
        $this->assertPlanned($results[0], 'Assign workflow stage: Default/Drafting');
        $this->assertSame('2 story assignment(s) planned.', $results[0]->detail);
    }

    #[Test]
    public function updates_story_component_fields_by_path(): void
    {
        $updateResponse = new MockResponse($this->storyJson([
            'body' => [
                [
                    '_uid' => 'hero',
                    'component' => 'hero-section',
                    'eyebrow' => 'Welcome to',
                    'image' => [
                        'id' => 123,
                        'filename' => 'https://a.storyblok.com/f/680/hero.jpg',
                        'fieldtype' => 'asset',
                        'alt' => 'New alt',
                    ],
                    'headline' => [
                        [
                            '_uid' => 'headline',
                            'component' => 'headline-segment',
                            'text' => 'Acme Demo Space!',
                        ],
                    ],
                ],
            ],
        ]));
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                new MockResponse($this->storiesJson([['id' => 440448565, 'slug' => 'home']])),
                new MockResponse($this->storyJson([
                    'body' => [
                        [
                            '_uid' => 'hero',
                            'component' => 'hero-section',
                            'eyebrow' => 'Old',
                            'image' => [
                                'id' => 123,
                                'filename' => 'https://a.storyblok.com/f/680/hero.jpg',
                                'fieldtype' => 'asset',
                                'alt' => 'Old alt',
                            ],
                            'headline' => [
                                [
                                    '_uid' => 'headline',
                                    'component' => 'headline-segment',
                                    'text' => 'Old headline',
                                ],
                            ],
                        ],
                    ],
                ])),
                $updateResponse,
            ),
            [
                'stories' => [
                    'update' => [
                        [
                            'slug' => 'home',
                            'components' => [
                                [
                                    'path' => 'content.body[0]',
                                    'component' => 'hero-section',
                                    'fields' => [
                                        'eyebrow' => 'Welcome to',
                                        'image' => [
                                            'alt' => 'New alt',
                                        ],
                                    ],
                                ],
                                [
                                    'path' => 'content.body[0].headline[0]',
                                    'component' => 'headline-segment',
                                    'fields' => [
                                        'text' => 'Acme Demo Space!',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Updated, 'Update story components: home');
        $payload = $this->requestJsonPayload($updateResponse);
        $this->assertSame('Welcome to', $this->valueAtPath($payload, ['story', 'content', 'body', 0, 'eyebrow']));
        $this->assertSame('Acme Demo Space!', $this->valueAtPath($payload, ['story', 'content', 'body', 0, 'headline', 0, 'text']));
        $this->assertSame('New alt', $this->valueAtPath($payload, ['story', 'content', 'body', 0, 'image', 'alt']));
        $this->assertSame('https://a.storyblok.com/f/680/hero.jpg', $this->valueAtPath($payload, ['story', 'content', 'body', 0, 'image', 'filename']));
    }

    #[Test]
    public function resolves_asset_find_directives_in_story_component_updates(): void
    {
        $assetsResponse = new MockResponse($this->assetsJson([
            [
                'id' => 8801,
                'filename' => 'https://a.storyblok.com/f/680/customer-hero.png',
                'content_type' => 'image/png',
                'fieldtype' => 'asset',
            ],
        ]));
        $updateResponse = new MockResponse($this->storyJson([
            'body' => [
                [
                    '_uid' => 'hero',
                    'component' => 'hero-section',
                    'image' => [
                        'id' => 8801,
                        'filename' => 'https://a.storyblok.com/f/680/customer-hero.png',
                        'fieldtype' => 'asset',
                        'alt' => 'Hero image for Acme',
                    ],
                ],
            ],
        ]));
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                new MockResponse($this->storiesJson([['id' => 440448565, 'slug' => 'home']])),
                new MockResponse($this->storyJson([
                    'body' => [
                        [
                            '_uid' => 'hero',
                            'component' => 'hero-section',
                            'image' => [
                                'id' => 123,
                                'filename' => 'https://a.storyblok.com/f/680/old-hero.jpg',
                                'fieldtype' => 'asset',
                            ],
                        ],
                    ],
                ])),
                new MockResponse('{"asset_folders":[{"id":3001,"name":"Brand","parent_id":null}]}'),
                $assetsResponse,
                $updateResponse,
            ),
            [
                'stories' => [
                    'update' => [
                        [
                            'slug' => 'home',
                            'components' => [
                                [
                                    'path' => 'content.body[0]',
                                    'component' => 'hero-section',
                                    'fields' => [
                                        'image' => [
                                            'asset' => [
                                                '_find' => [
                                                    'search' => 'customer-hero.png',
                                                    'in_folder' => 'Brand',
                                                    'tags' => ['customer-demo'],
                                                    'require_unique' => true,
                                                ],
                                                'alt' => 'Hero image for Acme',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Updated, 'Update story components: home');
        $this->assertStringContainsString('in_folder=3001', $assetsResponse->getRequestUrl());
        $this->assertStringContainsString('search=customer-hero.png', urldecode($assetsResponse->getRequestUrl()));
        $this->assertStringContainsString('with_tags=customer-demo', urldecode($assetsResponse->getRequestUrl()));
        $payload = $this->requestJsonPayload($updateResponse);
        $this->assertSame(8801, $this->valueAtPath($payload, ['story', 'content', 'body', 0, 'image', 'id']));
        $this->assertSame('Hero image for Acme', $this->valueAtPath($payload, ['story', 'content', 'body', 0, 'image', 'alt']));
        $this->assertSame('asset', $this->valueAtPath($payload, ['story', 'content', 'body', 0, 'image', 'fieldtype']));
        $image = $this->valueAtPath($payload, ['story', 'content', 'body', 0, 'image']);
        $this->assertIsArray($image);
        $this->assertArrayNotHasKey('_find', $image);
    }

    #[Test]
    public function creates_story_from_inline_content(): void
    {
        $createResponse = new MockResponse($this->storyJson(['body' => []], name: 'Landing Page', slug: 'landing', id: 991));
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                new MockResponse($this->storiesJson([])),
                $createResponse,
            ),
            [
                'stories' => [
                    'create' => [
                        [
                            'name' => 'Landing Page',
                            'slug' => 'landing',
                            'content' => [
                                'component' => 'default-page',
                                'body' => [],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Created, 'Create story: landing');
        $payload = $this->requestJsonPayload($createResponse);
        $this->assertSame('Landing Page', $this->valueAtPath($payload, ['story', 'name']));
        $this->assertSame('landing', $this->valueAtPath($payload, ['story', 'slug']));
        $this->assertSame('default-page', $this->valueAtPath($payload, ['story', 'content', 'component']));
    }

    #[Test]
    public function creates_story_from_content_file(): void
    {
        $configDirectory = sys_get_temp_dir() . '/blokctl-story-create-' . bin2hex(random_bytes(4));
        mkdir($configDirectory);
        file_put_contents($configDirectory . '/landing.json', json_encode([
            'component' => 'default-page',
            'body' => [],
        ], JSON_THROW_ON_ERROR));

        try {
            $createResponse = new MockResponse($this->storyJson(['body' => []], name: 'Landing Page', slug: 'landing', id: 991));
            $reporter = $this->provisionWithClient(
                $this->createMockClient(
                    new MockResponse($this->storiesJson([])),
                    $createResponse,
                ),
                [
                    'stories' => [
                        'create' => [
                            [
                                'name' => 'Landing Page',
                                'slug' => 'landing',
                                'content_file' => './landing.json',
                            ],
                        ],
                    ],
                ],
                configDirectory: $configDirectory,
            );
        } finally {
            unlink($configDirectory . '/landing.json');
            rmdir($configDirectory);
        }

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Created, 'Create story: landing');
        $payload = $this->requestJsonPayload($createResponse);
        $this->assertSame('default-page', $this->valueAtPath($payload, ['story', 'content', 'component']));
    }

    #[Test]
    public function skips_story_creation_when_slug_already_exists(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                new MockResponse($this->storiesJson([['id' => 991, 'slug' => 'landing']])),
            ),
            [
                'stories' => [
                    'create' => [
                        [
                            'name' => 'Landing Page',
                            'slug' => 'landing',
                            'content' => [
                                'component' => 'default-page',
                            ],
                        ],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Skipped, 'Create story: landing');
    }

    #[Test]
    public function plans_local_asset_directory_uploads(): void
    {
        $directory = $this->temporaryAssetDirectory([
            'logo.png' => 'logo',
            'notes.txt' => 'notes',
            'products/shoe.jpg' => 'shoe',
        ]);

        try {
            $results = $this->provision([
                'assets' => [
                    'upload_directory' => [
                        [
                            'source' => $directory,
                            'target_folder' => 'Brand',
                            'recursive' => true,
                            'include' => ['*.png', '*.jpg'],
                        ],
                    ],
                ],
            ])->results();
        } finally {
            $this->removeDirectory($directory);
        }

        $this->assertCount(4, $results);
        $this->assertPlanned($results[0], 'Ensure asset folder: Brand');
        $this->assertPlanned($results[1], 'Ensure asset folder: Brand/products');
        $this->assertPlanned($results[2], 'Upload asset: Brand/logo.png');
        $this->assertPlanned($results[3], 'Upload asset: Brand/products/shoe.jpg');
    }

    #[Test]
    public function rejects_asset_target_folders_with_empty_path_segments(): void
    {
        $directory = $this->temporaryAssetDirectory([
            'logo.png' => 'logo',
        ]);
        $this->expectException(SpaceSetupProvisioningException::class);
        $this->expectExceptionMessage('Asset target_folder must not contain empty path segments.');

        try {
            $this->provision([
                'assets' => [
                    'upload_directory' => [
                        [
                            'source' => $directory,
                            'target_folder' => 'Brand//Logos',
                        ],
                    ],
                ],
            ]);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    #[Test]
    public function plans_asset_conversion_to_global_library(): void
    {
        $results = $this->provision([
            'assets' => [
                'convert_to_global' => [
                    [
                        'asset_ids' => [123, 456],
                        'target_shared_folder_id' => 987,
                    ],
                    [
                        'source_folder_name' => 'Brand',
                        'target_shared_folder_id' => 987,
                        'filters' => [
                            'filetype' => 'image',
                            'extensions' => ['jpg', 'png', 'webp'],
                            'tags' => ['approved'],
                        ],
                    ],
                ],
            ],
        ])->results();

        $this->assertCount(2, $results);
        $this->assertPlanned($results[0], 'Convert assets to global folder: 987');
        $this->assertSame('Assets: 123, 456', $results[0]->detail);
        $this->assertPlanned($results[1], 'Convert assets to global folder: 987');
        $this->assertSame('Source folder name: Brand', $results[1]->detail);
    }

    #[Test]
    public function converts_asset_ids_to_global_library(): void
    {
        $firstConvert = new MockResponse($this->assetResponseJson(123));
        $secondConvert = new MockResponse($this->assetResponseJson(456));
        $reporter = $this->provisionWithClient(
            ManagementApiClient::initTest(new MockHttpClient([
                $firstConvert,
                $secondConvert,
            ])),
            [
                'assets' => [
                    'convert_to_global' => [
                        [
                            'asset_ids' => [123, 456],
                            'target_shared_folder_id' => 987,
                        ],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Updated, 'Convert assets to global folder: 987');
        $this->assertStringContainsString('/v1/spaces/680/assets/123/convert?target_asset_folder_id=987', $firstConvert->getRequestUrl());
        $this->assertStringContainsString('/v1/spaces/680/assets/456/convert?target_asset_folder_id=987', $secondConvert->getRequestUrl());
    }

    #[Test]
    public function plans_each_component_field(): void
    {
        $result = $this->singleResult([
            'components' => [
                'fields' => [
                    [
                        'component' => 'article-page',
                        'field' => 'SEO',
                        'type' => 'custom',
                        'field_type' => 'sb-ai-seo',
                        'tab' => 'SEO',
                    ],
                ],
            ],
        ]);

        $this->assertPlanned($result, 'Add component field: article-page.SEO');
    }

    #[Test]
    public function plans_each_tag_assignment(): void
    {
        $result = $this->singleResult([
            'tags' => [
                [
                    'stories' => [
                        'ids' => ['123'],
                        'slugs' => ['home'],
                    ],
                    'tags' => ['Featured', 'Marketing'],
                ],
            ],
        ]);

        $this->assertPlanned($result, 'Assign tags: Featured, Marketing');
        $this->assertSame('Stories: 123, home', $result->detail);
    }

    #[Test]
    public function omits_disabled_or_unconfigured_sections(): void
    {
        $reporter = $this->provision([
            'preview' => [
                'enabled' => false,
                'default' => 'https://demo.example.com',
            ],
            'demo_mode' => [
                'remove' => false,
            ],
            'workflow' => [
                'assign_unstaged' => false,
            ],
        ]);

        $this->assertSame([], $reporter->results());
        $this->assertFalse($reporter->hasFailures());
    }

    #[Test]
    public function records_section_failure_and_continues_when_configured(): void
    {
        $reporter = $this->provision([
            'components' => [
                'fields' => [
                    [
                        'component' => 'article-page',
                        'field' => 'SEO',
                    ],
                ],
            ],
            'apps' => [
                'install' => ['backups'],
            ],
        ], continueOnError: true);

        $results = $reporter->results();
        $this->assertCount(2, $results);
        $this->assertSame(SpaceSetupOperationStatus::Planned, $results[0]->status);
        $this->assertSame(SpaceSetupOperationStatus::Failed, $results[1]->status);
        $this->assertTrue($reporter->hasFailures());
    }

    #[Test]
    public function preserves_partial_report_when_failure_stops_provisioning(): void
    {
        try {
            $this->provision([
                'components' => [
                    'fields' => [
                        [
                            'component' => 'article-page',
                            'field' => 'SEO',
                        ],
                    ],
                ],
                'tags' => [
                    [
                        'stories' => ['slugs' => ['home']],
                        'tags' => ['Marketing'],
                    ],
                ],
            ]);
            $this->fail('Expected provisioning to stop.');
        } catch (SpaceSetupProvisioningException $spaceSetupProvisioningException) {
            $results = $spaceSetupProvisioningException->reporter->results();
            $this->assertCount(1, $results);
            $this->assertSame(SpaceSetupOperationStatus::Failed, $results[0]->status);
            $this->assertSame('Add component field: article-page.SEO', $results[0]->label);
        }
    }

    #[Test]
    public function applies_preview_configuration(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('one-space'),
                $this->mockResponse('one-space'),
            ),
            [
                'preview' => [
                    'default' => 'https://demo.example.com',
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Updated, 'Configure preview URLs');
    }

    #[Test]
    public function removes_demo_mode(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('one-space-demo'),
                $this->mockResponse('one-space-demo'),
            ),
            [
                'demo_mode' => [
                    'remove' => true,
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Removed, 'Remove demo mode');
    }

    #[Test]
    public function assigns_workflow_stages(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('list-stories-mixed-workflow'),
                $this->mockResponse('list-workflows'),
                $this->mockResponse('list-workflow-stages'),
                $this->mockResponse('one-workflow-stage-change'),
                $this->mockResponse('one-workflow-stage-change'),
            ),
            [
                'workflow' => [
                    'assign_unstaged' => true,
                    'stage_id' => 653554,
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Updated, 'Assign workflow stages');
        $this->assertSame('2 stories assigned.', $reporter->results()[0]->detail);
    }

    #[Test]
    public function assigns_specific_stories_to_workflow_stage(): void
    {
        $homeLookup = $this->mockResponse('list-stories-single');
        $aboutLookup = $this->mockResponse('list-stories-single');
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('list-workflows'),
                $this->mockResponse('list-workflow-stages'),
                $homeLookup,
                $this->mockResponse('one-story-with-stage'),
                $this->mockResponse('one-workflow-stage-change'),
                $this->mockResponse('list-workflows'),
                $this->mockResponse('list-workflow-stages'),
                $aboutLookup,
                $this->mockResponse('one-story-with-stage'),
                $this->mockResponse('one-workflow-stage-change'),
            ),
            [
                'workflow' => [
                    'assign' => [
                        [
                            'stories' => [
                                'slugs' => ['home', 'about'],
                            ],
                            'workflow' => 'Default one',
                            'stage' => 'Drafting',
                        ],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Updated, 'Assign workflow stage: Default one/Drafting');
        $this->assertSame('2 story assignment(s) applied.', $reporter->results()[0]->detail);
        $this->assertStringContainsString('with_slug=home', urldecode($homeLookup->getRequestUrl()));
        $this->assertStringContainsString('with_slug=about', urldecode($aboutLookup->getRequestUrl()));
    }

    #[Test]
    public function installs_apps(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('list-app-provisions'),
                $this->mockResponse('list-apps'),
                $this->mockResponse('one-app-provision'),
            ),
            [
                'apps' => [
                    'install' => ['seo-app'],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Installed, 'Install app: seo-app');
    }

    #[Test]
    public function installs_structured_app_reference_by_id(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('list-app-provisions'),
                $this->mockResponse('list-apps'),
                $this->mockResponse('one-app-provision'),
            ),
            [
                'apps' => [
                    'install' => [
                        ['slug' => 'dimensions', 'id' => 24],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Installed, 'Install app: dimensions');
    }

    #[Test]
    public function does_not_use_app_id_fallback_for_api_failures(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('list-app-provisions'),
                new MockResponse('{"error":"API unavailable"}', ['http_code' => 500]),
            ),
            [
                'apps' => [
                    'install' => [
                        ['slug' => 'dimensions', 'id' => 24],
                    ],
                ],
            ],
            continueOnError: true,
        );

        $results = $reporter->results();
        $this->assertCount(1, $results);
        $this->assertSame(SpaceSetupOperationStatus::Failed, $results[0]->status);
        $this->assertSame('Install app: dimensions', $results[0]->label);
        $this->assertTrue($reporter->hasFailures());
    }

    #[Test]
    public function creates_missing_folders_and_skips_existing_folders(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('list-folder-global'),
                $this->mockResponse('list-folders-empty'),
                $this->mockResponse('one-folder-created'),
            ),
            [
                'folders' => [
                    'ensure' => [
                        ['name' => 'Global', 'slug' => 'global'],
                        ['name' => 'Italy', 'slug' => 'italy'],
                    ],
                ],
            ],
        );

        $results = $reporter->results();
        $this->assertCount(2, $results);
        $this->assertSame(SpaceSetupOperationStatus::Skipped, $results[0]->status);
        $this->assertSame(SpaceSetupOperationStatus::Created, $results[1]->status);
    }

    #[Test]
    public function moves_matching_root_content_and_preserves_excluded_items(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('list-folder-global'),
                $this->mockResponse('list-root-content'),
                $this->mockResponse('one-story-moved'),
                $this->mockResponse('one-folder-moved'),
            ),
            [
                'stories' => [
                    'move' => [
                        [
                            'select' => [
                                'parent' => 'root',
                                'include_folders' => true,
                                'exclude_slugs' => ['site-config'],
                            ],
                            'to_folder' => 'global',
                        ],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Updated, 'Move selected root content to: global');
        $this->assertSame('2 item(s) moved.', $reporter->results()[0]->detail);
    }

    #[Test]
    public function reconciles_dimensions_and_preserves_unmanaged_folders(): void
    {
        $updatePayload = [];
        $responses = [
            $this->mockData('one-space-dimensions'),
            $this->mockData('list-folder-global'),
            $this->mockData('list-folder-italy'),
            $this->mockData('one-space-dimensions'),
        ];
        $request = 0;
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$request, &$updatePayload, $responses): MockResponse {
                if ($method === 'PUT') {
                    $body = $options['body'] ?? '';
                    if (is_string($body)) {
                        $updatePayload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    }
                }

                return new MockResponse($responses[$request++]);
            },
        );

        $reporter = $this->provisionWithClient(
            ManagementApiClient::initTest($httpClient),
            [
                'dimensions' => [
                    'folders' => [
                        ['slug' => 'global'],
                        ['slug' => 'italy', 'ai_translation_code' => 'it'],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Updated, 'Configure Dimensions folders');
        if (!is_array($updatePayload)) {
            $this->fail('Expected a space update payload.');
        }

        $space = $updatePayload['space'] ?? null;
        $this->assertIsArray($space);
        $this->assertSame([9999, 1001, 1002], $space['dimensions_app_folder_ids'] ?? null);
        $this->assertSame(
            [
                ['folder_id' => 9999, 'ai_translation_code' => ''],
                ['folder_id' => 1001, 'ai_translation_code' => ''],
                ['folder_id' => 1002, 'ai_translation_code' => 'it'],
            ],
            $space['dimensions_app_folders'] ?? null,
        );
    }

    #[Test]
    public function configures_only_declared_storyblok_ai_settings(): void
    {
        $updatePayload = [];
        $response = $this->mockData('one-space');
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$updatePayload, $response): MockResponse {
                if ($method === 'PUT') {
                    $body = $options['body'] ?? '';
                    if (is_string($body)) {
                        $updatePayload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    }
                }

                return new MockResponse($response);
            },
        );

        $reporter = $this->provisionWithClient(
            ManagementApiClient::initTest($httpClient),
            [
                'ai' => [
                    'enabled' => true,
                    'inherit_org_configuration' => false,
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Updated, 'Configure Storyblok AI');
        $this->assertSame([
            'space' => [
                'ai_text_generator_disabled' => false,
                'inherit_org_ai_configuration' => false,
            ],
        ], $updatePayload);
    }

    #[Test]
    public function configures_ai_translation_disclaimer(): void
    {
        $updatePayload = [];
        $response = $this->mockData('one-space');
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$updatePayload, $response): MockResponse {
                if ($method === 'PUT') {
                    $body = $options['body'] ?? '';
                    if (is_string($body)) {
                        $updatePayload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    }
                }

                return new MockResponse($response);
            },
        );

        $reporter = $this->provisionWithClient(
            ManagementApiClient::initTest($httpClient),
            [
                'ai_translation' => [
                    'disclaimer_id' => 173657768407244,
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Updated, 'Configure AI Translation disclaimer');
        $this->assertSame([
            'space' => [
                'disclaimer_id' => 173657768407244,
            ],
        ], $updatePayload);
    }

    #[Test]
    public function skips_matching_ai_translation_disclaimer(): void
    {
        $space = json_decode($this->mockData('one-space'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($space);
        $spaceData = $space['space'] ?? null;
        $this->assertIsArray($spaceData);
        $spaceData['disclaimer_id'] = 173657768407244;
        $space['space'] = $spaceData;

        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                new MockResponse(json_encode($space, JSON_THROW_ON_ERROR)),
            ),
            [
                'ai_translation' => [
                    'disclaimer_id' => 173657768407244,
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Skipped, 'Configure AI Translation disclaimer');
    }

    #[Test]
    public function skips_matching_storyblok_ai_configuration(): void
    {
        $space = json_decode($this->mockData('one-space'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($space);
        $spaceData = $space['space'] ?? null;
        $this->assertIsArray($spaceData);
        $spaceData['ai_text_generator_disabled'] = false;
        $spaceData['inherit_org_ai_configuration'] = false;
        $space['space'] = $spaceData;

        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                new MockResponse(json_encode($space, JSON_THROW_ON_ERROR)),
            ),
            [
                'ai' => [
                    'enabled' => true,
                    'inherit_org_configuration' => false,
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Skipped, 'Configure Storyblok AI');
    }

    #[Test]
    public function skips_existing_assets_with_the_same_filename_and_folder(): void
    {
        $directory = $this->temporaryAssetDirectory([
            'logo.png' => 'logo',
        ]);

        try {
            $reporter = $this->provisionWithClient(
                $this->createMockClient(
                    $this->mockResponse('list-asset-folders-brand'),
                    $this->mockResponse('list-assets-brand'),
                ),
                [
                    'assets' => [
                        'upload_directory' => [
                            [
                                'source' => $directory,
                                'target_folder' => 'Brand',
                            ],
                        ],
                    ],
                ],
            );
        } finally {
            $this->removeDirectory($directory);
        }

        $results = $reporter->results();
        $this->assertCount(2, $results);
        $this->assertSame(SpaceSetupOperationStatus::Skipped, $results[0]->status);
        $this->assertSame('Ensure asset folder: Brand', $results[0]->label);
        $this->assertSame(SpaceSetupOperationStatus::Skipped, $results[1]->status);
        $this->assertSame('Upload asset: Brand/logo.png', $results[1]->label);
    }

    #[Test]
    public function uploads_missing_assets_to_the_target_folder(): void
    {
        $directory = $this->temporaryAssetDirectory([
            'logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
        ]);
        $signedRequestPayload = [];
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$signedRequestPayload): MockResponse {
                if (str_ends_with($url, '/asset_folders')) {
                    return new MockResponse('{"asset_folders":[{"id":3001,"name":"Brand","parent_id":null}]}');
                }

                if ($method === 'GET' && str_contains($url, '/assets?')) {
                    return new MockResponse('{"assets":[]}');
                }

                if ($method === 'POST' && str_ends_with($url, '/assets/')) {
                    $body = $options['body'] ?? [];
                    if (is_array($body)) {
                        $signedRequestPayload = $body;
                    } elseif (is_string($body)) {
                        parse_str($body, $signedRequestPayload);
                    }

                    return new MockResponse('{"id":"4001","post_url":"https://uploads.example.com","fields":{}}');
                }

                return new MockResponse('{"id":4001,"filename":"https://a.storyblok.com/f/680/logo.svg"}');
            },
        );
        $assetClient = new MockHttpClient(new MockResponse('', ['http_code' => 204]));

        try {
            $reporter = $this->provisionWithClient(
                ManagementApiClient::initTest($httpClient, $assetClient),
                [
                    'assets' => [
                        'upload_directory' => [
                            [
                                'source' => $directory,
                                'target_folder' => 'Brand',
                            ],
                        ],
                    ],
                ],
            );
        } finally {
            $this->removeDirectory($directory);
        }

        $results = $reporter->results();
        $this->assertCount(2, $results);
        $this->assertSame(SpaceSetupOperationStatus::Skipped, $results[0]->status);
        $this->assertSame(SpaceSetupOperationStatus::Created, $results[1]->status);
        $this->assertSame('Upload asset: Brand/logo.svg', $results[1]->label);
        $this->assertSame('3001', $signedRequestPayload['asset_folder_id'] ?? null);
        $filename = $signedRequestPayload['filename'] ?? null;
        $this->assertIsString($filename);
        $this->assertStringEndsWith('/logo.svg', $filename);
    }

    #[Test]
    public function adds_component_fields(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('list-components'),
                $this->mockResponse('one-article-page'),
                $this->mockResponse('one-article-page'),
            ),
            [
                'components' => [
                    'fields' => [
                        [
                            'component' => 'article-page',
                            'field' => 'subtitle',
                            'type' => 'text',
                        ],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Created, 'Add component field: article-page.subtitle');
    }

    #[Test]
    public function assigns_story_tags(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('one-story'),
                $this->mockResponse('one-story'),
            ),
            [
                'tags' => [
                    [
                        'stories' => [
                            'ids' => ['440448565'],
                        ],
                        'tags' => ['Landing'],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Updated, 'Assign tags: Landing');
        $this->assertSame('1 stories tagged; 0 unchanged.', $reporter->results()[0]->detail);
    }

    #[Test]
    public function skips_apps_that_are_already_installed(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('list-app-provisions'),
            ),
            [
                'apps' => [
                    'install' => ['activity'],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Skipped, 'Install app: activity');
    }

    #[Test]
    public function skips_component_fields_that_already_match_declared_properties(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('list-components'),
                $this->mockResponse('one-article-page'),
            ),
            [
                'components' => [
                    'fields' => [
                        [
                            'component' => 'article-page',
                            'field' => 'title',
                            'type' => 'text',
                        ],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Skipped, 'Add component field: article-page.title');
    }

    #[Test]
    public function updates_only_declared_component_field_properties(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('list-components'),
                $this->mockResponse('one-article-page'),
                $this->mockResponse('one-article-page'),
            ),
            [
                'components' => [
                    'fields' => [
                        [
                            'component' => 'article-page',
                            'field' => 'title',
                            'type' => 'text',
                            'required' => true,
                        ],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Updated, 'Add component field: article-page.title');
    }

    #[Test]
    public function skips_story_tags_that_are_already_present(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('one-story'),
            ),
            [
                'tags' => [
                    [
                        'stories' => [
                            'ids' => ['440448565'],
                        ],
                        'tags' => ['tag1'],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Skipped, 'Assign tags: tag1');
    }

    #[Test]
    public function skips_preview_urls_that_already_match(): void
    {
        $reporter = $this->provisionWithClient(
            $this->createMockClient(
                $this->mockResponse('one-space'),
            ),
            [
                'preview' => [
                    'default' => 'https://example.storyblok.com',
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Skipped, 'Configure preview URLs');
    }

    #[Test]
    public function preserves_unmanaged_preview_environments(): void
    {
        $space = json_decode($this->mockData('one-space'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($space);
        $spaceData = $space['space'] ?? null;
        $this->assertIsArray($spaceData);
        $spaceData['environments'] = [
            [
                'name' => 'Production',
                'location' => 'https://production.example.com',
            ],
        ];
        $space['space'] = $spaceData;
        $response = json_encode($space, JSON_THROW_ON_ERROR);
        $updatePayload = [];
        $requestNumber = 0;

        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requestNumber, &$updatePayload, $response): MockResponse {
                ++$requestNumber;
                if ($method === 'PUT') {
                    $body = $options['body'] ?? '';
                    if (is_string($body)) {
                        $updatePayload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    }
                }

                return new MockResponse($response);
            },
        );

        $reporter = $this->provisionWithClient(
            ManagementApiClient::initTest($httpClient),
            [
                'preview' => [
                    'default' => 'https://demo.example.com',
                    'environments' => [
                        [
                            'name' => 'Local',
                            'url' => 'https://localhost:3000',
                        ],
                    ],
                ],
            ],
        );

        $this->assertSuccessful($reporter, SpaceSetupOperationStatus::Updated, 'Configure preview URLs');
        $this->assertIsArray($updatePayload);
        $updatedSpace = $updatePayload['space'] ?? null;
        $this->assertIsArray($updatedSpace);
        $environments = $updatedSpace['environments'] ?? null;
        $this->assertIsArray($environments);
        $production = $environments[0] ?? null;
        $local = $environments[1] ?? null;
        $this->assertIsArray($production);
        $this->assertIsArray($local);
        $this->assertSame('Production', $production['name'] ?? null);
        $this->assertSame('Local', $local['name'] ?? null);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function singleResult(array $config): SpaceSetupOperationResult
    {
        $results = $this->provision($config)->results();
        $this->assertCount(1, $results);

        return $results[0];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provision(array $config, bool $continueOnError = false): SpaceSetupReporter
    {
        return $this->provisionWithClient(
            $this->createMockClient(),
            $config,
            dryRun: true,
            continueOnError: $continueOnError,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionWithClient(
        ManagementApiClient $client,
        array $config,
        bool $dryRun = false,
        bool $continueOnError = false,
        string $configDirectory = '.',
    ): SpaceSetupReporter {
        return new SpaceSetupProvisioner($client)->run(
            spaceId: '680',
            config: $config,
            dryRun: $dryRun,
            continueOnError: $continueOnError,
            mode: 'Existing space',
            configDirectory: $configDirectory,
        );
    }

    private function assertPlanned(SpaceSetupOperationResult $result, string $label): void
    {
        $this->assertSame(SpaceSetupOperationStatus::Planned, $result->status);
        $this->assertSame($label, $result->label);
    }

    private function assertSuccessful(
        SpaceSetupReporter $reporter,
        SpaceSetupOperationStatus $status,
        string $label,
    ): void {
        $results = $reporter->results();
        $this->assertCount(1, $results);
        $this->assertSame($status, $results[0]->status);
        $this->assertSame($label, $results[0]->label);
        $this->assertFalse($reporter->hasFailures());
    }

    private function assetResponseJson(int $id): string
    {
        return json_encode([
            'id' => $id,
            'filename' => 'https://a.storyblok.com/f/680/asset-' . $id . '.jpg',
            'content_type' => 'image/jpeg',
            'fieldtype' => 'asset',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array{id: int, filename: string, content_type: string, fieldtype?: string}> $assets
     */
    private function assetsJson(array $assets): string
    {
        return json_encode(['assets' => $assets], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function storyJson(array $content, string $name = 'Home', string $slug = 'home', int $id = 440448565): string
    {
        $content = ['_uid' => 'root', 'component' => 'default-page'] + $content;

        return json_encode([
            'story' => [
                'name' => $name,
                'id' => $id,
                'uuid' => 'e656e146-f4ed-44a2-8017-013e5a9d9395',
                'slug' => $slug,
                'full_slug' => $slug,
                'content' => $content,
                'parent_id' => 0,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array{id: int, slug: string}> $stories
     */
    private function storiesJson(array $stories): string
    {
        return json_encode([
            'stories' => array_map(static fn(array $story): array => [
                'name' => ucfirst($story['slug']),
                'id' => $story['id'],
                'slug' => $story['slug'],
                'full_slug' => $story['slug'],
                'content' => [
                    'component' => 'default-page',
                ],
            ], $stories),
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

    /**
     * @param array<string, string> $files
     */
    private function temporaryAssetDirectory(array $files): string
    {
        $directory = sys_get_temp_dir() . '/blokctl-provision-assets-' . bin2hex(random_bytes(8));
        mkdir($directory);
        foreach ($files as $path => $content) {
            $fullPath = $directory . '/' . $path;
            $parent = dirname($fullPath);
            if (!is_dir($parent)) {
                mkdir($parent, recursive: true);
            }

            file_put_contents($fullPath, $content);
        }

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
