<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shared;

use App\Application\Services\ReportService;
use App\Domain\Contracts\PdfGenerator;
use App\Http\Controllers\Controller;
use App\Infrastructure\Exports\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The seven reports — ROUTES.md `/reports`, REPORTS.md section 3.
 *
 * Reachable by Manager, ADG and Admin (route middleware). Which reports each
 * may open, and whose rows they see, is decided by ReportService so the screen
 * and both export formats always share one rule.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly PdfGenerator $pdf,
    ) {}

    public function index(Request $request): View
    {
        return view('reports.index', [
            'types' => $this->reports->availableFor($request->user()),
        ]);
    }

    public function show(Request $request, string $type): View
    {
        abort_unless($this->reports->canView($request->user(), $type), 403);
        [$from, $to] = $this->period($request);

        try {
            $report = $this->reports->build($type, $from, $to, $request->user());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return view('reports.show', [
            'report' => $report,
            'types' => $this->reports->availableFor($request->user()),
        ]);
    }

    public function export(Request $request, string $type, string $format): Response
    {
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);
        abort_unless($this->reports->canView($request->user(), $type), 403);
        [$from, $to] = $this->period($request);

        $report = $this->reports->build($type, $from, $to, $request->user());

        // REPORTS.md section 5 asks for large exports to be queued. Until that
        // exists, refusing plainly is safer than tying up a worker for minutes
        // or silently truncating an audit-facing report.
        if ($report->rowCount() > ReportService::EXPORT_ROW_LIMIT) {
            return back()->withErrors(['report' => sprintf(
                'This report has %s rows, above the export limit of %s. Narrow the date range and export again.',
                number_format($report->rowCount()), number_format(ReportService::EXPORT_ROW_LIMIT),
            )]);
        }

        $by = $request->user()->name;
        $at = now()->format('d/m/Y, g:i a');
        $filename = sprintf('%s_%s_to_%s.%s', $type, $from, $to, $format);

        if ($format === 'xlsx') {
            $bytes = Excel::raw(new ReportExport($report, $by, $at), ExcelFormat::XLSX);

            return response($bytes, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        $bytes = $this->pdf->fromView('reports.pdf', [
            'report' => $report, 'generatedBy' => $by, 'generatedAt' => $at,
        ], 'a4', count($report->columns) > 6 ? 'landscape' : 'portrait');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Validated period; defaults to the current calendar month. Capped at one
     * year so a hand-edited query string cannot ask for a decade of day-by-day
     * occupancy.
     *
     * @return array{0: string, 1: string}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = $data['from'] ?? now()->startOfMonth()->format('Y-m-d');
        $to = $data['to'] ?? now()->endOfMonth()->format('Y-m-d');

        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            abort(422, 'A report can cover at most one year.');
        }

        return [$from, $to];
    }
}
