# blokctl CLI Reference

Use this skill when the user wants to run blokctl commands to manage a Storyblok space.

## IMPORTANT: Space ID validation

**Before suggesting or running any command that targets an existing space with `--space-id` (`-S`), you MUST confirm that the user has provided a clear, specific numeric Space ID.** If the Space ID is missing, ambiguous, or looks like a placeholder (e.g. "my space", "12345", "the demo one"), STOP and ask the user to provide the exact numeric Space ID. Do not guess, infer, or use example IDs from documentation. Commands mutate real Storyblok spaces; running against the wrong space can delete content, break workflows, or install unwanted apps.

Exceptions that do not require `-S`: `space:create`, `space:setup-validate`, `spaces:list`, `user:me`, and create/duplicate-first `space:setup` when the config defines `space.create_new: true` or `space.duplicate_from`.

## Global options

| Option | Short | Description |
|---|---|---|
| `--space-id` | `-S` | Storyblok Space ID (prompted if omitted) |
| `--region` | `-R` | Region: `EU` (default), `US`, `AP`, `CA`, `CN` |
| `--no-interaction` | `-n` | Skip prompts (requires all options provided) |

## Commands by domain

### Spaces

- **`spaces:list`** — List all spaces. Options: `--search`, `--owned-only`, `--updated-before=N` (days), `--solo-only`. No `--space-id` needed.
- **`space:create [name]`** — Create a blank space or duplicate an existing space. Options: `--name`, `--duplicate-from`, `--in-org`, `--demo`, `--only-id`. No `--space-id` needed.
- **`space:info`** — Show space details (ID, name, plan, preview URLs, owner status).
- **`space:delete`** — Delete a space. Safety: must be owner + sole collaborator.
- **`space:demo-remove`** — Remove demo mode from a space.
- **`space:token`** — Show the space's preview access token.
- **`space:setup`** — Reconcile a space from validated YAML/JSON Configuration as Code. Use `-S` for an existing space, `space.create_new: true` with `space.name` for a blank space, or `space.duplicate_from` with `space.name` for a duplicated target. Options: `--config`, `--dry-run`, `--continue-on-error`, repeatable `--set`, `--report`.
- **`space:role-list`** — List custom space roles with permissions, allowed/blocked paths, and languages. Option: `--search`.
- **`space:role-ensure`** — Create a custom space role or update its declared properties (matched by exact name). Options: `--name`, `--subtitle`, `-p`/`--permission`, `--allow`, `--block` (full slug or ID, repeatable), `-l`/`--language`. Passed lists replace existing values; omitted options are kept. `allowed_paths` needs the Elite plan.
- **`space:collaborator-list`** — List collaborators with email, name, role, and ID. The owner and pending invitations are not listed by the API.
- **`space:collaborator-invite`** — Add users by email. Options: `-e`/`--email` (repeatable), `--role` (`admin`, `editor` default, or custom role name or ID), `--dry-run`. Asks for confirmation (skip with `-n`). Current collaborators are skipped; emails without a Storyblok account get a pending invitation (expires after 7 days) that a later run cannot detect. Real emails are sent: confirm with the user before running without `--dry-run`.
- **`space:collaborator-role-set`** — Change a collaborator's main role. Options: `-e`/`--email` or `--id`, `--role` (`admin`, `editor`, or custom role name or ID), `--dry-run`. Asks for confirmation (skip with `-n`). No-op when the role already matches.
- **`space:collaborator-remove`** — Remove collaborators. Options: `-e`/`--email` and `--id` (repeatable; use `--id` for pending invitations, which are not in the list), `--dry-run`. Asks for confirmation (skip with `-n`).
- **`space:role-delete`** — Delete a custom space role. Options: `--role` (name or ID), `--dry-run`. Lists collaborators that still have the role, then asks for confirmation (skip with `-n`).
- **`space:setup-export`** — Export components, datasources, roles, and workflows of a space as a `space:setup` YAML config. Options: `--section` (repeatable), `-c`/`--component`, `-d`/`--datasource`, `-o`/`--output`, `--entries-dir` (CSV files). Unsupported settings become `# WARNING` comments. Progress goes to stderr (`-q` hides it). Secret values (names containing token, secret, password, api_key, ...) become `####YOURSECRET####`; configure with `--secret-name`, `--secret-placeholder`, or `BLOKCTL_SECRET_NAMES`/`BLOKCTL_SECRET_PLACEHOLDER` in `.env`; `--include-secrets` keeps them. Read-only.
- **`space:setup-validate`** — Validate a setup YAML/JSON file against `space-setup-schema.json` without accessing Storyblok. Options: `--config`, `--print-merged` (show the result of `extends`), `--set NAME=VALUE` (inputs for `extends` conditions). No `--space-id` needed.

### Configuration as Code setup

`space:setup` defaults to reconcile behavior:

- Preserves unmanaged resources.
- Adds or updates only explicitly configured values.
- Never removes resources merely because they are omitted.
- Merges story tags and preview environments.
- Skips matching apps, folders, fields, tags, workflows, demo mode, preview URLs, root-content moves, and Dimensions configuration.
- Supports multi-country setup with `folders.ensure`, `stories.move`, and `dimensions.folders`.
- Checks the target space first with `assert` (stories, components, datasources, languages, apps, `plan_at_least`); a failed check stops the run before any change.
- Combines reusable config files with `extends` (named items merge by name or slug, operations concatenate, value lists are replaced); `{path, when: ${{ inputs.x }}}` makes a file optional.
- Creates components with their folder and fields with `components.ensure` (after datasources, before stories).
- Creates or updates datasources, dimensions, and entries with `datasources` (inline or `entries_file` CSV); `remove_entries` deletes entries at the end with the other removals.
- Inserts components into story list fields with `stories.insert_component` (index or append; skipped when the same block is already present; runs after `stories.update`).
- Publishes selected stories with `stories.publish` (after other operations) and unpublishes with `stories.unpublish` (with the removals at the end).
- Creates or updates custom workflows and their stages with `workflow.ensure` (stage order from the list, roles and stage links by name; runs after `roles`).
- Uploads local asset directories with `assets.upload_directory`, preserving nested folders and skipping matching filenames.
- Converts space-local assets into the Global Asset Library with `assets.convert_to_global`; requires `target_shared_folder_id` and one source selector (`asset_id`, `asset_ids`, `source_folder_id`, or `source_folder_name`).
- Creates or updates custom space roles with `roles` (matched by name; only declared properties are reconciled; roles are never removed; users are not invited).
- Configures space-level Storyblok AI activation with `ai.enabled` and `ai.inherit_org_configuration`, and AI Translations disclaimer acceptance with `ai_translation.disclaimer_id`.

Duplicate-first setup waits until Storyblok reports no pending background tasks before provisioning. If setup fails after duplication, the new space is preserved for inspection or recovery.

```bash
# Validate only
php bin/blokctl space:setup-validate --config examples/demo-space.yaml

# Inspect a duplicate-first plan without API changes
php bin/blokctl space:setup --config examples/demo-space.yaml \
  --dry-run --set customer=Acme --report setup-plan.json

# Reconcile an existing space; requires an exact numeric ID
php bin/blokctl space:setup -S <exact-space-id> \
  --config existing-space.yaml --report setup-result.json

# Reconcile a multi-country demo structure
php bin/blokctl space:setup -S <exact-space-id> \
  --config examples/multi-country-space.yaml

# Reconcile a local asset directory
php bin/blokctl space:setup -S <exact-space-id> \
  --config examples/assets-space.yaml

# Reconcile region folders and custom roles scoped to them
php bin/blokctl space:setup -S <exact-space-id> \
  --config examples/roles-space.yaml

# Roles plus a custom review workflow (governance demo)
php bin/blokctl space:setup -S <exact-space-id> \
  --config examples/governance-space.yaml
```

See `space-setup-config.md` for complete syntax. Reports contain stable JSON status, target/duplication details, masked operations, and summary counts.

### Preview URLs

- **`space:preview-list`** — List default preview URL and environments.
- **`space:preview-set <url>`** — Set default preview URL. Option: `-e 'Name=URL'` (repeatable) for extra environments.
- **`space:preview-add <name> <url>`** — Add a preview environment.

### Folders

- **`folder:create [name]`** — Create a folder. Options: `--parent-slug`, `--parent-id`. Defaults to root.
- **`folder:dimension-add [name]`** — Create a folder at root and append it to the Dimensions app configuration. Option: `--ai-translation-code` (e.g. `it`, `fr`, `de`; defaults to empty string). Reads the current config before updating so existing folders are preserved.

### Stories

- **`story:create [name]`** — Create a story with content. Options: `--content='JSON'`, `--content-file=path`, `--slug`, `--parent-slug`, `--parent-id`, `--publish`. JSON must include `"component"`.
- **`stories:list`** — List stories. Options: `--content-type` (`-c`), `--starts-with` (`-s`), `--search`, `--with-tag` (`-t`), `--published-only`, `--page` (`-p`), `--per-page`.
- **`story:update`** — Update story content from simplified JSON. Lookup: `--by-slug`, `--by-id`. Content: `--content`, `--content-file`. Option: `--publish`.
- **`story:field-set <field> <value>`** — Set a single content field. Lookup: `--by-slug`, `--by-id`. Option: `--type` (`text`|`json`|`asset`).
- **`story:show`** — Display story as JSON. Lookup: `--by-slug`, `--by-id`, `--by-uuid`. Option: `--only-story`.
- **`story:move`** — Move story to a different folder. Lookup: `--by-slug`, `--by-id`. Target: `--to-folder-slug`, `--to-folder-id` (use `0` for root).
- **`story:workflow-change`** — Change workflow stage. Lookup: `--by-slug`, `--by-id`. Stage: `--stage` (case-insensitive name) or `--stage-id`; use `--stage-id=0` to remove the current workflow stage. Workflow: `--workflow-name`, `--workflow-id` (default workflow if omitted).
- **`stories:bulk-create [directory]`** — Create stories from JSON files in a directory. Options: `--recursive` (`-r`), `--pattern` (default `*.json`), `--parent-slug`, `--parent-id`, `--publish`. Each file can be content-only (JSON with `"component"`) or wrapper format (`{ "name", "slug", "content" }`). Name and slug default to the filename.
- **`stories:publish`** / **`stories:unpublish`** — Publish or unpublish stories. Options: `--slug` (exact full slug), `--id`, `--folder` (every story inside), all repeatable, `--dry-run`. Asks for confirmation (skip with `-n`). Skips stories already in the requested state.
- **`stories:tags-assign`** — Assign tags. Options: `--story-id` (repeatable), `--story-slug` (repeatable), `--tag` (`-t`, repeatable).
- **`story:versions`** — List versions of a story. Lookup: `--by-slug`, `--by-id`, `--by-uuid`. Options: `--show-content`, `--page` (`-p`), `--per-page`.
- **`stories:workflow-assign`** — Assign a workflow stage to all stories without one. Option: `--workflow-stage-id`.

### Assets

- **`assets:list`** — List assets. Options: `--search`, `--page` (`-p`), `--per-page` (max 1000). Management API only, no preview token needed.
- **`assets:unreferenced`** — Detect orphaned assets not referenced in any story. Fetches all assets via Management API (1000/page), scans all stories via CDN API (higher rate limits), then diffs.
- **`assets:convert-to-global`** — Convert space-local assets into shared assets in the Global Asset Library. Requires `--target-shared-folder-id`. Source selector: repeatable `--asset-id`, comma-separated `--asset-ids`, `--source-folder-id`, or `--source-folder-name`. Folder selection supports `--filetype`, repeatable `--extension`, repeatable `--tag`, `--dry-run`, and `--continue-on-error`.

### Workflows

- **`datasources:list`** — List datasources with slug, ID, and dimensions.
- **`datasource:entries-list`** — List entries. Options: `-d`/`--datasource` (slug or ID), `--dimension` (code).
- **`datasource:ensure`** — Create a datasource or update its name and dimensions. Options: `--slug`, `--name`, `--dimension code=Name` (repeatable).
- **`datasource:entries-ensure`** — Create or update entries. Options: `-d`/`--datasource`, `-e`/`--entry name=value` (repeatable), `--dimension-value code=value` (repeatable, with exactly one `--entry`; `-e name` alone keeps the current value), `--csv` (columns `name`, `value`, dimension codes). Never deletes entries. Common case: change one entry's values with `-d <datasource> -e red=Crimson --dimension-value it=Cremisi`.
- **`datasource:entries-export`** — Export entries as CSV (`name`, `value`, dimension codes). Options: `-d`/`--datasource`, `--dimension` (repeatable), `-o`/`--output` (prints when omitted). Round trip: export, edit, import with `datasource:entries-ensure --csv`.
- **`datasource:entries-remove`** — Delete entries by name. Options: `-d`/`--datasource`, `-e`/`--entry` (repeatable), `--dry-run`. Asks for confirmation (skip with `-n`); names that match no entry are skipped.
- **`workflows:list`** — List workflows and their stages with IDs.
- **`workflow:stage-show`** — Show stage details. Lookup: `--by-name`, `--by-id`. Scope: `--workflow-name`, `--workflow-id`.
- **`workflow:ensure`** — Create a custom workflow or update its content types. Options: `--name`, `-c`/`--content-type` (repeatable; required to create). A content type can belong to only one workflow.
- **`workflow:stage-ensure`** — Create a stage or update its declared options. Options: `-w`/`--workflow`, `--name`, `--color`, `--position`, `--[no-]default`, `--[no-]allow-publish`, `--[no-]allow-admin-publish`, `--[no-]allow-admin-change`, `--[no-]allow-editor-change`, `--next` and `--role` (repeatable, names or IDs, `*` for all), `--after-publish`. Create all stages before linking them with `--next`/`--after-publish`.

### Components

- **`components:list`** — List components. Options: `--search`, `--root-only`, `--in-group=UUID`.
- **`components:usage`** — Analyze component usage across stories. Options: `--starts-with`, `--per-page`.
- **`components:diff`** — Compare components (settings, fields, default values, tabs, presets) between `-S` (source) and `--target-space` / `-T`. Read-only. Options: `--target-region`, `--component` / `-c` (repeatable), `--with-presets`, `--json`, `--exit-code`.
- **`component:show`** — Display component fields and schema. Lookup: `--by-name`, `--by-id`. Option: `--with-tabs`.
- **`component:ensure`** — Create a component or update its settings. Options: `--name`, `--display-name`, `--type` (`content_type`, `nestable`, `universal`), `--folder` (path, created when missing), `--color`, `--icon`, `--preview-field`, `--description`. Never changes fields.
- **`component:folder-ensure`** — Create a component folder path, e.g. `--path=Marketing/Banners`. Folder names are unique in the space.
- **`component:field-add`** — Add a field to a component. Options: `--component`, `--field`, `--type` (core type or `custom`), `--field-type` (plugin slug), `--tab`, `--display-name`, `--required`, `--translatable`.

### Apps

- **`app:provision-list`** — List installed apps.
- **`app:provision-install [app-id]`** — Install an app. Option: `--by-slug`. Interactive selector if neither provided.

### User

- **`user:me`** — Show authenticated user info. No `--space-id` needed.

### Experiments

- **`experiment:list`** — List experiments. Options: `--page`, `--per-page`.
- **`experiment:create`** — Create a draft experiment. Options: repeatable `--story-id`, `--name`, `--display-name`, `--description`.
- **`experiment:results:push [experiment-id]`** — Push experiment result charts. Option: `--file`.

## Simplified JSON content format

Used by `story:create` and `story:update` for content fields:

```json
{
  "component": "default-page",
  "headline": "About Us",
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

**Conventions:**
- **`{ "_asset": "..." }`** — Asset field. URLs are downloaded and uploaded to Storyblok; local paths are uploaded directly.
- **`{ "_slug": "..." }`** — Multilink field. The slug is resolved to a story UUID.
- **Arrays with `"component"` objects** — Bloks (nested components). `_uid` is auto-generated.
- **Root `"component"`** — The content type (required for `story:create`, preserved for `story:update`).
- Everything else passes through as-is. The resolver walks the tree recursively.

## Workflow examples

### Create a page with hero and banner
```bash
php bin/blokctl story:create -S 12345 'About Us' \
  --content='{"component":"default-page","headline":"About Us","body":[{"component":"hero_section","title":"Welcome"},{"component":"banner","text":"Learn more"}]}' \
  --publish
```

### Update story fields
```bash
php bin/blokctl story:update -S 12345 --by-slug=home \
  --content='{"headline":"New Headline","featured":true}' --publish
```

### Set a single field (asset)
```bash
php bin/blokctl story:field-set -S 12345 cover_image 'https://example.com/photo.jpg' \
  --by-slug=home --type=asset
```

### Move stories between folders
```bash
php bin/blokctl story:move -S 12345 --by-slug=authors/john --to-folder-slug=archived/authors
```

### Change or remove a workflow stage
```bash
# Set by stage name in the default workflow
php bin/blokctl story:workflow-change -S 12345 --by-slug=home --stage=Reviewing

# Set by stage ID
php bin/blokctl story:workflow-change -S 12345 --by-id=456789 --stage-id=653555

# Remove the current workflow stage
php bin/blokctl story:workflow-change -S 12345 --by-slug=home --stage-id=0
```

### Create a folder then a story inside it
```bash
php bin/blokctl folder:create -S 12345 'Articles'
php bin/blokctl story:create -S 12345 'First Post' \
  --content='{"component":"article","title":"Hello"}' --parent-slug=articles
```
