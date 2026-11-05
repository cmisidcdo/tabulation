<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Group Ranking Report</title>
    <style>
        * {
            font-family: Arial, Helvetica, sans-serif;
        }

        .table {
            width: 100%;
        }

        table.bordered td {
            border: 1px solid black;
        }

        table.bordered th {
            border: 1px solid black;
        }

        .table {
            border-collapse: collapse;
        }

        .text-center {
            text-align: center;
        }

        .text-start {
            text-align: left;
        }

        .text-end {
            text-align: right;
        }

        .bold {
            font-weight: bold;
        }

        .p-2 {
            padding: 5px;
        }

        .p-3 {
            padding: 10px;
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

        #watermark {
            position: fixed;
            top: 1%;
            width: 100%;
            text-align: center;
            transform-origin: 50% 50%;
            opacity: .07;
        }

        table.bordered tbody tr:nth-child(odd) {
            background-color: #f2f2f2;
        }

        table.bordered tbody tr:nth-child(even) {
            background-color: #ffffff;
        }

        #developer {
            position: fixed;
            top: 50%;
            left: -115px;
            font-weight: bold;
            text-align: right;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
        }

        .winner-1 {
            background: rgb(255, 240, 152) !important;
            color: black;
            font-weight: bold !important;
        }

        .winner-2 {
            background: silver !important;
            color: black !important;
            font-weight: bold !important;
        }

        .winner-3 {
            background: #fcd6b0 !important;
            color: black;
            font-weight: bold !important;
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
            $isBangga = $category->category == 'bangga-sa-daygon';
            $titlePrefix = Setting::get('report_header_title', '');
            $venue = $isBangga ? Setting::get('report_header_venue_alt', 'Cagayan de Oro City Hall Building - Tourism Hall') : Setting::get('report_header_venue', '');
            $time = $isBangga ? Setting::get('report_header_time_alt', '4:00 PM') : Setting::get('report_header_time', '');
            $datetime = $time ? date('F d, Y') . ' | ' . $time : date('F d, Y');
        @endphp
        @include('generated_pdf._header', [
            'headerTitle' => $titlePrefix ? $titlePrefix . ': ' . $category->description : $category->description,
            'headerVenue' => $venue,
            'headerDatetime' => $datetime,
        ])

        {{-- Report title block --}}
        <div style="text-align:center;padding:10px 0 6px;">
            <div style="font-size:18pt;font-weight:bold;">Group Ranking &amp; Best Speaker</div>
            <div style="font-size:14pt;text-transform:uppercase;color:#266da7;font-weight:bold;">
                {{ $category->description }}
            </div>
            <div style="font-size:10pt;color:#888;margin-top:4px;">
                Group Score = average of members' final scores (judge average less deductions)
            </div>
        </div>

        {{-- Group ranking summary --}}
        <table class="table bordered" style="margin-top:10px;">
            <thead>
                <tr style="background:#2c3e50;color:#fff;">
                    <th width="8%" style="font-size:9pt;padding:4px;">RANK</th>
                    <th style="font-size:9pt;padding:4px;">GROUP</th>
                    <th width="8%" style="font-size:9pt;padding:4px;text-align:center;">MEMBERS</th>
                    <th width="10%" style="font-size:9pt;padding:4px;text-align:center;">TOTAL</th>
                    <th width="10%" style="font-size:9pt;padding:4px;text-align:center;background:#27ae60;color:#fff;">AVERAGE</th>
                    <th width="28%" style="font-size:9pt;padding:4px;">BEST SPEAKER</th>
                    <th width="14%" style="font-size:9pt;padding:4px;text-align:center;">PLACEMENT</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($groups as $group)
                    @php $hasScore = $group['average'] > 0; @endphp
                    <tr class="{{ $hasScore ? 'winner-' . $group['ordinal_rank'] : '' }}">
                        <td class="text-center" style="font-size:10pt;padding:3px;font-weight:bold;">{{ $hasScore ? bong_ordinal($group['ordinal_rank']) : '-' }}</td>
                        <td style="font-size:10pt;padding:3px;text-transform:uppercase;">{{ $group['group_name'] }}</td>
                        <td class="text-center" style="font-size:10pt;padding:3px;">{{ $group['member_count'] }}</td>
                        <td class="text-center" style="font-size:10pt;padding:3px;">{{ $hasScore ? bong_format($group['total']) : '-' }}</td>
                        <td class="text-center" style="font-size:11pt;padding:3px;font-weight:bold;">{{ $hasScore ? bong_format($group['average']) : '-' }}</td>
                        <td style="font-size:9pt;padding:3px;">
                            @forelse ($group['best_speakers'] as $speaker)
                                {{ $speaker['participant'] }} ({{ bong_format($speaker['score']) }})@if (!$loop->last)<br />@endif
                            @empty
                                -
                            @endforelse
                        </td>
                        <td class="text-center" style="font-size:9pt;padding:3px;color:green;font-weight:bold;">{{ $hasScore ? bong_ordinal_new($group['ordinal_rank']) : '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center" style="padding:10px;">
                            No groups found. Set a Group on each participant in Reference &rarr; Participants.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- Per-group member breakdown --}}
        @foreach ($groups as $group)
            <div style="margin-top:18px;page-break-inside:avoid;">
                <div style="font-size:11pt;font-weight:bold;padding:4px 0;text-transform:uppercase;">
                    {{ $group['group_name'] }}
                    <span style="font-weight:normal;font-size:9pt;color:#555;text-transform:none;">
                        &mdash; Average: <strong>{{ bong_format($group['average']) }}</strong>
                        &nbsp;|&nbsp; Total: {{ bong_format($group['total']) }}
                        &nbsp;|&nbsp; {{ $group['member_count'] }} member(s)
                    </span>
                </div>
                <table class="table bordered">
                    <thead>
                        <tr style="background:#eaf0f6;">
                            <th width="6%" style="font-size:8pt;padding:3px;">#</th>
                            <th style="font-size:8pt;padding:3px;">MEMBER</th>
                            @foreach ($judges as $judge)
                                <th width="9%" style="font-size:8pt;padding:3px;text-align:center;">{{ $judge->judge }}</th>
                            @endforeach
                            <th width="8%" style="font-size:8pt;padding:3px;text-align:center;">DEDUCTION</th>
                            <th width="9%" style="font-size:8pt;padding:3px;text-align:center;">FINAL SCORE</th>
                            <th width="12%" style="font-size:8pt;padding:3px;text-align:center;">AWARD</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group['members'] as $member)
                            <tr>
                                <td class="text-center" style="font-size:9pt;padding:3px;">{{ $member['participant_no'] }}</td>
                                <td style="font-size:9pt;padding:3px;{{ $member['is_best'] ? 'font-weight:bold;' : '' }}">{{ $member['participant'] }}</td>
                                @foreach ($judges as $judge)
                                    <td class="text-center" style="font-size:9pt;padding:3px;">
                                        {{ ($member['judge_scores'][$judge->user_id] ?? 0) > 0 ? bong_format($member['judge_scores'][$judge->user_id]) : '-' }}
                                    </td>
                                @endforeach
                                <td class="text-center" style="font-size:9pt;padding:3px;color:#c0392b;">{{ $member['deduction'] ? bong_format($member['deduction']) : '-' }}</td>
                                <td class="text-center" style="font-size:9pt;padding:3px;font-weight:bold;">{{ bong_format($member['score']) }}</td>
                                <td class="text-center" style="font-size:8pt;padding:3px;color:green;font-weight:bold;">{{ $member['is_best'] ? 'BEST SPEAKER' : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach

        {{-- Judge signature strip --}}
        @if ($judges->isNotEmpty())
            <div style="margin-top:30px;">
                <table style="width:100%;">
                    <tr>
                        @foreach ($judges as $judge)
                            <td style="text-align:center;vertical-align:bottom;padding:0 10px;">
                                <div style="margin-bottom:4px;font-size:9pt;font-weight:bold;">
                                    JUDGE #{{ $judge->nickname }}
                                </div>
                                <div style="border-top:1px solid black;padding-top:2px;">
                                    <span style="text-transform:uppercase;font-size:9pt;">{{ $judge->judge }}</span><br />
                                    <i style="font-size:8pt;color:#555;">Signature over Printed Name</i>
                                </div>
                            </td>
                            @if ($loop->iteration % 4 == 0 && !$loop->last)
                    </tr>
                    <tr style="height:30px;"></tr>
                    <tr>
                            @endif
                        @endforeach
                    </tr>
                </table>
            </div>
        @endif
    </main>
</body>

</html>
