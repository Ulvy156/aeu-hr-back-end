# Payroll API

## Purpose

Payroll and payslip module contract.

## Base Endpoint

No public payroll or payslip API endpoint is implemented yet.

## Auth Requirement

No public endpoint is available yet.

## Permissions

Relevant seeded permissions already exist for future payroll APIs:

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

- No public payroll endpoints are implemented yet.

## Request Body Fields

None documented yet because no payroll API route exists.

## Query Parameters

None documented yet because no payroll API route exists.

## Response Example

No response contract yet because no payroll API route exists.

## Validation Notes

- Do not build frontend payroll generation, approval, or payslip download flows until the backend routes exist and are documented here.

## Frontend Notes

- When payroll and payslip APIs are implemented, keep both contracts in this file unless they are later intentionally split.
