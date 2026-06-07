# Changelog

All notable changes to `blokctl` will be documented in this file.

## 0.10.0 - WIP

- Adding default reconcile-mode provisioning that preserves unmanaged resources and never removes resources omitted from setup configuration.
- Skipping already-installed apps, matching component fields, unchanged preview URLs, existing story tags, assigned workflows, and removed demo mode.
- Updating only explicitly configured component field properties, merging story tags, and preserving unmanaged preview environments.
- Adding `execution.mode: reconcile` and `execution.continue_on_error` setup configuration.
- Waiting for duplicated spaces to finish pending background tasks before applying setup configuration.

## 0.9.0 - 2026-06-07

- Adding `space:create`: Create a new Storyblok space or duplicate an existing space as a reusable demo template.
- Adding `space:setup`: Provision an existing space or configure duplicate-first provisioning entirely from a version-controlled YAML or JSON file.
- Adding `space:setup-validate`: Validate setup configuration files against the bundled JSON Schema without accessing Storyblok.
- Adding namespaced setup variables for runtime inputs, environment variables, target space IDs, and automatically retrieved preview tokens.
- Adding `--set` overrides for reusable setup templates with required inputs and default values.
- Adding complete `--dry-run` planning, including duplicate-first provisioning, without creating or modifying a space.
- Adding structured operation reporting with consistent statuses, execution summaries, non-zero exit codes for failed operations, and masked preview tokens.
- Adding setup configuration examples, schema, reference documentation, and a roadmap for future provisioning capabilities.
- Requiring PHP 8.4.1 or higher and upgrading `symfony/yaml` from ^7.0 to ^8.0.

## 0.8.0 - 2026-05-31
- Requiring `storyblok/php-management-api-client` `^1.7.0` for typed experiment API support.
- Adding `experiment:list`: List experiments in a space with pagination.
- Adding `experiment:create`: Create a draft experiment with default control and variant entries; story IDs are optional.
- Adding `experiment:results:push`: Upload static experiment result chart payloads from JSON.
- Adding `examples/experiment-results.json` and `examples/push-experiment-results.sh` for demo result uploads.
- Documenting experiment commands and noting that unsupported experiment endpoints can be called through the generic Management API client.

## 0.7.0 - 2026-05-16
- Requiring `storyblok/php-management-api-client` `^1.5.0` and using its typed schema field helpers when adding component fields.
- Adding `--display-name`, `--required`, and `--translatable` to `component:field-add`.

## 0.6.7 - 2026-05-10
- Improving `story:workflow-change`: resolve stories by full slug, resolve workflow stages by case-insensitive stage name, and support workflow selection by name or ID.
- Refactoring `StoryWorkflowChangeAction` developer API so `execute()` accepts story slug/ID and stage name/ID directly; `preflight()` now returns available workflow stages for interactive selection.
- Adding support for removing a story's current workflow stage with `--stage-id=0` or `stageId: 0` in PHP.
- Expanding workflow-change tests and README examples for slug lookup, stage-name lookup, workflow scoping, and workflow-stage removal.

## 0.6.6 - 2026-05-04
- Adding `component:show`: Display fields and schema of a component by name or ID. Shows field type, tab assignment, position, and plugin slug for custom fields.
- Adding `--with-tabs` option to `component:show`: Also display tab information (display name, position, and assigned field keys).
- Adding `--pos` option to `component:field-add`: Override the auto-calculated field position with an explicit integer value.

## 0.6.5 - 2026-05-01
- Adding `folder:dimension-add`: Create a folder at root and append it to the Dimensions app configuration (`dimensions_app_folder_ids` + `dimensions_app_folders`) in a single command. Option: `--ai-translation-code` (default: empty string).

## 0.6.4 - 2026-04-30
- `StoryMoveAction::execute()` now uses a minimal `parent_id`-only PUT instead of reconstructing the full story payload, so moving folders no longer fails with "component property is missing"
- `AppProvisionInstallAction::execute()` now accepts `string|int` for `$appId`, matching the underlying SDK signature

## 0.6.3 - 2026-04-21
- Adding `stories:bulk-create`: Create stories from JSON files in a directory (supports content-only and wrapper formats, recursive walk, parent folder, and publish flag)
- `StoriesWorkflowAssignAction::preflight()` now paginates through all story pages instead of stopping at the first page
- `bin/blokctl` autoloader now supports both standalone usage and installation as a Composer dependency

## 0.6.2 - 2026-04-03
- `AssetsUnreferencedAction` now accepts an optional `previewToken` parameter, skipping the SpaceApi call when provided (useful for OAuth-only applications)

## 0.6.1 - 2026-04-03
- Adding `assets:list`: List assets with optional search filter via Management API

## 0.6.0 - 2026-04-03
- Upgrading `symfony/console` from ^7.0 to ^8.0
- Upgrading `phpunit/phpunit` from ^12.0 to ^13.0

## 0.5.0 - 2026-04-02
- Adding `assets:unreferenced`: Detect orphaned assets not referenced in any story. Uses the Content Delivery API for story scanning (higher rate limits) and Management API for asset listing (up to 1000/page)
- Adding `story:versions`: List versions of a story by slug, ID, or UUID, with optional full content output

## 0.4.0 - 2026-03-19
- Adding `story:update`: Update a story's content from simplified JSON with `_asset` markers, `_slug` link markers, and `component` bloks (recursive resolution)
- Adding `story:field-set`: Set a content field value on a story by slug or ID, with `--type` option supporting `text`, `json`, and `asset` (local file upload or URL)
- Adding `story:create`: Create a story with content from JSON (file or inline)
- Adding `CLAUDE.md` as a lean entry point with skill pointers
- Adding Claude Code skills: `/blokctl-cli` (CLI reference), `/blokctl-api` (PHP Action API guide), `/blokctl-dev` (contributor guide)

## 0.3.0 - 2026-03-17
- Adding `folder:create`: Create a folder (with optional parent folder by slug or ID)
- Adding `workflow:stage-show`: Show details of a workflow stage by name or ID
- Adding `workflows:list`: List workflows and their stages (lookup stage IDs by name)
- Adding `story:workflow-change`: Change the workflow stage of a story

## 0.2.1 - 2026-03-14
-  Adding`story:move` — Move a story to a different folder

## 0.2.0 - 2026-03-11 
- Adding `components:usage` - Analyze component usage across all stories (shows how many stories each component appears in and total occurrences)
- New dependency: `storyblok/php-content-api-client` for Content Delivery API access (stories list with full content)

## 0.1.0 - 2026-03-05

Initial release of `blokctl`, a CLI tool for managing Storyblok spaces.

### Commands

- `space:info` - Display space details and ownership info
- `space:delete` - Safely delete a space (with ownership and collaborator checks)
- `space:demo-remove` - Remove demo mode from a space
- `space:preview-list` - List preview URLs and environments
- `space:preview-set` - Set the default preview URL (with optional extra environments)
- `space:preview-add` - Add a preview environment
- `spaces:list` - List all spaces (with search, ownership, and staleness filters)
- `stories:list` - List stories with content type, tag, slug, and publication filters
- `stories:tags-assign` - Assign tags to stories by ID or slug
- `stories:workflow-assign` - Assign workflow stages to stories
- `story:show` - Show a story by slug, ID, or UUID
- `components:list` - List components (with search, root-only, and group filters)
- `component:field-add` - Add a field to a component's schema (core types and plugins)
- `app:provision-install` - Install an app by ID, slug, or interactive selection
- `app:provision-list` - List installed apps
- `user:me` - Show authenticated user info

### Features

- Multi-region support (EU, US, AP, CA, CN)
- Interactive prompts with `--no-interaction` mode for scripting
- Action pattern architecture for reusable business logic
- Automatic rate-limit retry with backoff
