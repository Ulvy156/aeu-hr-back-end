<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip</title>
    <style>
        @page { margin: 35px 38px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; }
        .brand { width: 37%; text-align: center; font-size: 10px; }
        .logo { max-width: 145px; max-height: 105px; margin-bottom: 8px; }
        .heading { text-align: center; font-family: DejaVu Serif, serif; font-size: 21px; font-weight: bold; padding-top: 20px; }
        .details { margin: 35px 0 12px; line-height: 1.8; }
        .details th { width: 75px; text-align: left; font-weight: normal; }
        .items th, .items td { border: 1px solid #222; padding: 7px 8px; }
        .items th { font-size: 10px; }
        .right { text-align: right; white-space: nowrap; }
        .center { text-align: center; }
        .net { font-weight: bold; }
        .rate { text-align: right; font-size: 9px; margin-top: 7px; color: #555; }
        .signatures { margin-top: 55px; text-align: center; line-height: 2; }
    </style>
</head>
<body>
@php
    $batch = $payslip->payrollBatch;
    $employee = $payslip->employee;
    $start = \Carbon\Carbon::create($batch->year, $batch->month, 1)->startOfMonth();
    $end = $start->copy()->endOfMonth();
    $money = fn ($amount) => number_format((float) $amount, 2);
    $rows = [['Basic Salary', '1 month', $payslip->gross_salary, $payslip->gross_salary, false]];
    foreach ([
        ['Unpaid Leave', 'unpaid_leave_days', 'unpaid_deduction'],
        ['Absence', 'absent_days', 'absence_deduction'],
        ['Maternity Leave', 'maternity_leave_days', 'maternity_deduction'],
        ['Special Sick Leave', 'special_sick_leave_days', 'special_sick_deduction'],
    ] as [$label, $days, $amount]) {
        if ((float) $payslip->{$amount} > 0) {
            $rows[] = [$label, rtrim(rtrim(number_format((float) $payslip->{$days}, 2), '0'), '.').' days', null, $payslip->{$amount}, true];
        }
    }
    $rows[] = ['NSSF', '', null, $payslip->nssf_deduction, true];
    // Preserve reconciliation when the minimum net salary of zero is applied.
    $rawNet = (float) $payslip->gross_salary;
    foreach (array_slice($rows, 1) as $row) { $rawNet -= (float) $row[3]; }
    $adjustment = round((float) $payslip->net_salary - $rawNet, 2);
    if ($adjustment > 0) { $rows[] = ['Minimum net pay adjustment', '', null, $adjustment, false]; }
@endphp
<table class="header">
    <tr>
        <td class="brand">
            @if (!empty($companyLogoDataUri))
                <img src="{{ $companyLogoDataUri }}" alt="Company logo" class="logo"><br>
            @endif
            {{ $companySetting->company_address }}
        </td>
        <td class="heading">{{ $companySetting->company_name }}'s Family</td>
    </tr>
</table>
<div class="details">
    <strong>Payslip</strong>
    <table>
        <tr><th>Name</th><td>: {{ $employee?->full_name }}</td></tr>
        <tr><th>Job Title</th><td>: {{ $employee?->position?->name ?? '—' }}</td></tr>
        <tr><th>ID</th><td>: {{ $employee?->employee_id }}</td></tr>
        <tr><th>Date</th><td>: {{ $start->format('d-m-Y') }} – {{ $end->format('d-m-Y') }}</td></tr>
    </table>
</div>
<table class="items">
    <thead><tr>
        <th style="width: 5%">No.</th><th>Description</th><th style="width: 16%">Days/Month</th>
        <th style="width: 18%">Rate (USD)</th><th style="width: 21%">Amount (USD)</th>
    </tr></thead>
    <tbody>
        @foreach ($rows as $index => [$label, $quantity, $rate, $amount, $deduction])
            <tr>
                <td class="center">{{ $index + 1 }}</td><td>{{ $label }}</td><td class="center">{{ $quantity }}</td>
                <td class="right">{{ $rate === null ? '—' : '$ '.$money($rate) }}</td>
                <td class="right">{{ $deduction ? '($ '.$money($amount).')' : '$ '.$money($amount) }}</td>
            </tr>
        @endforeach
        @for ($i = count($rows); $i < 6; $i++)
            <tr><td class="center">{{ $i + 1 }}</td><td>&nbsp;</td><td></td><td></td><td class="right">—</td></tr>
        @endfor
        <tr class="net"><td colspan="4" rowspan="2">TOTAL NET SALARY</td><td class="right">USD {{ $money($payslip->net_salary) }}</td></tr>
        <tr class="net"><td class="right">KHR {{ number_format(round((float) $payslip->net_salary * 4000), 0) }}</td></tr>
    </tbody>
</table>
<div class="rate">1 USD = 4,000 KHR</div>
<table class="signatures"><tr><td>Administration<br>Signature</td><td>CEO<br>Signature</td></tr></table>
</body>
</html>
