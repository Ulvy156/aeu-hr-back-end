# Reports and Exports API

## Purpose

Provide backend-generated payroll, attendance, and leave reports plus Excel exports.

## Base Endpoint

```txt
/api/reports
```

## Auth Requirement

All report endpoints require a Sanctum bearer token.

## Permissions

- Payroll report view: `reports.payroll_view`
- Payroll report export: `reports.payroll_export`
- Attendance report view: `reports.attendance_view`
- Attendance report export: `reports.attendance_export`
- Leave report view: `reports.leave_view`
- Leave report export: `reports.leave_export`

## Endpoint List

- `GET /api/reports/payroll`
- `GET /api/reports/payroll/export`
- `GET /api/reports/attendance`
- `GET /api/reports/attendance/export`
- `GET /api/reports/leave`
- `GET /api/reports/leave/export`

---

## GET /api/reports/payroll

Return payroll report data based on `report_type`.

### Query Parameters

- `report_type`
  - `employee_list` default
  - `monthly_summary`
  - `status_summary`
- `month`
- `year`
- `status`
  - `draft`
  - `pending_approval`
  - `approved`
  - `rejected`
- `employee_id`
- `per_page`

### Response Notes

- `employee_list` is paginated payroll item data with backend totals.
- `monthly_summary` is paginated payroll batch summary data.
- `status_summary` is aggregate data grouped by payroll batch status and is not paginated.

---

## GET /api/reports/payroll/export

Download the selected payroll report as Excel.

### Query Parameters

Same filters as `GET /api/reports/payroll`.

### File Names

- `payroll-employee-list-report.xlsx`
- `payroll-monthly-summary-report.xlsx`
- `payroll-status-summary-report.xlsx`

---

## GET /api/reports/attendance

Return attendance report data based on `report_type`.

### Query Parameters

- `report_type`
  - `daily_list` default
  - `monthly_summary`
  - `late_employees`
  - `absent_employees`
  - `correction_list`
- `employee_id`
- `attendance_date`
- `date_from`
- `date_to`
- `month`
- `year`
- `status`
- `per_page`

### Response Notes

- `daily_list`, `late_employees`, `absent_employees`, and `correction_list` return paginated attendance rows.
- `monthly_summary` returns paginated employee-level aggregates for the selected month or date range.

---

## GET /api/reports/attendance/export

Download the selected attendance report as Excel.

### Query Parameters

Same filters as `GET /api/reports/attendance`.

### File Names

- `attendance-daily-list-report.xlsx`
- `attendance-monthly-summary-report.xlsx`
- `attendance-late-employees-report.xlsx`
- `attendance-absent-employees-report.xlsx`
- `attendance-correction-list-report.xlsx`

---

## GET /api/reports/leave

Return leave report data based on `report_type`.

### Query Parameters

- `report_type`
  - `request_list` default
  - `pending_approval`
  - `approved`
  - `rejected`
  - `leave_balance`
- `employee_id`
- `status`
  - `pending`
  - `approved`
  - `rejected`
  - `cancelled`
- `leave_type`
  - `annual`
  - `sick`
  - `maternity`
  - `unpaid`
- `date_from`
- `date_to`
- `year`
- `per_page`

### Response Notes

- `request_list`, `pending_approval`, `approved`, and `rejected` return paginated leave request data.
- `leave_balance` returns paginated employee leave balance rows calculated dynamically on the backend.

---

## GET /api/reports/leave/export

Download the selected leave report as Excel.

### Query Parameters

Same filters as `GET /api/reports/leave`.

### File Names

- `leave-request-list-report.xlsx`
- `leave-pending-approval-report.xlsx`
- `leave-approved-report.xlsx`
- `leave-rejected-report.xlsx`
- `leave-balance-report.xlsx`

---

## Success Response Shapes

### Paginated Report

```json
{
  "success": true,
  "message": "Payroll report fetched successfully.",
  "data": {
    "report_type": "employee_list",
    "summary": {},
    "items": []
  },
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 0
  }
}
```

### Non-Paginated Report

```json
{
  "success": true,
  "message": "Payroll report fetched successfully.",
  "data": {
    "report_type": "status_summary",
    "summary": {},
    "items": []
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

## Frontend Notes

- Always pass the intended `report_type`.
- Use backend aggregates and totals directly.
- Treat export endpoints as file downloads only.
- Do not recalculate payroll, attendance, or leave summaries on the frontend.
