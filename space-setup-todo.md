# Space Setup TODO

Roadmap for turning `space:setup` into a reliable, reusable provisioning system for daily customer demos.

Related documentation:

- [Space setup configuration](space-setup-config.md)
- [Example provisioning config](examples/demo-space.yaml)

## Immediate Fixes

- [ ] Apply variable substitution recursively to every config string, not only preview URLs.
- [ ] Make `--dry-run --duplicate-from` show the complete post-duplication setup plan.
- [ ] Detect conflicting options such as `--demo` with `demo_mode.remove: true`.
- [ ] Validate the complete config before creating or modifying a space.
- [ ] Improve validation errors with the YAML/JSON property path, for example `components.fields[0].type`.
- [ ] Make setup output distinguish `created`, `updated`, `unchanged`, `skipped`, and `failed`.
- [ ] Return a non-zero exit code when individual actions report errors unless explicitly configured otherwise.
- [ ] Add focused tests for every supported config section.

## Priority 1: Schema, Validation, and Template Definition

- [ ] Add required config schema version: `version: 1`.
- [ ] Create `space-setup-schema.json` using JSON Schema Draft 2020-12.
- [ ] Use the same JSON Schema to validate both YAML and JSON setup files.
- [ ] Add `additionalProperties: false` to detect unsupported and misspelled properties.
- [ ] Validate the complete config before duplicating, creating, or modifying a space.
- [ ] Report validation errors with precise property paths, for example `components.fields[0].type`.
- [ ] Add the YAML Language Server schema reference to example YAML files:

  ```yaml
  # yaml-language-server: $schema=../space-setup-schema.json
  ```

- [ ] Add editor autocomplete and inline validation through the JSON Schema.
- [ ] Add optional template metadata: name, description, category, owner, and tags.
- [ ] Add runtime parameters with required values, defaults, and secret/masked values.
- [ ] Add repeatable CLI overrides: `--set customer_name=Acme`.
- [ ] Support parameter placeholders such as `{{ customer_name }}`.
- [ ] Add a config validation command:

  ```bash
  php bin/blokctl space:setup-validate --config demo-space.yaml
  ```

## Priority 2: Idempotency and Reliability

- [ ] Add execution settings:

  ```yaml
  execution:
    mode: reconcile
    retries: 3
    timeout_seconds: 300
    continue_on_error: false
  ```

- [ ] Make every setup step idempotent.
- [ ] Skip apps that are already installed.
- [ ] Skip component fields that already match the requested definition.
- [ ] Update component fields when requested configuration differs.
- [ ] Reconcile preview environments without unintentionally replacing existing entries.
- [ ] Skip tags already assigned to stories.
- [ ] Reuse folders and datasources that already exist.
- [ ] Add retry handling for rate limits and temporary API failures.
- [ ] Add readiness polling after duplication before applying setup.
- [ ] Detect and report pending duplication/background tasks.
- [ ] Produce a machine-readable setup report with space ID, URLs, token, successful steps, skipped steps, and failures.
- [ ] Define rollback behavior when duplication succeeds but setup fails.

## Priority 3: Customer Personalization

- [ ] Add story field updates by story slug or ID.

  ```yaml
  stories:
    update:
      - slug: site-config
        fields:
          company_name: "{{ customer_name }}"
          primary_color: "{{ primary_color }}"
      - slug: home
        fields:
          headline: "Welcome to {{ customer_name }}"
  ```

- [ ] Add story publishing and unpublishing.
- [ ] Add story creation from inline content or JSON files.
- [ ] Add story moving and folder assignment.
- [ ] Support nested content field updates.
- [ ] Add standard demo parameters for customer logo, colors, contact details, navigation, and homepage content.
- [ ] Assert that required stories and components exist in the duplicated template.

## Priority 4: Assets

- [ ] Add asset upload from local files and URLs.

  ```yaml
  assets:
    upload:
      - key: customer_logo
        source: "{{ env.CUSTOMER_LOGO }}"
        folder: Brand
  ```

- [ ] Allow uploaded asset references in later sections, for example `{{ assets.customer_logo }}`.
- [ ] Add asset folder creation.
- [ ] Investigate and document asset behavior when duplicating spaces.
- [ ] Add optional asset copying from a template space.
- [ ] Add replacement of template assets with customer-specific assets.
- [ ] Detect broken or cross-space asset references after duplication.

## Priority 5: Localization, Folders, and Dimensions

- [ ] Add space language configuration.
- [ ] Add folder creation by name and parent.
- [ ] Add declarative story/folder moves.
- [ ] Move the behavior from `examples/multi-country-demo-setup.php` into `space:setup`.
- [ ] Add Dimensions app configuration.

  ```yaml
  dimensions:
    enabled: true
    root_folders:
      - name: Global
      - name: Italy
        ai_translation_code: it
      - name: Germany
        ai_translation_code: de
    move_root_content_to: Global
    exclude_slugs: [error-404, site-config]
  ```

- [ ] Add optional AI translation setup and execution.

## Priority 6: Workflows and Governance

- [ ] Create and reconcile workflows from config.
- [ ] Create and reconcile workflow stages.
- [ ] Resolve workflows and stages by name instead of requiring numeric IDs.
- [ ] Assign workflow stages to selected stories, not only all unstaged stories.
- [ ] Add collaborators by email.
- [ ] Add custom roles and permissions.
- [ ] Configure folder/path restrictions for regional editors.
- [ ] Support demo personas such as editor, reviewer, publisher, and market editor.

## Priority 7: Datasources and Integrations

- [ ] Create and reconcile datasources and entries.
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

- [ ] Allow one setup config to extend another.

  ```yaml
  extends:
    - base-demo.yaml
    - retail-demo.yaml
  ```

- [ ] Define predictable merge rules for maps, lists, and keyed resources.
- [ ] Allow reusable named profiles such as localization, commerce, governance, and AI.
- [ ] Add conditional sections based on parameters.
- [ ] Add plan-aware or capability-aware conditional steps.
- [ ] Add reusable setup hooks only when a declarative operation is not practical.

## Priority 10: Developer Experience

- [ ] Generate a starter config: `space:setup-init --output demo-space.yaml`.
- [ ] Add examples for basic branded, multilanguage, multimarket, workflow, governance, and AI demos.
- [ ] Add concise progress output and a final summary table.
- [ ] Add verbose/debug output for API troubleshooting.
- [ ] Add setup duration and per-step timing.
- [ ] Add documentation for known Storyblok API limitations.

## Decisions to Make

- [ ] Decide whether creation/duplication settings belong only in CLI options or may also live in config.
- [ ] Decide whether `space:setup` should support blank-space creation in addition to duplication.
- [ ] Decide whether reconcile is the default mode or must be explicitly enabled.
- [ ] Define how destructive operations are represented and confirmed.
- [ ] Define whether unresolved variables fail setup or resolve to empty strings.
- [ ] Define whether IDs are permitted in portable templates or names/slugs are required.
- [ ] Define how config secrets are masked in logs and reports.
- [ ] Define a stable compatibility policy for config schema versions.

## Current Supported Sections

- [x] Preview default URL and frontend environments
- [x] Preview URL placeholders: `{{ space_id }}`, `{{ preview_token }}`, and `{{ env.NAME }}`
- [x] Demo-mode removal
- [x] Assign default or configured workflow stage to unstaged stories
- [x] App installation by slug
- [x] Component field additions
- [x] Story tag assignments by slug or ID
- [x] Existing-space setup with `-S`
- [x] Duplicate-first setup with `--duplicate-from`
- [x] YAML and JSON config loading
- [x] Basic dry-run mode
- [x] Global and app-specific continue-on-error behavior
