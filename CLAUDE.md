# blokctl

An opinionated, unofficial CLI tool and PHP library for managing [Storyblok](https://www.storyblok.com/) spaces. Configure spaces, shape components, manage stories, set preview URLs, install apps, assign workflows and tags — from the command line or from PHP code.

## Requirements

- PHP 8.3+
- A `.env` file with `SECRET_KEY` (Storyblok Personal Access Token)

## Safety

Use a **test user** with a **test Personal Access Token** that only has access to a **test space**. Do not use production credentials or spaces.

## Skills

| Skill | Use when... |
|---|---|
| `/blokctl-cli` | You want to **run blokctl commands** to manage a Storyblok space from the terminal |
| `/blokctl-api` | You want to **use blokctl Action classes** from your own PHP code (Laravel, Symfony, scripts) |
| `/blokctl-dev` | You want to **add features or fix bugs** in the blokctl codebase |

## Quick start

```bash
cp .env.example .env          # add your SECRET_KEY
php bin/blokctl list           # see all commands
php bin/blokctl space:info -S <space-id>
```
