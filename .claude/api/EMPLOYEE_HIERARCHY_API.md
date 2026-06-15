# Employee Hierarchy API

## Purpose

Read-only company org chart, built from each employee's `manager_id` (who they report to). Returns the full reporting structure as a nested tree, for display purposes (e.g. an org-chart screen).

To change who an employee reports to, either update `manager_id` directly via `PUT /api/employees/{employee}` (see `.claude/api/EMPLOYEE_API.md`), or propose a `manager_id` change via `.claude/api/EMPLOYEE_UPGRADE_REQUEST_API.md` (HR proposes, `ceo` approves by default) when the change should require CEO approval. Approved `manager_id` changes via the upgrade-request flow are recorded in `.claude/api/EMPLOYMENT_HISTORY_API.md`; direct edits via `PUT` are not.

## Base Endpoint

```txt
/api/employees/hierarchy
```

## Auth Requirement

Sanctum bearer token required. No additional permission is required — **any authenticated user** (`admin`, `hr`, `ceo`, `employee`) can view the org chart.

## Endpoint List

- `GET /api/employees/hierarchy`

---

## GET /api/employees/hierarchy

Return the full org chart as a list of root nodes (employees with no `manager_id`), each with nested `children`.

### Response Example

```json
{
  "success": true,
  "message": "Employee hierarchy fetched successfully.",
  "data": [
    {
      "id": 1,
      "employee_id": "EMP-00001",
      "full_name": "Torn Punleu",
      "profile_photo_url": "https://files.example.com/employee-profile-photos/ceo.jpg",
      "department": { "id": 1, "name": "Executive" },
      "position": { "id": 1, "name": "CEO" },
      "children": [
        {
          "id": 2,
          "employee_id": "EMP-00002",
          "full_name": "Sreng Davy",
          "profile_photo_url": "https://files.example.com/employee-profile-photos/deputy.jpg",
          "department": { "id": 1, "name": "Executive" },
          "position": { "id": 2, "name": "Deputy Director" },
          "children": [
            {
              "id": 3,
              "employee_id": "EMP-00003",
              "full_name": "Staff Member",
              "profile_photo_url": null,
              "department": { "id": 2, "name": "Operations" },
              "position": { "id": 5, "name": "Operations Staff" },
              "children": []
            }
          ]
        }
      ]
    }
  ]
}
```

### Notes

- Soft-deleted employees are excluded from the tree entirely (neither shown as nodes nor as ancestors).
- An employee with `manager_id = null` is a root node. There can be more than one root if multiple employees have no manager.
- `department` and `position` are `null` if the employee has no department/position assigned.
- `profile_photo_url` is `null` if the employee has no profile photo.
- `children` is always an array (empty array `[]` for employees with no direct reports).

## Frontend Notes

- This endpoint is not paginated — it returns the entire org chart in one response.
- Use this for an org-chart display screen. It is display-only; there are no create/update/delete endpoints here.
- To reassign an employee's manager immediately, use `PUT /api/employees/{employee}` with `manager_id` (see `.claude/api/EMPLOYEE_API.md`). To propose a change that requires CEO approval and gets recorded in employment history, use `POST /api/employee-upgrade-requests` with `proposed_values.manager_id` (see `.claude/api/EMPLOYEE_UPGRADE_REQUEST_API.md`).
