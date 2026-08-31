# Employee Upgrade Request API

## Purpose

HR-proposed changes to an employee's department, position, base salary, employment status, and/or reporting manager, subject to approval by a permission-holding reviewer (seeded to `ceo`).

- `PUT /api/employees/{employee}` (see `.claude/api/EMPLOYEE_API.md`) remains unchanged — it is for direct profile edits/corrections, requires no approval, and writes no `employment_histories` rows.
- This module is for formal transfers/promotions/salary changes/status changes/reporting-line changes that require sign-off. Approval applies the change to the employee record and writes rows to `employment_histories` (see `.claude/api/EMPLOYMENT_HISTORY_API.md`).

### Upgradable Fields

- `department_id`
- `position_id`
- `base_salary`
- `employment_status` (when proposed, `last_working_date` must also be supplied in `proposed_values` — see Validation Notes)
- `manager_id` (who the employee reports to — see Validation Notes; see also `.claude/api/EMPLOYEE_HIERARCHY_API.md`)

`probation_end_date` and `intern_end_date` are never proposed directly — they are derived automatically on approval the same way they are on `POST`/`PUT /api/employees`.

`department_id`, `position_id`, and `manager_id` are sent as raw integers in the create request (see below), but are echoed back in every response's `current_values`/`proposed_values` as `{ "id": <int>, "name": "<string|null>" }` snapshots — `name` is resolved at read time (including soft-deleted departments/positions/employees), not stored. `base_salary`, `employment_status`, and `last_working_date` remain plain scalars.

## Base Endpoint

```txt
/api/employee-upgrade-requests
```

## Auth Requirement

Sanctum bearer token required.

## Permissions

- List requests: `employee_upgrade_requests.view_any` (see all requests) or `employee_upgrade_requests.view` (see only requests you created)
- View one request: `employee_upgrade_requests.view_any`, or `employee_upgrade_requests.view` + you are the requester
- Create request: `employee_upgrade_requests.create`
- Cancel request: `employee_upgrade_requests.cancel` + you are the requester
- Approve request: `employee_upgrade_requests.approve`
- Reject request: `employee_upgrade_requests.reject`

### Default Role Mapping

- `admin`: all permissions above (via `'all' => true`)
- `hr`: `.view`, `.create`, `.cancel` (sees only their own requests in the list — no `.view_any`)
- `ceo`: `.view_any`, `.view`, `.approve`, `.reject`
- `employee`: none

Permissions are reassignable via the existing role/permission management endpoints without code changes.

## Endpoint List

- `GET /api/employee-upgrade-requests`
- `POST /api/employee-upgrade-requests`
- `GET /api/employee-upgrade-requests/{upgradeRequest}`
- `POST /api/employee-upgrade-requests/{upgradeRequest}/approve`
- `POST /api/employee-upgrade-requests/{upgradeRequest}/reject`
- `POST /api/employee-upgrade-requests/{upgradeRequest}/cancel`

There is no update endpoint. A pending request cannot be edited — cancel it and create a new one.

---

## GET /api/employee-upgrade-requests

Return a paginated list of upgrade requests, most recent first.

### Query Parameters

- `employee_id`: optional integer, filters by employee
- `status`: optional enum, `pending`, `approved`, `rejected`, or `cancelled`
- `per_page`: optional integer, `1` to `100`, default `15`

### Response Example

```json
{
  "success": true,
  "message": "Employee upgrade requests fetched successfully.",
  "data": [
    {
      "id": 5,
      "status": "pending",
      "current_values": { "department_id": { "id": 1, "name": "Sales" }, "base_salary": "1000.00" },
      "proposed_values": { "department_id": { "id": 2, "name": "Engineering" }, "base_salary": "1500.00" },
      "effective_date": "2026-07-01",
      "rejection_reason": null,
      "attachments": [
        { "name": "promotion-letter.pdf", "size": 102400, "url": "https://files.example.com/employee-upgrade-requests/abc123.pdf" }
      ],
      "employee": { "id": 10, "employee_id": "EMP-00010", "full_name": "Jane Doe" },
      "requested_by": { "id": 2, "name": "HR Manager" },
      "reviewed_by": null,
      "reviewed_at": null,
      "created_at": "2026-06-15T09:00:00.000000Z",
      "updated_at": "2026-06-15T09:00:00.000000Z"
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

## POST /api/employee-upgrade-requests

Create a pending upgrade request for one employee.

Use `multipart/form-data` when sending `attachments`.

### Request Body

```json
{
  "employee_id": 10,
  "effective_date": "2026-07-01",
  "proposed_values": {
    "department_id": 2,
    "position_id": 5,
    "base_salary": "1500.00",
    "employment_status": "probation",
    "last_working_date": null
  },
  "attachments": []
}
```

### Request Fields

- `employee_id`: required integer, must exist in `employees`
- `effective_date`: optional date, passed through to the resulting `employment_histories` rows on approval (defaults to today if omitted)
- `proposed_values`: required object, at least one key, only `department_id`, `position_id`, `base_salary`, `employment_status`, `manager_id`, `last_working_date` allowed
  - `department_id`: optional integer, must exist in `departments`
  - `position_id`: optional integer, must exist in `positions`
  - `base_salary`: numeric, minimum `0`
  - `employment_status`: one of `full-time`, `probation`, `intern`, `resigned`, `terminated`
  - `manager_id`: optional integer, must exist in `employees` — the employee this employee will report to. Send `null` to clear the manager (remove from the org chart's reporting line).
  - `last_working_date`: optional date — required if `employment_status` is `resigned`/`terminated`, forbidden if `full-time`/`probation`/`intern`
- `attachments`: optional array, max `3` files, each `pdf`, `jpg`, `jpeg`, `png`, `webp`, `doc`, or `docx`, max `5120` KB

### Validation Notes

- At least one of `department_id`, `position_id`, `base_salary`, `employment_status`, `manager_id` must be present in `proposed_values`.
- Unknown keys in `proposed_values` are rejected.
- If `employment_status` is `resigned` or `terminated`, `last_working_date` is required; if `full-time`, `probation`, or `intern`, `last_working_date` must be absent/null.
- At least one proposed value must differ from the employee's current data, otherwise `422` on `proposed_values`.
- If the resulting `position_id` (proposed or current) belongs to a department, it must match the resulting `department_id` (proposed or current), otherwise `422` on `proposed_values.position_id`.
- An employee cannot be proposed as their own manager — `422` on `proposed_values.manager_id`.
- A proposed `manager_id` cannot create a circular reporting relationship (e.g. proposing that an employee report to one of their own subordinates) — `422` on `proposed_values.manager_id`.
- The proposed manager must belong to the same resulting `department_id` (proposed or current) as the employee, unless the manager holds the `ceo` role — `422` on `proposed_values.manager_id`. Skipped if either side's department is not set.
- `current_values`/`proposed_values` in the response only include the fields that actually changed (plus `last_working_date` whenever `employment_status` changes).
- Attachments are immutable — there is no endpoint to add/remove files on an existing request.

### Response Example

```json
{
  "success": true,
  "message": "Employee upgrade request submitted successfully.",
  "data": {
    "id": 5,
    "status": "pending",
    "current_values": { "base_salary": "1000.00" },
    "proposed_values": { "base_salary": "1500.00" },
    "effective_date": "2026-07-01",
    "rejection_reason": null,
    "attachments": [],
    "employee": { "id": 10, "employee_id": "EMP-00010", "full_name": "Jane Doe" },
    "requested_by": { "id": 2, "name": "HR Manager" },
    "reviewed_by": null,
    "reviewed_at": null,
    "created_at": "2026-06-15T09:00:00.000000Z",
    "updated_at": "2026-06-15T09:00:00.000000Z"
  }
}
```

---

## GET /api/employee-upgrade-requests/{upgradeRequest}

Return one upgrade request. Uses the same shape as the list item.

---

## POST /api/employee-upgrade-requests/{upgradeRequest}/approve

Approve a pending request. Applies `proposed_values` to the employee, derives `probation_end_date`/`intern_end_date` and syncs the linked user's `active`/`inactive` status when `employment_status` changes, and records one `employment_histories` row per changed tracked field (see `.claude/api/EMPLOYMENT_HISTORY_API.md`).

### Validation Notes

- Only requests with `status: "pending"` can be approved; otherwise `422`.

### Response

Returns the updated request with `status: "approved"`, `reviewed_by`, and `reviewed_at` populated, in the same shape as the list item.

---

## POST /api/employee-upgrade-requests/{upgradeRequest}/reject

Reject a pending request. No employee or employment history changes are made.

### Request Body

```json
{
  "rejection_reason": "Budget not approved for this quarter."
}
```

### Request Fields

- `rejection_reason`: required string

### Validation Notes

- Only requests with `status: "pending"` can be rejected; otherwise `422`.

### Response

Returns the updated request with `status: "rejected"`, `rejection_reason`, `reviewed_by`, and `reviewed_at` populated.

---

## POST /api/employee-upgrade-requests/{upgradeRequest}/cancel

Cancel your own pending request. No employee or employment history changes are made.

### Validation Notes

- Only requests with `status: "pending"` can be cancelled; otherwise `422`.
- Only the original requester can cancel their own request; otherwise `403`.

### Response

Returns the updated request with `status: "cancelled"`, `reviewed_by`, and `reviewed_at` populated.

## Frontend Notes

- Rejected and cancelled requests are kept as rows for audit history — they are not deleted, and their attachment files remain in storage.
- To change a pending request, cancel it and create a new one.
- `attachments[].url` is a full public file URL, same convention as other file fields in this API.
- After approval, `GET /api/employees/{employee}/employment-history` will include the newly recorded changes.
