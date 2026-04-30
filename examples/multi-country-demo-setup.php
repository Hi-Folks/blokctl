#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Multi-Country Demo Setup
 *
 * Creates Global, Italy, Germany, France folders, moves all root-level
 * stories and folders into Global (except 404 and site-config), installs
 * the Dimensions app (ID 24), and configures its folder settings.
 *
 * Usage: php examples/multi-country-demo-setup.php <SPACE_ID> [Folder1 Folder2 ...]
 *
 * Folders default to: Global Italy Germany France
 * The first folder is the root target where stories are moved.
 */

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (file_exists($autoload)) {
        require $autoload;
        break;
    }
}

use Blokctl\Action\AppProvision\AppProvisionInstallAction;
use Blokctl\Action\Folder\FolderCreateAction;
use Blokctl\Action\Space\SpaceInfoAction;
use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\PaginationParams;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

$spaceId = $argv[1] ?? null;
if ($spaceId === null) {
    echo "Usage: php examples/multi-country-demo-setup.php <SPACE_ID> [Folder1 Folder2 ...]\n";
    exit(1);
}

$folderNames = count($argv) > 2
    ? array_slice($argv, 2)
    : ['Global', 'Italy', 'Germany', 'France'];

// Load .env
foreach ([__DIR__ . '/../.env', __DIR__ . '/../../../../.env'] as $envPath) {
    if (file_exists($envPath)) {
        $dotenv = Dotenv\Dotenv::createImmutable(dirname($envPath));
        $dotenv->safeLoad();
        break;
    }
}

$token = $_ENV['SECRET_KEY'] ?? null;
if (empty($token)) {
    echo "ERROR: SECRET_KEY not set in .env\n";
    exit(1);
}

$client = new ManagementApiClient($token, shouldRetry: true);

$SKIP_SLUGS = ['404', 'error-404', 'site-config'];

// Step 1: Space info
echo "=== Step 1: Space Info ===\n";
try {
    $info = (new SpaceInfoAction($client))->execute($spaceId);
    echo "  Space: {$info->space->name()} ({$spaceId})\n";
} catch (\Throwable $e) {
    echo "  ERROR: {$e->getMessage()}\n";
    exit(1);
}

// Step 2: Create country folders at root (or reuse existing ones by slug)
echo "=== Step 2: Create Country Folders ===\n";
$folderAction = new FolderCreateAction($client);
$storyApiForFolders = new StoryApi($client, $spaceId);
$folders = [];

foreach ($folderNames as $name) {
    $slug     = strtolower($name);
    $existing = $storyApiForFolders->page(new StoriesParams(folderOnly: true, withSlug: $slug))->data();

    if (count($existing) === 1) {
        $id = (int) $existing[0]->id();
        $folders[$name] = $id;
        echo "  {$name} -> {$id} (already exists)\n";
    } else {
        try {
            $result = $folderAction->execute($spaceId, $name, parentId: 0);
            $id = (int) $result->folder->id();
            $folders[$name] = $id;
            echo "  {$name} -> {$id} (created)\n";
        } catch (\Throwable $e) {
            echo "  ERROR creating '{$name}': {$e->getMessage()}\n";
            exit(1);
        }
    }
}

$rootFolderName = $folderNames[0];
$globalId = $folders[$rootFolderName];

// Step 3: Move all root-level items to Global (skip 404, site-config, new folders)
echo "=== Step 3: Move Root Stories/Folders to Global ===\n";
$skipSlugs = array_merge($SKIP_SLUGS, array_map('strtolower', array_keys($folders)));

$storyApi = new StoryApi($client, $spaceId);
$mgmtApi  = new ManagementApi($client);

$page = 1;
do {
    // with_parent=0 is ignored by the API (treated as falsy), so we fetch all
    // stories and filter root-level items client-side via parent_id.
    $response = $storyApi->page(new StoriesParams(), page: new PaginationParams($page, 100));
    $items    = $response->data();

    foreach ($items as $item) {
        /** @var array{id: int|string, slug: string, parent_id: int|null} $item */
        $slug     = (string) $item['slug'];
        $itemId   = (string) $item['id'];
        $parentId = $item['parent_id'] ?? null;

        // Only move root-level items (parent_id 0 or null)
        if ($parentId !== 0 && $parentId !== null) {
            continue;
        }

        if (in_array($slug, $skipSlugs, true)) {
            echo "  Skipping: {$slug}\n";
            continue;
        }

        // Minimal parent_id-only PUT works for both stories and folders
        $moveResponse = $mgmtApi->put("spaces/{$spaceId}/stories/{$itemId}", [
            'story' => ['parent_id' => $globalId],
        ]);

        if ($moveResponse->isOk()) {
            echo "  Moved: {$slug} -> Global\n";
        } else {
            echo "  ERROR moving '{$slug}': {$moveResponse->getErrorMessage()}\n";
        }
    }

    $page++;
} while (count($items) === 100);

// Step 4: Install Dimensions app (ID 24)
echo "=== Step 4: Install Dimensions App (ID 24) ===\n";
try {
    $provision = (new AppProvisionInstallAction($client))->execute($spaceId, 24);
    echo "  Installed: {$provision->name()} ({$provision->slug()})\n";
} catch (\Throwable $e) {
    echo "  WARNING: {$e->getMessage()} — continuing...\n";
}

// Step 5: Configure Dimensions app folder settings
echo "=== Step 5: Configure Dimensions App Folders ===\n";
$dimensionsFolders = array_map(
    fn(int $folderId) => ['folder_id' => $folderId, 'ai_translation_code' => ''],
    array_values($folders),
);

try {
    $space = Space::forUpdate([
        'dimensions_app_folder_ids' => array_values($folders),
        'dimensions_app_folders'    => $dimensionsFolders,
    ]);

    $response = (new SpaceApi($client))->update($spaceId, $space);

    if ($response->isOk()) {
        echo "  Dimensions folders configured.\n";
    } else {
        echo "  ERROR: {$response->getErrorMessage()}\n";
    }
} catch (\Throwable $e) {
    echo "  ERROR: {$e->getMessage()}\n";
}

echo "=== Multi-Country Demo Setup Complete ===\n";
