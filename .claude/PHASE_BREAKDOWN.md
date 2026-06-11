# HR Management System - Backend Phase Breakdown

## Purpose

This document defines the **backend-only development phases** for the HR Management System MVP.

Use this file inside the Laravel backend project:

```txt
backend/PHASE_BREAKDOWN.md
```

The backend is the **single source of truth** for all business logic.

The backend must handle:

- Authentication
- Authorization
- Database structure
- Business calculations
- GPS validation
- Attendance logic
- Leave logic
- Payroll logic
- Payslip PDF generation
- Reports and exports
- Audit logs
- API responses

The backend must **not** generate frontend code.

---

## Backend Development Flow

For every backend feature, follow this order:

```txt
1. Migration
2. Model + relationships
3. Enum if needed
4. Service class
5. Form Request
6. Controller
7. API Resource
8. Policy / Permission check
9. Route
10. Test
11. Frontend Integration Summary
```

After every backend module, update:

```txt
.claude/api/<RELATED_MODULE_FILE>.md
```

Keep `.claude/API_CONTRACT.md` as the index only.

The frontend should consume the module API contract instead of guessing fields.

---

## AI Agent Setup Instruction

Use this instruction when working with an AI coding agent:

```txt
The Laravel backend project setup is mostly completed.

Some packages are already installed but not fully configured yet.

Do not reinstall existing packages unless required.

Before using any package, check whether it already exists in composer.json.

If the package exists but is not configured, configure it properly inside the correct phase.

Follow the backend phase breakdown strictly.

Do not generate frontend code.

Do not skip service classes, form requests, API resources, policies, permissions, tests, or API contract updates.
```

# Phase 1: Announcement Management ( Completed )

## Objective

Implement a complete Announcement Management module within the existing Laravel backend application.

This module acts as an internal communication board and supports:

* Announcement Categories
* Announcement Approval Workflow
* Rich Text Content Storage
* Single Attachment
* Read Tracking
* Audience Targeting
* Audit Logging

Target audiences may include:

* Everyone
* Roles
* Departments
* Specific Employees

---

# Implementation Rules

Before implementation:

* Inspect existing project structure
* Reuse existing architecture
* Reuse existing API response format
* Reuse existing Service Layer pattern
* Reuse existing Form Request pattern
* Reuse existing Resource pattern
* Reuse existing Policy pattern
* Reuse existing Audit Log implementation
* Reuse existing Permission structure

Do not:

* Generate frontend code
* Create Vue components
* Modify unrelated modules
* Refactor existing modules
* Introduce new architecture patterns
* Introduce repository pattern if project does not already use it
* Introduce CQRS
* Introduce event sourcing
* Introduce websocket functionality

Follow implementation order:

1. Migration
2. Model + Relationships
3. Enum (if needed)
4. Service
5. Form Request
6. Controller
7. API Resource
8. Policy
9. Route
10. Feature Test
11. API Contract Update

All business logic must live in Service classes.

Controllers should only:

* authorize
* validate
* call service
* return response/resource

---

# Module 1: Announcement Categories

## Table: announcement_categories

Columns:

* id
* name
* description nullable
* status enum(active,inactive)
* created_at
* updated_at

Rules:

* name unique
* no hard delete
* inactive categories cannot be selected for new announcements

## Seeder

Seed default categories:

* General
* HR
* Payroll
* Policy
* Holiday
* Event
* Training
* Safety

---

# Module 2: Announcements

## Table: announcements

Columns:

* id

* category_id

* title

* content

* priority enum(
  normal,
  important,
  urgent
  )

* status enum(
  draft,
  pending_approval,
  rejected,
  published,
  archived
  )

* attachment_path nullable

* attachment_name nullable

* attachment_size nullable

* created_by

* submitted_by nullable

* submitted_at nullable

* approved_by nullable

* approved_at nullable

* rejected_by nullable

* rejected_at nullable

* rejection_reason nullable

* created_at

* updated_at

Relationships:

* category
* creator
* submitter
* approver
* rejector

Indexes:

* status
* category_id
* created_by
* priority

---

# Module 3: Announcement Targets

## Table: announcement_targets

Columns:

* id

* announcement_id

* target_type enum(
  all,
  role,
  department,
  employee
  )

* target_id nullable

* created_at

* updated_at

Rules:

* supports multiple targets per announcement
* uses OR matching logic
* at least one target is required

Examples:

Announcement targets:

* HR Role
* Finance Department
* Employee #15

Any matching target may view the announcement.

Indexes:

* announcement_id
* target_type
* target_id

---

# Module 4: Announcement Views

## Table: announcement_views

Columns:

* id
* announcement_id
* employee_id
* viewed_at

Rules:

* unique(announcement_id, employee_id)
* first open creates record
* subsequent opens do nothing

Indexes:

* unique(announcement_id, employee_id)

---

# Permissions

Create permissions:

announcements.view
announcements.view_draft
announcements.create
announcements.update
announcements.submit
announcements.cancel_submission
announcements.approve
announcements.archive

announcement_categories.view
announcement_categories.create
announcement_categories.update
announcement_categories.deactivate

Do not assign permissions to roles automatically.

Use existing role-permission management.

---

# Business Rules

## Workflow

Draft
→ Pending Approval
→ Published

Draft
→ Pending Approval
→ Rejected

Rejected
→ Edit
→ Resubmit

Published
→ Archived

---

## Approval Rules

* creator cannot approve own announcement
* rejection reason required
* approval immediately publishes announcement

---

## Submission Rules

Pending Approval
→ Cancel Submission
→ Draft

Rules:

* only creator can cancel submission
* only pending approval announcements can be cancelled

---

## Editing Rules

Draft:

* editable

Pending Approval:

* not editable

Rejected:

* editable

Published:

* not editable

Archived:

* not editable

---

## Archive Rules

* only published announcements can be archived
* archived announcements are read-only
* archived announcements remain visible to management

---

## Category Rules

* category required
* category must be active

---

## Attachment Rules

Optional

Allowed:

* pdf
* jpg
* jpeg
* png

Store files using Laravel Storage.

Store metadata only:

* attachment_path
* attachment_name
* attachment_size

Use existing project file size standards.

---

# Visibility Rules

## Users with announcements.view_draft

Can view:

* draft
* pending_approval
* rejected
* published
* archived

---

## Employees

Can only view:

* published announcements
* announcements targeted to them

---

## Dynamic Targeting

Do not snapshot recipients.

Example:

Announcement targets IT Department.

Employee joins IT later.

Employee can still view the published announcement.

Supported target types:

1. Everyone
2. Role
3. Department
4. Specific Employee

---

# Read Tracking

## Employee List Response

Include:

* is_read

Rules:

* unread = no announcement_view record
* read = announcement_view exists

---

## Management Detail Response

Include:

* total_viewed
* total_unread

Support:

* viewed_employees
* unread_employees

---

## Read Logic

When employee opens announcement detail:

If no view record exists:

* create announcement_view

If view record already exists:

* do nothing

Must be idempotent.

---

# List Sorting

Employee list default sorting:

1. unread first
2. newest first

Management list default sorting:

1. newest first

---

# Audit Logging

Use existing audit logging implementation.

Log:

* announcement created

* announcement updated

* announcement submitted

* announcement submission cancelled

* announcement approved

* announcement rejected

* announcement archived

* category created

* category updated

* category deactivated

Do not log file contents.

---

# API Endpoints

## Announcement Categories

GET    /api/announcement-categories
POST   /api/announcement-categories
GET    /api/announcement-categories/{id}
PUT    /api/announcement-categories/{id}
DELETE /api/announcement-categories/{id}

DELETE must deactivate category.

---

## Announcements

GET    /api/announcements
POST   /api/announcements
GET    /api/announcements/{id}
PUT    /api/announcements/{id}

POST   /api/announcements/{id}/submit
POST   /api/announcements/{id}/cancel-submission
POST   /api/announcements/{id}/approve
POST   /api/announcements/{id}/reject
POST   /api/announcements/{id}/archive

---

## Read Tracking

POST /api/announcements/{id}/read

Must be idempotent.

---

# Filters

## Management Filters

* search
* category
* priority
* status
* created_by

Search should include:

* title
* content

---

## Employee Filters

* search
* category
* read_status

read_status values:

* read
* unread

Search should include:

* title
* content

---

# Authorization

Use:

* existing Policy pattern
* existing Permission middleware pattern

Do not hardcode role checks.

Authorization must rely on:

* permissions
* policies

---

# Response Format

Use the existing standardized API response structure already implemented in the project.

Do not introduce a different response format.

---

# Deliverables

Implement:

1. Migrations
2. Models + Relationships
3. Seeder
4. Permission Seeder Update
5. Form Requests
6. Services
7. Policies
8. API Resources
9. Controllers
10. Routes
11. Audit Logging
12. Feature Tests
13. .claude/api/ANNOUNCEMENT_API.md
14. Update .claude/API_CONTRACT.md

Backend implementation only.

Do not generate frontend code.
Do not modify unrelated modules.


# Phase 2: Recruitment Management

## Purpose

Implement a complete Recruitment Management module for the HR Management System.

The recruitment process is managed internally by HR and Admin.

The company does not provide a public recruitment portal.

Candidates are manually entered into the system after HR receives CVs through channels such as Facebook, Telegram, Email, Referral, Walk-in, LinkedIn, or other recruitment sources.

The Recruitment module is independent from Employee Management.

No automatic employee creation is required.

---

# Functional Requirements

## Vacancy Management

The system must allow authorized users to:

* Create vacancies
* Update vacancies
* View vacancies
* View vacancy details
* Close vacancies
* Search vacancies
* Filter vacancies

The system must track:

* Vacancy title
* Department
* Job description
* Required headcount
* Filled headcount
* Target hiring date
* Vacancy status

Rules:

* Required headcount must be greater than zero
* Filled headcount is maintained by the system
* Vacancy status defaults to Open
* Vacancy may be manually closed at any time
* Closed vacancy cannot be reopened
* If hiring is needed again, a new vacancy must be created
* Reaching required headcount does not automatically close a vacancy
* Reaching required headcount does not prevent new candidates from being added

---

## Candidate Management

The system must allow authorized users to:

* Create candidates
* Update candidates
* View candidates
* View candidate details
* Upload CV
* Change candidate status
* Search candidates
* Filter candidates

Rules:

* Candidate must belong to a vacancy
* Candidate can only be created under an Open vacancy
* Duplicate candidates are allowed if in different vacancy, if same vacancy must be block
* Each candidate record represents a single application
* Candidate becomes read-only when status becomes Hired
* No public candidate creation
* Candidates are created by HR/Admin only

---

## Candidate Source Management

The system must support:

* Facebook
* Telegram
* LinkedIn
* Referral
* Walk-in
* Email
* Other

Rules:

* Source is required

---

## CV Management

Rules:

* CV is required
* Single file only
* PDF only
* Store using exist method Storage
* Candidate cannot be created without CV

---

## Candidate Status Management

The system must support:

Active statuses:

* New
* Shortlisted
* Contacting Candidate
* Interview
* Offer Extended
* Offer Accepted

Final statuses:

* Hired
* Company Rejected
* Candidate Declined
* No Show

Rules:

* HR/Admin may update status at any time
* No workflow restrictions
* Backward status changes are allowed
* Status represents the candidate's current situation

Examples:

Interview → Contacting Candidate

Offer Extended → Interview

Shortlisted → Company Rejected

---

## Hiring Tracking

When candidate status becomes:

Hired

The system must:

* Automatically increment vacancy filled_headcount by 1

Rules:

* Recruitment is independent from Employee Management
* No employee relationship is required
* No employee record validation is required
* No automatic employee creation
* Employee creation is handled separately by HR/Admin

---

## Outcome Tracking

When status becomes:

* Company Rejected
* Candidate Declined
* No Show

The system must require:

* outcome_reason

Rules:

* outcome_reason is required
* Supports long text / rich text content

---

## Reporting

The system must provide summary metrics for:

Vacancies:

* Total Vacancies
* Open Vacancies
* Closed Vacancies

Candidates:

* Total Candidates
* Hired Candidates
* Company Rejected Candidates
* Candidate Declined Candidates
* No Show Candidates

---

# Database Requirements

## recruitment_vacancies

Columns:

* id
* title
* department_id
* description
* required_headcount
* filled_headcount
* target_hiring_date
* status
* created_by
* created_at
* updated_at

Status Enum:

* open
* closed

Relationships:

* department
* creator
* candidates

Rules:

* filled_headcount default 0
* status default open

---

## recruitment_candidates

Columns:

* id

* vacancy_id

* full_name

* phone

* email nullable

* source

* cv_path

* cv_name

* cv_size

* status

* interview_date nullable

* interviewer nullable

* notes nullable

* outcome_reason nullable

* created_by

* created_at

* updated_at

Status Enum:

* new
* shortlisted
* contacting_candidate
* interview
* offer_extended
* offer_accepted
* hired
* company_rejected
* candidate_declined
* no_show

Relationships:

* vacancy
* creator

Rules:

* vacancy required
* source required
* CV required

---

# Permission Requirements

Create permissions:

recruitment.vacancies.view

recruitment.vacancies.create

recruitment.vacancies.update

recruitment.vacancies.close

recruitment.candidates.view

recruitment.candidates.create

recruitment.candidates.update

recruitment.candidates.hire

Rules:

* Do not assign permissions automatically
* Respect existing role-permission implementation
* Reuse existing permission seeder structure

---

# Policy Requirements

Create policies for:

Vacancies:

* view
* create
* update
* close

Candidates:

* view
* create
* update
* hire

Use existing policy implementation pattern.

---

# Audit Logging Requirements

Use existing audit logging implementation.

Log:

Vacancies:

* vacancy created
* vacancy updated
* vacancy closed

Candidates:

* candidate created
* candidate updated
* candidate status changed
* candidate marked hired

Rules:

* Do not log CV file contents
* Reuse existing audit log architecture

---

# API Requirements

## Vacancy Endpoints

GET /api/recruitment/vacancies

POST /api/recruitment/vacancies

GET /api/recruitment/vacancies/{id}

PUT /api/recruitment/vacancies/{id}

POST /api/recruitment/vacancies/{id}/close

---

## Candidate Endpoints

GET /api/recruitment/candidates

POST /api/recruitment/candidates

GET /api/recruitment/candidates/{id}

PUT /api/recruitment/candidates/{id}

POST /api/recruitment/candidates/{id}/status

---

# Filter Requirements

## Vacancy Filters

* search
* department
* status
* target_hiring_date

## Candidate Filters

* search
* vacancy
* source
* status
* interview_date

---

# Validation Rules

Vacancy:

* title required
* department required
* required_headcount > 0
* target_hiring_date required

Candidate:

* full_name required
* phone required
* source required
* vacancy required
* CV required
* CV must be PDF

Status Change:

When status becomes:

* company_rejected
* candidate_declined
* no_show

Require:

* outcome_reason

---

# Testing Requirements

Create feature tests for:

Vacancies:

* create vacancy
* update vacancy
* close vacancy
* vacancy permissions

Candidates:

* create candidate
* upload CV
* update candidate
* candidate permissions

Status:

* change candidate status
* require outcome_reason
* candidate becomes read-only when hired
* vacancy filled_headcount increments when hired

Authorization:

* permission enforcement
* policy enforcement

---

# Documentation Requirements

Update:

.claude/api/RECRUITMENT_API.md

Include:

* Endpoints
* Request payloads
* Response payloads
* Filters
* Validation rules
* Permission requirements

Also update:

.claude/API_CONTRACT.md

as the API documentation index.

---

# Backend Development Rules

Before implementation:

* Inspect existing backend architecture
* Reuse existing service architecture
* Reuse existing API response format
* Reuse existing policies
* Reuse existing permission structure
* Reuse existing audit logging implementation
* Reuse existing testing patterns

Follow backend development flow:

1. Migration
2. Model + Relationships
3. Enum
4. Service
5. Form Request
6. Controller
7. API Resource
8. Policy
9. Route
10. Test
11. API Contract Update

Do not generate frontend code.

Do not create Vue components.

Do not create frontend routes.

Do not modify unrelated modules.

Implement backend only.
