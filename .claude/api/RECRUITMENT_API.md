# Recruitment API

## Purpose

Recruitment Management: vacancy postings (with required/filled headcount and open/closed lifecycle) and candidate pipeline tracking (CV upload, source, interview scheduling, status pipeline, and hiring outcome).

## Base Endpoints

```txt
/api/recruitment/vacancies
/api/recruitment/candidates
```

## Auth Requirement

All recruitment endpoints require a Sanctum bearer token.

## Permissions

### Vacancies

- View vacancies (list/show): `recruitment.vacancies.view`
- Create vacancy: `recruitment.vacancies.create`
- Update vacancy: `recruitment.vacancies.update`
- Close vacancy: `recruitment.vacancies.close` (only allowed while the vacancy is `open`)

### Candidates

- View candidates (list/show): `recruitment.candidates.view`
- Create candidate: `recruitment.candidates.create`
- Update candidate / change status (to any non-`hired` status): `recruitment.candidates.update`
- Change status to `hired`: `recruitment.candidates.hire` (in addition to `recruitment.candidates.update`)

Admin receives all of the above through the existing `all => true` role configuration.

Default assignments for the other roles (configured in `config/hr_permissions.php`, synced via `RoleSeeder`):

- `hr`: `recruitment.vacancies.view`, `recruitment.vacancies.create`, `recruitment.vacancies.update`, `recruitment.vacancies.close`, `recruitment.candidates.view`, `recruitment.candidates.create`, `recruitment.candidates.update`
- `ceo`: none by default
- `employee`: none by default

`hr` does not receive `recruitment.candidates.hire` by default. Grant it via the role/permission management endpoints if HR should be able to mark candidates as hired.

## Endpoint List

- `GET /api/recruitment/vacancies`
- `POST /api/recruitment/vacancies`
- `GET /api/recruitment/vacancies/{vacancy}`
- `PUT /api/recruitment/vacancies/{vacancy}`
- `POST /api/recruitment/vacancies/{vacancy}/close`
- `GET /api/recruitment/candidates`
- `POST /api/recruitment/candidates`
- `GET /api/recruitment/candidates/{candidate}`
- `PUT /api/recruitment/candidates/{candidate}`
- `POST /api/recruitment/candidates/{candidate}/status`

---

## GET /api/recruitment/vacancies

Return a paginated list of vacancies, eager loaded with `department` and `creator`, sorted newest first.

### Query Parameters

- `search`: optional string, matches `title`
- `department`: optional integer, filters by `department_id`
- `status`: optional enum, `open` or `closed`
- `target_hiring_date`: optional date, exact match
- `per_page`: optional integer, `1` to `100`

### Response Example

```json
{
  "success": true,
  "message": "Vacancies fetched successfully.",
  "data": [
    {
      "id": 1,
      "title": "Backend Developer",
      "department": { "id": 1, "name": "Engineering" },
      "description": "Build and maintain APIs.",
      "required_headcount": 2,
      "filled_headcount": 0,
      "target_hiring_date": "2026-07-11",
      "status": "open",
      "creator": { "id": 1, "name": "Admin User" },
      "created_at": "2026-06-11T00:00:00.000000Z",
      "updated_at": "2026-06-11T00:00:00.000000Z"
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

## POST /api/recruitment/vacancies

Create a new vacancy.

### Request Body

```json
{
  "title": "Backend Developer",
  "department_id": 1,
  "description": "Build and maintain APIs.",
  "required_headcount": 2,
  "target_hiring_date": "2026-07-11"
}
```

### Request Fields

- `title`: required string, max `255`
- `department_id`: required integer, must reference an existing department
- `description`: required string
- `required_headcount`: required integer, min `1`
- `target_hiring_date`: required date

The frontend must not send `status`, `filled_headcount`, or `created_by` — these are backend-controlled. New vacancies are always created with `status = "open"` and `filled_headcount = 0`.

### Response

`201 Created` with the created vacancy (see response shape above).

---

## GET /api/recruitment/vacancies/{vacancy}

Return one vacancy, eager loaded with `department` and `creator`.

---

## PUT /api/recruitment/vacancies/{vacancy}

Update a vacancy's details.

### Request Body

Same fields as `POST /api/recruitment/vacancies` (`title`, `department_id`, `description`, `required_headcount`, `target_hiring_date`). `status`, `filled_headcount`, and `created_by` remain backend-controlled and are rejected if sent.

This endpoint does not change `status` or `filled_headcount` — use `POST /api/recruitment/vacancies/{vacancy}/close` to close a vacancy.

---

## POST /api/recruitment/vacancies/{vacancy}/close

Close an `open` vacancy. Sets `status` to `closed`.

### Rules

- Only `open` vacancies can be closed. Closing an already-`closed` vacancy returns `422 Unprocessable Entity` ("This vacancy is already closed.").
- Closed vacancies cannot be reopened (no endpoint is provided for this).
- Once closed, candidates cannot be created against this vacancy (see `POST /api/recruitment/candidates`).

---

## GET /api/recruitment/candidates

Return a paginated list of candidates, eager loaded with `vacancy` (`id`, `title`, `status`) and `creator`, sorted newest first.

### Query Parameters

- `search`: optional string, matches `full_name`, `phone`, or `email`
- `vacancy`: optional integer, filters by `vacancy_id`
- `source`: optional enum, `facebook`, `telegram`, `linkedin`, `referral`, `walk_in`, `email`, `other`
- `status`: optional enum, see Candidate Status Pipeline below
- `interview_date`: optional date, exact match
- `per_page`: optional integer, `1` to `100`

### Response Example

```json
{
  "success": true,
  "message": "Candidates fetched successfully.",
  "data": [
    {
      "id": 1,
      "vacancy": { "id": 1, "title": "Backend Developer", "status": "open" },
      "full_name": "Jane Doe",
      "phone": "012345678",
      "email": "jane@example.com",
      "source": "linkedin",
      "cv": {
        "name": "resume.pdf",
        "size": 204800,
        "url": "https://.../recruitment-cvs/xyz.pdf"
      },
      "status": "new",
      "interview_date": null,
      "interviewer": null,
      "notes": null,
      "outcome_reason": null,
      "creator": { "id": 1, "name": "Admin User" },
      "created_at": "2026-06-11T00:00:00.000000Z",
      "updated_at": "2026-06-11T00:00:00.000000Z"
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

## POST /api/recruitment/candidates

Create a new candidate under a vacancy. New candidates always start with `status = "new"`.

Use `multipart/form-data` (a `cv` file is always required).

### Request Body (multipart/form-data)

- `vacancy_id`: required integer, must reference an existing vacancy and the vacancy must be `open`
- `full_name`: required string, max `255`
- `phone`: required string, max `50`, unique within the same `vacancy_id` (duplicate phone for the same vacancy is rejected; the same phone can be used for a different vacancy)
- `email`: optional nullable email, max `255`
- `source`: required enum, `facebook`, `telegram`, `linkedin`, `referral`, `walk_in`, `email`, `other`
- `cv`: required file, PDF only, max `2048 KB`
- `interview_date`: optional nullable date
- `interviewer`: optional nullable string, max `255`
- `notes`: optional nullable string

The frontend must not send `status`, `outcome_reason`, or `created_by` — these are backend-controlled.

### Response

`201 Created` with the created candidate (see response shape above), `status` always `"new"`.

### Validation Notes

- If `vacancy_id` references a `closed` vacancy, `vacancy_id` fails validation with "Candidates can only be added to an open vacancy."
- If `phone` already exists for the same `vacancy_id`, `phone` fails validation with "A candidate with this phone number already applied for this vacancy."

---

## GET /api/recruitment/candidates/{candidate}

Return one candidate, eager loaded with `vacancy` and `creator`.

---

## PUT /api/recruitment/candidates/{candidate}

Update a candidate's details. `vacancy_id` cannot be changed (candidates cannot be moved between vacancies).

### Request Body

Same fields as `POST /api/recruitment/candidates` except:

- `vacancy_id`: not accepted (`prohibited`)
- `cv`: optional nullable file, PDF only, max `2048 KB` — if provided, replaces the existing CV (the old file is deleted from storage)

`status` and `outcome_reason` cannot be set via this endpoint — use `POST /api/recruitment/candidates/{candidate}/status`.

### Rules

- A candidate with `status = "hired"` is read-only: this endpoint returns `422 Unprocessable Entity` ("Hired candidates are read-only and cannot be updated.").
- The `phone` uniqueness check is scoped to the candidate's current `vacancy_id`, ignoring the candidate's own record.

---

## POST /api/recruitment/candidates/{candidate}/status

Change a candidate's pipeline status.

### Request Body

```json
{
  "status": "interview",
  "outcome_reason": null
}
```

### Request Fields

- `status`: required enum, see Candidate Status Pipeline below
- `outcome_reason`: required string when `status` is `company_rejected`, `candidate_declined`, or `no_show`; otherwise nullable/ignored

### Rules

- A candidate with `status = "hired"` is read-only: this endpoint returns `422 Unprocessable Entity` ("Hired candidates are read-only and cannot change status.") — once hired, status cannot be changed again.
- Changing status to `hired` requires the additional `recruitment.candidates.hire` permission (on top of `recruitment.candidates.update`); without it the request returns `403 Forbidden`.
- Setting `status` to `hired` automatically increments the related vacancy's `filled_headcount` by 1.
- If the new `status` is one of `company_rejected`, `candidate_declined`, `no_show`, the provided `outcome_reason` is stored. For any other status, `outcome_reason` is cleared (set to `null`), even if previously set.

---

## Candidate Status Pipeline

`status` is one of:

- `new`
- `shortlisted`
- `contacting_candidate`
- `interview`
- `offer_extended`
- `offer_accepted`
- `hired` (terminal — candidate becomes read-only, increments vacancy `filled_headcount`)
- `company_rejected` (terminal outcome — requires `outcome_reason`)
- `candidate_declined` (terminal outcome — requires `outcome_reason`)
- `no_show` (terminal outcome — requires `outcome_reason`)

There is no enforced linear order between non-terminal statuses; any status in the list above can be set via `POST /api/recruitment/candidates/{candidate}/status` (subject to the `hired` permission rule and the read-only rule once `hired`).

## Audit Logging

All mutating actions are logged in the `recruitment_vacancies` and `recruitment_candidates` audit log modules:

- `recruitment_vacancies`: `create`, `update`, `close`
- `recruitment_candidates`: `create`, `update`, `status_change` (always logged on `POST /status`), plus an additional `hire` entry when the new status is `hired`

File contents are never logged — only `cv_name`.

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

### 422 Validation Example

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "phone": [
      "A candidate with this phone number already applied for this vacancy."
    ]
  }
}
```

### 422 Business Rule Example

```json
{
  "success": false,
  "message": "This vacancy is already closed.",
  "errors": []
}
```

## Frontend Notes

- Frontend must not send workflow/state fields (`status` on vacancies, `filled_headcount`, `created_by`, `outcome_reason` on create/update of candidates) — these are backend-controlled.
- Use `multipart/form-data` for `POST /api/recruitment/candidates` (CV is always required) and for `PUT /api/recruitment/candidates/{candidate}` only when replacing the CV; otherwise JSON is fine for the update endpoint.
- Disable the "Close" action once a vacancy's `status` is `closed`, and disable candidate creation for `closed` vacancies (the backend also enforces both).
- Disable edit/status-change controls once a candidate's `status` is `hired` — the backend enforces this as read-only.
- Show the `outcome_reason` input only when the selected status is `company_rejected`, `candidate_declined`, or `no_show`; the backend requires it for those statuses and clears it for all others.
- Only show the option to set status to `hired` for users with `recruitment.candidates.hire`, since the backend additionally enforces this permission.
