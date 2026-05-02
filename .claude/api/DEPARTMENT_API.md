# Department API

## Purpose

Manage company departments for the HR Management System.

## Base Endpoint

```txt
/api/departments
```

## Auth Requirement

All department endpoints require a Sanctum bearer token.

## Permissions

- List departments: `departments.view_any`
- View department detail: `departments.view`
- Create department: `departments.create`
- Update department: `departments.update`
- Delete department: `departments.delete`

HR and Admin can manage departments with the current seeded role setup.

## Endpoint List

- `GET /api/departments`
- `POST /api/departments`
- `GET /api/departments/{department}`
- `PUT /api/departments/{department}`
- `DELETE /api/departments/{department}`

---

## GET /api/departments

Return a paginated department list.

### Query Parameters

- `search`: optional string, filters by department name
- `status`: optional enum, `active` or `inactive`
- `per_page`: optional integer, `1` to `100`

### Response Example

```json
{
  "success": true,
  "message": "Departments fetched successfully.",
  "data": [
    {
      "id": 1,
      "name": "Finance",
      "status": "active",
      "positions_count": 3,
      "employees_count": 12,
      "created_at": "2026-05-02T10:00:00.000000Z",
      "updated_at": "2026-05-02T10:00:00.000000Z"
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

## POST /api/departments

Create a new department.

### Request Body

```json
{
  "name": "Finance",
  "status": "active"
}
```

### Request Fields

- `name`: required string, max `255`, unique in `departments`
- `status`: required enum, `active` or `inactive`

### Response Example

```json
{
  "success": true,
  "message": "Department created successfully.",
  "data": {
    "id": 1,
    "name": "Finance",
    "status": "active",
    "positions_count": 0,
    "employees_count": 0,
    "created_at": "2026-05-02T10:00:00.000000Z",
    "updated_at": "2026-05-02T10:00:00.000000Z"
  }
}
```

---

## GET /api/departments/{department}

Return one department.

### Response Example

```json
{
  "success": true,
  "message": "Department fetched successfully.",
  "data": {
    "id": 1,
    "name": "Finance",
    "status": "active",
    "positions_count": 3,
    "employees_count": 12,
    "created_at": "2026-05-02T10:00:00.000000Z",
    "updated_at": "2026-05-02T10:00:00.000000Z"
  }
}
```

---

## PUT /api/departments/{department}

Update a department.

### Request Body

```json
{
  "name": "Finance and Admin",
  "status": "inactive"
}
```

### Validation Notes

- `name` must remain unique.
- `status` must be `active` or `inactive`.

---

## DELETE /api/departments/{department}

Soft delete a department.

### Validation Notes

- Department deletion is blocked when the department still has related positions or employees.
- Blocked deletion returns the global validation error format with an error on `department`.

## Frontend Notes

- Use paginated list handling for department tables.
- Show `positions_count` and `employees_count` from the backend response instead of recalculating on the frontend.
- Allow only `active` and `inactive` as department status values.
- Treat delete as a protected action because the backend will reject departments that are still in use.
