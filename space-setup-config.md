# Space Setup Configuration

`space:setup` applies repeatable setup steps to a Storyblok space from a JSON or YAML file.

It can run against an existing space when the config does not define a `space` section:

```bash
php bin/blokctl space:setup -S 290817118944379 --config existing-space.yaml
```

Or it can use the configuration to duplicate a template space first, then apply the setup to the newly created space:

```bash
php bin/blokctl space:setup --config examples/demo-space.yaml
```

It can also create and configure a blank space explicitly:

```bash
php bin/blokctl space:setup --config examples/blank-space.yaml
```

Use `--dry-run` to inspect the plan without changing Storyblok:

```bash
php bin/blokctl space:setup -S 290817118944379 --config existing-space.yaml --dry-run
```

For duplicate-first provisioning, dry-run also displays the complete post-duplication plan without creating a space:

```bash
php bin/blokctl space:setup --config examples/demo-space.yaml --dry-run
```

During a create or duplicate dry-run, `${{ space.id }}` resolves to `NEW_SPACE_ID` and `${{ space.preview_token }}` resolves to `PREVIEW_TOKEN`.

Dry-run renders the complete desired setup without inspecting current target state. During real execution, reconcile mode may report planned operations as `SKIPPED` when the space already matches.

Dry-run and real execution share the same compact operation report:

```text
Operations
  PLANNED    Configure preview URLs
  PLANNED    Install app: backups
  PLANNED    Add component field: article-page.SEO

Summary
  Planned: 3
```

Real execution replaces `PLANNED` with outcomes such as `UPDATED`, `INSTALLED`, `CREATED`, `REMOVED`, `SKIPPED`, or `FAILED`. Preview tokens are masked whenever URLs are rendered.

Setup exits with a non-zero status when any operation fails. Enabling `continue_on_error` allows later operations to run, but does not hide the failed setup outcome from scripts or CI jobs.

Write a machine-readable JSON report for automation and subsequent workflow steps:

```bash
php bin/blokctl space:setup --config examples/demo-space.yaml --report setup-result.json
```

The report contains:

- Stable report `schema_version`.
- Final `completed`, `failed`, or `planned` status.
- Target space ID and setup mode.
- Duplication source and readiness details when applicable.
- Every provisioning operation with its status and masked detail.
- Summary counts for every operation status.

Reports are written for successful setups, dry runs, continued failures, and failures that stop provisioning early. Preview tokens in operation details are masked.

When duplicate-first provisioning fails after creating the new space, blokctl preserves that space for inspection or recovery. It does not automatically delete or roll back the duplicated space. The JSON report includes the new space ID, duplication details, and partial operation results.

Validate a config without accessing Storyblok:

```bash
php bin/blokctl space:setup-validate --config examples/demo-space.yaml
```

## File Format

The config file can be YAML or JSON. YAML is recommended for templates because it is easier to read and edit.

The general example is [examples/demo-space.yaml](examples/demo-space.yaml). See [examples/assets-space.yaml](examples/assets-space.yaml) for local-directory asset upload.

Future improvements and missing provisioning capabilities are tracked in [space-setup-todo.md](space-setup-todo.md).

Both YAML and JSON files are validated against [space-setup-schema.json](space-setup-schema.json) before `space:setup` creates, duplicates, or modifies a space. Unknown properties, invalid types, and missing required properties cause setup to stop.

YAML files can enable editor autocomplete and inline validation with:

```yaml
# yaml-language-server: $schema=../space-setup-schema.json
```

## Variables

The complete setup configuration supports namespaced expressions:

| Context | Description |
|---|---|
| `${{ inputs.NAME }}` | Declared setup input default or `--set` override. |
| `${{ env.NAME }}` | Environment variable named `NAME`. |
| `${{ space.id }}` | Target space ID. For create or duplicate modes, this is the newly created space ID. |
| `${{ space.preview_token }}` | First preview access token of the target space. |

Example:

```yaml
inputs:
  frontend_host:
    required: true

preview:
  default: "https://${{ inputs.frontend_host }}/?token=${{ space.preview_token }}&space=${{ space.id }}&path="
```

Override declared inputs with repeatable `--set` options:

```bash
php bin/blokctl space:setup -S 290817118944379 \
  --config examples/demo-space.yaml \
  --set frontend_host=customer-demo.example.com
```

Values passed through `--set` are parsed as JSON when possible. For example, `--set enabled=true` resolves to a boolean and `--set count=5` resolves to an integer.

Input resolution priority is:

1. A matching `--set NAME=VALUE` CLI override.
2. The input's `default` value declared in the YAML or JSON config.
3. A validation error when the input is marked `required` and no value is available.

The `env` and `space` contexts are not setup inputs:

- `${{ env.NAME }}` is read automatically from the process environment.
- `${{ space.id }}` and `${{ space.preview_token }}` are resolved automatically from the target space.

When an entire value is one expression, its native type is preserved:

```yaml
enabled: "${{ inputs.enable_feature }}"
```

When an expression is embedded in other text, it must resolve to a scalar value and the result is a string. Missing variables stop setup with the exact configuration path.

## `space`

Defines optional target-creation settings. Set `create_new: true` with `name` to create a blank space, or configure `duplicate_from` with `name` to duplicate a template. When targeting an existing space with `-S`, omit both creation settings.

```yaml
space:
  name: "${{ inputs.customer_name }} Demo"
  duplicate_from: "286863409930127"
  in_org: true
  demo: false
  readiness:
    timeout_seconds: 120
    poll_interval_seconds: 2
```

| Key | Required | Description |
|---|---:|---|
| `name` | Conditional | Name of the new space. Required when `create_new: true` or `duplicate_from` is configured. |
| `create_new` | No | Explicitly create a blank target space. Defaults to `false`. |
| `duplicate_from` | No | Source template space ID. Omit when targeting an existing space with `-S`. |
| `in_org` | No | Create the duplicated space inside the current organization. Defaults to `false`. |
| `demo` | No | Mark the created or duplicated space as a demo/example space. Defaults to `false`. |
| `readiness` | No | Readiness polling settings used after duplication. |

`space.demo: true` cannot be combined with `demo_mode.remove: true`.

`space.create_new: true`, `space.duplicate_from`, and `--space-id (-S)` are mutually exclusive target modes. Creating a blank space is always explicit; a `space.name` alone never creates a space.

After duplication, `space:setup` polls the new space until Storyblok reports `has_pending_tasks: false`. This prevents provisioning steps from running while duplication background tasks are still active. Blank-space creation, existing-space setup, and dry runs do not poll.

| Readiness key | Required | Description |
|---|---:|---|
| `timeout_seconds` | No | Maximum time to wait for pending tasks. Defaults to `120`. |
| `poll_interval_seconds` | No | Delay between readiness checks. Defaults to `2`. |

Readiness polling is separate from HTTP retry handling, which remains managed by the PHP Management Client.

## Top-Level Keys

Except for `version`, all top-level sections are optional. If a section is omitted, that setup step is skipped.

| Key | Description |
|---|---|
| `version` | Required configuration schema version. Currently `1`. |
| `inputs` | Reusable runtime input definitions with defaults and required values. |
| `execution` | Execution behavior. Reconcile mode is the default. |
| `space` | Optional target-creation settings. Omit `create_new` and `duplicate_from` when using `-S`. |
| `continue_on_error` | Global boolean. Continue after a failed step. |
| `preview` | Set the default preview URL and frontend environments. |
| `demo_mode` | Remove demo/example mode. |
| `workflow` | Assign a workflow stage to stories without one. |
| `folders` | Ensure folders exist by portable full slug. |
| `stories` | Move selected root-level stories and folders. |
| `apps` | Install Storyblok apps by slug or ID. |
| `ai` | Configure Storyblok AI availability and organization configuration inheritance. |
| `ai_translation` | Configure the disclaimer required by AI Translations. |
| `dimensions` | Reconcile Dimensions app folders. |
| `assets` | Upload local asset directories and convert space assets into the Global Asset Library. |
| `components` | Add fields to existing components. |
| `tags` | Assign tags to stories by ID or slug. |

## Full YAML Example

```yaml
version: 1

execution:
  mode: reconcile
  continue_on_error: false

inputs:
  frontend_host:
    description: "Frontend host used by the default preview URL"
    default: "storyblok-demo-default-se.netlify.app"

space:
  name: "Storyblok Customer Demo"
  duplicate_from: "286863409930127"
  in_org: true
  demo: false
  readiness:
    timeout_seconds: 120
    poll_interval_seconds: 2

preview:
  enabled: true
  default: "https://${{ inputs.frontend_host }}/?token=${{ space.preview_token }}&path="
  environments:
    - name: "Local Development"
      url: "https://localhost:3000/?token=${{ space.preview_token }}&path="

demo_mode:
  remove: true

workflow:
  assign_unstaged: true
  stage_id: null

apps:
  continue_on_error: true
  install:
    - releases_only
    - storyblok-gmbh@ai-seo
    - replace_asset
    - export
    - import
    - backups

ai:
  enabled: true
  inherit_org_configuration: false

ai_translation:
  disclaimer_id: 173657768407244

folders:
  ensure:
    - name: Global
      slug: global
    - name: Italy
      slug: italy

stories:
  move:
    - select:
        parent: root
        include_folders: true
        exclude_slugs: [error-404, site-config, global, italy]
      to_folder: global

dimensions:
  folders:
    - slug: global
    - slug: italy
      ai_translation_code: it

assets:
  upload_directory:
    - source: ./demo-assets/brand
      target_folder: Brand
      recursive: true
      include: ["*.svg", "*.png", "*.jpg"]
      on_existing: skip

components:
  fields:
    - component: article-page
      field: SEO
      type: custom
      field_type: sb-ai-seo
      tab: SEO

tags:
  - stories:
      slugs:
        - error-404
        - site-config
    tags:
      - Configuration

  - stories:
      slugs:
        - home
        - contact
    tags:
      - Landing
      - Marketing
```

## `execution`

Controls how setup operations are applied. The only supported mode is `reconcile`, and it is used by default when `execution` is omitted.

```yaml
execution:
  mode: reconcile
  continue_on_error: false
```

Reconcile mode:

- Preserves resources and values not managed by the configuration.
- Adds missing configured resources.
- Updates only explicitly configured values that differ.
- Skips resources that already match.
- Never removes resources merely because they are absent from the configuration.
- Merges story tags and preview environments instead of replacing unmanaged values.

| Key | Required | Description |
|---|---:|---|
| `mode` | No | Must be `reconcile`. Defaults to `reconcile`. |
| `continue_on_error` | No | Continue after failed operations while still returning a non-zero exit code. |

## `inputs`

Declares runtime values that can have defaults or be supplied through `--set`.

```yaml
inputs:
  customer_name:
    description: "Customer name shown in demo content"
    required: true

  frontend_host:
    default: "demo.example.com"

  enable_feature:
    default: false
```

| Key | Required | Description |
|---|---:|---|
| `description` | No | Human-readable input description. |
| `required` | No | Require a default or `--set` override. Defaults to `false`. |
| `default` | No | Default input value. Supports strings, numbers, booleans, arrays, and objects. |
| `secret` | No | Marks a value as sensitive for future masked output support. |

## `preview`

Sets the default preview URL and optional frontend environments.

```yaml
preview:
  enabled: true
  default: "https://example.com/?token=${{ space.preview_token }}&path="
  environments:
    - name: "Local Development"
      url: "https://localhost:3000/?token=${{ space.preview_token }}&path="
```

| Key | Required | Description |
|---|---:|---|
| `enabled` | No | Boolean. Defaults to `true` when the section exists. |
| `default` | Yes | Default preview URL. |
| `environments` | No | List of extra frontend environments. |
| `environments[].name` | Yes | Environment display name. |
| `environments[].url` | Yes | Environment preview URL. |

Configured preview environments are reconciled by name. Matching environments are updated, missing environments are added, and unmanaged environments are preserved.

## `demo_mode`

Removes demo/example mode from the target space.

```yaml
demo_mode:
  remove: true
```

| Key | Required | Description |
|---|---:|---|
| `remove` | Yes | Boolean. When `true`, remove demo mode if the space is currently marked as demo. |

## `workflow`

Assigns a workflow stage to stories that do not have one.

```yaml
workflow:
  assign_unstaged: true
  stage_id: null
  assign:
    - stories:
        slugs: [home, about]
      workflow: Default
      stage: Drafting
```

| Key | Required | Description |
|---|---:|---|
| `assign_unstaged` | No | Boolean. When `true`, assign a workflow stage to unstaged stories. |
| `stage_id` | No | Workflow stage ID. If `null`, blokctl tries to use the default workflow's first stage. |
| `assign` | No | Specific story-to-stage assignments. |
| `assign[].stories.slugs` | No | Story full slugs to assign. |
| `assign[].stories.ids` | No | Story IDs to assign. |
| `assign[].workflow` | No | Workflow name used to resolve `stage`. Mutually exclusive with `workflow_id`. |
| `assign[].workflow_id` | No | Workflow ID used to resolve `stage` or `stage_id`. Mutually exclusive with `workflow`. |
| `assign[].stage` | No | Workflow stage name to assign. Use exactly one of `stage` or `stage_id`. |
| `assign[].stage_id` | No | Workflow stage ID to assign. Use exactly one of `stage` or `stage_id`. |

## `apps`

Installs apps by app slug or a structured reference. A structured reference can include an app ID fallback when a slug cannot be resolved consistently.

```yaml
apps:
  continue_on_error: true
  install:
    - releases_only
    - storyblok-gmbh@ai-seo
    - slug: dimensions
      id: 24
    - id: 29942
```

| Key | Required | Description |
|---|---:|---|
| `continue_on_error` | No | Boolean. Useful because some apps may not be available for every space. |
| `install` | Yes | List of app slugs or structured references containing `slug`, `id`, or both. |

Apps that are already installed by matching slug or ID are reported as `SKIPPED`.

For example, `id: 29942` installs the AI Translations app without requiring its slug.

## `ai`

Configures Storyblok AI for the target space. Installing the AI Translations app, accepting its disclaimer, and enabling Storyblok AI are separate operations.

```yaml
ai:
  enabled: true
  inherit_org_configuration: false
```

| Key | Required | Description |
|---|---:|---|
| `enabled` | No | Enable or disable Storyblok AI text generation for the space. |
| `inherit_org_configuration` | No | Inherit the organization's AI configuration for the space. |

Only explicitly declared settings are updated. `enabled: true` sends `ai_text_generator_disabled: false`, while `inherit_org_configuration: false` sends `inherit_org_ai_configuration: false`.

## `ai_translation`

Configures the disclaimer required by the AI Translations app.

```yaml
ai_translation:
  disclaimer_id: 173657768407244
```

| Key | Required | Description |
|---|---:|---|
| `disclaimer_id` | Yes | Storyblok disclaimer ID required to activate AI Translation capabilities for the space. |

`ai_translation.disclaimer_id` sends a partial space update with only `disclaimer_id`.

## `folders`

Ensures configured folders exist. Folders are resolved by portable full slug; missing folders are created and existing folders are preserved.

```yaml
folders:
  ensure:
    - name: Global
      slug: global
    - name: Archive
      slug: global/archive
      parent_slug: global
```

| Key | Required | Description |
|---|---:|---|
| `ensure` | Yes | Folders to create or reuse. Entries run in declaration order. |
| `ensure[].name` | Yes | Folder display name used when creating it. |
| `ensure[].slug` | Yes | Expected full folder slug used for reconciliation. |
| `ensure[].parent_slug` | No | Existing or previously ensured parent folder full slug. Omit for root folders. |

## `stories.move`

Moves matching root-level content into a target folder. Both stories and folders can be selected. Repeated reconcile runs move only newly added matching root content.

```yaml
stories:
  move:
    - select:
        parent: root
        include_folders: true
        exclude_slugs: [error-404, site-config, global]
      to_folder: global
```

| Key | Required | Description |
|---|---:|---|
| `select.parent` | Yes | Currently must be `root`. |
| `select.include_folders` | No | Include root-level folders in addition to stories. Defaults to `false`. |
| `select.include_slugs` | No | Move only items with one of these slugs. |
| `select.exclude_slugs` | No | Preserve items with one of these slugs at root. |
| `to_folder` | Yes | Target folder full slug. |

## `stories.update`

Updates fields on existing components inside a story. Each component update uses a deterministic `path` and an expected `component` value, so setup fails instead of updating the wrong block when the template structure changes.

```yaml
stories:
  update:
    - slug: home
      components:
        - path: content.body[0]
          component: hero-section
          fields:
            eyebrow: "Welcome to"
            image:
              asset:
                _find:
                  search: "customer-hero.png"
                  in_folder: Brand
                  tags: [customer-demo]
                  require_unique: true
                alt: "Hero image for ${{ inputs.customer_name }}"

        - path: content.body[0].headline[0]
          component: headline-segment
          fields:
            text: "${{ inputs.customer_name }} Demo Space!"
```

| Key | Required | Description |
|---|---:|---|
| `update[].slug` | No | Story full slug. Use exactly one of `slug` or `id`. |
| `update[].id` | No | Story ID. Use exactly one of `slug` or `id`. |
| `update[].components` | Yes | Component updates to apply. |
| `components[].path` | Yes | Path to the component object, for example `content.body[0]` or `content.body[0].headline[0]`. |
| `components[].component` | Yes | Expected component technical name at the path. |
| `components[].fields` | Yes | Fields to set on that component. |

Only declared fields are changed. Object values are merged recursively with existing object fields, which allows partial asset updates such as changing `image.alt` while preserving `image.id`, `image.filename`, and `image.fieldtype`.

Asset fields can be resolved from existing space assets with `asset._find`. The resolver uses the Management API asset list filters, converts the matching asset into a Storyblok asset field, removes `_find`, and applies the remaining asset keys such as `alt`, `title`, `source`, `copyright`, or `focus`. By default `require_unique` is `true`, so setup fails when no asset or multiple assets match. `in_folder` accepts an asset folder ID or a configured folder path such as `Brand` or `Brand/products`.

## `stories.create`

Creates stories from inline content or from a JSON content file. Repeated setup runs skip creation when a story with the target slug already exists.

```yaml
stories:
  create:
    - name: Landing Page
      slug: landing
      parent_slug: campaigns
      content:
        component: default-page
        body: []

    - name: Legal Notice
      slug: legal-notice
      content_file: ./stories/legal-notice.json
```

| Key | Required | Description |
|---|---:|---|
| `create[].name` | Yes | Story name. |
| `create[].slug` | No | Story slug. Defaults to a slug generated from `name`. |
| `create[].parent_id` | No | Parent folder ID. Mutually exclusive with `parent_slug`. |
| `create[].parent_slug` | No | Parent folder full slug. Mutually exclusive with `parent_id`. |
| `create[].content` | No | Inline content object. Use exactly one of `content` or `content_file`. |
| `create[].content_file` | No | JSON content file, relative to the setup config file unless absolute. |
| `create[].publish` | No | Publish immediately after creation. Defaults to `false`. |

The content object must include `component`.

## `dimensions`

Reconciles folders configured for the Dimensions app. Unmanaged Dimensions folders are preserved. Configured folders are added when missing, and declared AI translation codes are updated.

```yaml
dimensions:
  enabled: true
  folders:
    - slug: global
    - slug: italy
      ai_translation_code: it
```

| Key | Required | Description |
|---|---:|---|
| `enabled` | No | Enable Dimensions configuration. Defaults to `true`. |
| `folders` | Yes | Folders to configure, resolved by full slug. |
| `folders[].slug` | Yes | Folder full slug. |
| `folders[].ai_translation_code` | No | AI translation language code. Existing values change only when declared. |

Install the Dimensions app separately through `apps.install`. See [examples/multi-country-space.yaml](examples/multi-country-space.yaml) for a complete setup replacing the behavior of `examples/multi-country-demo-setup.php`.

## `assets`

Uploads files from local directories into Storyblok asset folders. Relative `source` paths are resolved from the directory containing the YAML or JSON setup file, which keeps templates portable.

```yaml
assets:
  upload_directory:
    - source: ./demo-assets/brand
      target_folder: Brand
      recursive: true
      include:
        - "*.svg"
        - "*.png"
        - "*.jpg"
      on_existing: skip

  convert_to_global:
    - asset_ids: [123, 456]
      target_shared_folder_id: 987

    - source_folder_name: Brand
      target_shared_folder_id: 987
      filters:
        filetype: image
        extensions: [jpg, png, webp]
        tags: [approved]
```

| Key | Required | Description |
|---|---:|---|
| `upload_directory` | No | Local directories to scan and upload. |
| `upload_directory[].source` | Yes | Local directory path, relative to the setup config file unless absolute. |
| `upload_directory[].target_folder` | Yes | Storyblok asset folder path to create or reuse. |
| `upload_directory[].recursive` | No | Scan nested local directories and preserve them as nested asset folders. Defaults to `false`. |
| `upload_directory[].include` | No | Glob patterns matched against each relative path or filename. All files are included when omitted. |
| `upload_directory[].on_existing` | No | Existing-asset behavior. Currently must be `skip`, which is also the default. |
| `convert_to_global` | No | Assets to convert from the space asset library into the organization Global Asset Library. |
| `convert_to_global[].asset_id` | No | Single space asset ID to convert. |
| `convert_to_global[].asset_ids` | No | List of space asset IDs to convert. |
| `convert_to_global[].source_folder_id` | No | Convert assets from this source space asset folder ID. |
| `convert_to_global[].source_folder_name` | No | Convert assets from this source space asset folder name. Must resolve to exactly one folder. |
| `convert_to_global[].target_shared_folder_id` | Yes | Target shared/global asset folder ID. |
| `convert_to_global[].filters.filetype` | No | Folder-selection filter by content type family, such as `image` or `video`. |
| `convert_to_global[].filters.extensions` | No | Folder-selection filter by filename extensions. |
| `convert_to_global[].filters.tags` | No | Folder-selection filter by asset tags. |

Reconcile mode creates missing target asset folders and uploads missing files. A file is considered existing only when an asset with the exact same filename is present in the same target folder. Existing assets are never replaced or deleted. Dry-run scans the local directory and reports every planned folder and file without accessing Storyblok.

Each `convert_to_global` entry must define exactly one source selector: `asset_id`, `asset_ids`, `source_folder_id`, or `source_folder_name`. Folder-based selection fetches matching assets with pagination. Filters are only valid for folder-based selection. The destination is always explicit: `target_shared_folder_id` is required.

See [examples/assets-space.yaml](examples/assets-space.yaml) for a runnable example.

## `components`

Adds fields to existing components.

```yaml
components:
  fields:
    - component: article-page
      field: SEO
      type: custom
      field_type: sb-ai-seo
      tab: SEO
      display_name: SEO
      required: false
      translatable: false

    - component: article-page
      field: text
      type: richtext
      customize_toolbar: false
```

| Key | Required | Description |
|---|---:|---|
| `fields` | Yes | List of fields to add. |
| `fields[].component` | Yes | Component technical name. |
| `fields[].field` | Yes | Field technical name. |
| `fields[].type` | Yes | Storyblok field type, such as `text`, `richtext`, `asset`, `bloks`, `custom`, or `plugin`. |
| `fields[].field_type` | No | Plugin/custom field type, for example `sb-ai-seo`. |
| `fields[].tab` | No | Tab display name. Defaults to `General` when creating a field. Existing fields move only when this property is declared. |
| `fields[].pos` | No | Numeric field position. |
| `fields[].display_name` | No | Human-readable field label. |
| `fields[].required` | No | Boolean. Defaults to `false` when creating a field. Existing fields change only when declared. |
| `fields[].translatable` | No | Boolean. Defaults to `false` when creating a field. Existing fields change only when declared. |
| `fields[].customize_toolbar` | No | Boolean. Enable or disable richtext toolbar customization on fields that support it. Existing fields change only when declared. |

Missing fields are created. Existing fields are compared using only properties declared in the setup config: matching fields are skipped and differing declared properties are updated. Unmanaged field properties are preserved.

## `tags`

Assigns one or more tags to stories by ID or slug.

```yaml
tags:
  - stories:
      slugs:
        - home
        - contact
    tags:
      - Landing
      - Marketing

  - stories:
      ids:
        - "123456789"
    tags:
      - Featured
```

| Key | Required | Description |
|---|---:|---|
| `stories.slugs` | No | Story slugs to tag. |
| `stories.ids` | No | Story IDs to tag. |
| `tags` | Yes | Tags to assign to the selected stories. |

Each tag group needs at least one story slug or story ID.

Configured tags are merged with existing story tags. Existing tags are preserved, and stories that already contain every requested tag are reported as `SKIPPED`.

## Duplication and Setup

When `space.duplicate_from` is configured, `space:setup` does this in order:

1. Resolve and validate the complete configuration.
2. Create a new space by duplicating the source space ID.
3. Poll the new space until Storyblok reports that no background tasks are pending.
4. Store the new space ID.
5. Apply the config to the new space.

That means `${{ space.id }}` always refers to the final target space, not the source template space.

```bash
php bin/blokctl space:setup --config examples/demo-space.yaml
```

Do not pass `-S` when the config defines `space.duplicate_from`; these modes are mutually exclusive.

To reconcile an existing space, pass its ID and use a config that omits `space.create_new` and `space.duplicate_from`:

```bash
php bin/blokctl space:setup -S 290817118944379 --config existing-space.yaml
```

With `--dry-run`, duplication is skipped and the complete setup plan is rendered using placeholder target-space values.

## Blank-Space Creation and Setup

When `space.create_new: true` is configured, `space:setup` creates a blank space and then applies the remaining configuration:

```yaml
space:
  create_new: true
  name: "Customer Demo"
```

```bash
php bin/blokctl space:setup --config examples/blank-space.yaml
```

Do not combine `space.create_new: true` with `space.duplicate_from` or `-S`. Blank-space creation does not perform duplication readiness polling.
