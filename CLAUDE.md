# blokctl

An unofficial Storyblok automation toolkit providing a command-line interface, reusable PHP API, and Configuration as Code provisioning. It manages spaces, components, stories, preview URLs, apps, workflows, tags, experiments, and repeatable demo-space setup.

## Requirements

- PHP 8.4.1+
- A `.env` file with `SECRET_KEY` (Storyblok Management API access token; prefer a scoped Personal Access Token)

## Safety

Use a **test user** with a **scoped test Personal Access Token** that only has the required Management API permissions and access to a **test space**. Do not use production credentials or spaces.

## Skills

| Skill | Use when... |
|---|---|
| `/blokctl-cli` | You want to **run blokctl commands**, create spaces, or provision spaces from YAML/JSON |
| `/blokctl-api` | You want to **use blokctl Action classes** from your own PHP code (Laravel, Symfony, scripts) |
| `/blokctl-dev` | You want to **add features or fix bugs** in the blokctl codebase |

## Quick start

```bash
cp .env.example .env          # add your SECRET_KEY
php bin/blokctl list           # see all commands
php bin/blokctl space:info -S <space-id>
php bin/blokctl space:setup-validate --config examples/demo-space.yaml
```

For Configuration as Code provisioning, see `space-setup-config.md`, `space-setup-schema.json`, and `examples/demo-space.yaml`.
