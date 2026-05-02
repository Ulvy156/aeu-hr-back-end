# Position API

## Purpose

Manage company positions, optionally linked to departments.

## Base Endpoint

```txt
/api/positions
```

## Auth Requirement

All position endpoints require a Sanctum bearer token.

## Permissions

- List positions: `positions.view_any`
- View position detail: `positions.view`
- Create position: `positions.create`
- Update position: `positions.update`
- Delete position: `positions.delete`

HR and Admin can manage positions with the current seeded role setup.

## Endpoint List

- `GET /api/positions`
- `POST /api/positions`
- `GET /api/positions/{position}`
- `PUT /api/positions/{position}`
- `DELETE /api/positions/{position}`

---

## GET /api/positions

Return a paginated position list.

### Query Parameters

- `search`: optional string, filters by position name
- `department_id`: optional integer, filters by department
- `status`: optional enum, `active` or `inactive`
- `per_page`: optional integer, `1` to `100`

### Response Example

```json
{
  "success": true,
  "message": "Positions fetched successfully.",
  "data": [
    {
      "id": 1,
      "name": "Accountant",
      "status": "active",
      "department": {
        "id": 1,
        "name": "Finance",
        "status": "active"
      },
      "employees_count": 4,
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

## POST /api/positions

Create a new position.

### Request Body

```json
{
  "name": "Accountant",
  "department_id": 1,
  "status": "active"
}
```

### Request Fields

- `name`: required string, max `255`
- `department_id`: optional integer, must exist in `departments`
- `status`: required enum, `active` or `inactive`

### Response Example

```json
{
  "success": true,
  "message": "Position created successfully.",
  "data": {
    "id": 1,
    "name": "Accountant",
    "status": "active",
    "department": {
      "id": 1,
      "name": "Finance",
      "status": "active"
    },
    "employees_count": 0,
    "created_at": "2026-05-02T10:00:00.000000Z",
    "updated_at": "2026-05-02T10:00:00.000000Z"
  }
}
```

---

## GET /api/positions/{position}

Return one position.

### Response Example

```json
{
  "success": true,
  "message": "Position fetched successfully.",
  "data": {
    "id": 1,
    "name": "Accountant",
    "status": "active",
    "department": {
      "id": 1,
      "name": "Finance",
      "status": "active"
    },
    "employees_count": 4,
    "created_at": "2026-05-02T10:00:00.000000Z",
    "updated_at": "2026-05-02T10:00:00.000000Z"
  }
}
```

---

## PUT /api/positions/{position}

Update a position.

### Request Body

```json
{
  "name": "Senior Accountant",
  "department_id": 1,
  "status": "inactive"
}
```

### Validation Notes

- `department_id` is optional because positions may exist without a department.
- `status` must be `active` or `inactive`.

---

## DELETE /api/positions/{position}

Soft delete a position.

### Validation Notes

- Position deletion is blocked when employees are still assigned to the position.
- Blocked deletion returns the global validation error format with an error on `position`.

## Frontend Notes

- Use `department_id` filtering to power department-specific position dropdowns and lists.
- `department` may be `null`, so frontend selectors should support unassigned positions.
- Show `employees_count` directly from the backend response.
- Allow only `active` and `inactive` as status values.
