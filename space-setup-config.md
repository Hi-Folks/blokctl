# Space Setup Configuration

`space:setup` applies repeatable setup steps to a Storyblok space from a JSON or YAML file.

It can run against an existing space:

```bash
php bin/blokctl space:setup -S 290817118944379 --config examples/demo-space.yaml
```

Or it can duplicate a template space first, then apply the setup to the newly created space:

```bash
php bin/blokctl space:setup \
  --duplicate-from=286863409930127 \
  --name='My Demo' \
  --in-org \
  --demo \
  --config examples/demo-space.yaml
```

Use `--dry-run` to inspect the plan without changing Storyblok:

```bash
php bin/blokctl space:setup -S 290817118944379 --config examples/demo-space.yaml --dry-run
```

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
| `${{ space.id }}` | Target space ID. If `--duplicate-from` is used, this is the newly created space ID. |
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

## Top-Level Keys

Except for `version`, all top-level sections are optional. If a section is omitted, that setup step is skipped.

| Key | Description |
|---|---|
| `version` | Required configuration schema version. Currently `1`. |
| `inputs` | Reusable runtime input definitions with defaults and required values. |
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

inputs:
  frontend_host:
    description: "Frontend host used by the default preview URL"
    default: "storyblok-demo-default-se.netlify.app"

continue_on_error: false

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
| `fields[].tab` | No | Tab display name. Defaults to `General`. |
| `fields[].pos` | No | Numeric field position. |
| `fields[].display_name` | No | Human-readable field label. |
| `fields[].required` | No | Boolean. Defaults to `false`. |
| `fields[].translatable` | No | Boolean. Defaults to `false`. |

The command fails if the component does not exist or the field already exists, unless `--continue-on-error` is used.

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

## Duplication and Setup

When using `--duplicate-from`, `space:setup` does this in order:

1. Create a new space by duplicating the source space ID.
2. Store the new space ID.
3. Apply the config to the new space.

That means `${{ space.id }}` always refers to the final target space, not the source template space.

```bash
php bin/blokctl space:setup \
  --duplicate-from=286863409930127 \
  --name='Campaign Demo' \
  --in-org \
  --demo \
  --config examples/demo-space.yaml
```

Do not pass `-S` together with `--duplicate-from`; these modes are mutually exclusive.
