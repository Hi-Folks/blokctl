#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bulk user access: give groups of users edit access to their own content only.
 *
 * Typical use cases: regional offices, franchise locations, brands, or
 * departments that share one space but must only see and edit their content.
 *
 * For each group in the CSV, the script:
 *   1. resolves the content (story or folder, by full slug or numeric ID) to a story ID
 *   2. ensures a custom space role "<prefix><group>" exists, scoped with allowed_paths
 *   3. invites each email as a collaborator with that role (existing collaborators are skipped)
 *
 * CSV format (one row per user and content item; rows are grouped by "group"):
 *   group,email,content
 *   North Region,editor.north@example.com,regions/north
 *   North Region,manager.north@example.com,12345678
 *
 * Usage:
 *   php examples/bulk-user-access.php <space-id> examples/bulk-user-access.csv
 *   php examples/bulk-user-access.php <space-id> examples/bulk-user-access.csv --execute
 *   php examples/bulk-user-access.php <space-id> <csv> --execute --role-prefix="Team - " --region=US
 *
 * Dry run by default: it reads the space (content, roles, collaborators) and prints
 * the plan. Add --execute to create roles and send the invitations.
 *
 * Management API references:
 *   https://www.storyblok.com/docs/api/management/space-roles
 *   https://www.storyblok.com/docs/api/management/collaborators/add-a-collaborator
 */

require __DIR__ . '/../vendor/autoload.php';

use Blokctl\Action\Story\StoryMoveAction;
use Blokctl\Action\Story\StoryShowAction;
use Storyblok\ManagementApi\Data\Collaborator;
use Storyblok\ManagementApi\Data\Enum\Region;
use Storyblok\ManagementApi\Endpoints\CollaboratorApi;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\PaginationParams;
use Storyblok\ManagementApi\StoryblokUtils;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$spaceId = $argv[1] ?? null;
$csvPath = $argv[2] ?? null;
$execute = in_array('--execute', $argv, true);
$rolePrefix = optionValue($argv, '--role-prefix') ?? 'Group - ';

if ($spaceId === null || $csvPath === null) {
    echo "Usage: php examples/bulk-user-access.php <space-id> <csv-path> [--execute] [--role-prefix=\"Group - \"] [--region=EU|US|CA|AP|CN]\n";
    exit(1);
}

$region = Region::tryFrom(strtoupper(optionValue($argv, '--region') ?? StoryblokUtils::getRegionFromSpaceId($spaceId)));
if ($region === null) {
    echo 'Invalid region. Valid regions: ' . implode(', ', Region::values()) . "\n";
    exit(1);
}

$token = $_ENV['SECRET_KEY'] ?? '';
if ($token === '') {
    echo "SECRET_KEY not found in .env\n";
    exit(1);
}

$client = new ManagementApiClient($token, region: $region, shouldRetry: true);
$api = new ManagementApi($client);

try {
    $groups = groupRows(loadRows($csvPath), $client, $spaceId);
    $existingRoles = fetchRoles($api, $spaceId, $rolePrefix);
    $existingEmails = fetchCollaboratorEmails($client, $spaceId);
} catch (RuntimeException $runtimeException) {
    echo 'ERROR: ' . $runtimeException->getMessage() . "\n";
    exit(1);
}

echo ($execute ? 'EXECUTE' : 'DRY RUN') . ': bulk user access' . "\n";
echo 'Space ID: ' . $spaceId . ' (' . $region->value . ")\n\n";

foreach ($groups as $group) {
    $roleName = $rolePrefix . $group['name'];
    echo 'Group: ' . $group['name'] . "\n";
    echo '  Allowed content: ' . implode(', ', $group['content']) . ' (story IDs: ' . implode(', ', $group['story_ids']) . ")\n";

    $role = $existingRoles[$roleName] ?? null;
    if ($role !== null) {
        echo '  Reuse role: ' . $roleName . ' (ID ' . $role['id'] . ")\n";
        $missing = array_diff($group['story_ids'], $role['allowed_paths']);
        if ($missing !== []) {
            echo '  WARNING: existing role does not allow story IDs ' . implode(', ', $missing) . " (not updated)\n";
        }
    } else {
        echo '  Create role: ' . $roleName . "\n";
        if ($execute) {
            $role = createRole($api, $spaceId, $roleName, $group['story_ids']);
            $existingRoles[$roleName] = $role;
        }
    }

    foreach ($group['emails'] as $email) {
        if (isset($existingEmails[$email])) {
            echo '  Skip existing collaborator: ' . $email . "\n";
            continue;
        }

        echo '  Invite: ' . $email . "\n";
        if ($execute && $role !== null) {
            inviteCollaborator($api, $spaceId, $email, $role['id']);
            $existingEmails[$email] = true;
        }
    }

    echo "\n";
}

echo $execute
    ? "Done. Invited users receive an email from Storyblok to join the space.\n"
    : "Dry run only. Add --execute to create roles and invite collaborators.\n";

/**
 * @param array<int, string> $argv
 */
function optionValue(array $argv, string $option): ?string
{
    foreach ($argv as $argument) {
        if (str_starts_with($argument, $option . '=')) {
            return substr($argument, strlen($option) + 1);
        }
    }

    return null;
}

/**
 * @return list<array{group: string, email: string, content: string}>
 */
function loadRows(string $csvPath): array
{
    $handle = is_file($csvPath) ? fopen($csvPath, 'r') : false;
    if ($handle === false) {
        throw new RuntimeException('Unable to open CSV file: ' . $csvPath);
    }

    $headers = fgetcsv($handle, null, ',', '"', '');
    $headers = is_array($headers) ? array_map(trim(...), array_map(strval(...), $headers)) : [];
    foreach (['group', 'email', 'content'] as $required) {
        if (!in_array($required, $headers, true)) {
            throw new RuntimeException('CSV file must contain the column: ' . $required);
        }
    }

    $rows = [];
    $line = 1;
    while (($data = fgetcsv($handle, null, ',', '"', '')) !== false) {
        $line++;
        if ($data === [null]) {
            continue;
        }

        $row = array_combine($headers, array_pad($data, count($headers), ''));
        $group = trim((string) $row['group']);
        $email = strtolower(trim((string) $row['email']));
        $content = trim((string) $row['content'], " /");

        if ($group === '' || $content === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid CSV row at line ' . $line . ' (group, valid email, and content are required)');
        }

        $rows[] = ['group' => $group, 'email' => $email, 'content' => $content];
    }

    fclose($handle);

    return $rows;
}

/**
 * Group rows by group name and resolve content (slug or ID) to story IDs.
 *
 * @param list<array{group: string, email: string, content: string}> $rows
 * @return list<array{name: string, emails: list<string>, content: list<string>, story_ids: list<int>}>
 */
function groupRows(array $rows, ManagementApiClient $client, string $spaceId): array
{
    $storyIds = [];
    $groups = [];
    foreach ($rows as $row) {
        $content = $row['content'];
        if (!isset($storyIds[$content])) {
            $storyIds[$content] = ctype_digit($content)
                ? (int) $content
                : resolveContentId($client, $spaceId, $content);
        }

        $key = mb_strtolower($row['group']);
        $groups[$key]['name'] ??= $row['group'];
        $groups[$key]['emails'][$row['email']] = $row['email'];
        $groups[$key]['content'][$content] = $content;
        $groups[$key]['story_ids'][$storyIds[$content]] = $storyIds[$content];
    }

    return array_values(array_map(
        static fn(array $group): array => [
            'name' => $group['name'],
            'emails' => array_values($group['emails']),
            'content' => array_values($group['content']),
            'story_ids' => array_values($group['story_ids']),
        ],
        $groups,
    ));
}

/**
 * Resolve a full slug to a story ID, looking for a story first and then a folder.
 */
function resolveContentId(ManagementApiClient $client, string $spaceId, string $slug): int
{
    try {
        return (int) new StoryShowAction($client)->execute($spaceId, slug: $slug)->story->id();
    } catch (RuntimeException) {
        try {
            return new StoryMoveAction($client)->resolveFolderBySlug($spaceId, $slug);
        } catch (RuntimeException) {
            throw new RuntimeException('No story or folder found with slug: ' . $slug);
        }
    }
}

/**
 * Existing custom roles whose name starts with the prefix, keyed by role name.
 *
 * @return array<string, array{id: int, allowed_paths: list<int>}>
 */
function fetchRoles(ManagementApi $api, string $spaceId, string $rolePrefix): array
{
    $response = $api->get('spaces/' . $spaceId . '/space_roles', ['search' => $rolePrefix]);
    if (!$response->isOk()) {
        throw new RuntimeException('Failed to fetch space roles: ' . $response->getErrorMessage());
    }

    $roles = [];
    foreach ($response->toArray()['space_roles'] ?? [] as $role) {
        if (is_array($role) && is_string($role['role'] ?? null) && str_starts_with($role['role'], $rolePrefix)) {
            $roles[$role['role']] = [
                'id' => (int) $role['id'],
                'allowed_paths' => array_map(intval(...), (array) ($role['allowed_paths'] ?? [])),
            ];
        }
    }

    return $roles;
}

/**
 * Emails of all current collaborators of the space (lowercase), as a lookup set.
 *
 * @return array<string, true>
 */
function fetchCollaboratorEmails(ManagementApiClient $client, string $spaceId): array
{
    $collaboratorApi = new CollaboratorApi($client, $spaceId);
    $emails = [];
    $page = 1;
    do {
        $collaborators = $collaboratorApi->page(page: new PaginationParams($page, 100))->data();
        /** @var Collaborator $collaborator */
        foreach ($collaborators as $collaborator) {
            $email = strtolower($collaborator->realEmail());
            if ($email !== '') {
                $emails[$email] = true;
            }
        }

        $page++;
    } while ($collaborators->count() === 100);

    return $emails;
}

/**
 * Create a custom role that can only see and edit the given stories/folders.
 * allowed_paths takes numeric story IDs and is available on the Elite plan only.
 * The API docs don't state whether a folder ID also covers the stories inside it.
 *
 * @param list<int> $storyIds
 * @return array{id: int, allowed_paths: list<int>}
 */
function createRole(ManagementApi $api, string $spaceId, string $roleName, array $storyIds): array
{
    $response = $api->makeRequest('POST', '/v1/spaces/' . $spaceId . '/space_roles', [
        'json' => [
            'space_role' => [
                'role' => $roleName,
                'subtitle' => 'Can edit only the content assigned to this group.',
                'allowed_paths' => $storyIds,
                'permissions' => [
                    'read_stories',
                    'save_stories',
                    'view_content',
                    'view_folders',
                    'view_composer',
                ],
            ],
        ],
    ]);

    $role = $response->toArray()['space_role'] ?? null;
    if (!$response->isOk() || !is_array($role)) {
        throw new RuntimeException('Failed to create role "' . $roleName . '": ' . $response->getErrorMessage());
    }

    return ['id' => (int) $role['id'], 'allowed_paths' => $storyIds];
}

/**
 * Invite a user by email and assign the custom role.
 */
function inviteCollaborator(ManagementApi $api, string $spaceId, string $email, int $roleId): void
{
    $response = $api->makeRequest('POST', '/v1/spaces/' . $spaceId . '/collaborators/', [
        'json' => [
            'email' => $email,
            'role' => (string) $roleId,
            'space_role_id' => $roleId,
            'space_role_ids' => [],
            'permissions' => [],
            'allow_multiple_roles_creation' => false,
        ],
    ]);

    if (!$response->isOk()) {
        throw new RuntimeException('Failed to invite ' . $email . ': ' . $response->getErrorMessage());
    }
}
