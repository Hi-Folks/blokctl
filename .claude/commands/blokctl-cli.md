# blokctl CLI Reference

Use this skill when the user wants to run blokctl commands to manage a Storyblok space.

## IMPORTANT: Space ID validation

**Before suggesting or running any command that requires `--space-id` (`-S`), you MUST confirm that the user has provided a clear, specific numeric Space ID.** If the Space ID is missing, ambiguous, or looks like a placeholder (e.g. "my space", "12345", "the demo one"), STOP and ask the user to provide the exact numeric Space ID. Do not guess, infer, or use example IDs from documentation. Commands mutate real Storyblok spaces — running against the wrong space can delete content, break workflows, or install unwanted apps.

## Global options

| Option | Short | Description |
|---|---|---|
| `--space-id` | `-S` | Storyblok Space ID (prompted if omitted) |
| `--region` | `-R` | Region: `EU` (default), `US`, `AP`, `CA`, `CN` |
| `--no-interaction` | `-n` | Skip prompts (requires all options provided) |

## Commands by domain

### Spaces

- **`spaces:list`** — List all spaces. Options: `--search`, `--owned-only`, `--updated-before=N` (days), `--solo-only`. No `--space-id` needed.
- **`space:info`** — Show space details (ID, name, plan, preview URLs, owner status).
- **`space:delete`** — Delete a space. Safety: must be owner + sole collaborator.
- **`space:demo-remove`** — Remove demo mode from a space.
- **`space:token`** — Show the space's preview access token.

### Preview URLs

- **`space:preview-list`** — List default preview URL and environments.
- **`space:preview-set <url>`** — Set default preview URL. Option: `-e 'Name=URL'` (repeatable) for extra environments.
- **`space:preview-add <name> <url>`** — Add a preview environment.

### Folders

- **`folder:create [name]`** — Create a folder. Options: `--parent-slug`, `--parent-id`. Defaults to root.

### Stories

- **`story:create [name]`** — Create a story with content. Options: `--content='JSON'`, `--content-file=path`, `--slug`, `--parent-slug`, `--parent-id`, `--publish`. JSON must include `"component"`.
- **`stories:list`** — List stories. Options: `--content-type` (`-c`), `--starts-with` (`-s`), `--search`, `--with-tag` (`-t`), `--published-only`, `--page` (`-p`), `--per-page`.
- **`story:update`** — Update story content from simplified JSON. Lookup: `--by-slug`, `--by-id`. Content: `--content`, `--content-file`. Option: `--publish`.
- **`story:field-set <field> <value>`** — Set a single content field. Lookup: `--by-slug`, `--by-id`. Option: `--type` (`text`|`json`|`asset`).
- **`story:show`** — Display story as JSON. Lookup: `--by-slug`, `--by-id`, `--by-uuid`. Option: `--only-story`.
- **`story:move`** — Move story to a different folder. Lookup: `--by-slug`, `--by-id`. Target: `--to-folder-slug`, `--to-folder-id` (use `0` for root).
- **`story:workflow-change`** — Change workflow stage. Lookup: `--by-slug`, `--by-id`. Stage: `--stage` (name) or `--stage-id`. Workflow: `--workflow-name`, `--workflow-id`.
- **`stories:tags-assign`** — Assign tags. Options: `--story-id` (repeatable), `--story-slug` (repeatable), `--tag` (`-t`, repeatable).
- **`stories:workflow-assign`** — Assign a workflow stage to all stories without one. Option: `--workflow-stage-id`.

### Workflows

- **`workflows:list`** — List workflows and their stages with IDs.
- **`workflow:stage-show`** — Show stage details. Lookup: `--by-name`, `--by-id`. Scope: `--workflow-name`, `--workflow-id`.

### Components

- **`components:list`** — List components. Options: `--search`, `--root-only`, `--in-group=UUID`.
- **`components:usage`** — Analyze component usage across stories. Options: `--starts-with`, `--per-page`.
- **`component:field-add`** — Add a field to a component. Options: `--component`, `--field`, `--type` (core type or `custom`), `--field-type` (plugin slug), `--tab`.

### Apps

- **`app:provision-list`** — List installed apps.
- **`app:provision-install [app-id]`** — Install an app. Option: `--by-slug`. Interactive selector if neither provided.

### User

- **`user:me`** — Show authenticated user info. No `--space-id` needed.

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

### Create a folder then a story inside it
```bash
php bin/blokctl folder:create -S 12345 'Articles'
php bin/blokctl story:create -S 12345 'First Post' \
  --content='{"component":"article","title":"Hello"}' --parent-slug=articles
```
