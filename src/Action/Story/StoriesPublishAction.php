<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Publish or unpublish selected stories.
 *
 * Stories are selected by exact full slug, by ID, or by folder (every story inside
 * the folder, including nested folders and the folder's start page). Folders themselves
 * are never published. Stories already in the requested state are skipped:
 * publishing skips stories that are published without unpublished changes,
 * unpublishing skips stories that are not published.
 */
final readonly class StoriesPublishAction
{
    private const int PER_PAGE = 100;

    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Select the stories and their current state, without changing them.
     *
     * @param list<string> $slugs Exact full slugs, e.g. `about` or `products/` (a folder's start page)
     * @param list<string|int> $ids Story IDs
     * @param list<string> $folders Folder full slugs; every story inside is selected
     *
     * @throws \RuntimeException if nothing is selected, a slug or ID does not match a story, or the API request fails
     */
    public function preflight(
        string $spaceId,
        array $slugs = [],
        array $ids = [],
        array $folders = [],
        bool $publish = true,
    ): StoriesPublishResult {
        if ($slugs === [] && $ids === [] && $folders === []) {
            throw new \RuntimeException('Select stories with at least one slug, ID, or folder.');
        }

        $stories = [];
        if ($slugs !== []) {
            $found = array_values(array_filter(
                $this->stories($spaceId, ['by_slugs' => implode(',', $slugs)]),
                static fn(array $story): bool => in_array($story['full_slug'] ?? null, $slugs, true),
            ));
            $this->assertAllFound($slugs, array_column($found, 'full_slug'), 'slug');
            $stories = [...$stories, ...$found];
        }

        if ($ids !== []) {
            $ids = array_map(static fn(string|int $id): string => trim((string) $id), $ids);
            $found = array_values(array_filter(
                $this->stories($spaceId, ['by_ids' => implode(',', $ids)]),
                static fn(array $story): bool => in_array(is_scalar($story['id'] ?? null) ? (string) $story['id'] : '', $ids, true),
            ));
            $this->assertAllFound($ids, array_map(static fn(mixed $id): string => is_scalar($id) ? (string) $id : '', array_column($found, 'id')), 'ID');
            $stories = [...$stories, ...$found];
        }

        foreach ($folders as $folder) {
            $stories = [...$stories, ...$this->stories($spaceId, ['starts_with' => rtrim(trim($folder), '/') . '/'])];
        }

        $entries = [];
        foreach ($stories as $story) {
            $id = is_numeric($story['id'] ?? null) ? (int) $story['id'] : 0;
            if ($id === 0 || isset($entries[$id])) {
                continue;
            }

            $published = ($story['published'] ?? false) === true;
            $pendingChanges = ($story['unpublished_changes'] ?? false) === true;
            $alreadyDone = $publish ? $published && !$pendingChanges : !$published;
            $entries[$id] = [
                'id' => $id,
                'fullSlug' => is_string($story['full_slug'] ?? null) ? $story['full_slug'] : '',
                'status' => $alreadyDone ? StoriesPublishResult::SKIPPED : StoriesPublishResult::PLANNED,
                'message' => '',
            ];
        }

        return new StoriesPublishResult($publish, array_values($entries));
    }

    /**
     * Publish or unpublish every planned story from the preflight. Failures are collected per story.
     */
    public function execute(string $spaceId, StoriesPublishResult $preflight): StoriesPublishResult
    {
        $storyApi = new StoryApi($this->client, $spaceId);
        $entries = [];
        foreach ($preflight->entries as $entry) {
            if ($entry['status'] !== StoriesPublishResult::PLANNED) {
                $entries[] = $entry;
                continue;
            }

            $response = $preflight->publish
                ? $storyApi->publish((string) $entry['id'])
                : $storyApi->unpublish((string) $entry['id']);

            $entries[] = $response->isOk()
                ? [...$entry, 'status' => $preflight->publish ? StoriesPublishResult::PUBLISHED : StoriesPublishResult::UNPUBLISHED]
                : [...$entry, 'status' => StoriesPublishResult::FAILED, 'message' => ApiError::message($response)];
        }

        return new StoriesPublishResult($preflight->publish, $entries);
    }

    /**
     * Stories (no folders) matching a list filter, across all pages.
     *
     * @param array<string, string> $filter
     * @return list<array<string, mixed>>
     */
    private function stories(string $spaceId, array $filter): array
    {
        $api = new ManagementApi($this->client);
        $stories = [];
        $page = 1;
        do {
            $response = $api->get(
                sprintf('spaces/%s/stories', $spaceId),
                [...$filter, 'story_only' => 1, 'page' => $page, 'per_page' => self::PER_PAGE],
            );
            if (!$response->isOk()) {
                throw new \RuntimeException('Failed to fetch stories: ' . ApiError::message($response));
            }

            $data = $response->toArray();
            $batch = is_array($data['stories'] ?? null) ? $data['stories'] : [];
            foreach ($batch as $story) {
                if (is_array($story) && ($story['is_folder'] ?? false) !== true) {
                    /** @var array<string, mixed> $story */
                    $stories[] = $story;
                }
            }

            $page++;
        } while (count($batch) === self::PER_PAGE);

        return $stories;
    }

    /**
     * @param list<string> $requested
     * @param array<mixed> $found
     */
    private function assertAllFound(array $requested, array $found, string $kind): void
    {
        $missing = array_diff($requested, array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', $found));
        if ($missing !== []) {
            throw new \RuntimeException(sprintf('No story found with %s: %s', $kind, implode(', ', $missing)));
        }
    }
}
