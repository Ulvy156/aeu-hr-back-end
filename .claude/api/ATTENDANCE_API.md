# Attendance API

## Purpose

Handle employee clock-in, clock-out, attendance listing, attendance correction, absent marking, and QR code-based attendance.

## Base Endpoint

```txt
/api/attendance
```

## Auth Requirement

All attendance endpoints require a Sanctum bearer token.

## Permissions

- List any attendance: `attendance.view_any`
- List own attendance: `attendance.view_own`
- View attendance correction UI/data: `attendance.view_correction`
- Clock in: `attendance.clock_in`
- Clock out: `attendance.clock_out`
- Correct attendance: `attendance.correct`
- Mark absent: `attendance.mark_absent`
- Proxy clock in/out for employees: `attendance.proxy_clock`
- Generate / manage QR tokens: `attendance.generate_qr`

## Endpoint List

- `GET /api/attendance/summary`
- `POST /api/attendance/clock-in`
- `POST /api/attendance/clock-out`
- `POST /api/attendance/proxy-clock-in`
- `POST /api/attendance/proxy-clock-out`
- `GET /api/attendance`
- `PUT /api/attendance/{attendance}/correction`
- `POST /api/attendance/mark-absent`
- `POST /api/attendance/qr/generate`
- `GET /api/attendance/qr/current`
- `GET /api/attendance/qr/{qrToken}/download`
- `DELETE /api/attendance/qr/{qrToken}`
- `POST /api/attendance/qr/scan`

---

## GET /api/attendance/summary

Return the authenticated employee's own attendance summary for a given month.

### Permission

- Requires `attendance.view_own`

### Query Parameters

| Parameter | Type    | Required | Description                              |
|-----------|---------|----------|------------------------------------------|
| `month`   | integer | No       | Month number `1–12`. Defaults to current month. |
| `year`    | integer | No       | Four-digit year `2000–2100`. Defaults to current year. |

### Access Rules

- Employees can only view their own summary.
- If the authenticated user has no linked employee profile, the API returns `403`.
- For months in the future, all counts will be `0` and `today` will be `null`.

### Summary Calculation Rules

- `present` — count of attendance records with `status = present`.
- `late` — count of attendance records with `status = late`.
- `absent` — count of attendance records with `status = absent`.
- `missing_clock_out` — count of records where the employee clocked in but did not clock out.
- `attended_days` — `present + late + missing_clock_out` (days physically present regardless of late status).
- `working_days_in_period` — count of configured company working days in the period, excluding active public holidays and capped at today for the current month.
- `attendance_rate` — `(attended_days / working_days_in_period) × 100`, formatted to two decimals. Returns `"0.00"` when `working_days_in_period` is zero.

### `today` Field

- Included only when the requested period matches the current calendar month and year.
- `null` when the employee has no attendance record for today yet.
- Contains the employee's live clock-in/clock-out state for today when present.

### Success Example

```json
{
  "success": true,
  "message": "Attendance summary fetched successfully.",
  "data": {
    "employee": {
      "id": 1,
      "employee_id": "EMP-00001",
      "full_name": "Jane Doe"
    },
    "period": {
      "month": 5,
      "year": 2026,
      "from": "2026-05-01",
      "to": "2026-05-31"
    },
    "summary": {
      "present": 12,
      "late": 3,
      "absent": 1,
      "missing_clock_out": 0,
      "attended_days": 15,
      "working_days_in_period": 16,
      "attendance_rate": "93.75"
    },
    "today": {
      "status": "present",
      "clock_in_time": "2026-05-06T01:00:00.000000Z",
      "clock_out_time": null,
      "is_late": false
    }
  }
}
```

### Today Not Yet Clocked In Example

```json
{
  "success": true,
  "message": "Attendance summary fetched successfully.",
  "data": {
    "employee": { "id": 1, "employee_id": "EMP-00001", "full_name": "Jane Doe" },
    "period": { "month": 5, "year": 2026, "from": "2026-05-01", "to": "2026-05-31" },
    "summary": {
      "present": 12, "late": 3, "absent": 1, "missing_clock_out": 0,
      "attended_days": 15, "working_days_in_period": 16, "attendance_rate": "93.75"
    },
    "today": null
  }
}
```

### Past Month Example (no `today` field)

```json
{
  "success": true,
  "message": "Attendance summary fetched successfully.",
  "data": {
    "employee": { "id": 1, "employee_id": "EMP-00001", "full_name": "Jane Doe" },
    "period": { "month": 4, "year": 2026, "from": "2026-04-01", "to": "2026-04-30" },
    "summary": {
      "present": 18, "late": 2, "absent": 0, "missing_clock_out": 1,
      "attended_days": 21, "working_days_in_period": 21, "attendance_rate": "100.00"
    },
    "today": null
  }
}
```

---

## POST /api/attendance/proxy-clock-in

Clock in on behalf of a remote or system-impaired employee. Restricted to Admin and HR.

### Permission

`attendance.proxy_clock`

### Request Body

```json
{
  "employee_id": 5,
  "attendance_date": "2026-05-06"
}
```

### Rules

- `employee_id` is required and must exist in the employees table.
- `attendance_date` is required, must be a valid date, and cannot be in the future.
- If the employee has an approved leave covering `attendance_date`, the request is rejected (`"This employee is on approved leave on the selected date and cannot be clocked in."`).
- If a record already exists for that employee on that date, the request is rejected.
- Clock-in time is automatically set to the company `working_start_time` (default `08:00:00`). Frontend must not send a time.
- Status is set to `present`, `is_late` to `false` — because the time is exactly the working start time.
- No GPS validation is applied (remote work use case).
- The acting admin/HR is recorded in `proxied_clock_in_by_user` on the response.
- Action is audited under the `attendance` module as `proxy_clock_in`.

### Success Example

```json
{
  "success": true,
  "message": "Proxy clock-in recorded successfully.",
  "data": {
    "id": 42,
    "attendance_date": "2026-05-06",
    "clock_in_time": "2026-05-06T01:00:00.000000Z",
    "clock_out_time": null,
    "status": "present",
    "is_late": false,
    "correction_reason": null,
    "corrected_at": null,
    "employee": {
      "id": 5,
      "employee_id": "EMP-00005",
      "full_name": "John Remote"
    },
    "corrected_by_user": null,
    "proxied_clock_in_by_user": {
      "id": 2,
      "name": "Admin Alice",
      "email": "alice@company.com"
    },
    "proxied_clock_out_by_user": null,
    "created_at": "2026-05-06T03:00:00.000000Z",
    "updated_at": "2026-05-06T03:00:00.000000Z"
  }
}
```

### Error — Record Already Exists

```json
{
  "success": false,
  "message": "An attendance record already exists for this employee on the selected date.",
  "errors": []
}
```

---

## POST /api/attendance/proxy-clock-out

Clock out on behalf of a remote or system-impaired employee. Restricted to Admin and HR.

### Permission

`attendance.proxy_clock`

### Request Body

```json
{
  "employee_id": 5,
  "attendance_date": "2026-05-06"
}
```

### Rules

- `employee_id` is required and must exist in the employees table.
- `attendance_date` is required, must be a valid date, and cannot be in the future.
- If the employee has an approved leave covering `attendance_date`, the request is rejected (`"This employee is on approved leave on the selected date and cannot be clocked out."`).
- A clock-in record must already exist for the employee on the given date (whether it was a regular or proxy clock-in).
- If the employee has already clocked out, the request is rejected.
- Clock-out time is automatically set to the company `working_end_time` (default `17:00:00`). Frontend must not send a time.
- No GPS validation is applied.
- If the existing record had status `missing_clock_out`, the status is corrected to `late` or `present` based on the original clock-in time.
- The acting admin/HR is recorded in `proxied_clock_out_by_user` on the response.
- Action is audited under the `attendance` module as `proxy_clock_out`.

### Success Example

```json
{
  "success": true,
  "message": "Proxy clock-out recorded successfully.",
  "data": {
    "id": 42,
    "attendance_date": "2026-05-06",
    "clock_in_time": "2026-05-06T01:00:00.000000Z",
    "clock_out_time": "2026-05-06T10:00:00.000000Z",
    "status": "present",
    "is_late": false,
    "correction_reason": null,
    "corrected_at": null,
    "employee": {
      "id": 5,
      "employee_id": "EMP-00005",
      "full_name": "John Remote"
    },
    "corrected_by_user": null,
    "proxied_clock_in_by_user": {
      "id": 2,
      "name": "Admin Alice",
      "email": "alice@company.com"
    },
    "proxied_clock_out_by_user": {
      "id": 3,
      "name": "HR Bob",
      "email": "bob@company.com"
    },
    "created_at": "2026-05-06T03:00:00.000000Z",
    "updated_at": "2026-05-06T05:00:00.000000Z"
  }
}
```

### Error — No Clock-In Found

```json
{
  "success": false,
  "message": "No clock-in record found for this employee on the selected date. Clock in first.",
  "errors": []
}
```

### Error — Already Clocked Out

```json
{
  "success": false,
  "message": "This employee has already clocked out on the selected date.",
  "errors": []
}
```

---

## POST /api/attendance/clock-in

Clock in the authenticated employee using backend GPS validation.

### Request Body

```json
{
  "latitude": 11.5564,
  "longitude": 104.9282
}
```

### Rules

- Backend uses server time only.
- Frontend must not send clock-in time.
- Backend reads office latitude, longitude, and allowed radius from company settings.
- Clock-in is rejected when office GPS settings are missing.
- Clock-in is rejected if the employee has an approved leave covering today (`"You are on approved leave today and cannot clock in."`).
- Clock-in is rejected if today is an active public holiday (`"Today is a public holiday and attendance is not required."`).
- Clock-in is rejected if today is not a company working day (`"Today is not a working day."`).
- Duplicate same-day clock-in is rejected.
- Backend calculates `status` and `is_late`.

### Success Example

```json
{
  "success": true,
  "message": "Clock-in successful.",
  "data": {
    "id": 1,
    "attendance_date": "2026-05-05",
    "clock_in_time": "2026-05-05T01:30:00.000000Z",
    "clock_out_time": null,
    "status": "late",
    "is_late": true,
    "correction_reason": null,
    "corrected_at": null,
    "employee": {
      "id": 1,
      "employee_id": "EMP-00001",
      "full_name": "Example Employee"
    },
    "corrected_by_user": null,
    "created_at": "2026-05-05T01:30:00.000000Z",
    "updated_at": "2026-05-05T01:30:00.000000Z"
  }
}
```

### Business Error Example

```json
{
  "success": false,
  "message": "You are outside the allowed clock-in location.",
  "errors": []
}
```

### No Employee Profile Example

```json
{
  "success": false,
  "message": "No employee profile is linked to this user account.",
  "errors": []
}
```

---

## POST /api/attendance/clock-out

Clock out the authenticated employee using backend GPS validation.

### Request Body

```json
{
  "latitude": 11.5564,
  "longitude": 104.9282
}
```

### Rules

- Backend uses server time only.
- Frontend must not send clock-out time.
- Clock-out is rejected if the employee has an approved leave covering today (`"You are on approved leave today and cannot clock out."`).
- Clock-out is rejected if today is an active public holiday (`"Today is a public holiday and attendance is not required."`).
- Clock-out is rejected if today is not a company working day (`"Today is not a working day."`).
- Valid same-day clock-in must already exist.
- Duplicate same-day clock-out is rejected.
- Clock-out is rejected when office GPS settings are missing.

### No Employee Profile Example

```json
{
  "success": false,
  "message": "No employee profile is linked to this user account.",
  "errors": []
}
```

---

## GET /api/attendance

Return a paginated attendance list.

### Query Parameters

- `employee_id`: optional integer filter, only effective for users with `attendance.view_any`
- `attendance_date`: optional exact date filter
- `date_from`: optional start date filter
- `date_to`: optional end date filter
- `status`: optional enum, `present`, `late`, `absent`, or `missing_clock_out`
- `per_page`: optional integer, `1` to `100`

### Access Rules

- Users with `attendance.view_any` can list all attendance records.
- Users with `attendance.view_own` are automatically scoped to their own employee attendance only.
- If a user only has own-attendance access but has no linked employee profile, the API returns `403`.

---

## PUT /api/attendance/{attendance}/correction

Correct an attendance record as HR/Admin.

### Allowed Payload

Only these fields are accepted:

```json
{
  "clock_in_time": "2026-05-05 08:30:00",
  "clock_out_time": "2026-05-05 17:15:00",
  "status": "late",
  "correction_reason": "Manual correction after review"
}
```

### Allowed Fields

- `clock_in_time`
- `clock_out_time`
- `status`
- `correction_reason`

### Disallowed Fields

- `clock_in_latitude`
- `clock_in_longitude`
- `clock_out_latitude`
- `clock_out_longitude`
- `is_late`

### Correction Rules

- `correction_reason` is always required.
- `status` must be one of `present`, `late`, `absent`, `missing_clock_out`.
- `attendance.view_correction` is for viewing correction-related UI/data only and does not authorize updates.
- Backend stores `corrected_by` from the authenticated user.
- Backend stores `corrected_at` as current server time.
- Backend recalculates `is_late` from corrected `clock_in_time`, company `working_start_time`, and final attendance status.
- GPS fields cannot be corrected from the frontend.
- Employees cannot correct attendance.
- Successful corrections are audited.

---

## POST /api/attendance/mark-absent

Create absent attendance records for a date.

### Request Behavior

- No payload: marks absent for today
- With payload: marks absent for the provided date

### Request Example

```json
{
  "attendance_date": "2026-05-03"
}
```

### Rules

- Only users with `attendance.mark_absent` can run it.
- `attendance_date` is optional, must be a valid date, and cannot be in the future.
- If no date is provided, backend uses today.
- Backend skips non-working days from company settings.
- Backend skips active public holidays.
- Backend skips employees with approved leave overlapping the date.
- Backend respects employee `join_date`.
- Backend respects `last_working_date`.
- Backend does not create duplicate attendance rows.

### Success Example

```json
{
  "success": true,
  "message": "Absent marking completed successfully.",
  "data": {
    "attendance_date": "2026-05-03",
    "created_count": 4
  }
}
```

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

### 403 No Employee Profile

```json
{
  "success": false,
  "message": "No employee profile is linked to this user account.",
  "errors": []
}
```

### 422 Validation Example

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "attendance_date": [
      "The attendance date field must be a date before or equal to today."
    ]
  }
}
```

## Attendance Record Fields Reference

All attendance responses include these fields:

| Field | Description |
|---|---|
| `corrected_by_user` | Non-null when the record was manually corrected via the correction endpoint |
| `proxied_clock_in_by_user` | Non-null when an admin/HR clocked in on behalf of the employee |
| `proxied_clock_out_by_user` | Non-null when an admin/HR clocked out on behalf of the employee |

`proxied_clock_in_by_user` and `proxied_clock_out_by_user` are independent — a record can have a proxy clock-in but a self clock-out, or vice versa.

## Frontend Notes

- Frontend should send only `latitude` and `longitude` for clock-in and clock-out.
- Frontend must not calculate late status or GPS distance.
- Correction UI must not send GPS fields or `is_late`.
- `mark-absent` should display the returned `created_count` as the backend source of truth.
- Proxy clock-in/out UI must not send time fields — times are controlled by company settings on the backend.
- Show `proxied_clock_in_by_user` and `proxied_clock_out_by_user` as a badge or tooltip (e.g. "Clocked in by Admin Alice") so HR managers can identify proxy records at a glance.

---

## QR Code Attendance

The company prints a QR code and posts it outside the office. Employees scan it with their phone camera. The browser opens the HR web app at the `scan_url`, and the app auto-calls the scan endpoint. The backend determines clock-in or clock-out automatically based on the employee's attendance state for today.

QR tokens are **permanent** — they do not expire. They are valid until explicitly deleted by HR/admin.

### New fields on Attendance resource

| Field | Type | Description |
|---|---|---|
| `qr_clock_in` | boolean | True when this clock-in was performed via QR scan |
| `qr_clock_out` | boolean | True when this clock-out was performed via QR scan |

---

### POST /api/attendance/qr/generate

Generate a new QR token. **Idempotent** — if a token already exists, the same token is returned. HR calls this once, downloads the SVG, prints it, and posts it outside.

**Permission:** `attendance.generate_qr`

**Request body:** none

**Response 201:**
```json
{
  "success": true,
  "message": "QR token ready.",
  "data": {
    "id": 1,
    "token": "64-char-random-string",
    "scan_url": "https://hrapp.example.com/attendance/scan?token=64-char-random-string",
    "generated_by": { "id": 2, "name": "HR User", "email": "hr@example.com" },
    "created_at": "2026-06-16T08:00:00.000000Z"
  }
}
```

---

### GET /api/attendance/qr/current

Return the current active QR token for re-printing. Returns `data: null` if no token exists.

**Permission:** `attendance.generate_qr`

**Request body:** none

**Response 200:**
```json
{
  "success": true,
  "message": "Active QR token fetched.",
  "data": {
    "id": 1,
    "token": "64-char-random-string",
    "scan_url": "https://hrapp.example.com/attendance/scan?token=64-char-random-string",
    "generated_by": { "id": 2, "name": "HR User", "email": "hr@example.com" },
    "created_at": "2026-06-16T08:00:00.000000Z"
  }
}
```

---

### GET /api/attendance/qr/{qrToken}/download

Stream the QR code as an SVG file. The browser downloads `attendance-qr.svg` which HR prints and posts outside.

**Permission:** `attendance.generate_qr`

**Response:** `image/svg+xml` (Content-Disposition: attachment; filename="attendance-qr.svg")

---

### DELETE /api/attendance/qr/{qrToken}

Hard-delete a QR token. Use this when the printed QR is lost, stolen, or needs to be replaced. After deletion, call generate to create a new token and reprint.

**Permission:** `attendance.generate_qr`

**Request body:** none

**Response 200:**
```json
{
  "success": true,
  "message": "QR token deleted successfully.",
  "data": null
}
```

---

### POST /api/attendance/qr/scan

Employee submits the scanned QR token. The backend automatically determines whether to clock in or clock out based on the employee's attendance state today. **No GPS is required** — scanning the office QR proves physical presence.

**Permission:** `attendance.clock_in`

**Request body:**
```json
{ "token": "64-char-random-string" }
```

**Validation:**
- `token`: required, string, exactly 64 characters

**Clock-in response 201:**
```json
{
  "success": true,
  "message": "QR clock-in successful.",
  "data": {
    "action": "qr_clock_in",
    "attendance": { ...AttendanceResource... }
  }
}
```

**Clock-out response 200:**
```json
{
  "success": true,
  "message": "QR clock-out successful.",
  "data": {
    "action": "qr_clock_out",
    "attendance": { ...AttendanceResource... }
  }
}
```

**Error responses:**
| Status | Message |
|---|---|
| 422 | `Invalid QR code.` — token does not exist in DB |
| 422 | `You are on approved leave today and cannot use QR attendance.` |
| 422 | `Today is a public holiday and attendance is not required.` |
| 422 | `Today is not a working day.` |
| 422 | `You have already completed your attendance for today.` |
| 403 | `No employee profile is linked to this user account.` |

**Frontend integration notes:**

**`/attendance/scan` page — standalone page (no sidebar, mobile-optimised):**

The full entry flow when the phone camera opens `scan_url`:

1. **Check auth state** (read stored token / session).
2. **Not authenticated** → save the full current URL (`/attendance/scan?token=...`) into `sessionStorage` (e.g. key `redirectAfterLogin`), then redirect to `/login`.
3. **Login page** — after a successful login, check `sessionStorage.redirectAfterLogin`. If it exists, clear the key and navigate to that URL instead of the default home.
4. **Authenticated** (either directly or after the login redirect) → the `/attendance/scan` page reads `token` from the URL query params and automatically calls `POST /api/attendance/qr/scan` without any user interaction.
5. Display a full-screen result:
   - **Clock-in success** — "Clock-in recorded at HH:MM"
   - **Clock-out success** — "Clock-out recorded at HH:MM"
   - **Error** — show the `message` from the response (e.g. "Already completed", "Invalid QR code")

No submit button is needed — the API call fires automatically on page load once the user is authenticated.

- The QR code encodes `scan_url` from the generate response. Use `useQRCode()` from `@vueuse/core` to render it in the HR dashboard, or use the `/download` endpoint to get a printable SVG (vector — prints at any size without pixelation).
- `qr_clock_in` / `qr_clock_out` booleans on the attendance record can be shown as a badge (e.g. "Via QR") in the attendance list.
