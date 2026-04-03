# blokctl PHP Action API

Use this skill when the user wants to use blokctl Action classes from their own PHP code (Laravel, Symfony, scripts, etc.).

## Setup

```php
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\Data\Enum\Region;

$client = new ManagementApiClient('your-personal-access-token', shouldRetry: true);

// For non-EU regions:
$client = new ManagementApiClient('your-token', region: Region::US, shouldRetry: true);
```

Install as a Composer package: `composer require hi-folks/blokctl`

## Action pattern

Every CLI command is backed by a reusable Action class with no CLI dependencies:

- **Constructor** receives only the `ManagementApiClient`
- **Read-only Actions** have a single `execute()` returning a typed Result DTO
- **Mutating Actions** use `preflight()` to validate + fetch data, then `execute()` to apply changes
- **Result DTOs** are `final readonly` classes with public properties

## Quick reference

| Action | Does | Key result properties |
|---|---|---|
| `Space\SpaceInfoAction` | Get space info | `->space`, `->user`, `->isOwner` |
| `Space\SpacesListAction` | List/filter spaces | `->spaces`, `->errors`, `->count()` |
| `Space\SpaceDeleteAction` | Delete space (preflight+execute) | `->canDelete()`, `->isOwner`, `->isSolo` |
| `Space\SpaceDemoRemoveAction` | Remove demo mode (preflight+execute) | `->isDemo` |
| `SpacePreview\SpacePreviewListAction` | List preview URLs | `->defaultDomain`, `->environments` |
| `SpacePreview\SpacePreviewSetAction` | Set preview URL (preflight+execute) | `->space` |
| `SpacePreview\SpacePreviewAddAction` | Add environment (preflight+execute) | `->space` |
| `Folder\FolderCreateAction` | Create folder | `->folder`, `->parentId` |
| `Story\StoryCreateAction` | Create story with content | `->story` |
| `Story\StoriesListAction` | List/filter stories | `->stories`, `->count()` |
| `Story\StoryUpdateAction` | Update story content | `->story`, `->appliedContent` |
| `Story\StoryFieldSetAction` | Set a single field | `->story`, `->fieldName`, `->newValue`, `->previousValue` |
| `Story\StoryShowAction` | Show story by slug/id/uuid | `->story`, `->fullResponse` |
| `Story\StoryMoveAction` | Move story to folder | `->story`, `->previousFolderId`, `->newFolderId` |
| `Story\StoryWorkflowChangeAction` | Change workflow stage | `->story`, `->workflowStageName` |
| `Story\StoriesTagsAssignAction` | Assign tags to stories | `->tagged`, `->errors` |
| `Story\StoryVersionsAction` | List story versions | `->versions`, `->storyId`, `->count()` |
| `Story\StoriesWorkflowAssignAction` | Assign stage to unstaged stories (preflight+execute) | `->countWithoutStage`, `->workflowStages` |
| `Asset\AssetsUnreferencedAction` | Find unreferenced assets | `->unreferencedAssets`, `->totalAssets`, `->referencedCount`, `->storiesAnalyzed` |
| `Workflow\WorkflowsListAction` | List workflows+stages | `->workflows`, `->count()` |
| `Workflow\WorkflowStageShowAction` | Show stage details | `->stage`, `->workflowName` |
| `Component\ComponentsListAction` | List/filter components | `->components`, `->count()` |
| `Component\ComponentsUsageAction` | Analyze component usage | `->usage`, `->storiesAnalyzed` |
| `Component\ComponentFieldAddAction` | Add field to component (preflight+execute) | `->component`, `->schema` |
| `AppProvision\AppProvisionListAction` | List installed apps | `->provisions`, `->count()` |
| `AppProvision\AppProvisionInstallAction` | Install app (preflight+execute) | `->appOptions` (for selection) |
| `User\UserMeAction` | Get current user | `->user` |

All Action classes are in the `Blokctl\Action\` namespace.

## Common usage examples

### Read-only action
```php
use Blokctl\Action\Story\StoriesListAction;

$result = (new StoriesListAction($client))->execute(
    spaceId: $spaceId,
    contentType: 'page',
    startsWith: 'articles/',
    withTag: 'Landing',
    page: 1,
    perPage: 25,
);
// $result->stories, $result->count()
```

### Mutating action (preflight + execute)
```php
use Blokctl\Action\Space\SpaceDeleteAction;

$action = new SpaceDeleteAction($client);
$result = $action->preflight($spaceId);

if ($result->canDelete()) {
    $action->execute($spaceId, $result);
}
```

### Create story with simplified JSON
```php
use Blokctl\Action\Story\StoryCreateAction;

$action = new StoryCreateAction($client);
$result = $action->execute($spaceId, 'My Article', [
    'component' => 'article',
    'title' => 'Hello World',
    'cover_image' => ['_asset' => 'https://example.com/hero.jpg'],
    'cta_link' => ['_slug' => 'contact'],
    'body' => [
        ['component' => 'hero_section', 'title' => 'Welcome'],
        ['component' => 'text_block', 'content' => 'Hello'],
    ],
], slug: 'my-article', parentId: 123456, publish: true);
```

### Update story content
```php
use Blokctl\Action\Story\StoryUpdateAction;

$action = new StoryUpdateAction($client);
$result = $action->execute($spaceId, [
    'headline' => 'Updated headline',
    'cover_image' => ['_asset' => 'https://example.com/new-photo.jpg'],
], storySlug: 'home', publish: true);
```

### ContentResolver (standalone)
```php
use Blokctl\Action\Story\ContentResolver;

$resolver = new ContentResolver($client, $spaceId);
$resolved = $resolver->resolve($simplifiedContent);
// _asset markers → uploaded asset fields, bloks get _uid, nested recursively
```

### Resolve helpers
```php
// Resolve app by slug → ID
$appId = (new AppProvisionInstallAction($client))->resolveBySlug($spaceId, 'my-app');

// Resolve folder by slug → ID
$folderId = (new StoryMoveAction($client))->resolveFolderBySlug($spaceId, 'archived/authors');

// Resolve workflow stage by name → ID
$resolved = (new StoryWorkflowChangeAction($client))->resolveWorkflowStage($spaceId, stageName: 'Review');
$stageId = $resolved['stageId'];
```

## Error handling

- Fatal issues throw `\RuntimeException`
- Non-fatal batch errors are collected in `$result->errors` (string array)
- Always wrap API calls in try/catch with rate-limit awareness (`shouldRetry: true` handles 429s)

For the complete API with all method signatures, see `README.md` section "Using Actions from code".
