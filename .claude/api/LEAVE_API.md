# Leave API

## Purpose

Handle leave requests, leave listing and detail, dynamic leave balances, dual approval, rejection, and cancellation.

## Base Endpoints

```txt
/api/leaves
/api/leave-balances
```

## Auth Requirement

All leave endpoints require a Sanctum bearer token.

## Permissions

### Leave Requests

- List any leave: `leaves.view_any`
- View leave detail: `leaves.view`
- List or view own leave: `leaves.view_own`
- Create leave: `leaves.create`
- Cancel own pending leave: `leaves.cancel`

### Leave Approval

- Generic approval alias: `leaves.approve`
- Generic rejection alias: `leaves.reject`
- HR approval: `leaves.approve_hr`
- HR rejection: `leaves.reject_hr`
- CEO approval: `leaves.approve_ceo`
- CEO rejection: `leaves.reject_ceo`

### Leave Balances

- View any employee balance: `leave_balances.view_any`
- View own balance: `leave_balances.view_own`

## Endpoint List

- `POST /api/leaves`
- `GET /api/leaves`
- `GET /api/leaves/{leave}`
- `GET /api/leave-balances`
- `POST /api/leaves/{leave}/approve`
- `POST /api/leaves/{leave}/reject`
- `POST /api/leaves/{leave}/cancel`

---

## POST /api/leaves

Create a leave request for the authenticated employee profile.

### Request Body

```json
{
  "leave_type": "annual",
  "start_date": "2026-05-04",
  "end_date": "2026-05-06",
  "duration_type": "full_day",
  "reason": "Family event"
}
```

### Rules

- Backend calculates `total_days`.
- Frontend must not send `employee_id`, `status`, or `total_days`.
- `reason` is required.
- Supported `leave_type` values:
  - `annual`
  - `sick`
  - `special`
  - `maternity`
  - `unpaid`
- Supported `duration_type` values:
  - `full_day`
  - `half_day`
- `half_day` must use the same `start_date` and `end_date`.
- Public holidays are excluded.
- Non-working days from company settings are excluded.
- If all selected dates are excluded, the request is rejected.
- Paid annual, sick, and special leave must fit the available balance for each affected year.
- Maternity leave is limited to `90` days per request case. The request is rejected if `total_days` for the request exceeds the entitlement.
- Maternity leave is restricted to employees with `gender` of `female`.
- Unpaid leave has no balance limit.
- Past-date leave requests are currently allowed. The backend applies the same working-day, holiday, and balance rules to past and future dates.

### Success Example

```json
{
  "success": true,
  "message": "Leave requested successfully.",
  "data": {
    "id": 1,
    "leave_type": "annual",
    "start_date": "2026-05-04",
    "end_date": "2026-05-06",
    "duration_type": "full_day",
    "total_days": "3.00",
    "reason": "Family event",
    "status": "pending",
    "hr_approval_status": "pending",
    "ceo_approval_status": "pending",
    "rejection_reason": null,
    "cancelled_at": null,
    "employee": {
      "id": 1,
      "employee_id": "EMP-00001",
      "full_name": "Example Employee"
    },
    "hr_approved_by_user": null,
    "ceo_approved_by_user": null,
    "created_at": "2026-05-04T02:00:00.000000Z",
    "updated_at": "2026-05-04T02:00:00.000000Z"
  }
}
```

### Balance Error Example

```json
{
  "success": false,
  "message": "Requested annual leave exceeds the available 2026 balance.",
  "errors": []
}
```

---

## GET /api/leaves

Return a paginated leave list.

### Query Parameters

- `employee_id`: optional integer filter, only effective for users who can view all leave
- `status`: optional enum, `pending`, `approved`, `rejected`, or `cancelled`
- `leave_type`: optional enum, `annual`, `sick`, `special`, `maternity`, or `unpaid`
- `date_from`: optional start date filter
- `date_to`: optional end date filter
- `per_page`: optional integer, `1` to `100`

### Access Rules

- Employees are scoped to their own leave records only.
- HR, CEO, and Admin can view based on their permissions.
- Date filtering matches overlapping leave periods, not only exact start dates.
- Employee and approver relations are eager loaded.
- Leave responses intentionally expose only minimal employee identity fields needed for leave screens.

---

## GET /api/leaves/{leave}

Return one leave request.

### Access Rules

- Employees can only view their own leave.
- HR, CEO, and Admin can view by permission.

---

## GET /api/leave-balances

Return dynamic leave balances without a `leave_balances` table.

### Query Parameters

- `year`: optional integer, defaults to current year
- `employee_id`: optional integer

### Access Rules

- Employees can view their own balances.
- HR, CEO, and Admin can inspect another employee by `employee_id`.
- If a manager account has no linked employee profile, `employee_id` is required.

### Balance Rules

- Annual entitlement: `18.00` days per year
- Sick entitlement: `7.00` days per year, fully paid
- Special entitlement: `7.00` days per year
- Maternity entitlement: `90.00` days per case
- Unpaid leave is unlimited
- Only approved leave reduces annual, sick, and special balances

### Success Example

```json
{
  "success": true,
  "message": "Leave balances fetched successfully.",
  "data": {
    "employee": {
      "id": 1,
      "employee_id": "EMP-00001",
      "full_name": "Example Employee"
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
      },
      {
        "leave_type": "sick",
        "entitlement": "7.00",
        "used": "1.00",
        "remaining": "6.00",
        "is_unlimited": false,
        "rule": "per_year"
      },
      {
        "leave_type": "special",
        "entitlement": "7.00",
        "used": "0.00",
        "remaining": "7.00",
        "is_unlimited": false,
        "rule": "per_year"
      },
      {
        "leave_type": "maternity",
        "entitlement": "90.00",
        "used": "0.00",
        "remaining": "90.00",
        "is_unlimited": false,
        "rule": "per_case"
      },
      {
        "leave_type": "unpaid",
        "entitlement": null,
        "used": null,
        "remaining": null,
        "is_unlimited": true,
        "rule": "unlimited"
      }
    ]
  }
}
```

---

## POST /api/leaves/{leave}/approve

Approve a leave request as HR or CEO.

### Rules

- Employee cannot approve leave.
- Admin can view leave but does not approve through leave policy rules.
- Approval is intentionally role-specific because the leave table stores separate HR and CEO approval tracks.
- Approval order does not matter.
- Leave becomes fully `approved` only after both HR and CEO approve.
- If only one side has approved, final `status` remains `pending`.
- Approval actions are wrapped in a database transaction.
- Approval actions are audited in the `leaves` audit log module.

---

## POST /api/leaves/{leave}/reject

Reject a leave request as HR or CEO.

### Request Body

```json
{
  "rejection_reason": "Insufficient coverage for requested dates."
}
```

### Rules

- `rejection_reason` is required.
- Employee cannot reject leave.
- If HR rejects, final status becomes `rejected`.
- If CEO rejects, final status becomes `rejected`.
- Rejection actions are wrapped in a database transaction.
- Rejection actions are audited in the `leaves` audit log module.

---

## POST /api/leaves/{leave}/cancel

Cancel a leave request.

### Rules

- Only the owner of the leave request can cancel it.
- Only `pending` leave can be cancelled.
- Approved, rejected, and already cancelled leave cannot be cancelled again.
- Cancellation runs inside a database transaction.
- Cancellation is audited in the `leaves` audit log module.

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
    "rejection_reason": [
      "The rejection reason field is required."
    ]
  }
}
```

## Frontend Notes

- Frontend must not calculate or trust `total_days`.
- Frontend should display leave balances from `GET /api/leave-balances` as the source of truth.
- Approval buttons should remain role-aware:
  - HR approves or rejects through HR permissions
  - CEO approves or rejects through CEO permissions
- For manager accounts that do not have a linked employee profile, pass `employee_id` when loading leave balances.
- `special` leave (e.g. marriage, childbirth of spouse, death of immediate family) is treated like `annual`/`sick`: fully paid, `7.00` days/year, no extra fields or validation beyond the standard `reason` text.
- Approved `maternity` leave reduces pay during payroll: employees with at least 1 year of service receive `50%` of their daily rate for maternity days, employees with less than 1 year receive `0%`. See `.claude/api/PAYROLL_API.md` for the payroll-side calculation.
- `special_sick` leave is for extended serious illness. Rules:
  - Eligibility: employee must have at least 1 year of service (tenure check via `join_date`).
  - Max 180 days per case, one case per calendar year.
  - Tiered pay deduction during payroll:
    - Days 1–30: 100% pay (no deduction)
    - Days 31–90: 60% pay (40% deduction rate)
    - Days 91–180: 0% pay (full deduction)
  - The tier is calculated based on cumulative working days from the leave start date.
  - No prerequisite — does not require exhausting the regular 7-day sick leave first.
  - Balance display uses `rule: 'per_case_per_year'`.
