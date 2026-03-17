# blokctl

> **0.x — Early testing phase**
>
> This package is currently at version **0.x**, which means it is under active development and testing. APIs and commands may change without notice.
>
> If you'd like to participate in testing, feel free to use it and share your feedback, bug reports, and feature requests via [GitHub Issues](https://github.com/hi-folks/blokctl/issues).
>
> **Important:** Since this is a testing phase, please use a **test user** with a **test Personal Access Token** that only has access to a **test space**. Do not use your production credentials or spaces.

**Your Storyblok space, under your control.**

An opinionated, unofficial CLI tool for managing [Storyblok](https://www.storyblok.com/) spaces, built with PHP. Configure spaces, shape components, manage stories, set preview URLs, install apps, assign workflows and tags — all from the command line.

## How is `blokctl` different from the Official Storyblok CLI?

The official [Storyblok CLI](https://www.storyblok.com/docs/libraries/storyblok-cli) scaffolds Storyblok projects and facilitates Management API requests, such as pushing and pulling content and schemas.

**blokctl** is a different kind of tool. It focuses on **crafting and auto-setup**: fine-tuning and adapting your Storyblok space programmatically. Use it to configure preview URLs, install apps, assign workflow stages, manage tags, add component fields, and orchestrate demo setups — all from the command line or from your own PHP code.

## Requirements

- PHP 8.2 or higher
- A Storyblok [Personal Access Token](https://app.storyblok.com/#/me/account?tab=token)

## Installation

To install the `blokctl` as a project you can run:

```bash
composer create-project hi-folks/blokctl
# enter into the new directory created
cd blokctl
```

If you want to use the `blokctl` functions and functionalities in your PHP project (Laravel or Symfony also) you can install it as package:
```bash
composer require hi-folks/blokctl
```


## Setup

Create a `.env` file in your project root:

```env
SECRET_KEY=your-personal-access-token
```

You can copy the provided example:

```bash
cp .env.example .env
```

## Usage

```bash
php bin/blokctl <command> [options] [arguments]
```

List all available commands:

```bash
php bin/blokctl list
```

### Global options

Most commands accept the following option:

| Option | Short | Description |
|---|---|---|
| `--space-id` | `-S` | The Storyblok Space ID |
| `--region` | `-R` | The Storyblok region (`EU`, `US`, `AP`, `CA`, `CN`). Defaults to `EU` |
| `--no-interaction` | `-n` | Run without interactive prompts (requires all options to be provided) |

If `--space-id` is omitted, the command will prompt for it interactively.

By default, commands connect to the **EU** region. Use `--region` (`-R`) to target a different Storyblok region:

```bash
# US region
php bin/blokctl space:info -S 290817118944379 -R US

# Asia-Pacific region
php bin/blokctl spaces:list -R AP

# Canada region
php bin/blokctl stories:list -S 290817118944379 -R CA
```

Available regions: `EU` (default), `US`, `AP`, `CA`, `CN`.

---

## Commands

### Spaces

#### `spaces:list` — List all spaces

```bash
php bin/blokctl spaces:list
php bin/blokctl spaces:list --search=demo
php bin/blokctl spaces:list --owned-only
php bin/blokctl spaces:list --updated-before=90
php bin/blokctl spaces:list --owned-only --updated-before=90 --solo-only
```

| Option | Description |
|---|---|
| `--search` | Filter spaces by name |
| `--owned-only` | Only show spaces owned by the authenticated user |
| `--updated-before` | Only show spaces last updated more than N days ago (e.g. `90`) |
| `--solo-only` | Only show spaces where the user is the only collaborator (implies `--owned-only`) |

Filters are applied in order: `--owned-only` and `--updated-before` first (no extra API calls), then `--solo-only` (one API call per remaining space to check collaborators). Combining all three minimizes API calls.

Each space displays: name, ID, plan, demo mode flag, created date, and last updated date.

> This command does not require `--space-id`.

#### `space:info` — Display space information

```bash
php bin/blokctl space:info -S 290817118944379
```

Shows space details (ID, name, plan, demo mode status), current user info, and preview URL configuration.

#### `space:delete` — Delete a space

```bash
php bin/blokctl space:delete -S 290817118944379
```

Permanently deletes a Storyblok space. Two safety checks are enforced before deletion:

1. The authenticated user must be the **owner** of the space
2. There must be **no other collaborators** (user is the sole collaborator)

Prompts for confirmation before deleting. Use `-n` to skip the confirmation prompt.

#### `space:demo-remove` — Remove demo mode from a space

```bash
php bin/blokctl space:demo-remove -S 290817118944379
```

Prompts for confirmation before removing demo mode. Use `-n` to skip the confirmation prompt.

### Preview URLs

#### `space:preview-list` — List preview URLs

```bash
php bin/blokctl space:preview-list -S 290817118944379
```

Displays the default preview URL and all configured frontend environments.

#### `space:preview-set` — Set the default preview URL

```bash
# Set default preview URL
php bin/blokctl space:preview-set -S 290817118944379 'https://example.com/?path='

# Set default preview URL with additional environments
php bin/blokctl space:preview-set -S 290817118944379 'https://example.com/?path=' \
  -e 'Local=https://localhost:3000/?path=' \
  -e 'Staging=https://staging.example.com/?path='
```

| Type | Name | Short | Description |
|---|---|---|---|
| Argument | `preview-url` | | **(required)** The default preview URL for the space |
| Option | `--environment` | `-e` | Additional frontend environment as `Name=URL` (repeatable) |

#### `space:preview-add` — Add a preview environment

```bash
php bin/blokctl space:preview-add -S 290817118944379 'Staging' 'https://staging.example.com/?path='
```

| Type | Name | Description |
|---|---|---|
| Argument | `name` | **(required)** Environment name (e.g. `Staging`, `Local Development`) |
| Argument | `url` | **(required)** Environment URL |

### Folders

#### `folder:create` — Create a folder

```bash
# Create a folder at root
php bin/blokctl folder:create -S 290817118944379 'Archive'

# Create a folder inside a parent folder (by slug)
php bin/blokctl folder:create -S 290817118944379 'Old Posts' --parent-slug=articles

# Create a folder inside a parent folder (by ID)
php bin/blokctl folder:create -S 290817118944379 'Old Posts' --parent-id=123456

# Interactive: prompts for folder name
php bin/blokctl folder:create -S 290817118944379
```

| Type | Name | Description |
|---|---|---|
| Argument | `name` | Folder name (prompted interactively if omitted) |

**Parent folder options** (optional, mutually exclusive — defaults to root):

| Option | Description |
|---|---|
| `--parent-slug` | Parent folder slug (e.g. `articles`, `articles/archive`) |
| `--parent-id` | Parent folder numeric ID (default: `0` for root) |

### Stories

#### `story:create` — Create a story with content from JSON

```bash
# Create from a JSON file
php bin/blokctl story:create -S 290817118944379 'My Article' --content-file=content.json

# Create with inline JSON
php bin/blokctl story:create -S 290817118944379 'My Article' \
  --content='{"component": "page", "title": "Hello World", "body": "Some text"}'

# With custom slug
php bin/blokctl story:create -S 290817118944379 'My Article' \
  --slug=my-custom-slug --content='{"component": "page"}'

# Inside a folder (by slug or ID)
php bin/blokctl story:create -S 290817118944379 'My Article' \
  --content='{"component": "article"}' --parent-slug=articles
php bin/blokctl story:create -S 290817118944379 'My Article' \
  --content='{"component": "article"}' --parent-id=123456

# Publish immediately
php bin/blokctl story:create -S 290817118944379 'My Article' \
  --content='{"component": "page"}' --publish

# Interactive: prompts for name and content
php bin/blokctl story:create -S 290817118944379
```

| Type | Name | Description |
|---|---|---|
| Argument | `name` | Story name (prompted interactively if omitted) |

**Content options** (mutually exclusive — prompted interactively if omitted):

| Option | Description |
|---|---|
| `--content-file` | Path to a JSON file with content fields |
| `--content` | Inline JSON string with content fields |

The JSON must include a `"component"` key specifying the content type. All other keys are content fields. The same simplified format used by `story:update` is supported:

```json
{
  "component": "default-page",
  "headline": "About Us",
  "subheadline": "Learn more about our company",
  "cover_image": { "_asset": "https://example.com/hero.jpg" },
  "cta_link": { "_slug": "contact" },
  "body": [
    {
      "component": "hero_section",
      "title": "Welcome",
      "background": { "_asset": "/path/to/local-image.jpg" }
    },
    {
      "component": "banner",
      "text": "Check out our products",
      "link": { "_slug": "products" }
    }
  ]
}
```

Conventions:
- **`{ "_asset": "..." }`** — asset field (URL is downloaded and uploaded to Storyblok; local file is uploaded directly)
- **`{ "_slug": "..." }`** — multilink field pointing to a story (resolved by slug, stores the story UUID)
- **Arrays of objects with `"component"`** — bloks (nested components, `_uid` auto-generated)

**Optional:**

| Option | Description |
|---|---|
| `--slug` | Story slug (auto-generated from name if omitted) |
| `--parent-slug` | Parent folder slug |
| `--parent-id` | Parent folder numeric ID (default: `0` for root) |
| `--publish` | Publish the story immediately after creation |

#### `stories:list` — List stories with filters

```bash
# List all stories
php bin/blokctl stories:list -S 290817118944379

# Filter by content type
php bin/blokctl stories:list -S 290817118944379 --content-type=page

# Filter by slug prefix
php bin/blokctl stories:list -S 290817118944379 --starts-with=articles/

# Search by name
php bin/blokctl stories:list -S 290817118944379 --search=homepage

# Filter by tag
php bin/blokctl stories:list -S 290817118944379 --with-tag=Landing

# Show only published stories with pagination
php bin/blokctl stories:list -S 290817118944379 --published-only --page=2 --per-page=50
```

| Option | Short | Description |
|---|---|---|
| `--content-type` | `-c` | Filter by component name (e.g. `page`, `article`) |
| `--starts-with` | `-s` | Filter by slug prefix (e.g. `articles/`) |
| `--search` | | Search stories by name |
| `--with-tag` | `-t` | Filter by tag (comma-separated for multiple) |
| `--published-only` | | Only show published stories |
| `--page` | `-p` | Page number (default: `1`) |
| `--per-page` | | Results per page (default: `25`, max: `100`) |

#### `story:update` — Update a story's content from simplified JSON

```bash
# Update from a JSON file
php bin/blokctl story:update -S 290817118944379 --by-slug=articles/my-article \
  --content-file=content.json

# Update with inline JSON
php bin/blokctl story:update -S 290817118944379 --by-slug=home \
  --content='{"headline": "Welcome", "featured": true}'

# Update and publish
php bin/blokctl story:update -S 290817118944379 --by-id=123456 \
  --content-file=content.json --publish
```

The content JSON uses a simplified format with these conventions:

- **`{ "_asset": "..." }`** — asset field (URL is downloaded and uploaded to Storyblok; local file is uploaded directly)
- **`{ "_slug": "..." }`** — multilink field pointing to a story (resolved by slug, stores the story UUID)
- **Arrays of objects with `"component"`** — bloks (nested components, `_uid` auto-generated)

Everything else passes through as-is. The resolver walks the tree recursively.

**Example content file:**

```json
{
  "headline": "My Article",
  "cover_image": { "_asset": "https://example.com/photo.jpg" },
  "body": [
    {
      "component": "hero_section",
      "title": "Welcome",
      "background": { "_asset": "/path/to/local-image.jpg" }
    },
    {
      "component": "text_block",
      "content": "Hello world"
    }
  ],
  "cta_link": { "_slug": "contact" },
  "sidebar": [
    {
      "component": "related_articles",
      "items": [
        { "component": "link_card", "title": "Other Post", "link": { "_slug": "articles/other" } }
      ]
    }
  ]
}
```

Only the fields you specify are updated — the existing content type and other fields are preserved. Inside arrays, objects with `"component"` are treated as nested bloks.
```

**Story lookup options** (mutually exclusive):

| Option | Description |
|---|---|
| `--by-slug` | Find story by full slug |
| `--by-id` | Find story by numeric ID |

**Content options** (mutually exclusive):

| Option | Description |
|---|---|
| `--content-file` | Path to a JSON file |
| `--content` | Inline JSON string |

**Optional:**

| Option | Description |
|---|---|
| `--publish` | Publish the story after updating |

Only the fields you specify are updated — existing content fields not in the JSON are preserved.

#### `story:field-set` — Set a content field value on a story

```bash
# Set a text field by story slug (default type: text)
php bin/blokctl story:field-set -S 290817118944379 headline 'My new headline' \
  --by-slug=articles/my-article

# Set a field by story ID
php bin/blokctl story:field-set -S 290817118944379 title 'Updated title' --by-id=123456

# Set a complex value (JSON object, array, number, boolean)
php bin/blokctl story:field-set -S 290817118944379 tags '["news", "featured"]' \
  --by-slug=articles/my-article --type=json

php bin/blokctl story:field-set -S 290817118944379 featured 'true' \
  --by-slug=home --type=json

# Set an image field from a URL
php bin/blokctl story:field-set -S 290817118944379 image 'https://example.com/photo.jpg' \
  --by-slug=articles/my-article --type=asset

# Set an image field from a local file (uploads to Storyblok)
php bin/blokctl story:field-set -S 290817118944379 image '/path/to/photo.jpg' \
  --by-slug=articles/my-article --type=asset

# Interactive: prompts for story slug, field name, and value
php bin/blokctl story:field-set -S 290817118944379
```

| Type | Name | Description |
|---|---|---|
| Argument | `field` | Field name (e.g. `headline`, `body`, `image`). Prompted if omitted |
| Argument | `value` | Field value. Prompted if omitted |

**Story lookup options** (mutually exclusive — prompted interactively if omitted):

| Option | Description |
|---|---|
| `--by-slug` | Find story by full slug (e.g. `articles/my-article`) |
| `--by-id` | Find story by numeric ID |

**Optional:**

| Option | Description |
|---|---|
| `--type` | Value type: `text` (default), `json`, or `asset` |

Value types:
- **`text`** (default) — sets the value as a plain string
- **`json`** — parses the value as JSON (for objects, arrays, numbers, booleans)
- **`asset`** — treats the value as an image: a URL is downloaded and uploaded to Storyblok; a local file path is uploaded directly

#### `story:show` — Display a story as JSON

```bash
# By slug
php bin/blokctl story:show -S 290817118944379 --by-slug=about

# By ID
php bin/blokctl story:show -S 290817118944379 --by-id=123456

# By UUID
php bin/blokctl story:show -S 290817118944379 --by-uuid=abc-def-123

# Output only the story object
php bin/blokctl story:show -S 290817118944379 --by-slug=about --only-story
```

**Lookup options** (mutually exclusive — prompted interactively if omitted):

| Option | Description |
|---|---|
| `--by-slug` | Find story by full slug (e.g. `about`, `articles/my-article`) |
| `--by-id` | Find story by numeric ID |
| `--by-uuid` | Find story by UUID |

**Output options:**

| Option | Description |
|---|---|
| `--only-story` | Output only the `story` property instead of the full API response |

#### `story:move` — Move a story to a different folder

```bash
# Move by slugs
php bin/blokctl story:move -S 290817118944379 --by-slug=authors/john-doe --to-folder-slug=archived/authors

# Move by IDs
php bin/blokctl story:move -S 290817118944379 --by-id=123456 --to-folder-id=789012

# Move to root (no folder)
php bin/blokctl story:move -S 290817118944379 --by-slug=authors/john-doe --to-folder-id=0

# Interactive: prompts for story and folder
php bin/blokctl story:move -S 290817118944379
```

**Story lookup options** (mutually exclusive — prompted interactively if omitted):

| Option | Description |
|---|---|
| `--by-slug` | Find story by full slug (e.g. `authors/john-doe`) |
| `--by-id` | Find story by numeric ID |

**Target folder options** (mutually exclusive — prompted interactively if omitted):

| Option | Description |
|---|---|
| `--to-folder-slug` | Target folder slug (e.g. `archived/authors`) |
| `--to-folder-id` | Target folder numeric ID (use `0` to move to root) |

#### `story:workflow-change` — Change the workflow stage of a story

```bash
# By stage name (uses default workflow)
php bin/blokctl story:workflow-change -S 290817118944379 --by-slug=articles/my-post --stage=Review

# By stage ID
php bin/blokctl story:workflow-change -S 290817118944379 --by-id=123456 --stage-id=67890

# Specify a non-default workflow by name
php bin/blokctl story:workflow-change -S 290817118944379 --by-slug=articles/my-post --stage=Review --workflow-name="Custom Workflow"

# Specify a non-default workflow by ID
php bin/blokctl story:workflow-change -S 290817118944379 --by-slug=articles/my-post --stage=Review --workflow-id=99999

# Interactive: prompts for story and stage
php bin/blokctl story:workflow-change -S 290817118944379
```

**Story lookup options** (mutually exclusive — prompted interactively if omitted):

| Option | Description |
|---|---|
| `--by-slug` | Find story by full slug (e.g. `articles/my-post`) |
| `--by-id` | Find story by numeric ID |

**Stage options** (mutually exclusive — prompted interactively if omitted):

| Option | Description |
|---|---|
| `--stage` | Workflow stage name to assign (case-insensitive match) |
| `--stage-id` | Workflow stage numeric ID to assign |

**Workflow options** (optional, mutually exclusive — uses default workflow if omitted):

| Option | Description |
|---|---|
| `--workflow-name` | Workflow name |
| `--workflow-id` | Workflow numeric ID |

#### `stories:tags-assign` — Assign tags to stories

```bash
# Assign a tag by story slug
php bin/blokctl stories:tags-assign -S 290817118944379 --story-slug=home --tag=Landing

# Assign multiple tags to multiple stories
php bin/blokctl stories:tags-assign -S 290817118944379 \
  --story-slug=home --story-slug=about \
  --tag=Landing --tag=Marketing

# Mix story IDs and slugs
php bin/blokctl stories:tags-assign -S 290817118944379 \
  --story-id=123456 --story-slug=contact \
  --tag=Page
```

| Option | Short | Description |
|---|---|---|
| `--story-id` | | Story ID to tag (repeatable) |
| `--story-slug` | | Story slug to tag (repeatable) |
| `--tag` | `-t` | Tag name to assign (repeatable) |

At least one `--story-id` or `--story-slug` is required. Both can be combined.

#### `stories:workflow-assign` — Assign workflow stages to stories

```bash
# Interactive: prompts for workflow stage selection
php bin/blokctl stories:workflow-assign -S 290817118944379

# Non-interactive: provide the stage ID directly
php bin/blokctl stories:workflow-assign -S 290817118944379 --workflow-stage-id=12345 -n
```

| Option | Description |
|---|---|
| `--workflow-stage-id` | Workflow stage ID to assign (prompted interactively if omitted) |

Finds all stories without a workflow stage and assigns the selected stage to them.

### Workflows

#### `workflows:list` — List workflows and their stages

```bash
php bin/blokctl workflows:list -S 290817118944379
```

Lists all workflows configured in the space, along with their stages and IDs. Useful for looking up stage IDs by name (e.g. before using `story:workflow-change --stage-id=...`).

#### `workflow:stage-show` — Show details of a workflow stage

```bash
# By name (case-insensitive)
php bin/blokctl workflow:stage-show -S 290817118944379 --by-name=Review

# By ID
php bin/blokctl workflow:stage-show -S 290817118944379 --by-id=653554

# Specify a non-default workflow by name
php bin/blokctl workflow:stage-show -S 290817118944379 --by-name=Drafting --workflow-name="Article"

# Specify a non-default workflow by ID
php bin/blokctl workflow:stage-show -S 290817118944379 --by-name=Drafting --workflow-id=12345

# Interactive: prompts for lookup method
php bin/blokctl workflow:stage-show -S 290817118944379
```

**Lookup options** (mutually exclusive — prompted interactively if omitted):

| Option | Description |
|---|---|
| `--by-name` | Find stage by name (case-insensitive). Searches the default workflow unless `--workflow-name` or `--workflow-id` is given |
| `--by-id` | Find stage by numeric ID. Searches across all workflows unless scoped |

**Workflow options** (optional, mutually exclusive — uses default workflow for name lookup if omitted):

| Option | Description |
|---|---|
| `--workflow-name` | Workflow name (case-insensitive match) |
| `--workflow-id` | Workflow numeric ID |

Displays stage details: name, ID, workflow, position, color, publish permissions, user access, and allowed next stages.

### Components

#### `components:list` — List components with filters

```bash
# List all components
php bin/blokctl components:list -S 290817118944379

# Search by name
php bin/blokctl components:list -S 290817118944379 --search=hero

# Only content types (root components)
php bin/blokctl components:list -S 290817118944379 --root-only

# Filter by component group
php bin/blokctl components:list -S 290817118944379 --in-group=<group-uuid>
```

| Option | Description |
|---|---|
| `--search` | Search components by name |
| `--root-only` | Only show root components (content types) |
| `--in-group` | Filter by component group UUID |

#### `components:usage` — Analyze component usage across stories

```bash
# Analyze all stories
php bin/blokctl components:usage -S 290817118944379

# Only stories under a slug prefix
php bin/blokctl components:usage -S 290817118944379 --starts-with=articles/
```

| Option | Short | Description |
|---|---|---|
| `--starts-with` | `-s` | Filter by slug prefix (e.g. `articles/`) |
| `--per-page` | | Results per page for API pagination (default: `25`, max: `100`) |

Fetches all stories via the Content Delivery API, recursively walks each story's content tree, and reports how many stories each component appears in and how many total times it is used. Results are sorted by total occurrences (descending).

#### `component:field-add` — Add a field to a component

```bash
# Add a text field inside a "Content" tab
php bin/blokctl component:field-add -S 290817118944379 \
  --component=default-page \
  --field=subtitle \
  --type=text \
  --tab=Content

# Add a richtext field
php bin/blokctl component:field-add -S 290817118944379 \
  --component=default-page \
  --field=body \
  --type=richtext \
  --tab=Content

# Add a plugin field (--type defaults to "custom")
php bin/blokctl component:field-add -S 290817118944379 \
  --component=default-page \
  --field=SEO \
  --field-type=sb-ai-seo \
  --tab=SEO
```

| Option | Description |
|---|---|
| `--component` | Component name (e.g. `default-page`). Prompted interactively if omitted |
| `--field` | Field name to add (e.g. `subtitle`). Prompted interactively if omitted |
| `--type` | Field type: a core type (`text`, `textarea`, `richtext`, `number`, `boolean`, ...) or `custom` for plugins. Defaults to `custom` |
| `--field-type` | Plugin field_type slug (e.g. `sb-ai-seo`). Required when `--type=custom` |
| `--tab` | Tab display name to place the field in (e.g. `Content`). Prompted interactively if omitted |

Supported core types: `text`, `textarea`, `richtext`, `markdown`, `number`, `datetime`, `boolean`, `option`, `options`, `asset`, `multiasset`, `multilink`, `table`, `bloks`, `section`.

If a tab with the same display name already exists, the field is added to that tab. Returns an error if the field name already exists in the schema.

### Apps

#### `app:provision-list` — List installed apps

```bash
php bin/blokctl app:provision-list -S 290817118944379
```

Displays all apps installed in the space, with their slug, app ID, and sidebar/toolbar status.

#### `app:provision-install` — Install an app

```bash
# Interactive: shows an app selector
php bin/blokctl app:provision-install -S 290817118944379

# Install by app ID
php bin/blokctl app:provision-install -S 290817118944379 12345

# Install by slug
php bin/blokctl app:provision-install -S 290817118944379 --by-slug=my-app
```

| Type | Name | Description |
|---|---|---|
| Argument | `app-id` | The app ID to install (prompted interactively if omitted) |
| Option | `--by-slug` | Find and install the app by its slug |

`app-id` and `--by-slug` are mutually exclusive. If neither is provided, an interactive app selector is shown.

### User

#### `user:me` — Display authenticated user info

```bash
php bin/blokctl user:me

# With a specific region
php bin/blokctl user:me -R US
```

Shows details about the currently authenticated user (ID, name, email, timezone, login strategy, and more).

> This command does not require `--space-id`.

---

## Interactive mode

All commands support interactive mode by default. When a required option is omitted, the command will prompt for it. To disable interactive prompts (e.g. in CI/CD pipelines), use the `--no-interaction` (`-n`) flag — in that case, all required options must be provided.

```bash
# Interactive: prompts for space ID
php bin/blokctl space:info

# Non-interactive: all options provided
php bin/blokctl space:info -S 290817118944379 -n
```

## Using Actions from code

Every CLI command is backed by a reusable **Action** class that contains the business logic with no CLI dependencies. You can use Actions directly from controllers, queue jobs, scripts, or tests.

### Setup

```php
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\Data\Enum\Region;

$client = new ManagementApiClient('your-personal-access-token', shouldRetry: true);

// For non-EU regions:
$client = new ManagementApiClient('your-personal-access-token', region: Region::US, shouldRetry: true);
```

### Spaces

#### Get space info

```php
use Blokctl\Action\Space\SpaceInfoAction;

$result = (new SpaceInfoAction($client))->execute($spaceId);

$result->space;    // Space object (name, id, plan, domain, environments, ...)
$result->user;     // User object (current authenticated user)
$result->isOwner;  // bool
```

#### List spaces with filters

```php
use Blokctl\Action\Space\SpacesListAction;

$result = (new SpacesListAction($client))->execute(
    search: 'demo',
    ownedOnly: true,
    updatedBeforeDays: 90,
    soloOnly: true,
);

$result->spaces;  // Space[] — filtered results
$result->errors;  // string[] — non-fatal errors (e.g. collaborator check failures)
$result->count();  // int
```

#### Delete a space (with safety checks)

```php
use Blokctl\Action\Space\SpaceDeleteAction;

$action = new SpaceDeleteAction($client);

// Step 1: preflight — fetch data and evaluate safety checks
$result = $action->preflight($spaceId);

$result->space;         // Space object
$result->user;          // User object
$result->collaborators; // Collaborators collection
$result->isOwner;       // bool
$result->isSolo;        // bool
$result->canDelete();   // bool (isOwner && isSolo)

// Step 2: execute — only if safe
if ($result->canDelete()) {
    $action->execute($spaceId, $result);
}
```

#### Remove demo mode

```php
use Blokctl\Action\Space\SpaceDemoRemoveAction;

$action = new SpaceDemoRemoveAction($client);
$result = $action->preflight($spaceId);

if ($result->isDemo) {
    $action->execute($spaceId, $result);
}
```

### Preview URLs

#### List preview URLs

```php
use Blokctl\Action\SpacePreview\SpacePreviewListAction;

$result = (new SpacePreviewListAction($client))->execute($spaceId);

$result->defaultDomain;     // string
$result->environments;      // SpaceEnvironments collection
$result->hasEnvironments(); // bool
```

#### Set the default preview URL

```php
use Blokctl\Action\SpacePreview\SpacePreviewSetAction;
use Storyblok\ManagementApi\Data\SpaceEnvironment;

$action = new SpacePreviewSetAction($client);
$result = $action->preflight($spaceId);

$action->execute($spaceId, $result, 'https://example.com/?path=', [
    new SpaceEnvironment('Staging', 'https://staging.example.com/?path='),
    new SpaceEnvironment('Local', 'https://localhost:3000/?path='),
]);
```

#### Add a preview environment

```php
use Blokctl\Action\SpacePreview\SpacePreviewAddAction;

$action = new SpacePreviewAddAction($client);
$result = $action->preflight($spaceId);

$action->execute($spaceId, $result, 'Staging', 'https://staging.example.com/?path=');
```

### Folders

#### Create a folder

```php
use Blokctl\Action\Folder\FolderCreateAction;

$action = new FolderCreateAction($client);

// Create at root
$result = $action->execute($spaceId, 'Archive');

// Create inside a parent folder (by ID)
$result = $action->execute($spaceId, 'Old Posts', parentId: 123456);

// Resolve parent folder by slug, then create
$parentId = $action->resolveParentBySlug($spaceId, 'articles');
$result = $action->execute($spaceId, 'Old Posts', parentId: $parentId);

$result->folder;    // Story object (the created folder)
$result->parentId;  // int (0 for root)
```

### Stories

#### Create a story with content

```php
use Blokctl\Action\Story\StoryCreateAction;

$action = new StoryCreateAction($client);

// Create from a content array (must include "component")
$result = $action->execute($spaceId, 'My Article', [
    'component' => 'article',
    'title' => 'Hello World',
    'body' => 'Some text',
]);

// With custom slug, in a folder, and published
$result = $action->execute($spaceId, 'My Article', [
    'component' => 'page',
], slug: 'custom-slug', parentId: 123456, publish: true);

// Parse content from a JSON file
$content = $action->parseJsonFile('/path/to/content.json');
$result = $action->execute($spaceId, 'My Article', $content);

// Parse inline JSON
$content = $action->parseJson('{"component": "page", "title": "Hello"}');

// Resolve parent folder by slug
$parentId = $action->resolveParentBySlug($spaceId, 'articles');

$result->story; // Story object (the created story)
```

#### List stories with filters

```php
use Blokctl\Action\Story\StoriesListAction;

$result = (new StoriesListAction($client))->execute(
    spaceId: $spaceId,
    contentType: 'page',
    startsWith: 'articles/',
    search: 'homepage',
    withTag: 'Landing',
    publishedOnly: true,
    page: 1,
    perPage: 25,
);

$result->stories; // Stories collection
$result->count(); // int
```

#### Update a story's content from simplified JSON

```php
use Blokctl\Action\Story\StoryUpdateAction;

$action = new StoryUpdateAction($client);

// Update with plain fields
$result = $action->execute($spaceId, [
    'headline' => 'Updated headline',
    'featured' => true,
], storySlug: 'articles/my-article');

// With assets, bloks, and publish
$result = $action->execute($spaceId, [
    'cover_image' => ['_asset' => 'https://example.com/photo.jpg'],
    'body' => [
        ['component' => 'hero_section', 'title' => 'Welcome'],
        ['component' => 'text_block', 'content' => 'Hello'],
    ],
], storySlug: 'home', publish: true);

// Parse from file
$content = $action->parseJsonFile('/path/to/content.json');
$result = $action->execute($spaceId, $content, storyId: '123456');

$result->story;          // Story object (updated)
$result->appliedContent; // array (resolved content that was applied)
```

The `ContentResolver` class can also be used standalone:

```php
use Blokctl\Action\Story\ContentResolver;

$resolver = new ContentResolver($client, $spaceId);
$resolved = $resolver->resolve($simplifiedContent);
// $resolved now has _asset markers converted to asset fields,
// bloks with _uid generated, all nested recursively
```

#### Set a content field value on a story

```php
use Blokctl\Action\Story\StoryFieldSetAction;

$action = new StoryFieldSetAction($client);

// By slug
$result = $action->execute($spaceId, 'headline', 'My new headline', storySlug: 'articles/my-article');

// By ID
$result = $action->execute($spaceId, 'title', 'Updated title', storyId: '123456');

// Complex values (arrays, booleans, etc.)
$result = $action->execute($spaceId, 'tags', ['news', 'featured'], storySlug: 'home');

// Asset field from URL
$result = $action->execute($spaceId, 'image', 'https://example.com/photo.jpg',
    storySlug: 'articles/my-article', isAsset: true);

// Asset field from local file (uploads to Storyblok, then uses setAsset())
$result = $action->execute($spaceId, 'image', '/path/to/photo.jpg',
    storySlug: 'articles/my-article', isAsset: true);

// Upload an asset separately (for custom logic)
$asset = $action->uploadAsset($spaceId, '/path/to/photo.jpg'); // returns Asset object

$result->story;         // Story object (updated)
$result->fieldName;     // string
$result->newValue;      // mixed (for assets: the Storyblok CDN URL)
$result->previousValue; // mixed (null if field didn't exist before)
```

#### Show a story

```php
use Blokctl\Action\Story\StoryShowAction;

// By slug
$result = (new StoryShowAction($client))->execute($spaceId, slug: 'about');

// By ID
$result = (new StoryShowAction($client))->execute($spaceId, id: '123456');

// By UUID
$result = (new StoryShowAction($client))->execute($spaceId, uuid: 'abc-def-123');

$result->story;        // Story object
$result->fullResponse; // array (full API response)
```

#### Move a story to a different folder

```php
use Blokctl\Action\Story\StoryMoveAction;

$action = new StoryMoveAction($client);

// Resolve folder by slug
$folderId = $action->resolveFolderBySlug($spaceId, 'archived/authors');

// Move by story slug
$result = $action->execute($spaceId, folderId: $folderId, storySlug: 'authors/john-doe');

// Move by story ID
$result = $action->execute($spaceId, folderId: $folderId, storyId: '123456');

// Move to root (no folder)
$result = $action->execute($spaceId, folderId: 0, storySlug: 'authors/john-doe');

$result->story;              // Story object (updated)
$result->previousFolderId;   // int
$result->newFolderId;        // int
$result->previousFullSlug;   // string (full slug before the move)
```

#### Change the workflow stage of a story

```php
use Blokctl\Action\Story\StoryWorkflowChangeAction;

$action = new StoryWorkflowChangeAction($client);

// Resolve stage by name (uses default workflow)
$resolved = $action->resolveWorkflowStage($spaceId, stageName: 'Review');
$stageId = $resolved['stageId'];
$stageName = $resolved['stageName'];

// Resolve stage from a specific workflow by name
$resolved = $action->resolveWorkflowStage($spaceId, stageName: 'Review', workflowName: 'Custom Workflow');

// List available stages for interactive selection (no stage name)
$resolved = $action->resolveWorkflowStage($spaceId);
$resolved['workflowStages']; // array [id => name]

// Change workflow stage by story slug
$result = $action->execute($spaceId, $stageId, $stageName, storySlug: 'articles/my-post');

// Change workflow stage by story ID
$result = $action->execute($spaceId, $stageId, $stageName, storyId: '123456');

$result->story;                   // Story object
$result->workflowStageName;      // string
$result->workflowStageId;        // int
$result->previousWorkflowStageId; // int|null
```

#### Assign tags to stories

```php
use Blokctl\Action\Story\StoriesTagsAssignAction;

$result = (new StoriesTagsAssignAction($client))->execute(
    spaceId: $spaceId,
    storyIds: ['123456'],
    storySlugs: ['home', 'about'],
    tags: ['Landing', 'Marketing'],
);

$result->tagged; // array of ['name' => ..., 'tags' => ...]
$result->errors; // string[] — non-fatal errors
```

#### Assign workflow stages

```php
use Blokctl\Action\Story\StoriesWorkflowAssignAction;

$action = new StoriesWorkflowAssignAction($client);
$result = $action->preflight($spaceId);

$result->stories;            // Stories collection
$result->countWithoutStage;  // int
$result->workflowStages;     // array [id => name] for selection
$result->defaultStageId;     // string|null

if ($result->countWithoutStage > 0) {
    $executeResult = $action->execute($spaceId, $result, $stageId);
    $executeResult['assigned']; // array of ['name' => ..., 'stageId' => ...]
    $executeResult['errors'];   // string[]
}
```

### Workflows

#### List workflows and stages

```php
use Blokctl\Action\Workflow\WorkflowsListAction;

$result = (new WorkflowsListAction($client))->execute($spaceId);

$result->workflows; // array of ['id' => int, 'name' => string, 'isDefault' => bool, 'stages' => [...]]
$result->count();   // int

foreach ($result->workflows as $workflow) {
    foreach ($workflow['stages'] as $stage) {
        // $stage['id'], $stage['name'], $stage['position']
    }
}
```

#### Show a workflow stage

```php
use Blokctl\Action\Workflow\WorkflowStageShowAction;

$action = new WorkflowStageShowAction($client);

// By ID
$result = $action->execute($spaceId, stageId: 653554);

// By name (case-insensitive, searches default workflow)
$result = $action->execute($spaceId, stageName: 'Review');

// By name in a specific workflow (by name or ID)
$result = $action->execute($spaceId, stageName: 'Drafting', workflowName: 'Article');
$result = $action->execute($spaceId, stageName: 'Drafting', workflowId: '12345');

$result->stage;        // array (full stage data: id, name, color, position, permissions, ...)
$result->workflowName; // string
$result->workflowId;   // int
```

### Components

#### List components

```php
use Blokctl\Action\Component\ComponentsListAction;

$result = (new ComponentsListAction($client))->execute(
    spaceId: $spaceId,
    search: 'hero',
    rootOnly: true,
    inGroup: 'group-uuid',
);

$result->components; // Components collection
$result->count();    // int
```

#### Analyze component usage

```php
use Blokctl\Action\Component\ComponentsUsageAction;

$result = (new ComponentsUsageAction($client))->execute(
    spaceId: $spaceId,
    region: 'EU',
    startsWith: 'articles/',
    perPage: 25,
);

$result->usage;           // array<string, array{stories: int, total: int}>
$result->storiesAnalyzed; // int
$result->count();         // int (number of distinct components found)
```

#### Add a field to a component

```php
use Blokctl\Action\Component\ComponentFieldAddAction;

$action = new ComponentFieldAddAction($client);

// Preflight validates component exists and field name is available
$result = $action->preflight($spaceId, 'default-page', 'subtitle');

// Add a core field type
$action->execute($spaceId, $result, 'subtitle', 'text', 'Content');

// Add a plugin field
$action->execute($spaceId, $result, 'SEO', 'custom', 'SEO', fieldType: 'sb-ai-seo');
```

### Apps

#### List installed apps

```php
use Blokctl\Action\AppProvision\AppProvisionListAction;

$result = (new AppProvisionListAction($client))->execute($spaceId);

$result->provisions; // AppProvisions collection
$result->count();    // int
```

#### Install an app

```php
use Blokctl\Action\AppProvision\AppProvisionInstallAction;

$action = new AppProvisionInstallAction($client);

// Install by ID
$provision = $action->execute($spaceId, '12345');

// Install by slug (resolves ID first)
$appId = $action->resolveBySlug($spaceId, 'my-app');
$provision = $action->execute($spaceId, $appId);

$provision->name();  // string
$provision->appId(); // string
$provision->slug();  // string

// Get available apps for a custom selector
$result = $action->preflight($spaceId);
$result->appOptions; // array [id => "name (slug)"]
```

### User

#### Get current user info

```php
use Blokctl\Action\User\UserMeAction;

$result = (new UserMeAction($client))->execute();

$result->user; // User object (id, email, name, timezone, org, partner, ...)
```

### Action design pattern

Each Action follows these conventions:

- **Constructor** receives only the `ManagementApiClient`
- **Methods** receive plain scalars (space ID, field name, etc.) — no framework objects
- **Read-only Actions** have a single `execute()` method returning a typed Result DTO
- **Mutating Actions** use `preflight()` to fetch data and evaluate preconditions, then `execute()` to perform the change
- **Result DTOs** are `final readonly` classes with public properties and optional convenience methods (e.g. `canDelete()`, `hasEnvironments()`)
- **Errors** are either thrown as `\RuntimeException` (for fatal issues) or collected in an `errors` array on the result (for non-fatal batch operations)

## License

MIT
