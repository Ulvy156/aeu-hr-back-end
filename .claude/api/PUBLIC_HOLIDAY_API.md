# Public Holiday API

## Purpose

Manage public holidays that later affect attendance and payroll calculations.

## Base Endpoint

```txt
/api/public-holidays
```

## Auth Requirement

All public holiday endpoints require a Sanctum bearer token.

## Permissions

- List public holidays: `public_holidays.view_any`
- Create public holidays: `public_holidays.create`
- Update public holidays: `public_holidays.update`
- Disable public holidays: `public_holidays.delete`

With the current seeded roles:

- `admin` can manage public holidays
- `hr` can manage public holidays
- `ceo` and `employee` cannot manage public holidays

## Endpoint List

- `GET /api/public-holidays`
- `POST /api/public-holidays`
- `PUT /api/public-holidays/{public_holiday}`
- `DELETE /api/public-holidays/{public_holiday}`

---

## GET /api/public-holidays

Return a paginated holiday list.

### Query Parameters

- `search`: optional string, filters by holiday name or description
- `status`: optional enum, `active` or `inactive`
- `year`: optional integer year filter
- `per_page`: optional integer, `1` to `100`

### Response Example

```json
{
  "success": true,
  "message": "Public holidays fetched successfully.",
  "data": [
    {
      "id": 1,
      "holiday_date": "2026-04-14",
      "name": "Khmer New Year",
      "description": "National holiday",
      "status": "active",
      "created_at": "2026-05-03T00:00:00.000000Z",
      "updated_at": "2026-05-03T00:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

---

## POST /api/public-holidays

Create a new public holiday.

### Request Body

```json
{
  "holiday_date": "2026-11-09",
  "name": "Independence Day",
  "description": "Public holiday for independence day",
  "status": "active"
}
```

### Request Fields

- `holiday_date`: required date, unique in `public_holidays`
- `name`: required string, max `255`
- `description`: optional nullable string
- `status`: required enum, `active` or `inactive`

### Audit Notes

- Successful creation writes an audit log with module `public_holidays` and action `create`.

---

## PUT /api/public-holidays/{public_holiday}

Update an existing public holiday.

### Validation Notes

- `holiday_date` must remain unique.
- `status` must be `active` or `inactive`.

### Audit Notes

- Successful updates write an audit log with module `public_holidays` and action `update`.

---

## DELETE /api/public-holidays/{public_holiday}

Disable a public holiday instead of removing the row.

### Behavior Notes

- The backend sets `status` to `inactive`.
- The holiday record remains in the database for attendance and payroll history.

### Audit Notes

- Successful disable actions write an audit log with module `public_holidays` and action `delete`.

## Frontend Notes

- Treat delete as a disable action in the UI.
- Use the `status` field to separate active holidays from archived ones.
- Keep holiday dates unique in the form to avoid duplicate business-rule dates.
