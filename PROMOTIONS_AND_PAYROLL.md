# Promotions and payroll salary history

## Behavior

HR must select **next month** when submitting a promotion. The API keeps `effective_date` and accepts only that month's first day (`YYYY-MM-01`). Approval checks next month again; expired pending requests must be cancelled and resubmitted. CEO approval schedules every proposed field together and leaves current employee details unchanged.

Only one approved, unapplied request is allowed per employee. The CEO can cancel an unapplied request, including a failed one. HR can cancel their own pending requests. Applied requests cannot be cancelled.

Requests retain `pending`, `approved`, `rejected`, and `cancelled` approval statuses. An approved request also exposes `application_state` (`scheduled`, `applied`, or `failed`), `applied_at`, `application_failed_at`, and `application_error`.

The daily job runs at **01:00 Asia/Phnom_Penh**. It applies due and overdue approvals, retaining their original effective dates. Employment history records actual old/new values when application succeeds. Employee changes, role/account synchronization, history, application auditing, and the application marker commit together. Repeated application is harmless. Approval and application are separate audit events.

Transient failures retry on the next daily run. Invalid employment details or payroll conflicts stay failed and require cancellation and a replacement request. The job continues processing other employees, logs failures, and returns a nonzero exit code if due failures remain.

## Scheduler deployment

Deploy backend and frontend together, and run migrations before serving requests:

```sh
php artisan migrate --force
```

Configure the server to invoke Laravel's scheduler every minute (replace the installation path):

```cron
* * * * * cd /path/to/aeu-hr-back-end && php artisan schedule:run >> /var/log/aeu-hr-scheduler.log 2>&1
```

The promotion event itself runs once daily at 01:00 Cambodia time, regardless of the server's operating-system timezone. It uses `onOneServer()` and `withoutOverlapping()`; multiple app servers must share a supported cache store for scheduler locks. Database row locks also prevent duplicate promotion application.

Inspect schedules or run the promotion task manually:

```sh
php artisan schedule:list
php artisan employees:apply-due-promotions
```

Monitor the scheduler's exit/output and Laravel's configured logs. Failed request details are also visible in the promotion drawer. Manual execution applies any due approvals immediately, even before 01:00.

## Salary selection for payroll

Payroll generation applies due promotions for candidate employees before calculation, covering scheduler downtime and generation before 01:00. Promotion application commits independently, so a later payroll error does not undo an already due promotion or hide its failure. A failed due promotion aborts payroll generation with the employee/request identified.

Payroll rejects future months. Salary histories are loaded together and grouped by employee. For the selected month's first day, use the latest effective change's `new_value.value`; if none exists, use the earliest later change's `old_value.value`; if neither exists, use the verified baseline. Same-month changes are ordered by history ID. Existing tax, attendance, leave, and deduction formulas receive this resolved salary.

Example: $400 increases to $500 effective November 1. September and October payroll use $400, even if generated in November; November uses $500. Payroll items store the resolved salary snapshot, and later salary changes do not rewrite existing payroll.

An overdue promotion cannot apply before a newer recorded salary change; cancel and replace it instead. Salary-change approval/application and direct salary correction are blocked if that employee has payroll items in the effective month or any later period. Errors name the conflicting periods. An editable draft can be deleted through the existing workflow and regenerated after the change. Pending/approved payroll keeps its existing restrictions; approved payroll is locked. Arrears and adjustment payments are not implemented.

## Direct corrections

HR users with salary-update permission may correct a mistyped salary in the employee form without creating a promotion or obtaining CEO approval. The correction is immediate, effective from the current month's first day, and automatically records old/new salary, actor, and `source: correction` in employment history. It follows the same payroll-conflict protection. A scheduled promotion retains its approved target salary.

Actual employment pay changes must use promotion approval. The application cannot infer a user's intent from the amount entered. Initial salary is recorded as the employee's verified baseline on creation.

## Existing data and historical reconciliation

The migration marks all pre-existing approved requests as already applied using review time, falling back to update/creation time. It never replays promotions or rewrites old payroll/history.

Old direct edits did not create history, and old approval applied immediately even when its history used a future date. Therefore legacy history alone cannot guarantee historical accuracy. At rollout each existing employee receives:

- `salary_history_start_month`: the rollout month's first day;
- `salary_history_baseline`: their current salary at rollout;
- `salary_history_baseline_history_id`: the last history ID present at rollout.

Only history after that ID participates in the verified timeline. This prevents incomplete or incorrectly future-dated legacy records from overriding the rollout baseline. Newly created employees use their joining month, initial salary, and a history boundary of zero.

New payroll generation before an employee's verified coverage is blocked; existing payroll stays readable and unchanged. An authorized maintainer must reconcile older periods against trusted payroll/employment records before extending coverage. This is a reviewed data-repair operation, not an employee-form edit:

1. Back up the affected data and verify the initial salary and every subsequent salary change for the intended coverage period.
2. Restore a complete, chronologically consistent salary history. Keep old/new values in the existing `{ "value": amount }` format. Resolve mid-month legacy changes explicitly; monthly payroll does not prorate salary-rate changes.
3. Set `salary_history_start_month` to the verified first month, `salary_history_baseline` to the verified starting salary, and `salary_history_baseline_history_id` to zero only when the entire retained salary history has been reconciled.
4. Audit the repair with the employee, evidence, actor, and before/after values. Check representative month lookups before generating payroll.

Never extend coverage using an unverified current salary or reinterpret a legacy effective date without checking its actual application.
