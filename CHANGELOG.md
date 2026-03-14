# Changelog

All notable changes to `blokctl` will be documented in this file.

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
