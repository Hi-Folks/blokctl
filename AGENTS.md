# AI assistant instructions

When a user asks you to design or apply a Storyblok space setup, follow
[`docs/ai-space-setup-workflow.md`](docs/ai-space-setup-workflow.md).

The YAML or JSON setup file is the reviewable source of truth. Inspect spaces with
read-only commands, generate or edit the config, validate it, and show a dry-run plan.
Never run a mutating `space:setup` without the user's explicit approval and an exact
numeric space ID (unless the reviewed config creates or duplicates a new space).

For repository development commands and architecture, see [`CLAUDE.md`](CLAUDE.md).
