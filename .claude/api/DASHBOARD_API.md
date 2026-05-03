# Dashboard API

## Purpose

Provide dashboard summary data for privileged dashboard views.

## Base Endpoint

```txt
/api/dashboard
```

## Auth Requirement

All dashboard endpoints require a Sanctum bearer token.

## Permissions

- User summary: `dashboards.admin_view`

## Endpoint List

- `GET /api/dashboard/users-summary`

---

## GET /api/dashboard/users-summary

Return aggregate user statistics for the admin dashboard.

### Permission Required

- `dashboards.admin_view`

### Response Example

```json
{
  "success": true,
  "message": "User summary fetched successfully",
  "data": {
    "total_users": 10,
    "active_users": 8,
    "inactive_users": 2,
    "users_by_role": {
      "admin": 1,
      "hr": 2,
      "ceo": 1,
      "employee": 6
    }
  }
}
```

### Response Notes

- Counts are aggregated from the backend only.
- Role totals are calculated from Spatie role assignments, not from a `role_id` column on `users`.
- No personal user data is exposed.

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

## Frontend Notes

- Treat this endpoint as an aggregate summary endpoint, not a user list.
- The `users_by_role` object always returns the expected role keys: `admin`, `hr`, `ceo`, and `employee`.
