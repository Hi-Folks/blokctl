# blokctl Developer Guide

Use this skill when the user wants to add features, fix bugs, or contribute to blokctl.

## Naming conventions

- **Command names**: `<endpoint>:<context>-<operation>` (e.g. `story:workflow-change`, `space:preview-set`)
- **Class names**: `<Endpoint><Context><Operation>Command` / `Action` / `Result` (e.g. `StoryWorkflowChangeCommand`)
- **Test names**: `<Endpoint><Context><Operation>ActionTest` (e.g. `StoryWorkflowChangeActionTest`)

## Project structure

```
src/
├── Action/<Group>/          # Reusable business logic (no CLI deps)
│   ├── <Name>Action.php     # Action class
│   └── <Name>Result.php     # Result DTO
├── Command/
│   ├── AbstractCommand.php  # Base: client init, --space-id, --region
│   └── <Name>Command.php    # Thin CLI wrapper
├── SpaceSetup/              # Config loading, validation, variables, provisioning, and reporting
└── Render.php               # Terminal output helpers (Termwind)

tests/
├── TestCase.php             # Base: mockResponse(), createMockClient()
├── Unit/Action/<Group>/     # Tests mirror src/Action structure
├── Unit/SpaceSetup/         # Focused provisioning/configuration tests
└── Fixtures/                # Mock API response JSON files
```

Current Action groups: `AppProvision`, `Asset`, `Component`, `Experiment`, `Folder`, `Space`, `SpacePreview`, `Story`, `User`, and `Workflow`.

## How to add a new command

### Step 1: Create the Action + Result

**Read-only action** (`src/Action/<Group>/<Name>Action.php`):
```php
<?php
declare(strict_types=1);
namespace Blokctl\Action\<Group>;

use Storyblok\ManagementApi\ManagementApiClient;

final readonly class <Name>Action
{
    public function __construct(private ManagementApiClient $client) {}

    public function execute(string $spaceId, /* params */): <Name>Result
    {
        // API calls using $this->client
        return new <Name>Result(/* ... */);
    }
}
```

**Mutating action** — prefer a clear one-call `execute()` for normal use. Add `preflight()` only when callers need fetched state for confirmation, safety checks, or interactive selection:
```php
public function preflight(string $spaceId): <Name>Result
{
    // Fetch current state for confirmation/selection
}

public function execute(string $spaceId, <Name>Result $preflight, /* params */): void
{
    // Apply changes using preflight data
}
```

For mutating actions that can resolve their inputs internally, keep `execute()` as the main developer API and make resolution helpers private. Example: `StoryWorkflowChangeAction::execute()` accepts `storySlug`/`storyId` and `stageName`/`stageId` directly; `preflight()` is only used to list workflow stages for interactive selection.

**Result DTO** (`src/Action/<Group>/<Name>Result.php`):
```php
<?php
declare(strict_types=1);
namespace Blokctl\Action\<Group>;

final readonly class <Name>Result
{
    public function __construct(
        public mixed $data,  // use typed properties
    ) {}
}
```

### Step 2: Create the Command

`src/Command/<Name>Command.php`:
```php
<?php
declare(strict_types=1);
namespace Blokctl\Command;

use Blokctl\Action\<Group>\<Name>Action;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: '<endpoint>:<context>-<operation>', description: '...')]
final class <Name>Command extends AbstractCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        [$client, $spaceId] = $this->setup($input, $output);

        $action = new <Name>Action($client);
        $result = $action->execute($spaceId);

        // Render output using Render:: static methods
        Render::title('Title');
        Render::labelValue('Key', $value);

        return Command::SUCCESS;
    }
}
```

Key patterns:
- Call `$this->setup($input, $output)` to get `[$client, $spaceId]`
- Use `$input->getOption('...')` first, fall back to `text()` / `select()` / `confirm()` from `laravel/prompts`
- Resource lookup uses mutually exclusive `--by-slug`, `--by-id`, `--by-uuid`
- Use `Render::` helpers for terminal output
- Keep commands thin. Test Actions and `SpaceSetup` services instead of asserting terminal output
- Commands that do not target an existing space, such as `space:create` and `space:setup-validate`, initialize their client or inputs directly

## Space setup development

`space:setup` is a Configuration as Code workflow backed by services under `src/SpaceSetup/`.

- Validate YAML and JSON with `space-setup-schema.json` before modifying Storyblok.
- Preserve unmanaged resources and never remove omitted resources in reconcile mode.
- Keep dry-run API-free and record operations as `PLANNED`.
- Use `SpaceSetupReporter` for consistent operation statuses.
- Ensure failures return a non-zero exit code and remain available in JSON reports.
- Preserve duplicated spaces after setup failures; do not automatically roll them back.
- Update `space-setup-config.md`, examples, schema, changelog, roadmap, and focused tests when adding setup syntax.

### Step 3: Register in `bin/blokctl`

Add the command import and `$application->addCommand(new <Name>Command());` registration to `bin/blokctl`.

### Step 4: Write tests

**Add fixture** in `tests/Fixtures/<name>.json` (mock API response).

**Add test** in `tests/Unit/Action/<Group>/<Name>ActionTest.php`:
```php
<?php
declare(strict_types=1);
namespace Tests\Unit\Action\<Group>;

use Blokctl\Action\<Group>\<Name>Action;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class <Name>ActionTest extends TestCase
{
    #[Test]
    public function execute_does_something(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('fixture-name'),  // mock API response
        );

        $action = new <Name>Action($client);
        $result = $action->execute('12345');

        $this->assertSame('expected', $result->property);
    }
}
```

Testing helpers from `TestCase`:
- `mockResponse('fixture')` — load `tests/Fixtures/fixture.json` as a MockResponse
- `mockData('fixture')` — read fixture file content as string
- `createMockClient(...$responses)` — create `ManagementApiClient` with mocked HTTP

### Step 5: Update docs

- Update `README.md`: add CLI docs (Commands section) + Action API docs (Using Actions from code section)
- Update `CHANGELOG.md`

## Running checks

```bash
composer test-code        # PHPUnit 13
composer static-code      # PHPStan
composer style-check-code # PHP CS Fixer check
composer refactor-check-code # Rector dry run
composer all-checks       # Lint, style, PHPStan, Rector, and PHPUnit
```

The project uses PHPUnit 13 and requires PHP 8.4.1 or higher.

## PHPStan notes

- Narrow mixed values with real runtime checks before offset access.
- Prefer fixing underlying types instead of suppressing PHPStan errors.

## Key files to understand

| File | Purpose |
|---|---|
| `src/Command/AbstractCommand.php` | Base command: client init, `--space-id`/`--region` options, `setup()` method |
| `src/Render.php` | Terminal output: `title()`, `labelValue()`, `labelValueCondition()`, `titleSection()`, `log()`, `error()` |
| `src/SpaceSetup/SpaceSetupProvisioner.php` | Reconcile configured setup sections |
| `space-setup-schema.json` | YAML/JSON setup validation and editor autocomplete |
| `space-setup-config.md` | Setup syntax and behavior reference |
| `tests/TestCase.php` | Test base: `mockResponse()`, `createMockClient()` |
| `bin/blokctl` | Entry point: registers all commands |

## Render helpers

```php
Render::title('Space Info');                           // Blue header
Render::labelValue('Name', $space->name());            // Key-value with dots
Render::labelValueCondition('Owner', $isOwner);        // Green (true) / Red (false)
Render::titleSection('Preview URLs');                   // Green section header
Render::log('Processing...');                          // Yellow message
Render::error('Something went wrong');                 // Red error message
Render::operation('UPDATED', 'Configure preview URLs');// Structured operation
Render::notice('Space setup complete.');               // Important notice
```
