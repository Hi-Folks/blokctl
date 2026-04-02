#!/usr/bin/env php
<?php

/**
 * Country publishing status report.
 *
 * Starts from the "global" folder and uses group_id to find alternate
 * stories in country folders (italy, germany, france). Shows published
 * status, last published date, and workflow stage for each.
 *
 * Usage:
 *   php scripts/country-status.php <space-id>
 *   php scripts/country-status.php <space-id> --json
 *   php scripts/country-status.php <space-id> --md
 */

require __DIR__ . '/../vendor/autoload.php';

use Blokctl\Action\Story\StoriesListAction;
use Blokctl\Action\Story\StoryShowAction;
use Blokctl\Render;
use Storyblok\ManagementApi\Data\Story;
use Storyblok\ManagementApi\ManagementApiClient;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$spaceId = $argv[1] ?? null;
$jsonOutput = in_array('--json', $argv, true);
$mdOutput = in_array('--md', $argv, true);

if ($spaceId === null) {
    echo "Usage: php scripts/country-status.php <space-id> [--json] [--md]\n";
    exit(1);
}

$token = $_ENV['SECRET_KEY'] ?? '';
if ($token === '') {
    echo "SECRET_KEY not found in .env\n";
    exit(1);
}

$client = new ManagementApiClient($token, shouldRetry: true);
$listAction = new StoriesListAction($client);
$showAction = new StoryShowAction($client);

$countries = ['italy', 'germany', 'france'];

/**
 * Fetch all stories from a folder, paginating automatically.
 *
 * @return Story[]
 */
function fetchAllStories(StoriesListAction $action, string $spaceId, string $folder): array
{
    $stories = [];
    $page = 1;

    do {
        $result = $action->execute(
            spaceId: $spaceId,
            startsWith: $folder . '/',
            page: $page,
            perPage: 100,
        );

        foreach ($result->stories as $story) {
            $stories[] = $story;
        }

        $hasMore = $result->count() === 100;
        $page++;
    } while ($hasMore);

    return $stories;
}

function buildStoryStatus(Story $story): array
{
    $publishedAt = $story->publishedAt('Y-m-d H:i');
    return [
        'exists' => true,
        'full_slug' => $story->fullSlug(),
        'published' => $publishedAt !== null && $publishedAt !== '',
        'published_at' => $publishedAt,
        'unpublished_changes' => (bool) $story->get('unpublished_changes'),
        'workflow_stage' => $story->get('stage.workflow_stage_name') ?: null,
    ];
}

function printStatusLine(string $label, array $info): void
{
    $pubLabel = $info['published'] ? 'Published' : 'Not published';
    if ($info['published'] && $info['published_at']) {
        $pubLabel .= ' (' . $info['published_at'] . ')';
    }
    if ($info['unpublished_changes']) {
        $pubLabel .= ' + unpublished changes';
    }
    $stageLabel = $info['workflow_stage'] ?? 'none';
    Render::labelValue($label, $pubLabel . '  |  Workflow: ' . $stageLabel);
}

function statusLabel(array $info): string
{
    if (!$info['exists']) {
        return 'Missing';
    }
    $pubLabel = $info['published'] ? 'Published' : 'Not published';
    if ($info['published'] && $info['published_at']) {
        $pubLabel .= ' (' . $info['published_at'] . ')';
    }
    if ($info['unpublished_changes']) {
        $pubLabel .= ' + unpublished changes';
    }
    return $pubLabel;
}

// Step 1: Fetch global stories and index country stories by group_id
Render::title('Fetching stories from all folders');

$globalStories = fetchAllStories($listAction, $spaceId, 'global');
Render::labelValue('Global', (string) count($globalStories) . ' stories');

/** @var array<string, array<string, Story>> country => group_id => Story */
$countryByGroupId = [];

foreach ($countries as $country) {
    $stories = fetchAllStories($listAction, $spaceId, $country);
    Render::labelValue(ucfirst($country), (string) count($stories) . ' stories');

    foreach ($stories as $story) {
        $groupId = $story->groupId();
        if ($groupId !== '') {
            $countryByGroupId[$country][$groupId] = $story;
        }
    }
}

if (count($globalStories) === 0) {
    Render::log('No stories found in global/ folder.');
    exit(0);
}

// Step 2: For each global story, find alternates in country folders via group_id.
// Fetch full details for workflow stage info.
Render::title('Building country status report');

$report = [];

foreach ($globalStories as $story) {
    $groupId = $story->groupId();

    // Fetch full global story for workflow stage
    $globalDetail = $showAction->execute(spaceId: $spaceId, id: $story->id());
    $globalFull = $globalDetail->story;

    $entry = [
        'name' => $story->name(),
        'slug' => $story->slug(),
        'full_slug' => $story->fullSlug(),
        'group_id' => $groupId,
        'global' => buildStoryStatus($globalFull),
        'countries' => [],
    ];

    foreach ($countries as $country) {
        $countryStory = ($groupId !== '') ? ($countryByGroupId[$country][$groupId] ?? null) : null;

        if ($countryStory === null) {
            $entry['countries'][$country] = [
                'exists' => false,
                'full_slug' => null,
                'published' => false,
                'published_at' => null,
                'unpublished_changes' => false,
                'workflow_stage' => null,
            ];
            continue;
        }

        try {
            $detail = $showAction->execute(spaceId: $spaceId, id: $countryStory->id());
            $entry['countries'][$country] = buildStoryStatus($detail->story);
        } catch (\Exception $e) {
            $entry['countries'][$country] = [
                'exists' => true,
                'full_slug' => $countryStory->fullSlug(),
                'published' => false,
                'published_at' => null,
                'unpublished_changes' => false,
                'workflow_stage' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    $report[] = $entry;
}

// Step 3: Output
if ($jsonOutput) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($mdOutput) {
    $countryHeaders = array_map('ucfirst', $countries);
    $header = '| Story | Global |';
    $separator = '| --- | --- |';
    foreach ($countryHeaders as $ch) {
        $header .= ' ' . $ch . ' |';
        $separator .= ' --- |';
    }

    echo "# Country Publishing Status Report\n\n";
    echo "Space: `$spaceId` - Generated: " . date('Y-m-d H:i') . "\n\n";
    echo $header . "\n";
    echo $separator . "\n";

    foreach ($report as $entry) {
        $globalStatus = statusLabel($entry['global']);
        $globalStage = $entry['global']['workflow_stage'] ?? 'none';
        $row = '| **' . $entry['name'] . '** (`' . $entry['slug'] . '`) | ' . $globalStatus . ' / ' . $globalStage . ' |';

        foreach ($countries as $country) {
            $info = $entry['countries'][$country];
            if (!$info['exists']) {
                $row .= ' -- |';
                continue;
            }
            $status = statusLabel($info);
            $stage = $info['workflow_stage'] ?? 'none';
            $row .= ' ' . $status . ' / ' . $stage . ' |';
        }

        echo $row . "\n";
    }

    // Summary
    $totalGlobal = count($report);
    echo "\n## Summary\n\n";
    echo "| Country | Stories | Published | Unpublished changes | Missing |\n";
    echo "| --- | --- | --- | --- | --- |\n";

    foreach ($countries as $country) {
        $existing = 0;
        $published = 0;
        $withChanges = 0;
        foreach ($report as $entry) {
            $info = $entry['countries'][$country];
            if ($info['exists']) {
                $existing++;
            }
            if ($info['published']) {
                $published++;
            }
            if ($info['unpublished_changes']) {
                $withChanges++;
            }
        }
        $missing = $totalGlobal - $existing;
        echo '| ' . ucfirst($country) . ' | ' . $existing . '/' . $totalGlobal . ' | ' . $published . ' | ' . $withChanges . ' | ' . $missing . " |\n";
    }

    exit(0);
}

// Default: terminal output
foreach ($report as $entry) {
    Render::titleSection($entry['name'] . '  (' . $entry['slug'] . ')');

    printStatusLine('Global', $entry['global']);

    foreach ($countries as $country) {
        $info = $entry['countries'][$country];
        if (!$info['exists']) {
            Render::labelValue(ucfirst($country), 'Missing');
            continue;
        }
        printStatusLine(ucfirst($country), $info);
    }
}

// Summary
Render::titleSection('Summary');

$totalGlobal = count($report);

foreach ($countries as $country) {
    $existing = 0;
    $published = 0;
    $withChanges = 0;
    foreach ($report as $entry) {
        $info = $entry['countries'][$country];
        if ($info['exists']) {
            $existing++;
        }
        if ($info['published']) {
            $published++;
        }
        if ($info['unpublished_changes']) {
            $withChanges++;
        }
    }
    $missing = $totalGlobal - $existing;
    $line = $existing . '/' . $totalGlobal . ' stories';
    $line .= ', ' . $published . ' published';
    if ($withChanges > 0) {
        $line .= ', ' . $withChanges . ' with unpublished changes';
    }
    if ($missing > 0) {
        $line .= ', ' . $missing . ' missing';
    }
    Render::labelValue(ucfirst($country), $line);
}
