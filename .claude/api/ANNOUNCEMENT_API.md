# Announcement API

## Purpose

Internal communication board: announcement categories, announcement approval workflow (draft → pending approval → published/rejected → archived), audience targeting (everyone, role, department, specific employee), single attachment, and read tracking.

## Base Endpoints

```txt
/api/announcement-categories
/api/announcements
```

## Auth Requirement

All announcement endpoints require a Sanctum bearer token.

## Permissions

### Announcement Categories

- View categories: `announcement_categories.view`
- Create category: `announcement_categories.create`
- Update category: `announcement_categories.update`
- Deactivate category: `announcement_categories.deactivate`

### Announcements

- View published announcements (employee scope): `announcements.view`
- View all announcements regardless of status (management scope): `announcements.view_draft`
- Create announcement: `announcements.create`
- Update announcement: `announcements.update`
- Submit for approval: `announcements.submit`
- Cancel a pending submission: `announcements.cancel_submission`
- Approve or reject: `announcements.approve`
- Archive: `announcements.archive`

`admin` is granted the full `announcements` and `announcement_categories` permission groups (via the `groups` key on the `admin` role in `config/hr_permissions.php`) — full control over both categories and the announcement workflow, including create/update/submit/approve/reject/archive.

Default assignments for the other roles (configured in `config/hr_permissions.php`, synced via `RoleSeeder`):

- `hr`: `announcements.view`, `announcements.create`, `announcements.update`, `announcements.submit`, `announcements.cancel_submission`, `announcements.archive`, `announcement_categories.view`
- `ceo`: `announcements.view`, `announcements.approve`, `announcement_categories.view`
- `employee`: `announcements.view`, `announcement_categories.view`

Note `hr` does not have `announcements.approve` — the dual-control rule (creator cannot approve their own announcement) means an HR-created announcement is approved by the `ceo` or `admin`. This same rule applies to `admin`: an admin-created announcement must be approved by someone else (e.g. `ceo`, or another admin). Adjust via the role/permission management endpoints if needed.

There is no separate `announcements.reject` permission — rejection uses `announcements.approve`.

## Endpoint List

- `GET /api/announcement-categories`
- `POST /api/announcement-categories`
- `GET /api/announcement-categories/{announcement_category}`
- `PUT /api/announcement-categories/{announcement_category}`
- `DELETE /api/announcement-categories/{announcement_category}`
- `GET /api/announcements`
- `POST /api/announcements`
- `GET /api/announcements/{announcement}`
- `PUT /api/announcements/{announcement}`
- `POST /api/announcements/{announcement}/submit`
- `POST /api/announcements/{announcement}/cancel-submission`
- `POST /api/announcements/{announcement}/approve`
- `POST /api/announcements/{announcement}/reject`
- `POST /api/announcements/{announcement}/archive`
- `POST /api/announcements/{announcement}/read`

---

## GET /api/announcement-categories

Return a paginated list of announcement categories.

### Query Parameters

- `search`: optional string, filters by category name
- `status`: optional enum, `active` or `inactive`
- `per_page`: optional integer, `1` to `100`

### Response Example

```json
{
  "success": true,
  "message": "Announcement categories fetched successfully.",
  "data": [
    {
      "id": 1,
      "name": "General",
      "description": null,
      "status": "active",
      "created_at": "2026-06-11T00:00:00.000000Z",
      "updated_at": "2026-06-11T00:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

---

## POST /api/announcement-categories

Create an announcement category.

### Request Body

```json
{
  "name": "Finance",
  "description": "Finance related announcements",
  "status": "active"
}
```

### Request Fields

- `name`: required string, max `255`, unique in `announcement_categories`
- `description`: optional nullable string
- `status`: optional enum, `active` or `inactive`, defaults to `active`

---

## GET /api/announcement-categories/{announcement_category}

Return one announcement category.

---

## PUT /api/announcement-categories/{announcement_category}

Update an announcement category.

### Request Body

```json
{
  "name": "Finance",
  "description": "Updated description",
  "status": "active"
}
```

### Request Fields

- `name`: required string, max `255`, unique in `announcement_categories` (ignoring the current record)
- `description`: optional nullable string
- `status`: required enum, `active` or `inactive`

---

## DELETE /api/announcement-categories/{announcement_category}

Deactivate an announcement category (no hard delete). Sets `status` to `inactive`.

### Validation Notes

- Inactive categories cannot be selected when creating or updating an announcement.
- Calling delete on an already-inactive category is a no-op and still returns success.

---

## GET /api/announcements

Return a paginated announcement list. The response shape depends on the requesting user's permissions.

### Query Parameters

- `search`: optional string, matches `title` or `content`
- `category`: optional integer, filters by `category_id`
- `priority`: optional enum, `normal`, `important`, `urgent`
- `per_page`: optional integer, `1` to `100`

Management-only filters (require `announcements.view_draft`):

- `status`: optional enum, `draft`, `pending_approval`, `rejected`, `published`, `archived`
- `created_by`: optional integer, filters by creator user id

Employee-only filter (users without `announcements.view_draft`):

- `read_status`: optional enum, `read` or `unread`

### Access Rules

- Users with `announcements.view_draft` see all announcements regardless of status, eager loaded with `category` and `creator`, sorted newest first.
- Users without `announcements.view_draft` (employees) only see `published` announcements that target them (see Audience Targeting), sorted unread first then newest first.
- Employees must have an employee profile linked to their user account; otherwise the request fails with the "No employee profile is linked to this user account." error.

---

## POST /api/announcements

Create a new announcement in `draft` status.

### Request Body

```json
{
  "category_id": 1,
  "title": "Office Closure",
  "content": "The office will be closed on Friday.",
  "priority": "important",
  "targets": [
    { "target_type": "all" }
  ]
}
```

Use `multipart/form-data` when uploading an `attachment`.

### Request Fields

- `category_id`: required integer, must reference an `active` announcement category
- `title`: required string, max `255`
- `content`: required string
- `priority`: optional enum, `normal`, `important`, `urgent`, defaults to `normal`
- `attachment`: optional file, `pdf`, `jpg`, `jpeg`, `png`, max `2048 KB`
- `targets`: required array, min `1` item
- `targets.*.target_type`: required enum, `all`, `role`, `department`, `employee`
- `targets.*.target_id`: required for `role`/`department`/`employee` (must reference an existing role, department, or employee respectively); not used for `all`

The frontend must not send `status`, `created_by`, `submitted_by`, `submitted_at`, `approved_by`, `approved_at`, `rejected_by`, `rejected_at`, or `rejection_reason` — these are backend-controlled.

### Response Example

```json
{
  "success": true,
  "message": "Announcement created successfully.",
  "data": {
    "id": 1,
    "category": { "id": 1, "name": "General" },
    "title": "Office Closure",
    "content": "The office will be closed on Friday.",
    "priority": "important",
    "status": "draft",
    "attachment": null,
    "targets": [
      { "target_type": "all", "target_id": null }
    ],
    "creator": { "id": 1, "name": "Admin User" },
    "submitted_by_user": null,
    "submitted_at": null,
    "approved_by_user": null,
    "approved_at": null,
    "rejected_by_user": null,
    "rejected_at": null,
    "rejection_reason": null,
    "created_at": "2026-06-11T00:00:00.000000Z",
    "updated_at": "2026-06-11T00:00:00.000000Z"
  }
}
```

When an attachment is present, `attachment` is:

```json
{
  "name": "memo.pdf",
  "size": 102400,
  "url": "https://.../announcements/xyz.pdf"
}
```

---

## GET /api/announcements/{announcement}

Return one announcement.

### Access Rules

- Users with `announcements.view_draft` can view any announcement regardless of status. The response additionally includes `read_summary`.
- Employees can only view `published` announcements that target them. Viewing automatically and idempotently marks the announcement as read for that employee (creates an `announcement_views` record on first open only).

### Management Response Additions

```json
{
  "data": {
    "...": "standard announcement fields",
    "read_summary": {
      "total_viewed": 1,
      "total_unread": 4,
      "viewed_employees": [
        { "id": 2, "employee_id": "EMP-00002", "full_name": "Jane Doe" }
      ],
      "unread_employees": [
        { "id": 3, "employee_id": "EMP-00003", "full_name": "John Smith" }
      ]
    }
  }
}
```

`read_summary` is computed against the announcement's current audience (active employees matching its targets), not a fixed snapshot.

### Employee Response Additions

- `is_read`: boolean, `true` if an `announcement_views` record exists for the requesting employee.
- `creator`: `{ id, name }` of the announcement's author is included for employees too (both list and detail), so the board can show "Posted by X". `targets`, `submitted_by_user`, `approved_by_user`, `rejected_by_user`, and `read_summary` remain management-only.

---

## PUT /api/announcements/{announcement}

Update an announcement.

### Request Body

Same fields as `POST /api/announcements`, plus:

- `remove_attachment`: optional boolean, removes the existing attachment when `true` and no new `attachment` file is provided

### Rules

- Only announcements with status `draft` or `rejected` can be edited.
- `targets` are fully replaced (existing targets are deleted and re-created from the request).
- Editing a `rejected` announcement does not change its status; call `submit` afterwards to resubmit it.
- Uploading a new `attachment` replaces and deletes the previous file from storage.

---

## POST /api/announcements/{announcement}/submit

Submit a `draft` or `rejected` announcement for approval. Sets status to `pending_approval`, records `submitted_by`/`submitted_at`, and clears any prior rejection fields.

---

## POST /api/announcements/{announcement}/cancel-submission

Cancel a `pending_approval` announcement and return it to `draft`.

### Rules

- Only the announcement's creator can cancel the submission.
- Only `pending_approval` announcements can be cancelled.

---

## POST /api/announcements/{announcement}/approve

Approve a `pending_approval` announcement. Sets status to `published`, records `approved_by`/`approved_at`, and clears any prior rejection fields.

### Rules

- The creator cannot approve their own announcement.
- Only `pending_approval` announcements can be approved.

---

## POST /api/announcements/{announcement}/reject

Reject a `pending_approval` announcement.

### Request Body

```json
{
  "rejection_reason": "Please add the event dates."
}
```

### Rules

- `rejection_reason` is required.
- The creator cannot reject their own announcement.
- Only `pending_approval` announcements can be rejected.
- Rejected announcements can be edited and resubmitted by the creator.

---

## POST /api/announcements/{announcement}/archive

Archive a `published` announcement. Sets status to `archived`. Archived announcements are read-only but remain visible to users with `announcements.view_draft`.

### Rules

- Only `published` announcements can be archived.

---

## POST /api/announcements/{announcement}/read

Idempotently mark an announcement as read for the requesting employee. Creates an `announcement_views` record on first call only; subsequent calls are no-ops.

### Response Example

```json
{
  "success": true,
  "message": "Announcement marked as read.",
  "data": null
}
```

---

## Audience Targeting

Each announcement has one or more rows in `announcement_targets`, matched with OR logic:

- `all`: visible to every active employee
- `role`: visible to employees whose user account holds the given role (`target_id` = role id)
- `department`: visible to employees in the given department (`target_id` = department id)
- `employee`: visible to the specific employee (`target_id` = employee id)

Targeting is **dynamic**, not snapshotted. If an announcement targets a department and an employee later joins that department, they immediately gain visibility to the (still published) announcement.

## Read Tracking

- `announcement_views` records are unique per `(announcement_id, employee_id)`.
- A record is created the first time an employee views the announcement (via `GET /api/announcements/{announcement}` or `POST /api/announcements/{announcement}/read`); subsequent views are no-ops.
- Employee list responses include `is_read`.
- Management detail responses include `read_summary` with `total_viewed`, `total_unread`, `viewed_employees`, and `unread_employees`.

## List Sorting

- Employee list: unread first, then newest first.
- Management list: newest first.

## Audit Logging

All mutating actions are logged in the `announcements` and `announcement_categories` audit log modules: `create`, `update`, `submit`, `cancel_submission`, `approve`, `reject`, `archive` (announcements) and `create`, `update`, `deactivate` (categories). File contents are never logged — only `attachment_name`.

## Error Responses

### 401 Unauthenticated

```json
{
  "success": false,
  "message": "Unauthenticated.",
  "errors": []
}
```

### 403 Forbidden

```json
{
  "success": false,
  "message": "Forbidden.",
  "errors": []
}
```

### 403 No Employee Profile

```json
{
  "success": false,
  "message": "No employee profile is linked to this user account.",
  "errors": []
}
```

### 422 Validation Example

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "category_id": [
      "The selected category id is invalid."
    ]
  }
}
```

## Frontend Notes

- Frontend must not send workflow/state fields (`status`, `created_by`, `submitted_by`, `submitted_at`, `approved_by`, `approved_at`, `rejected_by`, `rejected_at`, `rejection_reason`) — these are backend-controlled.
- Use `multipart/form-data` only when uploading or replacing an `attachment`; otherwise use JSON.
- Edit/Submit/Cancel/Approve/Reject/Archive buttons should be conditionally shown based on the announcement's `status` and the current user's permissions, since the backend enforces these transitions via policies regardless of UI state.
- For employee announcement boards, render `is_read` to distinguish unread items, and rely on the backend's unread-first sort order.
- For management dashboards, use `read_summary` on the detail view to show audience read progress.
