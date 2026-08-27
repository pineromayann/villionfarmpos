<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>@yield('title')</title>
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            color: #1f2937;
            font-size: 12px;
        }
        .letterhead {
            border-bottom: 2px solid #111827;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .letterhead .brand {
            font-size: 18px;
            font-weight: bold;
            color: #111827;
        }
        .letterhead .tagline {
            font-size: 11px;
            color: #6b7280;
        }
        h1 {
            font-size: 16px;
            margin: 16px 0 4px;
        }
        .meta {
            font-size: 11px;
            color: #6b7280;
            margin-bottom: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        th, td {
            border: 1px solid #d1d5db;
            padding: 6px 8px;
            text-align: left;
            font-size: 11px;
        }
        th {
            background-color: #f3f4f6;
            text-transform: uppercase;
            font-size: 10px;
            color: #374151;
        }
        tr:nth-child(even) td {
            background-color: #f9fafb;
        }
        .text-right {
            text-align: right;
        }
        .summary {
            width: 100%;
            margin-bottom: 14px;
        }
        .summary td {
            border: none;
            padding: 4px 12px 4px 0;
            font-size: 12px;
        }
        .summary .label {
            color: #6b7280;
        }
        .summary .value {
            font-weight: bold;
            color: #111827;
        }
    </style>
</head>
<body>
    <div class="letterhead">
        <div class="brand">VillonFarm POS</div>
        <div class="tagline">Farm Management &middot; Insecticide POS</div>
    </div>

    <h1>@yield('title')</h1>
    <div class="meta">@yield('meta')</div>

    @yield('body')
</body>
</html>
