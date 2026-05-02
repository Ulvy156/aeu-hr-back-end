# Auth API

## Purpose

Authentication for API clients using Laravel Sanctum bearer tokens.

## Base Endpoint

```txt
/api
```

## Auth Requirement

- `POST /api/login`: public
- `POST /api/logout`: authenticated with Sanctum bearer token
- `GET /api/me`: authenticated with Sanctum bearer token

## Permissions

- No separate permission middleware is applied to these endpoints.
- `POST /api/login` and `GET /api/me` return the authenticated user's effective Spatie role names in `roles`.
- `POST /api/login` and `GET /api/me` return the authenticated user's effective Spatie permissions in `permissions`.

## Endpoint List

- `POST /api/login`
- `POST /api/logout`
- `GET /api/me`

---

## POST /api/login

Authenticate a user and issue a Sanctum token.

### Request Body

```json
{
  "email": "admin@example.com",
  "password": "password",
  "device_name": "web-client"
}
```

### Request Fields

- `email`: required, valid email format, lowercased before validation
- `password`: required string
- `device_name`: optional string, max 255

### Response Example

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
      "permissions": [
        "employees.create",
        "employees.update",
        "employees.view"
      ],
      "employee": null,
      "created_at": "2026-05-02T09:00:00.000000Z",
      "updated_at": "2026-05-02T09:00:00.000000Z"
    }
  }
}
```

### Validation Notes

- Invalid credentials return:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["The provided credentials are incorrect."]
  }
}
```

- Inactive accounts return:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["This account is inactive."]
  }
}
```

- Login is rate limited. When exceeded, the response is:

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

### Auth

```txt
Authorization: Bearer {token}
```

### Request Body

No body fields.

### Response Example

```json
{
  "success": true,
  "message": "Logout successful.",
  "data": null
}
```

### Validation Notes

- Unauthenticated requests return the global `401` JSON error format.

---

## GET /api/me

Return the authenticated user for the active bearer token.

### Auth

```txt
Authorization: Bearer {token}
```

### Query Parameters

None.

### Response Example

```json
{
  "success": true,
  "message": "Authenticated user fetched successfully.",
  "data": {
    "id": 1,
    "name": "Admin User",
    "email": "admin@example.com",
    "status": "active",
    "roles": ["admin"],
    "permissions": [
      "employees.create",
      "employees.update",
      "employees.view"
    ],
    "employee": {
      "id": 1,
      "employee_id": "EMP001",
      "full_name": "Admin User"
    },
    "created_at": "2026-05-02T09:00:00.000000Z",
    "updated_at": "2026-05-02T09:00:00.000000Z"
  }
}
```

## Frontend Notes

- Store the returned `token` securely and send it in the `Authorization` header for protected requests.
- Call `GET /api/me` on app boot or refresh to restore the authenticated user.
- `roles` and `permissions` come from Spatie Laravel Permission. Do not infer them from a `role_id` field because none exists on `users`.
- `employee` is nullable. Frontend must handle users that do not have a linked employee record yet.
- Treat `roles` and `permissions` as backend-provided authorization context for UI decisions only. The backend remains the authorization source of truth.
- Use the global API response envelope: `{ success, message, data }` or `{ success, message, errors }`.
