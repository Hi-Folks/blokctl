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

Most resource-oriented commands are backed by reusable Action classes with no CLI dependencies. Orchestration commands such as `space:setup` compose Actions with services under `Blokctl\SpaceSetup`.

- **Constructor** receives only the `ManagementApiClient`
- **Read-only Actions** have a single `execute()` returning a typed Result DTO
- **Mutating Actions** usually expose `execute()` as the normal one-call API. Add `preflight()` when callers need fetched state for confirmation or interactive selection.
- **Result DTOs** are `final readonly` classes with public properties

## Quick reference

| Action | Does | Key result properties |
|---|---|---|
| `Space\SpaceCreateAction` | Create or duplicate a space | `->space`, `->duplicated`, `->duplicateFrom` |
| `Space\SpaceInfoAction` | Get space info | `->space`, `->user`, `->isOwner` |
| `Space\SpacesListAction` | List/filter spaces | `->spaces`, `->errors`, `->count()` |
| `Space\SpaceDeleteAction` | Delete space (preflight+execute) | `->canDelete()`, `->isOwner`, `->isSolo` |
| `Space\SpaceDemoRemoveAction` | Remove demo mode (preflight+execute) | `->isDemo` |
| `Space\SpaceReadinessAction` | Wait until duplicated-space tasks complete | `->attempts`, `->elapsedSeconds` |
| `Space\SpaceTokenAction` | Retrieve first preview token | `->token` |
| `SpacePreview\SpacePreviewListAction` | List preview URLs | `->defaultDomain`, `->environments` |
| `SpacePreview\SpacePreviewSetAction` | Set preview URL (preflight+execute) | `->space` |
| `SpacePreview\SpacePreviewAddAction` | Add environment (preflight+execute) | `->space` |
| `Folder\FolderCreateAction` | Create folder | `->folder`, `->parentId` |
| `Folder\FolderDimensionAddAction` | Create folder + append to Dimensions app config | `->folder`, `->folderIds`, `->dimensionsFolders` |
| `Story\StoryCreateAction` | Create story with content | `->story` |
| `Story\StoriesListAction` | List/filter stories | `->stories`, `->count()` |
| `Story\StoryUpdateAction` | Update story content | `->story`, `->appliedContent` |
| `Story\StoryFieldSetAction` | Set a single field | `->story`, `->fieldName`, `->newValue`, `->previousValue` |
| `Story\StoryShowAction` | Show story by slug/id/uuid | `->story`, `->fullResponse` |
| `Story\StoryMoveAction` | Move story to folder | `->story`, `->previousFolderId`, `->newFolderId` |
| `Story\StoryWorkflowChangeAction` | Change or remove workflow stage | `->story`, `->workflowStageName`, `->workflowStageId`, `->previousWorkflowStageId` |
| `Story\StoriesBulkCreateAction` | Create stories from JSON files in a directory | `->created`, `->errors`, `->count()`, `->errorCount()` |
| `Story\StoriesTagsAssignAction` | Assign tags to stories | `->tagged`, `->errors` |
| `Story\StoryVersionsAction` | List story versions | `->versions`, `->storyId`, `->count()` |
| `Story\StoriesWorkflowAssignAction` | Assign stage to unstaged stories (preflight+execute) | `->countWithoutStage`, `->workflowStages` |
| `Asset\AssetsListAction` | List/search assets (MAPI only) | `->assets`, `->count()` |
| `Asset\AssetsUnreferencedAction` | Find unreferenced assets (optional `previewToken`) | `->unreferencedAssets`, `->totalAssets`, `->referencedCount`, `->storiesAnalyzed` |
| `Workflow\WorkflowsListAction` | List workflows+stages | `->workflows`, `->count()` |
| `Workflow\WorkflowStageShowAction` | Show stage details | `->stage`, `->workflowName` |
| `Component\ComponentsListAction` | List/filter components | `->components`, `->count()` |
| `Component\ComponentsUsageAction` | Analyze component usage | `->usage`, `->storiesAnalyzed` |
| `Component\ComponentFieldAddAction` | Add field to component (preflight+execute) | `->component`, `->schema` |
| `Component\ComponentShowAction` | Get component fields and schema | `->component` |
| `AppProvision\AppProvisionListAction` | List installed apps | `->provisions`, `->count()` |
| `AppProvision\AppProvisionInstallAction` | Install app (preflight+execute) | `->appOptions` (for selection) |
| `Experiment\ExperimentsListAction` | List experiments | `->experiments`, `->count()` |
| `Experiment\ExperimentCreateAction` | Create a draft experiment | `->experiment` |
| `Experiment\ExperimentResultsPushAction` | Push result charts | `->experimentResult` |
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

### Create or duplicate a space

```php
use Blokctl\Action\Space\SpaceCreateAction;

$result = (new SpaceCreateAction($client))->execute(
    name: 'Acme Demo',
    duplicateFrom: '286863409930127',
    inOrg: true,
);

$newSpaceId = $result->space->id();
```

### Wait for duplicated-space readiness

```php
use Blokctl\Action\Space\SpaceReadinessAction;

$result = (new SpaceReadinessAction($client))->execute(
    spaceId: $newSpaceId,
    timeoutSeconds: 120,
    pollIntervalSeconds: 2,
);
```

`SpaceReadinessAction` polls the space's `has_pending_tasks` value. HTTP request retries remain managed by `ManagementApiClient`.

## Configuration as Code API

The `space:setup` command is composed from services under `Blokctl\SpaceSetup`:

- `SpaceSetupConfigLoader` and `SpaceSetupConfigValidator`
- `SpaceSetupInputsResolver` and `SpaceSetupVariableResolver`
- `SpaceSetupTargetResolver` and `SpaceSetupProvisioner`
- `SpaceSetupReporter` and `SpaceSetupReportWriter`

These services support validated YAML/JSON setup, reconcile behavior, dry-run planning, structured operation results, machine-readable reports, folder reconciliation, selected root-content moves, and Dimensions folder configuration. The setup command is currently the supported orchestration entry point; use individual Actions when embedding custom workflows in PHP.

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

### Change or remove a workflow stage
```php
use Blokctl\Action\Story\StoryWorkflowChangeAction;

$action = new StoryWorkflowChangeAction($client);

// Set by story slug and stage name. Stage name matching is case-insensitive.
$result = $action->execute(
    spaceId: $spaceId,
    storySlug: 'home',
    stageName: 'Reviewing',
);

// Set by story ID and stage ID.
$result = $action->execute(
    spaceId: $spaceId,
    storyId: '123456',
    stageId: 653555,
);

// Scope stage-name lookup to a specific workflow.
$result = $action->execute(
    spaceId: $spaceId,
    storySlug: 'home',
    stageName: 'Reviewing',
    workflowName: 'Default workflow',
);

// Remove the current workflow stage.
$result = $action->execute(
    spaceId: $spaceId,
    storySlug: 'home',
    stageId: 0,
);

// For interactive pickers, fetch available stages first.
$preflight = $action->preflight($spaceId);
$preflight->workflowStages; // array [id => name]
```

### Resolve helpers
```php
// Resolve app by slug → ID
$appId = (new AppProvisionInstallAction($client))->resolveBySlug($spaceId, 'my-app');

// Resolve folder by slug → ID
$folderId = (new StoryMoveAction($client))->resolveFolderBySlug($spaceId, 'archived/authors');
```

## Error handling

- Fatal issues throw `\RuntimeException`
- Non-fatal batch errors are collected in typed Result properties such as `$result->errors`
- Always wrap API calls in try/catch with rate-limit awareness (`shouldRetry: true` handles 429s)

For the complete API with all method signatures, see `README.md` section "Using Actions from code".
