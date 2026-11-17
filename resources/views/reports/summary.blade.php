<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>INTERNAL - QC Energy DSS</title>
<style>
    @page { margin: 36px; }
    body { font-family: "DejaVu Sans", sans-serif; color: #1c2b40; font-size: 10px; }
    h1 { color: #173a7a; font-size: 20px; } h2 { font-size: 13px; color: #173a7a; margin-top: 22px; }
    table { border-collapse: collapse; width: 100%; } th, td { padding: 6px; border-bottom: 1px solid #dfe6f0; text-align: left; overflow-wrap: break-word; }
    th { background: #eff4fb; } .notice { background: #eff4fb; padding: 10px; } .internal { font-weight: bold; color: #173a7a; }
</style></head>
<body>
<p class="internal">INTERNAL — QUEZON CITY HALL ENERGY DSS</p>
<h1>Monthly executive summary</h1>
<p><strong>{{ $report['building']['name'] }}</strong> ({{ $report['building']['code'] }})</p>
<p>Generated snapshot: {{ $report['generatedAt'] }} (UTC)<br>Latest complete verified period: {{ $report['latestPeriod'] ?? 'None available' }}<br>
Dataset version: {{ $report['dataset']['version'] ?? 'Unavailable' }}<br>
Dataset fingerprint: {{ $report['dataset']['fingerprint'] ?? 'Unavailable' }}</p>
@if ($report['stale'])<p class="notice">Stored dataset superseded. Historical billing values are excluded until reprocessing.</p>@endif
<h2>Verified electricity trend (latest 12 available months)</h2>
<table><thead><tr><th>Month</th><th>kWh</th><th>MoM %</th>@if($report['canViewCost'])<th>Actual cost PHP</th>@endif</tr></thead><tbody>
@forelse(array_slice($report['rows'], -12) as $row)
<tr><td>{{ substr($row['month'], 0, 7) }}</td><td>{{ number_format($row['kwh'], 3) }}</td><td>{{ $row['momPercent'] === null ? 'Unavailable' : number_format($row['momPercent'], 3) }}</td>@if($report['canViewCost'])<td>{{ $row['costPhp'] === null ? 'Unavailable' : number_format($row['costPhp'], 2) }}</td>@endif</tr>
@empty<tr><td colspan="{{ $report['canViewCost'] ? 4 : 3 }}">No current complete verified building months available.</td></tr>@endforelse
</tbody></table>
<h2>Current issued forecast</h2>
@if($report['forecast'])
<p>Run #{{ $report['forecast']['id'] }}; target {{ substr($report['forecast']['targetMonth'], 0, 7) }}; Linear Regression {{ $report['forecast']['modelVersion'] }}.<br>
Point: {{ number_format($report['forecast']['predictionKwh'], 3) }} kWh; empirical band {{ number_format($report['forecast']['lowerKwh'], 3) }}–{{ number_format($report['forecast']['upperKwh'], 3) }} kWh.<br>
{{ $report['forecast']['validationLabel'] }}</p>
<table><thead><tr><th>Method</th><th>MAE kWh</th><th>RMSE kWh</th><th>R²</th><th>Held-out months</th></tr></thead><tbody>
@foreach($report['forecast']['metrics'] as $method => $metrics)
<tr><td>{{ $method }}</td><td>{{ number_format($metrics['mae'], 3) }}</td><td>{{ number_format($metrics['rmse'], 3) }}</td><td>{{ $metrics['r2'] === null ? 'Undefined' : number_format($metrics['r2'], 3) }}</td><td>{{ $metrics['test_months'] }}</td></tr>
@endforeach
</tbody></table>
@else<p>No current eligible issued forecast. No prediction is fabricated.</p>@endif
<h2>Recommendations and decisions</h2><p>{{ $report['recommendationState'] }}</p>
@foreach($report['recommendations'] as $item)<p><strong>{{ $item['rule_code'] }}: {{ $item['title'] }}</strong> — {{ $item['decision'] }}<br>{{ $item['message'] }}<br>Evidence: {{ json_encode($item['evidence'], JSON_UNESCAPED_UNICODE) }}</p>@endforeach
<h2>Open accepted actions</h2>
<table><thead><tr><th>Action</th><th>Owner</th><th>Due</th><th>Historical source run</th></tr></thead><tbody>
@forelse($report['openActions'] as $action)<tr><td>#{{ $action['id'] }} {{ $action['title'] }}</td><td>{{ $action['owner'] }}</td><td>{{ $action['dueDate'] }}</td><td>#{{ $action['sourceForecastId'] }}</td></tr>
@empty<tr><td colspan="4">No accessible open accepted actions.</td></tr>@endforelse
</tbody></table>
<h2>Budget estimates</h2><p>{{ $report['budgetState'] }}</p>
@foreach($report['notes'] as $note)<p>{{ $note }}</p>@endforeach
<p class="internal">INTERNAL — authorized institutional use only.</p>
</body></html>
