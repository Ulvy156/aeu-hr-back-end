# Company Setting API

## Purpose

Manage the singleton company settings record used by attendance, payroll, and organization-wide configuration.

## Base Endpoint

```txt
/api/settings/company
```

## Auth Requirement

All company settings endpoints require a Sanctum bearer token.

## Permissions

- View company settings: `company_settings.view`
- Update company settings: `company_settings.update`

With the current seeded roles:

- `admin` can view and update company settings
- `hr` can view company settings
- `ceo` can view company settings

## Endpoint List

- `GET /api/settings/company`
- `PUT /api/settings/company` (JSON updates)
- `POST /api/settings/company` (`multipart/form-data` updates, required when uploading `company_logo`)

---

## GET /api/settings/company

Return the singleton company settings record.

### Response Example

```json
{
  "success": true,
  "message": "Company settings fetched successfully.",
  "data": {
    "id": 1,
    "company_name": "Laravel",
    "company_logo": null,
    "company_logo_url": null,
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
    "payroll_day_rate": 26,
    "created_at": "2026-05-03T00:00:00.000000Z",
    "updated_at": "2026-05-03T00:00:00.000000Z"
  }
}
```

### Notes

- The backend auto-creates the default singleton row on first read if it does not exist yet.
- `company_logo` stores only the relative object path; `company_logo_url` is the generated public file URL.

---

## PUT /api/settings/company

Update the singleton company settings record.

### Request Body

Use JSON (`PUT`) for normal updates. Use `multipart/form-data` (`POST`) when uploading `company_logo`.

> **Important:** PHP does not parse `multipart/form-data` bodies for `PUT` requests, so a `PUT` request with a `FormData` body arrives on the server empty and silently updates nothing. Always send `multipart/form-data` payloads as `POST /api/settings/company` (same endpoint, same controller action — no `_method` override needed).

```json
{
  "company_name": "AEU HR",
  "company_address": "Phnom Penh, Cambodia",
  "company_phone": "+85512345678",
  "company_email": "hr@aeu.test",
  "office_latitude": 11.5564,
  "office_longitude": 104.9282,
  "allowed_radius_meters": 250,
  "working_start_time": "09:00",
  "working_end_time": "18:00",
  "working_days": [
    "monday",
    "tuesday",
    "wednesday",
    "thursday",
    "friday"
  ],
  "salary_currency": "KHR",
  "payroll_day_rate": 24
}
```

### Request Fields

- `company_name`: optional string, max `255`
- `company_logo`: optional image file, `jpg`, `jpeg`, `png`, `webp`, or `svg`, max `2048 KB`
- `company_address`: optional nullable string
- `company_phone`: optional nullable string, max `50`
- `company_email`: optional nullable valid email, max `255`
- `office_latitude`: optional nullable numeric, between `-90` and `90`
- `office_longitude`: optional nullable numeric, between `-180` and `180`
- `allowed_radius_meters`: optional integer, between `1` and `100000`
- `working_start_time`: optional time, accepts `HH:MM` or `HH:MM:SS`
- `working_end_time`: optional time, accepts `HH:MM` or `HH:MM:SS`
- `working_days`: optional array, `1` to `7` unique weekday values
- `working_days.*`: one of `monday`, `tuesday`, `wednesday`, `thursday`, `friday`, `saturday`, `sunday`
- `salary_currency`: optional 3-letter alphabetic currency code; backend normalizes it to uppercase
- `payroll_day_rate`: optional integer, between `1` and `31`

### Validation Notes

- `office_latitude` and `office_longitude` must be provided together.
- `working_end_time` must be after `working_start_time`.
- Existing company logos are replaced when a new file is uploaded.
- The backend enforces a single-row company settings record and collapses duplicates during reads and updates.

### Audit Notes

- Successful updates create an audit log entry with module `company_settings` and action `update`.

## Frontend Notes

- Treat this as a singleton settings page, not a list/detail module.
- Use `company_logo_url` to display the current logo preview.
- `company_logo` remains the stored relative path, not the full public URL.
- Send `multipart/form-data` as `POST` only when replacing the logo; JSON via `PUT` is fine for normal updates. Never send a `multipart/form-data` body as `PUT` — PHP silently drops the body and the update becomes a no-op.
- Time inputs can submit `HH:MM`; the backend normalizes them to `HH:MM:SS` in the response.
