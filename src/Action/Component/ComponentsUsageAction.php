<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

use Storyblok\Api\Domain\Value\Dto\Pagination;
use Storyblok\Api\Domain\Value\Dto\Version;
use Storyblok\Api\Request\StoriesRequest;
use Storyblok\Api\StoriesApi;
use Storyblok\Api\StoryblokClient;
use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\StoryblokUtils;

final readonly class ComponentsUsageAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function execute(
        string $spaceId,
        string $region = 'EU',
        ?string $startsWith = null,
        int $perPage = 25,
    ): ComponentsUsageResult {
        // Retrieve the preview token from the space via Management API
        $space = (new SpaceApi($this->client))->get($spaceId)->data();
        $token = $space->firstToken();

        if ($token === '') {
            throw new \RuntimeException('No preview access token found for this space.');
        }

        $baseUri = StoryblokUtils::baseUriFromRegionForOauth($region);
        $cdnClient = new StoryblokClient(
            baseUri: $baseUri,
            token: $token,
            timeout: 30,
        );
        $storiesApi = new StoriesApi($cdnClient, 'draft');

        /** @var array<string, array{stories: int, total: int}> $usage */
        $usage = [];
        $storiesAnalyzed = 0;
        $page = 1;

        do {
            $request = new StoriesRequest(
                pagination: new Pagination(page: $page, perPage: $perPage),
                version: Version::Draft,
                startsWith: $startsWith !== null
                    ? new \Storyblok\Api\Domain\Value\Slug\Slug($startsWith)
                    : null,
            );

            $response = $storiesApi->all($request);

            /** @var array<string, mixed> $story */
            foreach ($response->stories as $story) {
                ++$storiesAnalyzed;
                /** @var array<string, mixed> $content */
                $content = $story['content'] ?? [];
                $found = $this->extractComponents($content);

                foreach ($found as $componentName => $count) {
                    if (!isset($usage[$componentName])) {
                        $usage[$componentName] = ['stories' => 0, 'total' => 0];
                    }

                    ++$usage[$componentName]['stories'];
                    $usage[$componentName]['total'] += $count;
                }
            }

            $fetchedCount = count($response->stories);
            ++$page;
        } while ($fetchedCount >= $perPage);

        // Sort by total occurrences descending
        uasort($usage, fn(array $a, array $b): int => $b['total'] <=> $a['total']);

        return new ComponentsUsageResult(
            usage: $usage,
            storiesAnalyzed: $storiesAnalyzed,
        );
    }

    /**
     * Recursively extract component names and their occurrence count from content.
     *
     * @param array<mixed> $data
     * @return array<string, int> Component name => count in this story
     */
    private function extractComponents(array $data): array
    {
        /** @var array<string, int> $components */
        $components = [];

        if (isset($data['component']) && is_string($data['component'])) {
            $name = $data['component'];
            $components[$name] = 1;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                foreach ($this->extractComponents($value) as $name => $count) {
                    $components[$name] = ($components[$name] ?? 0) + $count;
                }
            }
        }

        return $components;
    }
}
