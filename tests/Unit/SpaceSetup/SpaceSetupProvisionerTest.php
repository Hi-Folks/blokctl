<?php

declare(strict_types=1);

namespace Tests\Unit\SpaceSetup;

use Blokctl\SpaceSetup\SpaceSetupOperationResult;
use Blokctl\SpaceSetup\SpaceSetupOperationStatus;
use Blokctl\SpaceSetup\SpaceSetupProvisioner;
use Blokctl\SpaceSetup\SpaceSetupReporter;
use PHPUnit\Framework\Attributes\Test;
use Storyblok\ManagementApi\ManagementApiClient;
use Symfony\Component\Console\Output\NullOutput;
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
        $this->assertSame('https://demo.example.com (1 environments)', $result->detail);
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
        $this->assertSame('1 stories tagged.', $reporter->results()[0]->detail);
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
