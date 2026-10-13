@extends('pennant-analytics::layout')

@section('content')
    <header>
        <h1>Pennant Analytics</h1>
        <span class="muted">{{ count($features) }} {{ \Illuminate\Support\Str::plural('feature', count($features)) }} tracked</span>
    </header>

    <div class="card">
        @if ($features === [])
            <div class="empty">
                <p>No data yet.</p>
                <p>Record an impression with <code>PennantAnalytics::impression('feature')</code> and a conversion with
                    <code>PennantAnalytics::track('feature', 'action')</code>.</p>
            </div>
        @else
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>Feature</th>
                        <th class="num">Variants</th>
                        <th class="num">Unique impressions</th>
                        <th class="num">Last activity</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($features as $row)
                        <tr>
                            <td><a href="{{ route('pennant-analytics.show', $row->feature) }}">{{ $row->feature }}</a></td>
                            <td class="num">{{ $row->variants }}</td>
                            <td class="num">{{ number_format($row->impressions) }}</td>
                            <td class="num muted">{{ $row->lastSeen }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
