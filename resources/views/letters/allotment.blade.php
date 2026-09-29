<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Allotment Letter — {{ $request->request_no }}</title>
    <style>
        /* Plain CSS: dompdf does not process Tailwind. DejaVu Sans ships with
           dompdf and covers every character used here. */
        @page { margin: 22mm 18mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; color: #1f2933; line-height: 1.45; }
        .masthead { border-bottom: 2px solid #1e3a5f; padding-bottom: 8px; margin-bottom: 16px; }
        .masthead h1 { margin: 0; font-size: 15pt; color: #1e3a5f; }
        .masthead p { margin: 2px 0 0; font-size: 9pt; color: #52606d; }
        h2 { font-size: 11pt; color: #1e3a5f; margin: 18px 0 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .meta td.k { width: 34%; color: #52606d; }
        .grid th, .grid td { border: 1px solid #cbd2d9; padding: 5px 7px; text-align: left; font-size: 9.5pt; }
        .grid th { background: #f0f4f8; }
        .num { text-align: right !important; }
        .qr { text-align: center; }
        .qr img { width: 150px; height: 150px; }
        .qr .code { font-family: "DejaVu Sans Mono", monospace; font-size: 7pt; color: #52606d; word-break: break-all; }
        .note { font-size: 9pt; color: #52606d; margin-top: 18px; border-top: 1px solid #cbd2d9; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="masthead">
        <h1>Allotment Letter</h1>
        <p>{{ config('gh.building') }}, {{ config('gh.institution') }}, {{ config('gh.city') }} — {{ config('gh.pin') }}</p>
    </div>

    <table>
        <tr>
            <td style="width: 64%; vertical-align: top;">
                <table class="meta">
                    <tr><td class="k">Request No.</td><td><strong>{{ $request->request_no }}</strong></td></tr>
                    <tr><td class="k">Applicant</td><td>{{ $request->requester->name }}@if ($request->requester->employee_code) ({{ $request->requester->employee_code }})@endif</td></tr>
                    @if ($request->requester->designation)
                        <tr><td class="k">Designation</td><td>{{ $request->requester->designation }}</td></tr>
                    @endif
                    <tr><td class="k">Purpose</td><td>{{ $request->purpose->label() }}@if ($request->training_programme) — {{ $request->training_programme }}@endif</td></tr>
                    <tr><td class="k">Check-in</td><td>{{ $request->check_in_date->format('d/m/Y') }}</td></tr>
                    <tr><td class="k">Check-out</td><td>{{ $request->check_out_date->format('d/m/Y') }}</td></tr>
                    <tr><td class="k">Nights</td><td>{{ $request->nights }}</td></tr>
                    <tr><td class="k">Members</td><td>{{ $request->total_members }}</td></tr>
                </table>
            </td>
            <td class="qr" style="width: 36%; vertical-align: top;">
                <img src="{{ $qr }}" alt="Check-in QR code">
                <div>Show this code at the front desk</div>
                <div class="code">{{ $token }}</div>
            </td>
        </tr>
    </table>

    <h2>Rooms allotted</h2>
    <table class="grid">
        <thead>
            <tr>
                <th>Allotment No.</th>
                <th>Room</th>
                <th>Type</th>
                <th class="num">Occupants</th>
                <th class="num">Rate / night (Rs.)</th>
                <th class="num">Amount (Rs.)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($allotments as $a)
                <tr>
                    <td>{{ $a->allotment_no }}</td>
                    <td>{{ $a->room->room_number }}</td>
                    <td>{{ $a->room->roomType->name }}</td>
                    <td class="num">{{ $a->occupants_count }}</td>
                    <td class="num">{{ number_format((float) $a->rate_per_night, 2) }}</td>
                    <td class="num">{{ number_format((float) $a->total_amount, 2) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="5" class="num"><strong>Total</strong></td>
                <td class="num"><strong>{{ number_format((float) $total, 2) }}</strong></td>
            </tr>
        </tbody>
    </table>

    <h2>Occupants</h2>
    <table class="grid">
        <thead><tr><th>#</th><th>Name</th><th>Relation</th><th class="num">Age</th></tr></thead>
        <tbody>
            @foreach ($request->occupants as $i => $o)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $o->name }}@if ($o->is_primary) (primary)@endif</td>
                    <td>{{ $o->relation ?? '—' }}</td>
                    <td class="num">{{ $o->age ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="note">
        Please carry the original identity proof submitted with this request. Check-in is from the date shown above;
        rooms not taken up on the arrival date may be released at the discretion of the Administration.
        Issued {{ $issuedAt->format('d/m/Y, g:i a') }} IST. This is a system-generated letter and needs no signature.
    </p>
</body>
</html>
