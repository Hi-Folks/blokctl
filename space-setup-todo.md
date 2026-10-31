# Space Setup TODO

Roadmap for turning `space:setup` into a reliable, reusable provisioning system for daily customer demos.

Related documentation:

- [Space setup configuration](space-setup-config.md)
- [Example provisioning config](examples/demo-space.yaml)

## Immediate Fixes

- [x] Apply variable substitution recursively to every config string, not only preview URLs.
- [x] Make duplicate-first `--dry-run` show the complete post-duplication setup plan.
- [x] Detect conflicting settings such as `space.demo: true` with `demo_mode.remove: true`.
- [x] Validate the complete config before creating or modifying a space.
- [x] Improve validation errors with the YAML/JSON property path, for example `components.fields[0].type`.
- [x] Make setup output distinguish `planned`, `created`, `updated`, `installed`, `removed`, `skipped`, and `failed`.
- [x] Return a non-zero exit code when individual actions report errors, including when setup continues.
- [x] Add focused dry-run and execution tests for every supported config section.

## Next: Multi-Country Demo Provisioning

Move the behavior from `examples/multi-country-demo-setup.php` into declarative, idempotent `space:setup` configuration.

- [x] Add `folders.ensure` to create or reuse folders by full slug and parent.
- [x] Make folder reconciliation skip existing folders and fail when multiple folders ambiguously match.
- [x] Add `stories.move` with selectors for root-level items, explicit slugs, folders, and stories.
- [x] Support `include_folders`, `include_slugs`, and `exclude_slugs` selectors.
- [x] Resolve move targets by folder slug, paginate through all stories, and skip items already in the target folder.
- [x] Add `dimensions.folders` reconciliation that resolves folders by slug.
- [x] Preserve unmanaged Dimensions folders, add missing configured folders, and update explicitly declared AI translation codes.
- [x] Extend `apps.install` to accept structured app references with slug and optional ID fallback.
- [x] Add a complete multi-country YAML example covering Global, Italy, Germany, and France.
- [x] Add schema validation, dry-run planning, operation reporting, and focused execution tests for every new section.

Recommended implementation order:

1. `folders.ensure`
2. `stories.move`
3. `dimensions.folders`
4. Structured app references

## Priority 1: Schema, Validation, and Template Definition

- [x] Add required config schema version: `version: 1`.
- [x] Create `space-setup-schema.json` using JSON Schema Draft 2020-12.
- [x] Use the same JSON Schema to validate both YAML and JSON setup files.
- [x] Add `additionalProperties: false` to detect unsupported and misspelled properties.
- [x] Validate the complete config before duplicating, creating, or modifying a space.
- [x] Report validation errors with precise property paths, for example `components.fields[0].type`.
- [x] Add the YAML Language Server schema reference to example YAML files:

  ```yaml
  # yaml-language-server: $schema=../space-setup-schema.json
  ```

- [x] Add editor autocomplete and inline validation through the JSON Schema.
- [ ] Add optional template metadata: name, description, category, owner, and tags.
- [ ] Complete secret/masked runtime parameter handling.
- [x] Add repeatable CLI overrides: `--set customer_name=Acme`.
- [x] Support namespaced parameter placeholders such as `${{ inputs.customer_name }}`.
- [x] Add a config validation command:

  ```bash
  php bin/blokctl space:setup-validate --config demo-space.yaml
  ```
- [x] Validate setup story content against configured and existing component fields
  before mutations, with nested-block, required-field, basic-type, and allowlist checks.
- [x] Add machine-readable `space:setup-validate --json` output for AI repair loops.
- [x] Document the guarded AI authoring workflow for tool-neutral and Claude agents.

## Priority 1A: PHP Schema as Code

Build a PHP-native schema authoring layer inspired by Storyblok's JavaScript `@storyblok/schema`, but make it reuse `blokctl`'s existing `space:setup` reconciliation instead of creating a parallel sync engine.

- [ ] Add schema model classes: `Schema`, `Block`, `Field`, `Folder`, and `Datasource`.
  - Suggested namespace: `Blokctl\Schema`.
  - Keep these classes as fluent, immutable authoring objects with no HTTP client, command, or reporter dependencies.
  - Start with the field and block properties already supported by `space:setup`: technical names, field type, display name, tab, position, required, translatable, `customize_toolbar`, and plugin/custom `field_type`.
  - Use enums where they prevent string drift, for example `FieldType`.
  - Validate duplicate field names, empty technical names, invalid folder paths, and unsupported field/reference combinations early.

- [ ] Export PHP schema definitions to the existing `space:setup` config format.
  - Add an exporter such as `Blokctl\Schema\Export\SpaceSetupConfigExporter`.
  - `Schema::toSpaceSetupConfig()` should delegate to the exporter rather than embedding config-shape logic in the model classes.
  - Generated config should pass `SpaceSetupConfigValidator` and should be executable by `SpaceSetupProvisioner`.
  - First exporter target can emit only currently supported sections, especially `components.fields`; keep the public schema API stable so richer exports can be added later.

- [x] Expand `space:setup` to create and reconcile full components, component folders, and datasources.
  - [x] Add `components.ensure` for component/block creation with settings and fields (no full schema replacement: undeclared fields are kept).
  - [x] Add component folder/group reconciliation by path (`folder` in `components.ensure`, `component:folder-ensure`).
  - [x] Add datasource reconciliation by slug, including dimensions; preserve or explicitly manage datasource entries separately (`datasources`, `remove_entries`).
  - [x] Reuse existing setup primitives: validation, dry-run planning, operation reporting, continue-on-error behavior, and idempotent update checks.
  - [ ] Keep destructive schema changes (field or component removal) explicit and gated by the destructive-operation decision below.

- [ ] Add story validation against the declared PHP schema.
  - Validate root component names, nested `bloks` content, required fields, scalar field value types, allowed components/folders for `bloks`, datasource-backed option values where practical, and custom field plugin values when validators are available.
  - Return non-throwing validation results with paths such as `content.body[0].headline`.
  - Use this validation for local JSON story files before `stories.create` or `stories.update`.

- [ ] Add schema commands: `schema:init`, `schema:push`, and `schema:rollback`.
  - `schema:init` should inspect a space and generate PHP schema files as a one-time bootstrap for adopting an existing space.
  - `schema:push` should load a PHP schema entry file, validate it, generate a plan/diff, and apply changes through the existing setup/provisioning layer where possible.
  - `schema:rollback` should revert a saved schema changeset; define the changeset format before implementing destructive operations.
  - Prefer composing existing `space:setup` services over adding separate API mutation paths.

- [ ] Optionally generate PHPStan/Psalm types or DTO classes from PHP schema definitions.
  - Start with generated array-shape annotations for story `content`.
  - Consider DTO generation only after the schema model and story validation are stable.
  - Document that PHP cannot fully mirror TypeScript literal inference, but can provide useful static-analysis support.

## Priority 2: Idempotency and Reliability

- [x] Add default reconcile execution mode and scoped `continue_on_error`:

  ```yaml
  execution:
    mode: reconcile
    continue_on_error: false
  ```

- [x] Make every currently supported setup step idempotent.
- [x] Skip apps that are already installed.
- [x] Skip component fields that already match the requested definition.
- [x] Update component fields when requested configuration differs.
- [x] Reconcile preview environments without unintentionally replacing existing entries.
- [x] Skip tags already assigned to stories.
- [x] Reuse datasources that already exist (matched by slug).
- [x] Rely on retry handling provided by the PHP Management Client.
- [x] Add readiness polling after duplication before applying setup.
- [x] Detect and report pending duplication/background tasks.
- [x] Produce a machine-readable setup report with space ID, duplication details, successful steps, skipped steps, and failures.
- [x] Preserve duplicated spaces when setup fails; do not automatically roll back or delete them.

## Priority 3: Customer Personalization

- [x] Add story component field updates by story slug or ID, using deterministic component paths and expected component assertions.

  ```yaml
  stories:
    update:
      - slug: home
        components:
          - path: content.body[0]
            component: hero-section
            fields:
              eyebrow: "Welcome to"
              text: "We bring fresh ideas to life for ${{ inputs.customer_name }}."

          - path: content.body[0].headline[0]
            component: headline-segment
            fields:
              text: "${{ inputs.customer_name }} Demo Space!"
  ```

- [x] Add story creation from inline content or JSON files.
- [x] Add deterministic component insertion into array fields (`stories.insert_component`).

  ```yaml
  stories:
    insert_component:
      - slug: home
        target:
          path: content.body
          index: 1
        component:
          component: banner-section
          title: "Special offer for ${{ inputs.customer_name }}"
  ```

- [ ] Add safe component removal requiring both path and expected component.

  ```yaml
  stories:
    remove_component:
      - slug: home
        target:
          path: content.body[3]
          component: newsletter-form-section
  ```

- [x] Add story publishing and unpublishing (`stories.publish`, `stories.unpublish`; unpublish runs with the removals).
- [x] Support nested object field merge for component fields, including partial asset field updates.
- [ ] Add standard demo parameters for customer logo, colors, contact details, navigation, and homepage content.
- [x] Assert that required stories and components exist in the duplicated template (`assert`).

## Priority 4: Assets

- [x] Add asset upload from local directories.

  ```yaml
  assets:
    upload_directory:
      - source: ./demo-assets/brand
        target_folder: Brand
        recursive: true
        include: ["*.svg", "*.png", "*.jpg"]
        on_existing: skip
  ```

- [x] Add asset upload from URLs (`assets.upload_url`).
- [x] Allow uploaded asset references in later sections (`asset: { _ref: customer_logo }`).
- [x] Add asset folder creation for local-directory uploads.
- [ ] Investigate and document asset behavior when duplicating spaces.
- [ ] Add optional asset copying from a template space.
- [ ] Add replacement of template assets with customer-specific assets.
- [ ] Detect broken or cross-space asset references after duplication.

## Priority 5: Localization, Folders, and Dimensions

- [x] Add space language configuration (`space.languages` with add/remove).
- [ ] Add optional AI translation setup and execution.

## Priority 6: Workflows and Governance

- [x] Create and reconcile workflows from config (`workflow.ensure`).
- [x] Create and reconcile workflow stages (position from list order, roles and stage links by name).
- [x] Resolve workflows and stages by name instead of requiring numeric IDs.
- [x] Assign workflow stages to selected stories, not only all unstaged stories (`workflow.assign`).
- [x] Add collaborators by email: `space:collaborator-invite`, `space:collaborator-list`, and `space:collaborator-remove` (CLI and Actions only; see Decisions to Make).
- [x] Add custom roles and permissions: `roles` section in `space:setup`, plus `space:role-list`, `space:role-ensure`, and `space:role-delete`.
- [x] Configure folder/path restrictions for regional editors: `allowed_paths` and `blocked_paths` by full slug or ID (Elite plan).
- [ ] Verify in the UI whether a folder in `allowed_paths` also covers the stories inside it, then update the docs.
- [ ] Verify what the API does when an email with a pending invitation is invited again.
- [ ] Reconcile the other space role properties (field, component, datasource, asset folder, and branch permissions).
- [ ] Support demo personas such as editor, reviewer, publisher, and market editor (custom roles cover the role side; assigning users stays outside `space:setup`).

## Priority 7: Datasources and Integrations

- [x] Create and reconcile datasources and entries (`datasources`, with dimensions and CSV entries).
- [ ] Create and reconcile webhooks.
- [ ] Configure deployment/build hooks.
- [ ] Configure releases when supported.
- [ ] Add integration-specific setup sections where the Management API supports them.
- [ ] Support environment variables for integration secrets.

## Priority 8: Space Lifecycle

- [ ] Add general space settings management.
- [ ] Add cleanup/expiration metadata: `cleanup.delete_after: "7 days"`.
- [ ] Add a command to list expired demo spaces.
- [ ] Add an explicit cleanup command with safety checks.
- [ ] Add naming conventions for daily demos.
- [ ] Add duplicate-name detection.
- [ ] Add optional ownership and organization validation before duplication.
- [ ] Record who provisioned the demo and when.

## Priority 9: Template Composition

- [x] Allow one setup config to extend another (`extends`).

  ```yaml
  extends:
    - base-demo.yaml
    - retail-demo.yaml
  ```

- [x] Define predictable merge rules for maps, lists, and keyed resources (documented in `space-setup-config.md`).
- [ ] Allow reusable named profiles such as localization, commerce, governance, and AI.
- [x] Add conditional sections based on parameters (`extends` entries with `when`).
- [ ] Add plan-aware or capability-aware conditional steps (`assert.plan_at_least` checks the plan; conditional steps are still open).
- [ ] Add reusable setup hooks only when a declarative operation is not practical.

## Priority 10: Developer Experience

- [x] Generate a config from an existing space: `space:setup-export -o space.yaml` (components, datasources, roles, workflows).
- [ ] Add examples for basic branded, multilanguage, multimarket, workflow, governance, and AI demos (governance: `examples/governance-space.yaml`).
- [ ] Add concise progress output and a final summary table.
- [ ] Add verbose/debug output for API troubleshooting.
- [ ] Add setup duration and per-step timing.
- [x] Add documentation for known Storyblok API limitations: [management-api-behavior.md](management-api-behavior.md) (keep it updated as new endpoints are added).

## Decisions to Make

- [x] Store duplication settings in config for `space:setup`; keep CLI options on `space:create`.
- [x] Support explicit blank-space creation with `space.create_new: true`.
- [x] Make reconcile the default execution mode.
- [ ] Define how destructive operations are represented and confirmed.
  - [x] Removals run at the end of `space:setup`, after every create and update (`space.languages.remove` moved there). A removal that must run first goes in a separate run with its own config.
  - [ ] Declare removals explicitly per section (`remove` lists, exact references only, no prune mode).
  - [ ] Require a CLI confirmation flag for real runs with removals (name to decide, e.g. `--allow-destructive`); dry runs list them as destructive with their impact.
  - [ ] Record removed objects in the JSON report.
  - [ ] Decide whether single delete commands need `--force` together with `-n`.
- [x] Keep collaborator invitations out of `space:setup` configs: invitations send real emails, and pending invitations are not returned by the collaborators list, so reruns could not skip them.
- [x] Define whether unresolved variables fail setup or resolve to empty strings: unresolved variables fail setup.
- [ ] Define whether IDs are permitted in portable templates or names/slugs are required.
- [ ] Define how config secrets are masked in logs and reports.
- [ ] Define a stable compatibility policy for config schema versions.

## Current Supported Sections

- [x] Preview default URL and frontend environments
- [x] Recursive namespaced expressions for `inputs`, `env`, and `space` contexts
- [x] Native type preservation for full-value expressions
- [x] Setup input defaults, required values, and repeatable `--set` overrides
- [x] Demo-mode removal
- [x] Assign default or configured workflow stage to unstaged stories
- [x] Create stories from inline content or JSON content files
- [x] Update existing story component fields by deterministic path and expected component
- [x] App installation by slug
- [x] App installation by structured slug/ID reference
- [x] Storyblok AI activation and organization configuration inheritance
- [x] AI Translations disclaimer acceptance
- [x] Component field additions
- [x] Folder reconciliation by full slug and parent
- [x] Selected root-content moves
- [x] Dimensions folder reconciliation
- [x] Global Asset Library conversion with `assets.convert_to_global`
- [x] Story tag assignments by slug or ID
- [x] Custom space roles with permissions, allowed and blocked paths by full slug or ID, and allowed languages
- [x] Custom workflows and workflow stages with `workflow.ensure`
- [x] Story publishing and unpublishing by slug, ID, or folder
- [x] Component insertion into story list fields with `stories.insert_component`
- [x] Datasources, dimensions, and entries with `datasources`
- [x] Components with folder and fields with `components.ensure`
- [x] Export of an existing space as a setup config with `space:setup-export`
- [x] Config composition with `extends`
- [x] Template checks with `assert`
- [x] Existing-space setup with `-S`
- [x] Configuration-driven blank-space creation with `space.create_new: true`
- [x] Configuration-driven duplicate-first setup with `space.duplicate_from`
- [x] YAML and JSON config loading
- [x] Draft 2020-12 JSON Schema validation for YAML and JSON configs
- [x] Standalone `space:setup-validate` command
- [x] Editor autocomplete and validation through YAML Language Server schema reference
- [x] Basic dry-run mode
- [x] Complete duplicate-first dry-run planning with target-space placeholders
- [x] Shared compact operation reporting for dry-run and real execution
- [x] Preview-token masking in rendered setup URLs
- [x] Global and app-specific continue-on-error behavior
