# Phase 1.4 Global API Response Format

## Success Response

```json
{
  "success": true,
  "message": "Action completed successfully",
  "data": {}
}
```

## Error Response

```json
{
  "success": false,
  "message": "Something went wrong",
  "errors": {}
}
```

## Validation Error Response

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

## Paginated Response

```json
{
  "success": true,
  "message": "Data fetched successfully",
  "data": [],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 0
  }
}
```

---

# Phase 1.2 Authentication

## Base URL

```txt
/api
```

## Authentication

Use Sanctum bearer tokens for protected API requests:

```txt
Authorization: Bearer {token}
Accept: application/json
```

---

## POST /api/login

Authenticate a user and return a Sanctum token.

### Request Body

```json
{
  "email": "admin@example.com",
  "password": "password",
  "device_name": "web-client"
}
```

### Success Response

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "token": "1|plain-text-token",
    "token_type": "Bearer",
    "user": {
      "id": 1,
      "name": "System Admin",
      "email": "admin@example.com",
      "status": "active",
      "roles": ["admin"],
      "created_at": "2026-05-02T09:00:00.000000Z",
      "updated_at": "2026-05-02T09:00:00.000000Z"
    }
  }
}
```

### Validation Error Response

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["The provided credentials are incorrect."]
  }
}
```

### Rate Limit Response

```json
{
  "success": false,
  "message": "Too many login attempts. Please try again later.",
  "errors": {
    "email": ["Too many login attempts. Please try again later."]
  }
}
```

---

## POST /api/logout

Revoke the current Sanctum token.

### Headers

```txt
Authorization: Bearer {token}
```

### Success Response

```json
{
  "success": true,
  "message": "Logout successful.",
  "data": null
}
```

---

## GET /api/me

Return the authenticated user for the current bearer token.

### Headers

```txt
Authorization: Bearer {token}
```

### Success Response

```json
{
  "success": true,
  "message": "Authenticated user fetched successfully.",
  "data": {
    "id": 1,
    "name": "System Admin",
    "email": "admin@example.com",
    "status": "active",
    "roles": ["admin"],
    "created_at": "2026-05-02T09:00:00.000000Z",
    "updated_at": "2026-05-02T09:00:00.000000Z"
  }
}
```

---

## Frontend Integration Summary

- Store the returned `token` securely and send it in the `Authorization` header for protected requests.
- Call `GET /api/me` on app boot or page refresh to restore the authenticated user.
- Call `POST /api/logout` using the active bearer token to revoke that token.
- Expect the standard `{ success, message, data }` or `{ success, message, errors }` envelope across API endpoints.

---

## Phase 1.3 Roles and Permissions Foundation

This phase does not add new public API endpoints yet.

It establishes the authorization data and middleware that later APIs will use.

### Seeded Roles

```txt
admin
hr
ceo
employee
```

### Permission Naming Convention

Permissions use `module.action` naming:

```txt
departments.view_any
employees.update
attendance.clock_in
leaves.approve_hr
payrolls.approve
audit_logs.view
```

### Important Frontend Notes

- `GET /api/me` already returns the authenticated user's role names in the `roles` array.
- Future protected endpoints may reject access with `403 Forbidden` based on role middleware, permission middleware, or policy checks.
- Frontend should treat roles as display/context data only; backend remains the source of truth for authorization.

---

## Phase 1.5 Global Exception Handling

API errors should return JSON for API routes and `Accept: application/json` requests.

### 400 Bad Request

```json
{
  "success": false,
  "message": "Bad request.",
  "errors": {}
}
```

### 401 Unauthenticated

```json
{
  "success": false,
  "message": "Unauthenticated.",
  "errors": {}
}
```

### 403 Forbidden

```json
{
  "success": false,
  "message": "Forbidden.",
  "errors": {}
}
```

### 404 Not Found

```json
{
  "success": false,
  "message": "Resource not found.",
  "errors": {}
}
```

### 422 Validation Failed

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

### 429 Too Many Requests

```json
{
  "success": false,
  "message": "Too many requests.",
  "errors": {}
}
```

### 500 Server Error

```json
{
  "success": false,
  "message": "Server error.",
  "errors": {}
}
```

### Important Frontend Notes

- Do not rely on HTML error pages for API routes.
- Treat `message` as user-displayable summary text.
- Use `errors` for field-level validation feedback when present.
- Do not depend on sensitive backend exception messages being exposed for `500` responses.

---

## Phase 1.6 Audit Log System

Audit logs store important backend actions with actor, module, payload diff, IP address, and user agent.

Current foundation includes login/logout logging and a protected listing endpoint.

### GET /api/audit-logs

Return paginated audit logs.

### Access

```txt
Admin: allowed
CEO: allowed
HR: forbidden
Employee: forbidden
```

### Query Parameters

```txt
user_id     optional integer
module      optional string
action      optional string
date_from   optional date
date_to     optional date
per_page    optional integer (default 15, max 100)
```

### Success Response

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

### Frontend Integration Summary

- Use this endpoint for admin/CEO audit monitoring screens.
- Filter by `module`, `action`, `user_id`, and date range before rendering large tables.
- Expect additional audit-producing modules later such as attendance correction, leave approval, payroll actions, settings updates, and user management.
