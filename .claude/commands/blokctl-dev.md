# blokctl Developer Guide

Use this skill when the user wants to add features, fix bugs, or contribute to blokctl.

## Naming conventions

- **Command names**: `<endpoint>:<context>-<operation>` (e.g. `story:workflow-change`, `space:preview-set`)
- **Class names**: `<Endpoint><Context><Operation>Command` / `Action` / `Result` (e.g. `StoryWorkflowChangeCommand`)
- **Test names**: `<Endpoint><Context><Operation>ActionTest` (e.g. `StoryWorkflowChangeActionTest`)

## Project structure

```
src/
├── Action/<Group>/          # Business logic (no CLI deps) — Groups: AppProvision, Asset, Component, Folder, Space, SpacePreview, Story, User, Workflow
│   ├── <Name>Action.php     # Action class
│   └── <Name>Result.php     # Result DTO
├── Command/
│   ├── AbstractCommand.php  # Base: client init, --space-id, --region
│   └── <Name>Command.php    # Thin CLI wrapper
└── Render.php               # Terminal output helpers (Termwind)

tests/
├── TestCase.php             # Base: mockResponse(), createMockClient()
├── Unit/Action/<Group>/     # Tests mirror src/Action structure
└── Fixtures/                # Mock API response JSON files
```

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

**Mutating action** — add a `preflight()` method that fetches data and validates preconditions:
```php
public function preflight(string $spaceId): <Name>Result
{
    // Fetch current state, return result with safety flags
}

public function execute(string $spaceId, <Name>Result $preflight, /* params */): void
{
    // Apply changes using preflight data
}
```

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

### Step 3: Register in `bin/blokctl`

Add the use statement and `$application->add(new <Name>Command());`.

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
composer test-code        # PHPUnit 12
composer static-code      # PHPStan
composer style-fix-code   # PHP CS Fixer
composer all-checks       # All of the above + lint
```

## PHPStan notes

- Use `@var` annotations for mixed types from API responses
- Use `@phpstan-ignore` for SDK iterables that PHPStan can't type

## Key files to understand

| File | Purpose |
|---|---|
| `src/Command/AbstractCommand.php` | Base command: client init, `--space-id`/`--region` options, `setup()` method |
| `src/Render.php` | Terminal output: `title()`, `labelValue()`, `labelValueCondition()`, `titleSection()`, `log()`, `error()` |
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
```
