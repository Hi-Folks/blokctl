#!/usr/bin/env php
<?php

/**
 * Assign "Market Validation" workflow stage to stories in Italy and Germany
 * folders that don't have a workflow stage yet.
 *
 * Usage:
 *   php scripts/assign-market-validation.php <space-id>
 *   php scripts/assign-market-validation.php <space-id> --dry-run
 */

require __DIR__ . '/../vendor/autoload.php';

use Blokctl\Action\Story\StoriesListAction;
use Blokctl\Action\Story\StoryWorkflowChangeAction;
use Blokctl\Render;
use Storyblok\ManagementApi\Data\Story;
use Storyblok\ManagementApi\ManagementApiClient;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$spaceId = $argv[1] ?? null;
$dryRun = in_array('--dry-run', $argv, true);

if ($spaceId === null) {
    echo "Usage: php scripts/assign-market-validation.php <space-id> [--dry-run]\n";
    exit(1);
}

$token = $_ENV['SECRET_KEY'] ?? '';
if ($token === '') {
    echo "SECRET_KEY not found in .env\n";
    exit(1);
}

$client = new ManagementApiClient($token, shouldRetry: true);

$folders = ['italy/', 'germany/'];
$stageName = 'Market Validation';

// Resolve the workflow stage ID for "Market Validation"
Render::title('Resolving workflow stage: ' . $stageName);

$workflowAction = new StoryWorkflowChangeAction($client);
$resolved = $workflowAction->resolveWorkflowStage($spaceId, $stageName);
$stageId = $resolved['stageId'];

Render::labelValue('Stage ID', (string) $stageId);
Render::labelValue('Stage name', $resolved['stageName']);

// Fetch stories from each folder and filter those without a workflow stage
$listAction = new StoriesListAction($client);
$storiesWithoutStage = [];

foreach ($folders as $folder) {
    Render::titleSection('Scanning folder: ' . $folder);

    $page = 1;
    do {
        $result = $listAction->execute(
            spaceId: $spaceId,
            startsWith: $folder,
            page: $page,
            perPage: 100,
        );

        /** @var Story $story */
        foreach ($result->stories as $story) {
            if (!$story->hasWorkflowStage()) {
                $storiesWithoutStage[] = $story;
                Render::log('  No stage: ' . $story->slug() . ' (ID: ' . $story->id() . ')');
            }
        }

        $hasMore = $result->count() === 100;
        $page++;
    } while ($hasMore);

    Render::log('Found ' . $result->count() . ' stories in ' . $folder);
}

$total = count($storiesWithoutStage);
Render::titleSection('Summary');
Render::labelValue('Stories without workflow stage', (string) $total);

if ($total === 0) {
    Render::log('Nothing to do. All stories already have a workflow stage.');
    exit(0);
}

if ($dryRun) {
    Render::log('Dry run. No changes applied.');
    exit(0);
}

// Assign the workflow stage
Render::titleSection('Assigning "' . $stageName . '" stage');

$assigned = 0;
$errors = 0;

foreach ($storiesWithoutStage as $story) {
    try {
        $result = $workflowAction->execute(
            spaceId: $spaceId,
            workflowStageId: $stageId,
            workflowStageName: $stageName,
            storyId: (string) $story->id(),
        );
        $assigned++;
        Render::log('  Assigned: ' . $story->slug());
    } catch (\Exception $e) {
        $errors++;
        Render::error('  Error on ' . $story->slug() . ': ' . $e->getMessage());
        sleep(1);
    }
}

Render::titleSection('Done');
Render::labelValue('Assigned', (string) $assigned);
if ($errors > 0) {
    Render::labelValue('Errors', (string) $errors);
}
