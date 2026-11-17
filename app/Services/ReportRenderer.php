<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportRenderer
{
    /** @param array<string, mixed> $report */
    public function render(array $report, string $type): string
    {
        if (! in_array($type, ['summary', 'billing', 'evidence'], true)) {
            throw new \InvalidArgumentException('Unknown report type.');
        }
        if ($type === 'summary') {
            $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
                'chroot' => resource_path('views/reports'), 'defaultFont' => 'DejaVu Sans']);
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view('reports.summary', ['report' => $report])->render());
            $pdf->setPaper('A4');
            $pdf->render();

            return $pdf->output();
        }
        $book = new Spreadsheet;
        $book->getProperties()->setTitle('INTERNAL - QC Energy DSS')->setSubject($report['building']['name']);
        $summary = [
            ['Classification', 'INTERNAL'], ['Building', $report['building']['name']], ['Building code', $report['building']['code']],
            ['Generated at (UTC)', $report['generatedAt']], ['Latest complete verified period', $report['latestPeriod']],
            ['Dataset version', $report['dataset']['version'] ?? null], ['Dataset fingerprint', $report['dataset']['fingerprint'] ?? null],
            ['Source state', $report['stale'] ? 'Superseded dataset; values excluded' : ($report['dataset'] ? 'Current verified dataset' : 'No current dataset')],
            ['Recommendation availability', $report['recommendationState']], ['Budget estimate', $report['budgetState']],
            ['Forecast run', $report['forecast']['id'] ?? null], ['Forecast target', $report['forecast']['targetMonth'] ?? null],
            ['Forecast kWh', $report['forecast']['predictionKwh'] ?? null], ['Empirical lower kWh', $report['forecast']['lowerKwh'] ?? null],
            ['Empirical upper kWh', $report['forecast']['upperKwh'] ?? null],
            ['Primary method', 'Linear Regression'], ['Model version', $report['forecast']['modelVersion'] ?? null],
            ['Training through', $report['forecast']['trainingEndMonth'] ?? null],
            ['Validation note', $report['forecast']['validationLabel'] ?? null],
        ];
        foreach ($report['notes'] as $note) {
            $summary[] = ['Scope note', $note];
        }
        $this->sheet($book, 'Summary', $summary, true);
        if ($type === 'billing') {
            $headers = ['Month', 'Record ID', 'Meter ID', 'kWh', 'Billed demand kW', 'Power factor', 'Verified version'];
            if ($report['canViewCost']) {
                $headers[] = 'Actual cost PHP';
            }
            $rows = [$headers];
            foreach ($report['billing'] as $bill) {
                $row = [$bill['month'], $bill['recordId'], $bill['meterId'], $bill['kwh'], $bill['billedDemandKw'], $bill['powerFactor'], $bill['version']];
                if ($report['canViewCost']) {
                    $row[] = $bill['costPhp'];
                }
                $rows[] = $row;
            }
            $this->sheet($book, 'Verified billing', $rows);
            $rows = [['Month', 'Complete building kWh', 'MoM percent', 'YoY percent', 'kWh per m2', 'Single-meter load factor']];
            if ($report['canViewCost']) {
                $rows[0][] = 'Actual cost PHP';
            }
            foreach ($report['rows'] as $period) {
                $row = [$period['month'], $period['kwh'], $period['momPercent'], $period['yoyPercent'], $period['kwhPerM2'], $period['loadFactor']];
                if ($report['canViewCost']) {
                    $row[] = $period['costPhp'];
                }
                $rows[] = $row;
            }
            $this->sheet($book, 'Monthly analytics', $rows);
        }
        if ($report['forecast'] !== null) {
            $rows = [['Method', 'MAE kWh', 'RMSE kWh', 'R2', 'Held-out months']];
            foreach ($report['forecast']['metrics'] as $method => $metrics) {
                $rows[] = [$method, $metrics['mae'], $metrics['rmse'], $metrics['r2'], $metrics['test_months']];
            }
            $this->sheet($book, 'Model metrics', $rows);
            $rows = [['Held-out month', 'Actual kWh', 'Linear predicted kWh', 'Three-month baseline kWh', 'Training end', 'Training rows']];
            foreach ($report['forecast']['evidence'] as $evidence) {
                $rows[] = [$evidence['month'], $evidence['actual_kwh'], $evidence['linear_kwh'], $evidence['baseline_kwh'], $evidence['train_end_month'], $evidence['training_rows']];
            }
            $this->sheet($book, 'Walk-forward evidence', $rows);
        }
        $rows = [['Action ID', 'Title', 'Assigned owner', 'Due date', 'Status', 'Historical source run']];
        foreach ($report['openActions'] as $action) {
            $rows[] = [$action['id'], $action['title'], $action['owner'], $action['dueDate'], $action['status'], $action['sourceForecastId']];
        }
        $this->sheet($book, 'Open accepted actions', $rows);
        $rows = [['Rule', 'Title', 'Decision', 'Priority', 'Evidence JSON']];
        foreach ($report['recommendations'] as $item) {
            $rows[] = [$item['rule_code'], $item['title'], $item['decision'], $item['priority'], json_encode($item['evidence'], JSON_THROW_ON_ERROR)];
        }
        $this->sheet($book, 'Current recommendations', $rows);
        $writer = new Xlsx($book);
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Cannot allocate report buffer.');
        }
        try {
            $writer->save($stream);
            rewind($stream);
            $bytes = stream_get_contents($stream);
            if ($bytes === false) {
                throw new \RuntimeException('Cannot read report buffer.');
            }

            return $bytes;
        } finally {
            fclose($stream);
            $book->disconnectWorksheets();
        }
    }

    /** @param list<list<string|int|float|null>> $rows */
    private function sheet(Spreadsheet $book, string $title, array $rows, bool $first = false): void
    {
        $sheet = $first ? $book->getActiveSheet() : $book->createSheet();
        $sheet->setTitle($title);
        foreach ($rows as $row => $cells) {
            foreach ($cells as $column => $value) {
                // Explicit text cells prevent formula execution of user-authored
                // names/titles (including values beginning with =, +, -, or @).
                if ($value !== null) {
                    $sheet->setCellValueExplicit([$column + 1, $row + 1], $value, is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
            }
        }
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
        $sheet->getHeaderFooter()->setOddHeader('&CINTERNAL - QC Energy DSS');
    }
}
