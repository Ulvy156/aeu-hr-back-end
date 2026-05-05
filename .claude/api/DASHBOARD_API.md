# Dashboard API

## Purpose

Provide role-specific dashboard summary data after authentication.

## Base Endpoint

```txt
/api/dashboard
```

## Auth Requirement

All dashboard endpoints require a Sanctum bearer token.

## Permission Summary

- Employee dashboard: `dashboards.employee_view`
- HR dashboard: `dashboards.hr_view`
- CEO dashboard: `dashboards.ceo_view`
- Admin dashboard: `dashboards.admin_view`
- Admin users summary: `dashboards.admin_view`

## Endpoint List

- `GET /api/dashboard/employee`
- `GET /api/dashboard/hr`
- `GET /api/dashboard/ceo`
- `GET /api/dashboard/admin`
- `GET /api/dashboard/users-summary`

---

## GET /api/dashboard/employee

Return the authenticated employee dashboard summary.

### Permission Required

- `dashboards.employee_view`

### Response Shape

```json
{
  "success": true,
  "message": "Employee dashboard fetched successfully.",
  "data": {
    "today_attendance": {
      "id": 1,
      "attendance_date": "2026-05-05",
      "clock_in_time": "2026-05-05T08:00:00.000000Z",
      "clock_out_time": null,
      "status": "present",
      "is_late": false,
      "employee": {
        "id": 3,
        "employee_id": "EMP-00003",
        "full_name": "John Employee"
      }
    },
    "leave_balance": {
      "employee": {
        "id": 3,
        "employee_id": "EMP-00003",
        "full_name": "John Employee"
      },
      "year": 2026,
      "balances": [
        {
          "leave_type": "annual",
          "entitlement": "18.00",
          "used": "2.00",
          "remaining": "16.00",
          "is_unlimited": false,
          "rule": "per_year"
        }
      ]
    },
    "latest_approved_payslip": {
      "id": 5,
      "base_salary": "3000.00",
      "daily_rate": "100.00",
      "working_days": "30.00",
      "present_days": "30.00",
      "absent_days": "0.00",
      "unpaid_leave_days": "0.00",
      "gross_salary": "3000.00",
      "unpaid_deduction": "0.00",
      "absence_deduction": "0.00",
      "taxable_salary": "3000.00",
      "tax_amount": "200.00",
      "net_salary": "2800.00",
      "employee": {
        "id": 3,
        "employee_id": "EMP-00003",
        "full_name": "John Employee"
      },
      "payroll_batch": {
        "id": 2,
        "month": 4,
        "year": 2026,
        "status": "approved"
      }
    }
  }
}
```

### Response Notes

- `today_attendance` is `null` if the employee has no attendance record for today.
- `latest_approved_payslip` is `null` until an approved payroll item exists for the employee.
- Leave balances are calculated dynamically on the backend.
- Employees never receive another employee's dashboard, leave balance, or payslip data.

---

## GET /api/dashboard/hr

Return summary data for the HR dashboard.

### Permission Required

- `dashboards.hr_view`

### Response Shape

```json
{
  "success": true,
  "message": "HR dashboard fetched successfully.",
  "data": {
    "date": "2026-05-05",
    "today_attendance_summary": {
      "total_records": 25,
      "present_count": 18,
      "late_count": 3,
      "absent_count": 2,
      "missing_clock_out_count": 2
    },
    "pending_leave_requests": {
      "total": 4,
      "items": []
    },
    "payroll_status": {
      "counts": {
        "draft": 1,
        "pending_approval": 1,
        "approved": 3,
        "rejected": 0
      },
      "latest_batch": null,
      "latest_pending_approval_batch": null
    }
  }
}
```

### Response Notes

- `pending_leave_requests.items` contains the earliest pending leave requests still awaiting HR action, limited to 5 rows.
- `payroll_status.counts` is aggregated fully on the backend.
- Payroll batch summaries include only summary-level batch data and totals.

---

## GET /api/dashboard/ceo

Return summary data for the CEO dashboard.

### Permission Required

- `dashboards.ceo_view`

### Response Shape

```json
{
  "success": true,
  "message": "CEO dashboard fetched successfully.",
  "data": {
    "date": "2026-05-05",
    "pending_leave_approvals": {
      "total": 3,
      "items": []
    },
    "payroll_approval_summary": {
      "pending_approval_count": 1,
      "latest_pending_approval_batch": null,
      "recent_pending_batches": []
    }
  }
}
```

### Response Notes

- `pending_leave_approvals.items` contains leave requests still awaiting CEO action, limited to 5 rows.
- `recent_pending_batches` contains pending payroll batches only.

---

## GET /api/dashboard/admin

Return summary data for the admin dashboard.

### Permission Required

- `dashboards.admin_view`

### Response Shape

```json
{
  "success": true,
  "message": "Admin dashboard fetched successfully.",
  "data": {
    "user_count": 10,
    "user_summary": {
      "total_users": 10,
      "active_users": 8,
      "inactive_users": 2,
      "users_by_role": {
        "admin": 1,
        "hr": 2,
        "ceo": 1,
        "employee": 6
      }
    },
    "system_settings_summary": {
      "company_name": "AEU HR",
      "salary_currency": "USD",
      "payroll_day_rate": 26,
      "allowed_radius_meters": 30,
      "working_start_time": "08:00:00",
      "working_end_time": "17:00:00",
      "working_days": [
        "monday",
        "tuesday",
        "wednesday",
        "thursday",
        "friday",
        "saturday"
      ],
      "working_days_count": 6,
      "office_location_configured": true
    }
  }
}
```

### Response Notes

- `system_settings_summary` intentionally exposes only a safe summary subset.
- This endpoint is for aggregate dashboard data, not raw user, salary, or audit log listings.

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

- Each dashboard endpoint is role-specific and should be called only for users allowed to view that dashboard.
- Frontend must not infer payroll, leave, or attendance totals on its own.
- If the backend returns `403`, treat it as the final authority and hide or disable that dashboard view.
