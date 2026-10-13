<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Pennant Analytics')</title>
    <style>
        :root {
            --bg: #f7f7f5; --surface: #ffffff; --border: #e4e4e0; --text: #1c1c1a; --muted: #6b6b66;
            --accent: #2f5bd3; --good: #1d7a46; --good-bg: #e6f4ec; --bad: #b42318; --bad-bg: #fdecea;
            --neutral-bg: #f0f0ec; --bar: #2f5bd3; --bar-control: #a3a39c;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #141413; --surface: #1d1d1b; --border: #2e2e2b; --text: #ececea; --muted: #9b9b95;
                --accent: #7c9cf5; --good: #5fd08f; --good-bg: #16301f; --bad: #f28b82; --bad-bg: #3a1a17;
                --neutral-bg: #262624; --bar: #7c9cf5; --bar-control: #5c5c57;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .wrap { max-width: 1040px; margin: 0 auto; padding: 32px 16px 64px; }
        header { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
        h1 { font-size: 22px; margin: 0; letter-spacing: -0.01em; }
        h2 { font-size: 15px; margin: 0 0 12px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
        .muted { color: var(--muted); }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        .table-scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); white-space: nowrap; }
        th { font-size: 12px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
        td.num, th.num { text-align: right; }
        tr:last-child td { border-bottom: 0; }
        .pill { display: inline-block; font-size: 12px; padding: 2px 8px; border-radius: 999px; background: var(--neutral-bg); color: var(--muted); }
        .pill.good { background: var(--good-bg); color: var(--good); }
        .pill.bad { background: var(--bad-bg); color: var(--bad); }
        .verdict { font-size: 18px; font-weight: 600; padding: 16px 20px; border-radius: 10px; margin-bottom: 20px; background: var(--neutral-bg); }
        .verdict.good { background: var(--good-bg); color: var(--good); }
        .verdict.bad { background: var(--bad-bg); color: var(--bad); }
        .bar { height: 8px; border-radius: 4px; background: var(--neutral-bg); min-width: 120px; }
        .bar > span { display: block; height: 100%; border-radius: 4px; background: var(--bar); }
        .bar.control > span { background: var(--bar-control); }
        form.filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
        label { display: grid; gap: 4px; font-size: 12px; color: var(--muted); }
        input, select, button { font: inherit; color: var(--text); background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 6px 10px; }
        button { background: var(--accent); color: #fff; border-color: transparent; cursor: pointer; }
        .empty { text-align: center; padding: 48px 16px; color: var(--muted); }
        /* Phones: one card per row, every value labelled, nothing off-screen. */
        @media (max-width: 640px) {
            table.stack thead { display: none; }
            table.stack, table.stack tbody, table.stack tr, table.stack td { display: block; width: 100%; }
            table.stack tr { padding: 12px 0; border-bottom: 1px solid var(--border); }
            table.stack tr:last-child { border-bottom: 0; }
            table.stack td { border: 0; padding: 3px 0; white-space: normal; }
            table.stack td.lead { padding-bottom: 6px; font-size: 16px; }
            table.stack td[data-label] { display: flex; justify-content: space-between; gap: 16px; text-align: right; }
            table.stack td[data-label]::before { content: attr(data-label); color: var(--muted); font-size: 13px; text-align: left; }
            table.stack td.bar-cell { padding: 6px 0; }
        }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; background: var(--neutral-bg); padding: 1px 6px; border-radius: 4px; }
    </style>
</head>
<body>
<div class="wrap">
    @yield('content')
</div>
</body>
</html>
