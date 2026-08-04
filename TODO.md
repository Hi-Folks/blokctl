# blokctl TODO

## Fix: `customize_toolbar` is ignored when a component field is *created*

**Priority:** Medium (correctness bug in shipped `space:setup` feature)
**Area:** `space:setup` component field reconciliation

### Problem

`customize_toolbar` is only applied on the **update** path (when the field already
exists on the component). On the **create** path (field does not yet exist) the value
is silently dropped, so a fresh field is created without the toolbar setting.

The value only takes effect if the field already exists, or on a second `space:setup`
run once the field has been created.

`examples/demo-space.yaml` triggers this: `article-page.text` (richtext,
`customize_toolbar: false`) will be created without the setting on a first run.

### Evidence (source lines)

- `src/SpaceSetup/SpaceSetupProvisioner.php:1563-1575` — `reconcileComponentField()`
  create branch calls `ComponentFieldAddAction::execute()` passing only
  `fieldType, pos, displayName, required, translatable`. Does **not** pass
  `customize_toolbar`.
- `src/Action/Component/ComponentFieldAddAction.php:81-92` — `execute()` has no
  `customize_toolbar` parameter to receive it.
- `src/SpaceSetup/SpaceSetupProvisioner.php:1622-1655` — `applyDeclaredFieldProperties()`
  (update path) *does* handle `customize_toolbar` (alias map at `:1631`, boolean
  coercion at `:1644`). This is why the update path works and the create path does not.

### Implementation steps

1. **`src/Action/Component/ComponentFieldAddAction.php`**
   - Add parameter `?bool $customizeToolbar = null` to `execute()` (after
     `bool $translatable = false`).
   - Pass it into `makeField(...)`.
   - In `makeField()`, set `customize_toolbar` on the field array only when the value
     is not null (keep parity with how nullable props are handled — do not force a
     default, so "declared-only" semantics match the update path).

2. **`src/SpaceSetup/SpaceSetupProvisioner.php` — `reconcileComponentField()` (create branch, ~line 1570)**
   - Forward the declared value:
     ```php
     customizeToolbar: array_key_exists('customize_toolbar', $field)
         ? $this->boolValue($field['customize_toolbar'])
         : null,
     ```
   - Rationale for the `array_key_exists` guard: only send the setting when the user
     declared it, matching the update path (`applyDeclaredFieldProperties` only acts on
     declared keys).

### Tests to add

- **`tests/Unit/SpaceSetup/SpaceSetupProvisionerTest.php`**
  - New test `creates_component_field_with_toolbar_customization()`: mock a component
    whose schema does **not** contain the target field, run setup with
    `customize_toolbar: false`, assert the create request payload carries
    `component.schema.<field>.customize_toolbar === false`.
  - Mirror the existing `updates_component_field_toolbar_customization()`
    (~`SpaceSetupProvisionerTest.php:1394`) which already covers the update path.

- **`tests/Unit/Action/Component/ComponentFieldAddActionTest.php`**
  - Add a case asserting `customize_toolbar` reaches the built field when the new
    parameter is passed, and is absent when it is null.

### Docs / schema polish (do together with the fix)

- **`space-setup-schema.json:1001-1004`** — `customize_toolbar` description omits the
  "existing fields change only when declared" note that `required` (`:992`) and
  `translatable` (`:997`) carry. Align the wording once create-path behavior is fixed.

### Verification

- `composer run all-checks` (lint, cs-fixer, phpstan, rector, phpunit).
- Optionally exercise against a test space:
  `php bin/blokctl space:setup -S <test-space-id> --config examples/demo-space.yaml`
  on a component where `article-page.text` does not yet exist, then confirm the
  richtext field is created with toolbar customization disabled.

### Out of scope / already verified (do not re-investigate)

- `asset._find` is **not** a gap — it is a documented, intentional freeform-content
  feature (`README.md:266,284`; `space-setup-config.md:541,565`) living inside
  `additionalProperties: true` regions. No schema change needed.
- The `space` block (`create_new`, `duplicate_from`, `in_org`, `demo`, `readiness`) is
  handled in `SpaceSetupCommand`, not `SpaceSetupProvisioner::run()`. Not a drift.
- `assets.upload_directory[].on_existing` is schema-only/inert but its `const: "skip"`
  matches actual behavior. Not worth changing.
