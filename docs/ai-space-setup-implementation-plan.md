# AI-Assisted Storyblok Space Setup: Implementation Plan

## Vision

Let a user describe a Storyblok space in natural language, then use an AI assistant
to produce a reviewable YAML setup. `blokctl` remains the deterministic engine that
validates, plans, reconciles, executes, and reports the setup.

The core contract is:

> AI translates intent into a declarative plan; `blokctl` validates and executes
> that plan deterministically.

The AI must not bypass the YAML by issuing an ad hoc sequence of mutations.

## Responsibilities

| Layer | Responsibility |
|---|---|
| User | Describes the desired space, reviews assumptions, and approves execution |
| AI skill | Clarifies intent, inspects safely, authors YAML, repairs errors, and explains the plan |
| YAML | Reviewable, portable, version-controlled source of truth |
| `blokctl` | Validates, plans, reconciles, masks secrets, executes, and reports |
| Storyblok | Target platform |

## Target user journey

1. The user describes the components, content, assets, languages, governance, and
   integrations they need.
2. The assistant asks only questions whose answers materially change the setup.
3. The assistant searches reusable templates and, when relevant, inspects a source
   or existing space using read-only commands.
4. The assistant generates a small YAML composition and any supporting content or
   asset files.
5. The assistant validates the setup using machine-readable output and repairs all
   errors.
6. The assistant runs a no-change dry run and presents the important operations,
   warnings, assumptions, and plan requirements.
7. The user reviews the YAML and dry-run plan and explicitly approves execution.
8. The assistant runs the reviewed configuration without `--dry-run` and retains a
   JSON report.
9. The assistant reports the target space ID, outcomes, failures, and safe rerun
   instructions.

The authoritative safety and authoring workflow remains
[`ai-space-setup-workflow.md`](ai-space-setup-workflow.md).

## Product principles

- YAML is always the reviewable source of truth.
- Prefer composition from maintained templates over generating large files from
  scratch.
- Use read-only discovery before authoring for existing or source spaces.
- Require an exact numeric ID for an existing target space.
- Never put secrets in setup files or command-line `--set` values.
- Validate both configuration structure and story/component semantics.
- Always show a dry-run plan before requesting execution approval.
- Require explicit approval immediately before any mutation.
- Keep execution idempotent and preserve a machine-readable audit report.
- Never silently retry with broader, destructive, or less safe configuration.

## Phase 1: Formalize the AI workflow

Create and distribute a checked-in `blokctl-cli` skill for Claude, ChatGPT/Codex,
and other CLI-capable assistants.

The skill should contain:

- Intent-discovery questions.
- Target-mode rules for an existing space, new blank space, or duplicated space.
- Read-only inspection commands.
- Template discovery and composition guidance.
- YAML authoring and secret-handling rules.
- The validation and repair loop.
- Dry-run summarization and approval protocol.
- Execution, reporting, partial-failure, and recovery rules.
- Examples of correct and unsafe behavior.

### Phase 1 acceptance criteria

- A fresh assistant session can follow the workflow without undocumented project
  knowledge.
- Every mutating route reaches an explicit approval gate.
- The skill handles all three target modes.
- The skill has a documented installation and update mechanism.
- At least three end-to-end examples cover blank, duplicate, and existing spaces.

## Phase 2: Strengthen the AI-facing CLI contract

Build on the existing `--json`, `--print-merged`, `--dry-run`, and `--report`
interfaces so assistants never need to scrape human-oriented terminal output.

### Stable diagnostics

Add stable validation codes alongside paths, messages, and suggested repairs:

```json
{
  "code": "UNKNOWN_COMPONENT",
  "path": "stories.create[0].content.body[1].component",
  "message": "Component 'promo-card' is not declared or present",
  "suggestion": "Declare it under components.ensure"
}
```

### Capability manifest

Consider a machine-readable command such as:

```bash
php bin/blokctl space:setup-capabilities --json
```

It should identify the supported schema versions, sections, target modes, field
types, destructive operations, plan requirements, and known unsupported features.

### Machine-readable planning

Ensure dry-run reports expose stable operation types, resource identifiers, planned
actions, reasons, dependencies, warnings, required plans, and a destructive flag.

### Failure classification

Distinguish invalid configuration, missing input, failed assertion, unsupported
plan, authentication/permission failure, API failure, and partial execution failure.

### Phase 2 acceptance criteria

- Every actionable failure identifies a YAML path and stable category.
- Schema and capability versions are included in reports.
- All dry-run information needed by an assistant is available as structured data.
- No secret value can appear in validation, planning, failure, or report output.

## Phase 3: Curated template library

Make AI authoring composition-first. Suggested organization:

```text
templates/
|-- foundations/
|   |-- blank-marketing.yaml
|   |-- blank-commerce.yaml
|   `-- duplicated-demo.yaml
|-- components/
|   |-- marketing.yaml
|   |-- editorial.yaml
|   `-- commerce.yaml
|-- capabilities/
|   |-- localization.yaml
|   |-- multimarket.yaml
|   |-- governance.yaml
|   `-- ai.yaml
|-- content/
|   |-- starter-marketing.yaml
|   `-- starter-commerce.yaml
`-- examples/
    |-- branded-website.yaml
    |-- multilingual-retail.yaml
    `-- governed-editorial.yaml
```

Each maintained template should have metadata, documented inputs, plan assertions,
capability tags, an owner, an example invocation, tests, and no embedded secrets.

Initial template priorities:

1. Basic branded marketing site.
2. Multilingual site.
3. Multimarket site.
4. Retail/commerce demo.
5. Editorial governance workflow.
6. AI-enabled demo.

### Phase 3 acceptance criteria

- Common requests generate a short entry YAML using `extends`.
- Shared fixes require changing only the maintained library file.
- Templates are discoverable through `template:find`.
- Every template validates in CI and has dry-run coverage.

## Phase 4: Close high-value provisioning gaps

Prioritize missing primitives according to real evaluation failures and user demand:

- Standard branding inputs: logo, colors, company name, and contact details.
- Starter navigation and homepage content.
- Safe customer-asset replacement.
- Detection of broken or cross-space assets after duplication.
- Additional general space settings.
- Webhooks and build/deployment hooks.
- Reusable named profiles such as localization, commerce, governance, and AI.

Before adding deletion, define one consistent destructive-operation model. Every
destructive operation must require exact resource identity, expected current state,
prominent dry-run classification, and separate explicit confirmation.

## Phase 5: AI evaluation suite

Create realistic prompt fixtures for:

- A blank marketing space.
- A duplicated and rebranded demo.
- A multilingual editorial site.
- Multimarket governance.
- Retail with product datasources.
- Modification of an existing space.
- A feature unavailable on the target plan.
- Secret-backed integration input.
- An ambiguous request.
- A destructive request.
- An attempt to bypass review or approval.

Evaluate intent and safety invariants rather than exact YAML formatting:

- The generated configuration validates.
- Referenced components are present or declared.
- Content conforms to component schemas.
- Secrets use secret inputs backed by environment variables.
- Dependencies are protected by assertions.
- Dry-run succeeds or returns the expected controlled warning.
- No mutation occurs before explicit approval.
- Reapplication is idempotent.
- The requested capability checklist is satisfied.

Initial quality targets:

- At least 90% of representative prompts validate within two repair iterations.
- 100% of mutation attempts pass through the approval gate.
- Zero secrets appear in YAML, logs, errors, or reports.
- A second unchanged execution contains only `SKIPPED` operations.
- Every partial failure produces an actionable report.

## Phase 6: Documentation and distribution

Provide:

- A "Describe your first Storyblok space" tutorial.
- An AI skill installation and update guide.
- A review and approval safety guide.
- Complete transcripts for blank, duplicate, and existing-space workflows.
- Examples of validation repair, partial failure, and safe rerun behavior.

Keep the core skill tool-neutral and add only small adapters where Claude and
ChatGPT/Codex need different installation or invocation instructions.

## Delivery milestones

### Milestone 1: Safe authoring prototype

- Checked-in AI skill.
- Three end-to-end examples.
- Validation, repair, dry-run, and approval workflow.
- Ten evaluation prompts.

### Milestone 2: Reliable composition

- Curated template library.
- Capability manifest.
- Stable validation error codes.
- CI validation for every template.
- Twenty-five evaluation prompts.

### Milestone 3: Production-quality workflow

- Rich structured dry-run reports.
- Asset-integrity safeguards.
- Standard branding and content inputs.
- Documented partial-failure recovery.
- Security and approval-bypass tests.

### Milestone 4: Broader provisioning

- Webhooks and deployment hooks.
- Additional space settings.
- Named profiles.
- Explicit destructive-operation model.
- Remote template catalog only if usage demonstrates a need.

## Definition of done

A user can describe a space in natural language and receive:

1. A valid, readable, minimal YAML configuration.
2. A clear list of assumptions and unresolved inputs.
3. Successful schema and content validation.
4. A deterministic dry-run report.
5. An explicit approval checkpoint.
6. An auditable execution report.
7. An idempotent configuration that can be safely reapplied.

## Start here next session

Begin with Milestone 1:

1. Decide the repository location and distribution format for the `blokctl-cli`
   skill.
2. Turn `ai-space-setup-workflow.md` into executable skill instructions.
3. Add the three canonical example briefs and expected safety invariants.
4. Run the first brief through a clean assistant session.
5. Record friction separately as a skill problem, CLI contract problem, missing
   provisioning primitive, or documentation problem.
6. Fix only what is required to complete the first end-to-end safe workflow.

