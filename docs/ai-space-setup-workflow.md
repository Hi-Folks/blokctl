# Authoring Storyblok space setups with an AI assistant

Use this workflow when a user describes the components, content, assets, governance,
or initial configuration they want in a Storyblok space. The assistant writes a
reviewable YAML file; `blokctl` validates and executes it deterministically.

## Safety rules

- Ask for the exact numeric space ID before targeting an existing space. Do not infer
  it from examples, names, prior output, or another environment.
- Use read-only inspection first. Useful commands include `space:setup-export`,
  `components:list`, `component:show`, `story:show`, `datasources:list`,
  `space:role-list`, and `workflows:list`.
- Do not place management tokens or other secrets in the setup file. Declare each
  runtime secret as an input with `secret: true` and `from_env: VARIABLE_NAME`, then
  reference `${{ inputs.NAME }}`. Do not use a direct `${{ env.NAME }}` expression for
  a secret because blokctl cannot infer that it must be masked. Leave exported secret
  placeholders for the user to fill through their secret manager or environment.
- Never put a secret in `--set NAME=value`: blokctl masks its own output, but the shell
  can retain the command in history or expose it in the process list.
- Never apply a setup until the user has reviewed the generated config and dry-run
  plan and explicitly approved the real run.

## Workflow

1. Clarify the desired outcome, target region, and whether the target is an exact
   existing space, a new blank space, or a duplicate. Ask only for choices that alter
   the resulting config.
2. Inspect an existing or source space read-only. Prefer `space:setup-export` when a
   reusable baseline is useful. Do not mutate the space during discovery.
3. Write YAML with `version: 1` and the schema comment. Prefer small reusable files
   through `extends`; use `examples/composition/` as a pattern. Add `assert` entries
   for stories, components, plans, or apps on which the setup depends. Add optional
   `template` metadata so people and future assistants can identify the setup's purpose,
   category, owner, and capabilities without confusing it with `space.name`.
4. Validate and repair in a loop using machine-readable output:

   ```bash
   php bin/blokctl space:setup-validate --config setup.yaml --json
   ```

   When content uses components already present in an existing space, include the
   exact ID so field names and types are checked against that space (read-only):

   ```bash
   php bin/blokctl space:setup-validate -S 123456 --config setup.yaml --json
   ```

   Use `--print-merged --json` to inspect the merged `config` value for an `extends`
   setup. Fix every error. Explain any unchecked-component warning before continuing.
5. Generate a no-change execution plan and show the important operations:

   ```bash
   php bin/blokctl space:setup -S 123456 --config setup.yaml \
     --dry-run --report setup-plan.json
   ```

6. Ask for explicit approval. Only then run the same command without `--dry-run`,
   retaining `--report` for an auditable result. Report failures and do not silently
   retry with broader or destructive configuration.

## Authoring patterns

- Declare new components and their fields in `components.ensure`; story content is
  checked against these definitions before setup changes a space.
- Refer to assets from `assets.upload_url` with `asset: { _ref: logical_name }`.
- Use `stories.insert_component` for idempotent list insertion and `stories.update`
  for deterministic component-path updates.
- Put reusable components, datasources, roles, and workflows in library files. A
  customer file should mostly contain inputs, `extends`, assertions, and differences.
- Use conditional `extends` entries for variants instead of copying full setups.

The complete syntax is in [`space-setup-config.md`](../space-setup-config.md), reusable
composition is covered by [`space-setup-reusable-configs.md`](../space-setup-reusable-configs.md),
and runnable patterns live under [`examples/`](../examples/).
