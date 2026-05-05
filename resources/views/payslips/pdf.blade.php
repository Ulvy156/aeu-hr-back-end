<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1f2937;
            font-size: 12px;
            line-height: 1.4;
        }

        .header-table {
            width: 100%;
            border: none;
            margin-bottom: 16px;
        }

        .header-table td {
            border: none;
            padding: 0;
            vertical-align: top;
        }

        .logo-cell {
            width: 120px;
            padding-right: 16px;
        }

        .logo {
            max-width: 100px;
            max-height: 60px;
        }

        .header,
        .section,
        .totals {
            margin-bottom: 20px;
        }

        .title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .muted {
            color: #6b7280;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            text-align: left;
        }

        th {
            background: #f3f4f6;
        }

        .right {
            text-align: right;
        }
    </style>
</head>
<body>
    @php
        $currency = $companySetting->salary_currency ?? 'USD';
        $batch = $payslip->payrollBatch;
        $employee = $payslip->employee;
        $payPeriod = sprintf('%04d-%02d', $batch?->year ?? now()->year, $batch?->month ?? now()->month);
        $taxBreakdown = collect($payslip->tax_breakdown ?? []);
    @endphp

    <div class="header">
        <table class="header-table">
            <tr>
                @if (! empty($companyLogoDataUri))
                    <td class="logo-cell">
                        <img src="{{ $companyLogoDataUri }}" alt="Company Logo" class="logo">
                    </td>
                @endif
                <td>
                    <div class="title">{{ $companySetting->company_name }}</div>
                    <div class="muted">Payslip for {{ $payPeriod }}</div>
                    @if ($companySetting->company_address)
                        <div>{{ $companySetting->company_address }}</div>
                    @endif
                    @if ($companySetting->company_email)
                        <div>{{ $companySetting->company_email }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <table>
            <tr>
                <th>Employee</th>
                <td>{{ $employee?->full_name }}</td>
                <th>Employee ID</th>
                <td>{{ $employee?->employee_id }}</td>
            </tr>
            <!-- <tr>
                <th>Payroll Status</th>
                <td>{{ strtoupper((string) $batch?->status) }}</td>
                <th>Approved At</th>
                <td>{{ $batch?->approved_at?->format('Y-m-d H:i:s') }}</td>
            </tr> -->
        </table>
    </div>

    <div class="section">
        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <!-- <tr>
                    <td>Base Salary</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $payslip->base_salary, 2) }}</td>
                </tr> -->
                <!-- <tr>
                    <td>Daily Rate</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $payslip->daily_rate, 2) }}</td>
                </tr> -->
                <tr>
                    <td>Gross Salary</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $payslip->gross_salary, 2) }}</td>
                </tr>
                <tr>
                    <td>Unpaid Leave Deduction</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $payslip->unpaid_deduction, 2) }}</td>
                </tr>
                <tr>
                    <td>Absence Deduction</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $payslip->absence_deduction, 2) }}</td>
                </tr>
                <tr>
                    <td>Tax Amount</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $payslip->tax_amount, 2) }}</td>
                </tr>
                <tr>
                    <td>NSSF Deduction</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $payslip->nssf_deduction, 2) }}</td>
                </tr>
                <tr>
                    <th>Net Salary</th>
                    <th class="right">{{ $currency }} {{ number_format((float) $payslip->net_salary, 2) }}</th>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- <div class="section">
        <table>
            <thead>
                <tr>
                    <th>Tax Bracket</th>
                    <th class="right">Taxable Amount</th>
                    <th class="right">Tax Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($taxBreakdown as $taxRow)
                    <tr>
                        <td>{{ $taxRow['bracket'] ?? 'N/A' }}</td>
                        <td class="right">{{ $currency }} {{ number_format((float) ($taxRow['taxable_amount'] ?? 0), 2) }}</td>
                        <td class="right">{{ $currency }} {{ number_format((float) ($taxRow['tax_amount'] ?? 0), 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">No tax applied for this payroll.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div> -->

    <div class="totals">
        <table>
            <tr>
                <th>Working Days</th>
                <td>{{ number_format((float) $payslip->working_days, 2) }}</td>
                <th>Present Days</th>
                <td>{{ number_format((float) $payslip->present_days, 2) }}</td>
            </tr>
            <tr>
                <th>Absent Days</th>
                <td>{{ number_format((float) $payslip->absent_days, 2) }}</td>
                <th>Unpaid Leave Days</th>
                <td>{{ number_format((float) $payslip->unpaid_leave_days, 2) }}</td>
            </tr>
        </table>
    </div>
</body>
</html>
