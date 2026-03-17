# Changelog

All notable changes to `blokctl` will be documented in this file.

## 0.4.0 - WIP
- Adding `story:update`: Update a story's content from simplified JSON with `_asset` markers, `_slug` link markers, and `component` bloks (recursive resolution)
- Adding `story:field-set`: Set a content field value on a story by slug or ID, with `--type` option supporting `text`, `json`, and `asset` (local file upload or URL)
- Adding `story:create`: Create a story with content from JSON (file or inline)

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
