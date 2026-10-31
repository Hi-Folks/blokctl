# Author a deterministic Storyblok space setup

Follow `docs/ai-space-setup-workflow.md` to turn the user's requirements into a
reviewable `space:setup` YAML file.

Inspect the target or source space only with read-only commands. Require an exact
numeric space ID for an existing target. Validate with `space:setup-validate --json`
(and `-S` when existing component definitions are needed), repair all errors, then
produce a `space:setup --dry-run --report` plan. Show the config and plan to the user.
Do not run the real setup until the user explicitly approves it.
