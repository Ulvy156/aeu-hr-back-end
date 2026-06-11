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
- `GET /api/profile`: authenticated with Sanctum bearer token
- `POST /api/profile/change-password`: authenticated with Sanctum bearer token

## Permissions

- No separate permission middleware is applied to these endpoints.
- `POST /api/login` and `GET /api/me` return the authenticated user's effective Spatie role names in `roles`.
- `POST /api/login` and `GET /api/me` return the authenticated user's effective Spatie permissions in `permissions`.
- `GET /api/profile` and `POST /api/profile/change-password` operate on the authenticated user only — there is no permission to manage another user's password here. Admin/HR resetting another user's password is a separate endpoint: see `.claude/api/USER_API.md` (`POST /api/users/{user}/reset-password`, requires `users.reset_password`).

## Endpoint List

- `POST /api/login`
- `POST /api/logout`
- `GET /api/me`
- `GET /api/profile`
- `POST /api/profile/change-password`

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

## GET /api/profile

Return the authenticated user's own profile, including their role, effective permissions, and linked employee record (if any).

### Auth

```txt
Authorization: Bearer {token}
```

### Response Example

```json
{
  "success": true,
  "message": "Profile fetched successfully.",
  "data": {
    "id": 5,
    "name": "John Doe",
    "email": "john@example.com",
    "status": "active",
    "roles": ["employee"],
    "permissions": [
      "attendance.clock_in",
      "leaves.create",
      "payslips.view_own"
    ],
    "employee": {
      "id": 3,
      "employee_id": "EMP001",
      "full_name": "John Doe",
      "gender": "male",
      "date_of_birth": "2000-01-01",
      "phone_number": "012345678",
      "email": "john@example.com",
      "address": "Phnom Penh",
      "department": { "id": 1, "name": "IT" },
      "position": { "id": 1, "name": "Developer" },
      "join_date": "2024-01-01",
      "last_working_date": null,
      "employment_status": "active",
      "profile_photo_url": null
    }
  }
}
```

`employee` is `null` for users without a linked employee record (e.g. some `admin`/`hr` accounts). Salary fields are intentionally never included in this response.

---

## POST /api/profile/change-password

Allow the authenticated user to change their own password.

### Auth

```txt
Authorization: Bearer {token}
```

### Request Body

```json
{
  "current_password": "old-password",
  "password": "new-password",
  "password_confirmation": "new-password"
}
```

### Request Fields

- `current_password`: required string, must match the authenticated user's current password
- `password`: required string, min `8`, max `255`, must be confirmed (`password_confirmation`)

### Response Example

```json
{
  "success": true,
  "message": "Password changed successfully.",
  "data": null
}
```

### Behavior

- On success, the user's password is updated and **all other Sanctum tokens for this user are revoked** — only the token used to make this request remains valid. The frontend does not need to log the user out, but should be aware that any other active sessions/devices will be signed out.
- The action is recorded in the audit log (`module: profile`, `action: change_password`).

### Validation Notes

- Incorrect `current_password` returns:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "current_password": ["The provided password is incorrect."]
  }
}
```

- `password` too short, missing, or not matching `password_confirmation` returns standard `422` validation errors on the `password` field.

## Frontend Notes

- Store the returned `token` securely and send it in the `Authorization` header for protected requests.
- Call `GET /api/me` on app boot or refresh to restore the authenticated user.
- `roles` and `permissions` come from Spatie Laravel Permission. Do not infer them from a `role_id` field because none exists on `users`.
- `employee` is nullable. Frontend must handle users that do not have a linked employee record yet.
- Treat `roles` and `permissions` as backend-provided authorization context for UI decisions only. The backend remains the authorization source of truth.
- Use the global API response envelope: `{ success, message, data }` or `{ success, message, errors }`.
- Use `GET /api/profile` (not `/api/me`) for a "My Profile" / "Account Settings" page, and pair it with `POST /api/profile/change-password` for a self-service "Change Password" form. Do not reuse the admin `users.reset_password` endpoint for this.
