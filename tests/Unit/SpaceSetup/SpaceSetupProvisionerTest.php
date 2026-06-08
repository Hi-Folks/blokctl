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
    ): SpaceSetupReporter {
        return new SpaceSetupProvisioner($client)->run(
            spaceId: '680',
            config: $config,
            dryRun: $dryRun,
            continueOnError: $continueOnError,
            mode: 'Existing space',
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
}
