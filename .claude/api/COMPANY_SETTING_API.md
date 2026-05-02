# Company Setting API

## Purpose

Describe the current backend foundation for singleton company settings used by attendance and payroll logic.

## Base Endpoint

No public company settings API endpoint is implemented yet.

## Auth Requirement

No public endpoint is available yet.

## Permissions

Relevant seeded permissions already exist for future settings APIs:

- `company_settings.view`
- `company_settings.update`

## Endpoint List

- No public company settings endpoints are implemented yet.

## Request Body Fields

None for this module at the moment.

## Query Parameters

None for this module at the moment.

## Response Example

The backend currently seeds one singleton settings row with these defaults:

```json
{
  "company_name": "Laravel",
  "company_logo": null,
  "company_address": null,
  "company_phone": null,
  "company_email": null,
  "office_latitude": null,
  "office_longitude": null,
  "allowed_radius_meters": 100,
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
  "salary_currency": "USD",
  "payroll_day_rate": 26
}
```

## Validation Notes

- The backend enforces company settings as a single-row record through `CompanySettingService`.
- If duplicates ever exist, the service collapses them back to one row before returning or updating settings.

## Frontend Notes

- Do not assume `GET /api/settings/company` exists yet. It is planned for a later phase, not currently implemented.
- Later attendance and payroll UIs should expect these defaults unless the future settings API changes them.
- When the settings API is implemented, update this file only.
