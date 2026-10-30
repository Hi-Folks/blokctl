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
 *   2. ensures a custom space role "<prefix><group>" exists, with allowed_paths set to that content
 *   3. adds each email as a collaborator with that role (current collaborators are skipped)
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
 * the plan. Add --execute to create or update the roles and add the collaborators.
 *
 * Notes:
 *   - allowed_paths requires the Elite plan.
 *   - Emails without a Storyblok account get a pending invitation. Pending invitations
 *     are not returned by the collaborators list, so a second --execute run cannot skip them.
 */

require __DIR__ . '/../vendor/autoload.php';

use Blokctl\Action\Space\SpaceCollaboratorInviteAction;
use Blokctl\Action\Space\SpaceCollaboratorInviteResult;
use Blokctl\Action\Space\SpaceCollaboratorListAction;
use Blokctl\Action\Space\SpaceRoleEnsureAction;
use Blokctl\Action\Space\SpaceRoleListAction;
use Blokctl\Action\Story\StoryResolveIdAction;
use Storyblok\ManagementApi\Data\Enum\Region;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\StoryblokUtils;

const ROLE_SUBTITLE = 'Can edit only the content assigned to this group.';
const ROLE_PERMISSIONS = ['read_stories', 'save_stories', 'view_content', 'view_folders', 'view_composer'];

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

try {
    $groups = groupRows(loadRows($csvPath));
    $resolver = new StoryResolveIdAction($client);
    foreach ($groups as $index => $group) {
        $groups[$index]['story_ids'] = $resolver->executeMany($spaceId, $group['content']);
    }

    $roles = new SpaceRoleListAction($client)->execute($spaceId, $rolePrefix);
    $collaborators = new SpaceCollaboratorListAction($client)->execute($spaceId);
} catch (RuntimeException $runtimeException) {
    echo 'ERROR: ' . $runtimeException->getMessage() . "\n";
    exit(1);
}

echo ($execute ? 'EXECUTE' : 'DRY RUN') . ': bulk user access' . "\n";
echo 'Space ID: ' . $spaceId . ' (' . $region->value . ")\n\n";

$failed = false;
foreach ($groups as $group) {
    $roleName = $rolePrefix . $group['name'];
    echo 'Group: ' . $group['name'] . "\n";
    echo '  Allowed content: ' . implode(', ', $group['content']) . ' (story IDs: ' . implode(', ', $group['story_ids']) . ")\n";

    if (!$execute) {
        echo '  ' . ($roles->findByName($roleName) === null ? 'Create' : 'Ensure') . ' role: ' . $roleName . "\n";
        foreach ($group['emails'] as $email) {
            echo '  ' . ($collaborators->findByEmail($email) === null ? 'Add' : 'Skip existing collaborator') . ': ' . $email . "\n";
        }

        echo "\n";
        continue;
    }

    try {
        // Creates the role, or updates it so subtitle, permissions, and allowed_paths match.
        $role = new SpaceRoleEnsureAction($client)->execute(
            spaceId: $spaceId,
            name: $roleName,
            subtitle: ROLE_SUBTITLE,
            permissions: ROLE_PERMISSIONS,
            allowedPaths: $group['story_ids'],
        );
        echo '  Role ' . $role->status . ': ' . $roleName . ' (ID ' . $role->roleId() . ")\n";

        $invite = new SpaceCollaboratorInviteAction($client);
        $result = $invite->execute($spaceId, $invite->preflight($spaceId, $group['emails'], (string) $role->roleId()));
    } catch (RuntimeException $runtimeException) {
        echo '  ERROR: ' . $runtimeException->getMessage() . "\n\n";
        $failed = true;
        continue;
    }

    foreach ($result->entries as $entry) {
        echo '  ' . match ($entry['status']) {
            SpaceCollaboratorInviteResult::SKIPPED => 'Skip existing collaborator',
            SpaceCollaboratorInviteResult::ADDED => 'Added existing Storyblok user',
            SpaceCollaboratorInviteResult::INVITED => 'Invitation pending (no Storyblok account yet)',
            default => 'FAILED (' . $entry['message'] . ')',
        } . ': ' . $entry['email'] . "\n";
    }

    $failed = $failed || $result->hasFailures();
    echo "\n";
}

echo $execute
    ? ($failed ? "Done with errors.\n" : "Done.\n")
    : "Dry run only. Add --execute to create roles and add collaborators.\n";
exit($failed ? 1 : 0);

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
 * Group rows by group name (case-insensitive).
 *
 * @param list<array{group: string, email: string, content: string}> $rows
 * @return list<array{name: string, emails: list<string>, content: list<string>, story_ids: list<int>}>
 */
function groupRows(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $key = mb_strtolower($row['group']);
        $groups[$key]['name'] ??= $row['group'];
        $groups[$key]['emails'][$row['email']] = $row['email'];
        $groups[$key]['content'][$row['content']] = $row['content'];
    }

    return array_values(array_map(
        static fn(array $group): array => [
            'name' => $group['name'],
            'emails' => array_values($group['emails']),
            'content' => array_values($group['content']),
            'story_ids' => [],
        ],
        $groups,
    ));
}
