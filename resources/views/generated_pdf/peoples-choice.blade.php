<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>People's Choice Award Report</title>
    <style>
        * { font-family: Arial, Helvetica, sans-serif; }
        .table { width: 100%; border-collapse: collapse; }
        table.bordered td, table.bordered th { border: 1px solid black; }
        table.bordered tbody tr:nth-child(odd) { background-color: #f2f2f2; }
        table.bordered tbody tr:nth-child(even) { background-color: #ffffff; }
        .text-center { text-align: center; }
        .winner-1 { background: rgb(255, 240, 152) !important; font-weight: bold !important; }
        .text-start { text-align: left; }
        .text-end { text-align: right; }

        /* Watermark + footer come from generated_pdf._header; position them like the other reports
           (fixed, faint, behind the content), centered for this portrait page. */
        #watermark {
            position: fixed;
            top: 25%;
            left: 0;
            right: 0;
            width: 100%;
            text-align: center;
            opacity: .07;
            z-index: -1000;
        }

        .footer {
            position: fixed;
            bottom: 10px;
            left: 0;
            right: 0;
            text-align: center;
            height: 70px;
            z-index: -1000;
        }
        #developer {
            position: fixed; top: 50%; left: -115px; font-weight: bold; text-align: right;
            transform: rotate(-90deg); transform-origin: 50% 50%;
        }
    </style>
</head>

<body>
    <div id="developer">
        <div><i>CMISID Tabulation System</i></div>
    </div>
    <main>
        @php
            use App\Models\Setting;
            $titlePrefix = Setting::get('report_header_title', '');
            $venue = Setting::get('report_header_venue', '');
            $time = Setting::get('report_header_time', '');
            $datetime = $time ? date('F d, Y') . ' | ' . $time : date('F d, Y');
        @endphp
        @include('generated_pdf._header', [
            'headerTitle' => $titlePrefix ? $titlePrefix . ': ' . $category->description : $category->description,
            'headerVenue' => $venue,
            'headerDatetime' => $datetime,
        ])

        <div style="text-align:center;padding:10px 0 6px;">
            <div style="font-size:18pt;font-weight:bold;">People's Choice Award</div>
            <div style="font-size:14pt;text-transform:uppercase;color:#266da7;font-weight:bold;">
                {{ $category->description }}
            </div>
            <div style="font-size:10pt;color:#888;margin-top:4px;">
                Audience voting via QR code &mdash; one vote per device &mdash; {{ number_format($tally['total']) }} total vote(s)
            </div>
        </div>

        @if (count($tally['winners']))
            <div style="text-align:center;margin:10px 0 14px;font-size:13pt;">
                Winner{{ count($tally['winners']) > 1 ? 's (tie)' : '' }}:
                <strong style="text-transform:uppercase;">
                    {{ implode(', ', array_column($tally['winners'], 'group_name')) }}
                </strong>
            </div>
        @endif

        <table class="table bordered" style="margin-top:10px;">
            <thead>
                <tr style="background:#2c3e50;color:#fff;">
                    <th width="8%" style="font-size:9pt;padding:4px;">RANK</th>
                    <th width="28%" style="font-size:9pt;padding:4px;">GROUP</th>
                    <th style="font-size:9pt;padding:4px;">MEMBERS</th>
                    <th width="10%" style="font-size:9pt;padding:4px;text-align:center;">VOTES</th>
                    <th width="10%" style="font-size:9pt;padding:4px;text-align:center;">SHARE</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tally['rows'] as $row)
                    @php $hasVotes = $tally['total'] > 0; @endphp
                    <tr class="{{ $hasVotes && $row['ordinal_rank'] == 1 ? 'winner-1' : '' }}">
                        <td class="text-center" style="font-size:10pt;padding:3px;font-weight:bold;">{{ $hasVotes ? bong_ordinal($row['ordinal_rank']) : '-' }}</td>
                        <td style="font-size:10pt;padding:3px;text-transform:uppercase;">{{ $row['group_name'] }}</td>
                        <td style="font-size:9pt;padding:3px;">{{ implode(', ', $row['members']) ?: '-' }}</td>
                        <td class="text-center" style="font-size:11pt;padding:3px;font-weight:bold;">{{ number_format($row['votes']) }}</td>
                        <td class="text-center" style="font-size:10pt;padding:3px;">{{ number_format($row['percent'], 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center" style="padding:10px;">
                            No groups found. Set a Group on each participant in Reference &rarr; Participants.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top:14px;font-size:8pt;color:#888;">
            Generated {{ date('F d, Y h:i A') }}.
            @if ($tally['shared_fingerprints'] > 0)
                {{ $tally['shared_fingerprints'] }} vote(s) share a browser fingerprint with another vote.
            @endif
        </div>
    </main>
</body>

</html>
