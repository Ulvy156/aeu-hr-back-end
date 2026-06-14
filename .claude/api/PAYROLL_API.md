# Payroll API

## Purpose

Payroll batch generation, payroll review workflow, and payslip access contract.

## Base Endpoint

`/api`

## Auth Requirement

All payroll and payslip endpoints require Sanctum authentication.

## Permissions

Payroll endpoints:

- `payrolls.view_any`
- `payrolls.view_own`
- `payrolls.generate`
- `payrolls.update`
- `payrolls.submit`
- `payrolls.approve`
- `payrolls.reject`
- `payslips.view_any`
- `payslips.view_own`
- `payslips.download_any`
- `payslips.download_own`

## Endpoint List

### Payroll Batches

- `GET /api/payrolls`
- `POST /api/payrolls`
- `GET /api/payrolls/{payroll}`
- `PUT /api/payrolls/{payroll}`
- `POST /api/payrolls/{payroll}/submit`
- `POST /api/payrolls/{payroll}/approve`
- `POST /api/payrolls/{payroll}/reject`

### Payslips

- `GET /api/payslips`
- `GET /api/payslips/{payslip}`
- `GET /api/payslips/{payslip}/download`

## Request Body Fields

### `POST /api/payrolls`

```json
{
  "month": 4,
  "year": 2026
}
```

### `PUT /api/payrolls/{payroll}`

Only editable fields are accepted per item. Computed fields such as `gross_salary`, `taxable_salary`, and `net_salary` are backend-controlled.

```json
{
  "items": [
    {
      "id": 10,
      "base_salary": 3000,
      "working_days": 30,
      "present_days": 28,
      "absent_days": 0,
      "unpaid_leave_days": 2,
      "tax_amount": 50
    }
  ]
}
```

### `POST /api/payrolls/{payroll}/reject`

```json
{
  "rejection_reason": "Please correct the manual adjustments."
}
```

## Query Parameters

### `GET /api/payrolls`

- `month`
- `year`
- `status`
- `employee_id`
- `per_page`

### `GET /api/payslips`

- `employee_id`
- `payroll_batch_id`
- `month`
- `year`
- `status`
- `per_page`

## Response Example

### Payroll batch detail

```json
{
  "success": true,
  "message": "Payroll fetched successfully.",
  "data": {
    "id": 1,
    "month": 4,
    "year": 2026,
    "status": "approved",
    "rejection_reason": null,
    "item_count": 2,
    "totals": {
      "gross_salary": "4500.00",
      "unpaid_deduction": "100.00",
      "absence_deduction": "100.00",
      "tax_amount": "381.25",
      "nssf_deduction": "12.00",
      "net_salary": "3906.75"
    },
    "items": [
      {
        "id": 10,
        "base_salary": "3000.00",
        "daily_rate": "100.00",
        "working_days": "30.00",
        "present_days": "28.00",
        "absent_days": "0.00",
        "unpaid_leave_days": "1.00",
        "maternity_leave_days": "0.00",
        "gross_salary": "3000.00",
        "unpaid_deduction": "100.00",
        "absence_deduction": "0.00",
        "maternity_deduction": "0.00",
        "taxable_salary": "2900.00",
        "tax_rate": "0.0983",
        "tax_amount": "285.00",
        "nssf_deduction": "6.00",
        "tax_breakdown": [
          {
            "bracket": "0.00 - 375.00 @ 0.00%",
            "from": "0.00",
            "to": "375.00",
            "rate": "0.0000",
            "taxable_amount": "375.00",
            "tax_amount": "0.00"
          },
          {
            "bracket": "375.00 - 500.00 @ 5.00%",
            "from": "375.00",
            "to": "500.00",
            "rate": "0.0500",
            "taxable_amount": "125.00",
            "tax_amount": "6.25"
          },
          {
            "bracket": "500.00 - 2125.00 @ 10.00%",
            "from": "500.00",
            "to": "2125.00",
            "rate": "0.1000",
            "taxable_amount": "1625.00",
            "tax_amount": "162.50"
          },
          {
            "bracket": "2125.00 - 3125.00 @ 15.00%",
            "from": "2125.00",
            "to": "3125.00",
            "rate": "0.1500",
            "taxable_amount": "775.00",
            "tax_amount": "116.25"
          }
        ],
        "net_salary": "2609.00",
        "status": "locked",
        "employee": {
          "id": 5,
          "employee_id": "EMP-10001",
          "full_name": "Full Employee"
        }
      }
    ]
  }
}
```

### Payslip detail

```json
{
  "success": true,
  "message": "Payslip fetched successfully.",
  "data": {
    "id": 10,
    "base_salary": "3000.00",
    "daily_rate": "100.00",
    "working_days": "30.00",
    "present_days": "28.00",
    "absent_days": "0.00",
    "unpaid_leave_days": "1.00",
    "maternity_leave_days": "0.00",
    "gross_salary": "3000.00",
    "unpaid_deduction": "100.00",
    "absence_deduction": "0.00",
    "maternity_deduction": "0.00",
    "taxable_salary": "2900.00",
    "tax_rate": "0.0983",
    "tax_amount": "285.00",
    "nssf_deduction": "6.00",
    "tax_breakdown": [
      {
        "bracket": "0.00 - 375.00 @ 0.00%",
        "from": "0.00",
        "to": "375.00",
        "rate": "0.0000",
        "taxable_amount": "375.00",
        "tax_amount": "0.00"
      },
      {
        "bracket": "375.00 - 500.00 @ 5.00%",
        "from": "375.00",
        "to": "500.00",
        "rate": "0.0500",
        "taxable_amount": "125.00",
        "tax_amount": "6.25"
      },
      {
        "bracket": "500.00 - 2125.00 @ 10.00%",
        "from": "500.00",
        "to": "2125.00",
        "rate": "0.1000",
        "taxable_amount": "1625.00",
        "tax_amount": "162.50"
      },
      {
        "bracket": "2125.00 - 3125.00 @ 15.00%",
        "from": "2125.00",
        "to": "3125.00",
        "rate": "0.1500",
        "taxable_amount": "775.00",
        "tax_amount": "116.25"
      }
    ],
    "net_salary": "2609.00",
    "employee": {
      "id": 5,
      "employee_id": "EMP-10001",
      "full_name": "Full Employee"
    },
    "payroll_batch": {
      "id": 1,
      "month": 4,
      "year": 2026,
      "status": "approved"
    }
  }
}
```

## Validation Notes

- Duplicate payroll generation for the same `month` and `year` is rejected.
- Approved payroll batches are locked and cannot be edited.
- Payroll rejection requires `rejection_reason`.
- Employee payslip access is limited to the employee's own approved payslips.
- HR/Admin/CEO can view broader payroll or payslip data according to seeded permissions.
- Tax is calculated progressively from backend-configured tax brackets.
- Current default tax brackets are:
  - `0.00 - 375.00` -> `0%`
  - `375.00 - 500.00` -> `5%`
  - `500.00 - 2125.00` -> `10%`
  - `2125.00 - 3125.00` -> `15%`
  - `3125.00+` -> `20%`
- NSSF deduction is backend-calculated and snapshot per payroll item:
  - salary below `300.00` -> `4.00`
  - salary `300.00` or above -> `6.00`
- Approved `maternity` leave days reduce pay (`maternity_leave_days` / `maternity_deduction` on the payroll item) based on the employee's tenure as of the end of the payroll period:
  - Employees with **at least 1 year of service** (`join_date` to period end) are paid `50%` of their daily rate for maternity leave days — `maternity_deduction = daily_rate * 0.5 * maternity_leave_days`.
  - Employees with **less than 1 year of service** are unpaid for maternity leave days — `maternity_deduction = daily_rate * 1.0 * maternity_leave_days` (same effect as unpaid leave).
- Payslip PDF uses the company logo from company settings when available and falls back safely when no logo exists.

## Frontend Notes

- Use payroll batch APIs for HR/CEO workflow screens.
- Use payslip APIs for employee self-service and PDF download.
- Do not send frontend-calculated totals back as source of truth; the backend recalculates payroll item totals.
