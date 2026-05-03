# Attendance API

## Purpose

Handle employee clock-in, clock-out, attendance listing, attendance correction, and absent marking.

## Base Endpoint

```txt
/api/attendance
```

## Auth Requirement

All attendance endpoints require a Sanctum bearer token.

## Permissions

- List any attendance: `attendance.view_any`
- List own attendance: `attendance.view_own`
- Clock in: `attendance.clock_in`
- Clock out: `attendance.clock_out`
- Correct attendance: `attendance.correct`
- Mark absent: `attendance.mark_absent`

## Endpoint List

- `POST /api/attendance/clock-in`
- `POST /api/attendance/clock-out`
- `GET /api/attendance`
- `PUT /api/attendance/{attendance}/correction`
- `POST /api/attendance/mark-absent`

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

## Frontend Notes

- Frontend should send only `latitude` and `longitude` for clock-in and clock-out.
- Frontend must not calculate late status or GPS distance.
- Correction UI must not send GPS fields or `is_late`.
- `mark-absent` should display the returned `created_count` as the backend source of truth.
