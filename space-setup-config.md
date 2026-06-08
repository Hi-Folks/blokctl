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

Use `--dry-run` to inspect the plan without changing Storyblok:

```bash
php bin/blokctl space:setup -S 290817118944379 --config existing-space.yaml --dry-run
```

For duplicate-first provisioning, dry-run also displays the complete post-duplication plan without creating a space:

```bash
php bin/blokctl space:setup --config examples/demo-space.yaml --dry-run
```

During a duplicate dry-run, `${{ space.id }}` resolves to `NEW_SPACE_ID` and `${{ space.preview_token }}` resolves to `PREVIEW_TOKEN`.

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

The current example is [examples/demo-space.yaml](examples/demo-space.yaml).

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
| `${{ space.id }}` | Target space ID. If `space.duplicate_from` is configured, this is the newly created space ID. |
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

Defines optional target-creation settings. Configure `duplicate_from` and `name` to duplicate a template before applying the setup. When targeting an existing space with `-S`, omit `duplicate_from`; the other setup sections are applied directly to that space.

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
| `name` | Conditional | Name of the duplicated space. Required when `duplicate_from` is configured. |
| `duplicate_from` | No | Source template space ID. Omit when targeting an existing space with `-S`. |
| `in_org` | No | Create the duplicated space inside the current organization. Defaults to `false`. |
| `demo` | No | Mark the duplicated space as a demo/example space. Defaults to `false`. |
| `readiness` | No | Readiness polling settings used after duplication. |

`space.demo: true` cannot be combined with `demo_mode.remove: true`.

After duplication, `space:setup` polls the new space until Storyblok reports `has_pending_tasks: false`. This prevents provisioning steps from running while duplication background tasks are still active. Existing-space setup and dry runs do not poll.

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
| `space` | Optional target-creation settings. Omit `space.duplicate_from` when using `-S`. |
| `continue_on_error` | Global boolean. Continue after a failed step. |
| `preview` | Set the default preview URL and frontend environments. |
| `demo_mode` | Remove demo/example mode. |
| `workflow` | Assign a workflow stage to stories without one. |
| `apps` | Install Storyblok apps by slug. |
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
```

| Key | Required | Description |
|---|---:|---|
| `assign_unstaged` | Yes | Boolean. When `true`, assign a workflow stage to unstaged stories. |
| `stage_id` | No | Workflow stage ID. If `null`, blokctl tries to use the default workflow's first stage. |

## `apps`

Installs apps by app slug.

```yaml
apps:
  continue_on_error: true
  install:
    - releases_only
    - storyblok-gmbh@ai-seo
```

| Key | Required | Description |
|---|---:|---|
| `continue_on_error` | No | Boolean. Useful because some apps may not be available for every space. |
| `install` | Yes | List of app slugs to install. |

Apps that are already installed are reported as `SKIPPED`.

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

To reconcile an existing space, pass its ID and omit `space.duplicate_from`:

```bash
php bin/blokctl space:setup -S 290817118944379 --config examples/demo-space.yaml
```

With `--dry-run`, duplication is skipped and the complete setup plan is rendered using placeholder target-space values.
