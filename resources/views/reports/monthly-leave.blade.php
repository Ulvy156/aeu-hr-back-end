<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Monthly Leave Report</title>
    <style>
        @font-face {
            font-family: 'NotoSansKhmer';
            font-style: normal;
            font-weight: normal;
            src: url('{{ resource_path('fonts/NotoSansKhmer-Regular.ttf') }}') format('truetype');
        }
        @font-face {
            font-family: 'NotoSansKhmer';
            font-style: normal;
            font-weight: bold;
            src: url('{{ resource_path('fonts/NotoSansKhmer-Bold.ttf') }}') format('truetype');
        }
        @page { margin: 20px 18px; }
        body {
            font-family: 'NotoSansKhmer', DejaVu Sans, sans-serif;
            font-size: 9px;
            line-height: 1.45;
            color: #111;
        }
        table { width: 100%; border-collapse: collapse; }
        .header { margin-bottom: 10px; }
        .header td { vertical-align: middle; }
        .logo { max-width: 72px; max-height: 56px; }
        .brand { text-align: center; }
        .company { font-size: 13px; font-weight: bold; margin: 0 0 3px; }
        .title { font-size: 12px; font-weight: bold; margin: 0 0 2px; }
        .period { font-size: 10px; color: #333; margin: 0; }
        .items { margin-top: 6px; table-layout: fixed; }
        .items th, .items td {
            border: 1px solid #222;
            padding: 4px 5px;
            line-height: 1.45;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        /* Latin headers use DejaVu — Noto Sans Khmer metrics throw DomPDF centering off */
        .items th {
            background: #e8d9a8;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8px;
            font-weight: bold;
            text-align: center;
            vertical-align: middle;
        }
        .items td {
            font-family: 'NotoSansKhmer', DejaVu Sans, sans-serif;
            font-size: 8px;
            vertical-align: top;
        }
        .center { text-align: center; }
        .left { text-align: left; }
        .footer { margin-top: 36px; width: 100%; }
        .footer td { width: 50%; vertical-align: top; text-align: center; }
        .footer-date { font-size: 9px; margin: 0 0 6px; min-height: 14px; }
        .footer-date-spacer { min-height: 14px; margin: 0 0 6px; font-size: 9px; visibility: hidden; }
        .sign-label { font-size: 10px; font-weight: bold; margin: 0 0 2px; }
        .sign-sub { font-size: 9px; margin: 0; }
        .sign-space { height: 70px; }
        .sign-line {
            border-top: 1px solid #222;
            width: 150px;
            margin: 0 auto;
            height: 0;
            font-size: 0;
            line-height: 0;
            overflow: hidden;
        }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 18%;">
                @if (! empty($companyLogoDataUri))
                    <img class="logo" src="{{ $companyLogoDataUri }}" alt="Logo">
                @endif
            </td>
            <td class="brand">
                <p class="company">{{ $companySetting->company_name }}</p>
                <p class="title">Monthly Leave Report</p>
                <p class="period">{{ $periodLabel }}</p>
            </td>
            <td style="width: 18%;"></td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 5%; text-align: center;">No.</th>
                <th style="width: 16%; text-align: center;">Name</th>
                <th style="width: 16%; text-align: center;">Position</th>
                <th style="width: 6%; text-align: center;">Sex</th>
                <th style="width: 8%; text-align: center;">Absence</th>
                <th style="width: 10%; text-align: center;">{{ $leaveColumnLabel }}</th>
                <th style="width: 39%; text-align: center;">Date / Reason</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="center">{{ $row['no'] }}</td>
                    <td class="left">{{ $row['name'] }}</td>
                    <td class="left">{{ $row['position'] }}</td>
                    <td class="center">{{ $row['gender'] }}</td>
                    <td class="center">{{ $row['absence_days'] }}</td>
                    <td class="center">{{ $row['leave_days'] }}</td>
                    <td class="left">{{ $row['reason'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="center">No employees found for this month.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="footer">
        <tr>
            <td>
                <p class="footer-date-spacer">Date: {{ $generatedDateLabel }}</p>
                <p class="sign-label">Seen and Approved</p>
                <p class="sign-sub">CEO</p>
                <div class="sign-space"></div>
                <div class="sign-line"></div>
            </td>
            <td>
                <p class="footer-date">Date: {{ $generatedDateLabel }}</p>
                <p class="sign-label">Prepared by</p>
                <p class="sign-sub">HR</p>
                <div class="sign-space"></div>
                <div class="sign-line"></div>
            </td>
        </tr>
    </table>
</body>
</html>
