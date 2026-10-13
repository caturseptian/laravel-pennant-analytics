@extends('pennant-analytics::layout')

@section('title', $feature.' · Pennant Analytics')

@section('content')
    <header>
        <h1><a href="{{ route('pennant-analytics.index') }}">Pennant Analytics</a> <span class="muted">/</span> {{ $feature }}</h1>
    </header>

    @if ($report === null)
        <div class="card empty">
            <p>No conversion actions recorded for <code>{{ $feature }}</code> yet.</p>
            <p>Track one with <code>PennantAnalytics::track('{{ $feature }}', 'completed-purchase')</code>.</p>
        </div>
    @else
        @php
            $leader = $report->leader();
            $tone = $leader?->comparison?->isSignificant() ? ($leader->comparison->isWinning() ? 'good' : 'bad') : '';
            $maxRate = max(array_map(fn ($v) => $v->conversionRate(), $report->variants) ?: [0]) ?: 1;
        @endphp

        <form class="card filters" method="get">
            <label>Conversion action
                <select name="action">
                    @foreach ($actions as $option)
                        <option value="{{ $option }}" @selected($option === $action)>{{ $option }}</option>
                    @endforeach
                </select>
            </label>
            <label>From <input type="date" name="from" value="{{ $from }}"></label>
            <label>To <input type="date" name="to" value="{{ $to }}"></label>
            <button type="submit">Apply</button>
        </form>

        <div class="verdict {{ $tone }}">{{ $report->summary() }}</div>

        <div class="card">
            <h2>{{ $action }} · {{ rtrim(rtrim(number_format($report->confidence * 100, 2), '0'), '.') }}% confidence</h2>
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>Variant</th>
                        <th class="num">Impressions</th>
                        <th class="num">Conversions</th>
                        <th class="num">Rate</th>
                        <th></th>
                        <th class="num">Lift</th>
                        <th class="num">p-value</th>
                        <th>Result</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($report->variants as $variant)
                        @php $c = $variant->comparison; @endphp
                        <tr>
                            <td><strong>{{ $variant->variant }}</strong> @if ($variant->isControl)<span class="pill">control</span>@endif</td>
                            <td class="num">{{ number_format($variant->impressions) }}</td>
                            <td class="num">{{ number_format($variant->conversions) }}</td>
                            <td class="num">{{ number_format($variant->conversionRate(), 2) }}%</td>
                            <td><div class="bar {{ $variant->isControl ? 'control' : '' }}"><span style="width: {{ $variant->conversionRate() / $maxRate * 100 }}%"></span></div></td>
                            <td class="num">{{ $c?->relativeLift !== null ? sprintf('%+.1f%%', $c->relativeLift) : '—' }}</td>
                            <td class="num">{{ $c ? number_format($c->pValue, 4) : '—' }}</td>
                            <td>
                                @if ($c === null)
                                    <span class="muted">baseline</span>
                                @elseif (! $c->hasEnoughData)
                                    <span class="pill">insufficient data</span>
                                @elseif ($c->isSignificant())
                                    <span class="pill {{ $c->isWinning() ? 'good' : 'bad' }}">significant</span>
                                @else
                                    <span class="pill">not significant</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted" style="margin: 16px 0 0; font-size: 13px;">
                Impressions and conversions are unique subjects. Significance uses a two-sided, two-proportion z-test against the control.
                Results are marked "insufficient data" until each variant has {{ config('pennant-analytics.statistics.min_sample_size') }}+ impressions and 5+ expected conversions.
            </p>
        </div>
    @endif
@endsection
