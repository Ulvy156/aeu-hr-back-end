# Audit Log API

## Purpose

Read-only audit log listing for Admin and CEO users.

Audit records are stored in Spatie Activitylog's `activity_log` table and exposed through a frontend-friendly response shape.

## Base Endpoint

```txt
/api/audit-logs
```

## Auth Requirement

Sanctum bearer token required.

## Permissions

- Access requires the `audit_logs.view` permission.
- Current role access:
  - `admin`: allowed
  - `ceo`: allowed
  - `hr`: forbidden
  - `employee`: forbidden

## Endpoint List

- `GET /api/audit-logs`

---

## GET /api/audit-logs

Return paginated audit logs.

### Query Parameters

- `user_id`: optional integer, must exist in `users.id`
- `module`: optional string, max 100
- `action`: optional string, max 255
- `date_from`: optional date
- `date_to`: optional date, must be after or equal to `date_from`
- `per_page`: optional integer, min 1, max 100, default 15

### Response Example

```json
{
  "success": true,
  "message": "Audit logs fetched successfully.",
  "data": [
    {
      "id": 1,
      "user": {
        "id": 1,
        "name": "System Admin",
        "email": "admin@example.com"
      },
      "action": "login",
      "module": "auth",
      "model_type": "App\\Models\\User",
      "model_id": 1,
      "old_values": null,
      "new_values": {
        "status": "logged_in",
        "device_name": "web-client"
      },
      "ip_address": "127.0.0.1",
      "user_agent": "Symfony",
      "created_at": "2026-05-02T09:00:00.000000Z"
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

### Validation Notes

- Invalid filter values return the global `422` validation response.
- Unauthorized users receive the global `403` response.
- Unauthenticated users receive the global `401` response.

## Frontend Notes

- This API is read-only. There are no create, update, or delete audit log endpoints.
- Filter before rendering large tables to reduce payload size.
- `old_values`, `new_values`, `ip_address`, and `user_agent` are read from Spatie Activitylog `properties`.
- Sensitive data such as passwords and tokens must not be expected in audit responses.
