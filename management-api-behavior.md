# Storyblok Management API: Verified Behavior

Notes for developers who automate Storyblok with the Management API, with or without blokctl.

The official API reference documents endpoints and properties. It does not always document response codes, partial updates, defaults, or side effects. This page collects behavior we verified with live requests while building blokctl, plus gaps and inconsistencies in the docs.

- **Verified** means tested with real requests against a space on the Elite plan, on the date shown.
- **Not documented** means neither the docs nor our tests answer the question yet.

Behavior can change. If something here no longer matches, please open an issue.

## General

### Empty responses on update and delete

Verified 2026-10-31. Updating or deleting a workflow or workflow stage returns `204 No Content` with an empty body. Creating returns `201` with the created object.

With the PHP Management API client, calling `toArray()` or `data()` on a `204` response throws a `JsonException` ("Response body is empty"). Check `isOk()` and, if you need the new state, read the resource again.

### Validation errors (422)

Verified 2026-10-31. Validation errors return `422 Unprocessable Entity` with field-level messages in the body, for example:

```json
{"name": ["has already been taken"], "color": ["has already been taken"]}
```

The PHP Management API client does not show this body. `getErrorMessage()` only looks for an `error` key and, for 4xx responses, falls back to a generic text ("The request cannot be processed because it is invalid, for example, due to a missing required parameter or a duplicate key."). To read the details, use the underlying HTTP response:

```php
$response = $api->makeHttpRequest('POST', '/v1/spaces/' . $spaceId . '/workflow_stages', ['json' => $payload]);
$response->getStatusCode();     // 422
$response->getContent(false);   // raw body, without throwing on 4xx
```

### JSON request bodies

The generic `post()` and `put()` methods of the PHP client do not send nested arrays as a JSON body. For endpoints with nested bodies (space roles, collaborators, workflows), send JSON explicitly:

```php
$api = new \Storyblok\ManagementApi\Endpoints\ManagementApi($client);
$api->makeRequest('PUT', '/v1/spaces/' . $spaceId . '/space_roles/' . $roleId, [
    'json' => ['space_role' => ['role' => 'Editors - North', 'permissions' => ['read_stories']]],
]);
```

### Partial updates

Verified for space roles (2026-10-30), workflows, and workflow stages (2026-10-31). A `PUT` that sends only some properties keeps the others. You don't need to send the full object to change one field.

## Stories and folders

### Full slugs of folders and start pages

A folder and its start page share the same path. The folder's `full_slug` is `products`; the start page's `full_slug` is `products/`, with a trailing slash. Match exactly when you resolve a slug to an ID, or you may get the start page instead of the folder.

## Space roles

Endpoint: `/v1/spaces/:space_id/space_roles`. Request bodies are wrapped as `{"space_role": {...}}`.

| Behavior | Status |
|---|---|
| `allowed_paths` accepts folder IDs | Verified 2026-10-30 |
| The response adds `resolved_allowed_paths` with full slugs; a folder is shown with a trailing slash (`products/`) | Verified 2026-10-30 |
| A partial `PUT` keeps the properties it does not send | Verified 2026-10-30 |
| `allowed_paths` and the other folder, field, and language restrictions require the Elite plan | Documented |
| Whether a folder in `allowed_paths` also gives access to the stories inside it | Not documented, to be verified |
| What happens to collaborators who still have a role when it is deleted | Not documented |

The list endpoint supports `search` (by name) and `by_ids`. Pagination is not documented.

## Collaborators

Endpoint: `/v1/spaces/:space_id/collaborators`.

### Adding a collaborator

`POST /v1/spaces/:space_id/collaborators/` with `email` and `role`. For a custom role, send the role ID as a string in `role` and as a number in `space_role_id`:

```json
{"email": "editor@example.com", "role": "236350497967503", "space_role_id": 236350497967503}
```

Verified 2026-10-30. The result depends on whether the email already has a Storyblok account:

- **Existing user:** the user is added to the space. The response has a `user` object.
- **No account:** the response is `201` with `"user": null` and an `invitation` object (`email`, `expires_at` seven days later, `is_expired`, `rid`). This is a pending invitation.

Not documented: what happens when you invite an email that already has a pending invitation.

### What the collaborators list does not return

Verified 2026-10-30. `GET /v1/spaces/:space_id/collaborators` does **not** return:

- **The space owner.**
- **Pending invitations.** You can't detect them by email in the list, so a script that skips existing collaborators will invite pending emails again on a second run. Keep the collaborator `id` from the create response if you need to remove the invitation later.

User emails are in `user.real_email`. The list supports `page` and `per_page` (maximum 100).

### Changing a role and removing a collaborator

- **Change a role:** `PUT /v1/spaces/:space_id/collaborators/:collaborator_id` with the body wrapped as `{"collaborator": {"role": ..., "space_role_id": ...}}`. Verified 2026-10-31 for a custom role.
- **Remove a collaborator:** `DELETE /v1/spaces/:space_id/collaborators/:collaborator_id`. The docs also allow an SSO ID in place of the collaborator ID.

## Workflows

Endpoint: `/v1/spaces/:space_id/workflows`. Request bodies are wrapped as `{"workflow": {...}}`.

| Behavior | Status |
|---|---|
| A content type can belong to only one workflow; a second workflow with the same content type returns `422` | Verified 2026-10-31 |
| A new custom workflow has no stages; create them yourself | Verified 2026-10-31 |
| The default workflow has `content_types: []` and applies to every content type without its own workflow | Verified 2026-10-31 |
| The default workflow cannot be deleted | Documented |
| Deleting a workflow also deletes its stages, with a short delay (a list right after the delete can still show them) | Verified 2026-10-31 |
| `POST /workflows/:workflow_id/duplicate` copies the stages; name and content types must differ from the original | Documented |
| The response includes `per_language_stages` (boolean), which the docs don't describe | Verified 2026-10-31 |

## Workflow stages

Endpoint: `/v1/spaces/:space_id/workflow_stages`. Request bodies are wrapped as `{"workflow_stage": {...}}`. The list supports `in_workflow` (workflow ID), `search`, `by_ids`, and `exclude_id`.

| Behavior | Status |
|---|---|
| If `allow_all_users` and `allow_all_stages` are not sent on create, both are `true`. A stage with `space_role_ids` or `workflow_stage_ids` therefore needs `allow_all_users: false` or `allow_all_stages: false` for the restriction to apply | Verified 2026-10-31 |
| Only one default stage per workflow. Setting `is_default: true` while another stage is the default returns `422`. Unset the old default first, then set the new one | Verified 2026-10-31 |
| Two stages in the same workflow cannot have the same color (`422`). Names must also be unique | Verified 2026-10-31 (color), documented (name) |
| A stage created without `workflow_id` goes to the default workflow | Documented |
| Stage order is set with `position` | Documented |
| The response includes `story_editing_locked`, `allow_none_for_next_stages`, and `auto_remove_assignee`, which the docs don't describe | Verified 2026-10-31 |

Docs inconsistency: the pages for getting, updating, and deleting a single stage name the path parameter `:workflow_id`, but it is the stage ID (`/workflow_stages/:workflow_stage_id`).

Who can move a story to the next stages: `user_ids`, `space_role_ids`, `allow_all_users`, `allow_admin_change`, `allow_editor_change`. Which stages come next: `workflow_stage_ids` or `allow_all_stages`. Publishing: `allow_publish`, `allow_admin_publish`, and `after_publish_id` (the stage assigned after publishing).

## How blokctl handles this

- Space roles and workflow changes send JSON bodies with `makeRequest()`.
- Updates send only changed fields and treat a `204` as success without parsing the body.
- `space:collaborator-invite` skips current collaborators and reports pending invitations; `space:collaborator-remove --id` can remove a pending invitation.
- `space:role-delete` lists the collaborators who still have the role before deleting it.
