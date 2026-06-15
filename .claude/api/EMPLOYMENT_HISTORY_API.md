# Employment History API

## Purpose

Read-only career timeline for an employee: department transfers, position changes, salary changes, employment status transitions, and probation end date changes.

`PUT /api/employees/{employee}` (see `.claude/api/EMPLOYEE_API.md`) does not create rows here — routine employee updates (including HR fixing incorrect data) must not pollute this timeline. Rows are created automatically when an Employee Upgrade Request is approved — see `.claude/api/EMPLOYEE_UPGRADE_REQUEST_API.md`. This is in addition to, not a replacement for, the generic audit log (`.claude/api/AUDIT_LOG_API.md`), which does record every employee update.

### Tracked Fields

- `department_id`
- `position_id`
- `base_salary`
- `employment_status`
- `probation_end_date`

Changes to other employee fields (name, contact details, photo, etc.) are not recorded here.

## Base Endpoint

```txt
/api/employees/{employee}/employment-history
```

## Auth Requirement

Sanctum bearer token required.

## Permissions

- Access requires `employees.view` (the same permission as `GET /api/employees/{employee}`).
- Current role access: `admin` and `hr` allowed; `ceo` and `employee` forbidden under the default seeded roles.

## Endpoint List

- `GET /api/employees/{employee}/employment-history`

---

## GET /api/employees/{employee}/employment-history

Return a paginated, most-recent-first list of employment history rows for one employee.

### Query Parameters

- `field`: optional string, one of `department_id`, `position_id`, `base_salary`, `employment_status`, `probation_end_date`
- `date_from`: optional date, filters `effective_date >= date_from`
- `date_to`: optional date, filters `effective_date <= date_to`
- `per_page`: optional integer, min 1, max 100, default 15

### Response Example

```json
{
  "success": true,
  "message": "Employment history fetched successfully.",
  "data": [
    {
      "id": 12,
      "field": "base_salary",
      "old_value": { "value": 1200.5 },
      "new_value": { "value": 1500.0 },
      "effective_date": "2026-07-01",
      "changed_by": { "id": 2, "name": "HR Manager" },
      "created_at": "2026-06-14T09:00:00.000000Z"
    },
    {
      "id": 11,
      "field": "department_id",
      "old_value": { "id": 1, "name": "Sales" },
      "new_value": { "id": 2, "name": "Engineering" },
      "effective_date": "2026-06-01",
      "changed_by": { "id": 2, "name": "HR Manager" },
      "created_at": "2026-06-01T09:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 2
  }
}
```

### `old_value` / `new_value` Shapes

- `department_id` / `position_id` (FK fields): `{ "id": <int>, "name": "<string|null>" }`, or bare `null` when the employee had no department/position before this change. `name` is a point-in-time snapshot — it remains readable even if the department/position is later renamed or soft-deleted.
- `base_salary`: `{ "value": <number> }`
- `employment_status`: `{ "value": "<active|probation|resigned|terminated>" }`
- `probation_end_date`: `{ "value": "<YYYY-MM-DD>|null" }`, or `old_value` is bare `null` when the employee had no probation end date before this change.

### Validation Notes

- Invalid filter values return the global `422` validation response.
- Unauthorized users receive the global `403` response.
- Unauthenticated users receive the global `401` response.

## Frontend Notes

- This API is read-only — there are no create, update, or delete endpoints.
- `PUT /api/employees/{employee}` does not create rows here.
- Approving an Employee Upgrade Request (`POST /api/employee-upgrade-requests/{upgradeRequest}/approve`, see `.claude/api/EMPLOYEE_UPGRADE_REQUEST_API.md`) creates one row per changed tracked field.
