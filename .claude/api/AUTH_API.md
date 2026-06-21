# Auth API

## Purpose

Authentication for API clients using Laravel Sanctum bearer tokens with refresh token rotation via httpOnly cookie.

## Base Endpoint

```txt
/api
```

## Auth Requirement

- `POST /api/login`: public (rate-limited)
- `POST /api/refresh`: public (rate-limited), reads refresh token from httpOnly cookie
- `POST /api/logout`: authenticated with Sanctum bearer token
- `GET /api/me`: authenticated with Sanctum bearer token
- `GET /api/profile`: authenticated with Sanctum bearer token
- `POST /api/profile/change-password`: authenticated with Sanctum bearer token

## Token Strategy

- **Access token**: Short-lived (15 minutes default). Sent in `Authorization: Bearer {token}` header. Stored in JS memory only (not localStorage).
- **Refresh token**: Long-lived (7 days default). Stored as an httpOnly cookie set by the backend (`SameSite=None; Secure; HttpOnly`). Frontend never accesses it directly — the browser sends it automatically with `credentials: 'include'`.
- **Token rotation**: Every refresh issues a new access token AND a new refresh token. The old refresh token is revoked immediately.
- **Cross-domain**: Cookie uses `SameSite=None` and `Secure` to support frontend and backend on different domains. Frontend must set `withCredentials: true` on all requests.

## Permissions

- No separate permission middleware is applied to these endpoints.
- `POST /api/login` and `GET /api/me` return the authenticated user's effective Spatie role names in `roles`.
- `POST /api/login` and `GET /api/me` return the authenticated user's effective Spatie permissions in `permissions`.
- `GET /api/profile` and `POST /api/profile/change-password` operate on the authenticated user only — there is no permission to manage another user's password here. Admin/HR resetting another user's password is a separate endpoint: see `.claude/api/USER_API.md` (`POST /api/users/{user}/reset-password`, requires `users.reset_password`).

## Endpoint List

- `POST /api/login`
- `POST /api/refresh`
- `POST /api/logout`
- `GET /api/me`
- `GET /api/profile`
- `POST /api/profile/change-password`

---

## POST /api/login

Authenticate a user and issue an access token + refresh token.

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
    "access_token": "1|plain-text-token",
    "token_type": "Bearer",
    "expires_in": 900,
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

### Response Headers (Cookie)

```
Set-Cookie: refresh_token={token}; HttpOnly; Secure; SameSite=None; Path=/; Max-Age=604800
```

The refresh token is NOT included in the JSON response body. It is only set as an httpOnly cookie.

### Response Fields

- `access_token`: Bearer token for API requests. Expires after `expires_in` seconds.
- `token_type`: Always `"Bearer"`.
- `expires_in`: Access token lifetime in seconds (default: 900 = 15 minutes).
- `user`: Authenticated user with roles, permissions, and employee data.

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

- Non-admin users must have a linked `Employee` profile with `employment_status` of `full-time` or `probation` to log in. Users without an employee profile, or whose employee profile is `resigned`/`terminated`, are rejected:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["Your account is not linked to an active employee profile. Please contact HR."]
  }
}
```

- The `admin` role is exempt from the employee-profile check above.

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

## POST /api/refresh

Issue a new access token using the refresh token cookie. No authentication header required.

### Auth

None. The refresh token is read from the httpOnly cookie sent by the browser.

### Request Body

None.

### Prerequisites

- `withCredentials: true` must be set on the HTTP client so the browser sends the cookie.

### Response Example

```json
{
  "success": true,
  "message": "Token refreshed successfully.",
  "data": {
    "access_token": "2|new-plain-text-token",
    "token_type": "Bearer",
    "expires_in": 900
  }
}
```

### Response Headers (Cookie)

A new `refresh_token` cookie replaces the previous one (token rotation).

### Error Responses

- Missing cookie:

```json
{
  "success": false,
  "message": "Refresh token not found.",
  "errors": []
}
```
Status: 401

- Expired or revoked refresh token:

```json
{
  "success": false,
  "message": "Invalid or expired refresh token.",
  "errors": []
}
```
Status: 403

- Account deactivated:

```json
{
  "success": false,
  "message": "Account is inactive.",
  "errors": []
}
```
Status: 403

### Rate Limiting

This endpoint shares the `login` rate limiter (5 attempts per minute per email/IP).

---

## POST /api/logout

Revoke the current access token and the refresh token.

### Auth

```txt
Authorization: Bearer {access_token}
```

### Request Body

None. The refresh token is read from the httpOnly cookie.

### Response Example

```json
{
  "success": true,
  "message": "Logout successful.",
  "data": null
}
```

### Response Headers (Cookie)

```
Set-Cookie: refresh_token=; Max-Age=0
```

The refresh token cookie is cleared.

### Validation Notes

- Unauthenticated requests return the global `401` JSON error format.

---

## GET /api/me

Return the authenticated user for the active bearer token.

### Auth

```txt
Authorization: Bearer {access_token}
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

---

## GET /api/profile

Return the authenticated user's own profile, including their role, effective permissions, and linked employee record (if any).

### Auth

```txt
Authorization: Bearer {access_token}
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
      "employment_status": "full-time",
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
Authorization: Bearer {access_token}
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
- `password`: required string, max `255`, must be confirmed (`password_confirmation`), strong password (min `8` chars, must contain letters, mixed case, numbers, and symbols)

### Response Example

```json
{
  "success": true,
  "message": "Password changed successfully.",
  "data": null
}
```

### Behavior

- On success, the user's password is updated and **all other Sanctum tokens and refresh tokens for this user are revoked** — only the token used to make this request remains valid.
- All other active sessions/devices will be signed out immediately.
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

---

## Token Lifetimes

| Token | Default Lifetime | Env Variable |
|---|---|---|
| Access token | 15 minutes | `ACCESS_TOKEN_EXPIRATION_MINUTES` |
| Refresh token | 7 days | `REFRESH_TOKEN_EXPIRATION_DAYS` |

---

## What Invalidates Tokens

| Event | Access Token | Refresh Token |
|---|---|---|
| Logout | Current session deleted | Current session revoked |
| Password change (self) | Other sessions deleted | All revoked |
| Password reset (admin) | All deleted | All revoked |
| Account deactivated | All deleted | All revoked |
| Account deleted | All deleted | All revoked (cascade) |

---

## Frontend Integration Rules

1. **Storage**: Access token in memory only (not localStorage). Refresh token in httpOnly cookie (browser-managed, invisible to JS).
2. **Credentials**: All requests must use `withCredentials: true` (Axios) or `credentials: 'include'` (fetch) for the cookie to be sent cross-domain.
3. **CORS**: Backend allows credentials via `supports_credentials: true`. `Access-Control-Allow-Origin` must be the exact frontend domain (not `*`). Configured via `CORS_ALLOWED_ORIGINS` env variable.
4. **401 handling**: On 401 → call `POST /api/refresh` → retry original request. If refresh fails → redirect to login.
5. **Page refresh**: Access token is lost (it's in memory). Call `POST /api/refresh` on app initialization to restore session silently.
6. **Concurrent 401s**: Only one refresh call should be in-flight. Queue other failed requests and retry after refresh succeeds.
7. **Roles & permissions**: Provided by the backend for UI decisions only. Backend remains the authorization source of truth.
8. **Employee field**: Nullable. Handle users without a linked employee record.
9. **No device fingerprint**: Security relies on short-lived access tokens and httpOnly cookie refresh tokens, not device fingerprinting.
