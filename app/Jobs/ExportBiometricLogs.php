<?php

namespace App\Jobs;

use App\Services\EmployeeService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ExportBiometricLogs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1;

    private const REMARK_COLORS = [
        'Present'            => ['bg' => 'FFC6EFCE', 'fg' => 'FF375623'],
        'Late'               => ['bg' => 'FFFFCC99', 'fg' => 'FF833C00'],
        'Absent'             => ['bg' => 'FFFFC7CE', 'fg' => 'FF9C0006'],
        'Rest Day'           => ['bg' => 'FFF2F2F2', 'fg' => 'FF7F7F7F'],
        'Holiday'            => ['bg' => 'FFFFEB9C', 'fg' => 'FF9C6500'],
        'On Leave'           => ['bg' => 'FFDAEEF3', 'fg' => 'FF17375E'],
        'On Leave (Present)' => ['bg' => 'FFE4DFEC', 'fg' => 'FF403151'],
        'Pending'            => ['bg' => 'FFDDEBF7', 'fg' => 'FF1F4E79'],
    ];

    private array $timings = [];
    private float $jobStart;

    public function __construct(
        private string $jobId,
        private string $dateFrom,
        private string $dateTo,
        private string $type,
    ) {}

    private function tick(string $label): void
    {
        $elapsed = round(microtime(true) - $this->jobStart, 3);
        $this->timings[] = "[{$elapsed}s] {$label}";
        Log::channel('single')->info("[EXPORT TIMING] [{$elapsed}s] {$label}");
    }

public function handle(EmployeeService $employeeService): void
{
    $this->jobStart = microtime(true);
    $this->tick('Job started');
    $this->updateProgress(5, 'Initializing export...');

    try {
        $isWithBreaks = $this->type === 'with_breaks';
        
        // Define column widths and headers
        if ($isWithBreaks) {
            $headers = [
                'Employee ID', 'Employee Name', 'Department', 'Station', 'Prodline',
                'Date', 'Day', 'Shift',
                'Time In', 'Break Out 1', 'Break In 1',
                'Lunch Out', 'Lunch In',
                'Break Out 2', 'Break In 2', 'Time Out',
                'Remarks',
            ];
            $colWidths = [12, 32, 22, 14, 14, 12, 6, 16, 10, 12, 10, 10, 10, 12, 10, 10, 16];
        } else {
            $headers = ['Employee ID', 'Employee Name', 'Date DTR', 'Time DTR', 'Flag'];
            $colWidths = [12, 32, 12, 10, 12];
        }
        
        // Define normalize function
        $normalize = fn($v) => (!$v || $v === '--:--') ? '--' : $v;
        
        $colCount = count($headers);
        $lastColLetter = Coordinate::stringFromColumnIndex($colCount);
        
        // Spreadsheet setup
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('DTR Export');
        $sheet->fromArray($headers, null, 'A1');
        
        // Style headers
        $sheet->getStyle("A1:{$lastColLetter}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'name' => 'Arial', 'size' => 9],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F2937']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        
        // Set column widths
        foreach ($colWidths as $idx => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($idx + 1))->setWidth($width);
        }
        $sheet->freezePane('A2');
        
        // Get data ULTRA FAST from database
        $this->updateProgress(20, 'Fetching data from database...');
        $t = microtime(true);
        
        $data = $employeeService->getExportDataUltraFast($this->dateFrom, $this->dateTo, $this->type);
        
        $this->tick('Data fetched: ' . count($data) . ' rows in ' . round(microtime(true) - $t, 3) . 's');
        
        // Process and write to spreadsheet
        $this->updateProgress(50, 'Writing to spreadsheet...');
        $rowIdx = 2;
        $buffer = [];
        $bufferSize = 500;
        $rowsByColor = [];
        
        foreach ($data as $row) {
            if ($isWithBreaks) {
                $buffer[] = [
                    $row->EMPLOYID,
                    $row->EMPNAME,
                    $row->DEPARTMENT ?? '',
                    $row->STATION ?? '',
                    $row->PRODLINE ?? '',
                    $row->log_date,
                    $row->day,  // Use the day from the data
                    $row->shift_type,  // Shift type (Day/Night/Afternoon)
                    $normalize($row->time_in ?? null),
                    $row->is_shifting ? 'N/A' : $normalize($row->break_out_1 ?? null),
                    $row->is_shifting ? 'N/A' : $normalize($row->break_in_1 ?? null),
                    $normalize($row->lunch_out ?? null),
                    $normalize($row->lunch_in ?? null),
                    $normalize($row->break_out_2 ?? null),
                    $normalize($row->break_in_2 ?? null),
                    $normalize($row->time_out ?? null),
                    $row->remarks,
                ];
                
                // Track colors
                if (isset(self::REMARK_COLORS[$row->remarks])) {
                    $rowsByColor[$row->remarks][] = $rowIdx;
                }
                $rowIdx++;
            } else {
                // For without breaks, create a row for check_in if exists
                if ($row->time_in) {
                    $buffer[] = [$row->EMPLOYID, $row->EMPNAME, $row->log_date, $row->time_in, 'check_in'];
                    if (isset(self::REMARK_COLORS[$row->remarks])) {
                        $rowsByColor[$row->remarks][] = $rowIdx;
                    }
                    $rowIdx++;
                }
                // Create a row for check_out if exists
                if ($row->time_out) {
                    $buffer[] = [$row->EMPLOYID, $row->EMPNAME, $row->log_date, $row->time_out, 'check_out'];
                    if (isset(self::REMARK_COLORS[$row->remarks])) {
                        $rowsByColor[$row->remarks][] = $rowIdx;
                    }
                    $rowIdx++;
                }
            }
            
            // Write in chunks to save memory
            if (count($buffer) >= $bufferSize) {
                $sheet->fromArray($buffer, null, 'A' . ($rowIdx - count($buffer)));
                $buffer = [];
            }
        }
        
        // Write remaining rows
        if (!empty($buffer)) {
            $sheet->fromArray($buffer, null, 'A' . ($rowIdx - count($buffer)));
        }
        
        $this->tick('Writing complete in ' . round(microtime(true) - $t, 3) . 's');
        
        // Apply row colors
        if (!empty($rowsByColor)) {
            $this->updateProgress(90, 'Applying row colors...');
            $colorT = microtime(true);
            $this->applyRowColorsFast($sheet, $rowsByColor, $lastColLetter);
            $this->tick('Row coloring: ' . round(microtime(true) - $colorT, 3) . 's');
        }
        
        // Save file
        $this->updateProgress(95, 'Saving file...');
        $filename = "biometric_dtr_{$this->dateFrom}_to_{$this->dateTo}_{$this->type}.xlsx";
        $dir = storage_path('app/exports');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($dir . '/' . $filename);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $writer);
        
        $this->tick('File saved: ' . $filename);
        
        // Update progress and cache
        $totalTime = round(microtime(true) - $this->jobStart, 3);
        $this->updateProgress(100, 'Export complete!');
        
        Cache::put("export_timing_{$this->jobId}", [
            'total_seconds' => $totalTime,
            'timings' => $this->timings,
        ], now()->addMinutes(30));
        
        Cache::put("export_{$this->jobId}", [
            'status' => 'done',
            'progress' => 100,
            'message' => "Export complete! ({$totalTime}s)",
            'filename' => $filename,
        ], now()->addMinutes(10));
        
    } catch (\Throwable $e) {
        $this->tick('FAILED: ' . $e->getMessage());
        Log::channel('single')->error('[EXPORT TIMING] FAILED: ' . $e->getMessage());
        
        Cache::put("export_{$this->jobId}", [
            'status' => 'failed',
            'progress' => 0,
            'message' => 'Export failed: ' . $e->getMessage(),
            'filename' => null,
        ], now()->addMinutes(5));
        
        throw $e;
    }
}

    private function applyRowColorsFast(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array  $rowsByColor,
        string $lastColLetter
    ): void {
        foreach ($rowsByColor as $remarks => $rowIndices) {
            $colors = self::REMARK_COLORS[$remarks] ?? null;
            if (!$colors) continue;

            foreach ($this->buildContiguousRanges($rowIndices, $lastColLetter) as $range) {
                $style = $sheet->getStyle($range);
                $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($colors['bg']);
                $font = $style->getFont();
                $font->getColor()->setARGB($colors['fg']);
                $font->setName('Arial')->setSize(9);
            }
        }
    }

    private function updateProgress(int $pct, string $message): void
    {
        Cache::put("export_{$this->jobId}", [
            'status'   => 'processing',
            'progress' => $pct,
            'message'  => $message,
            'filename' => null,
        ], now()->addMinutes(10));
    }

    private function buildContiguousRanges(array $rowIndices, string $lastCol): array
    {
        if (empty($rowIndices)) return [];
        sort($rowIndices);
        $ranges = [];
        $start  = $rowIndices[0];
        $prev   = $rowIndices[0];
        for ($i = 1; $i < count($rowIndices); $i++) {
            if ($rowIndices[$i] === $prev + 1) {
                $prev = $rowIndices[$i];
            } else {
                $ranges[] = "A{$start}:{$lastCol}{$prev}";
                $start    = $rowIndices[$i];
                $prev     = $rowIndices[$i];
            }
        }
        $ranges[] = "A{$start}:{$lastCol}{$prev}";
        return $ranges;
    }

    public function failed(\Throwable $exception): void
    {
        Cache::put("export_{$this->jobId}", [
            'status'   => 'failed',
            'progress' => 0,
            'message'  => 'Export failed: ' . $exception->getMessage(),
            'filename' => null,
        ], now()->addMinutes(5));
    }
}