<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Holiday calendar — ROUTES.md `/admin/holidays`.
 *
 * Unlike rooms and users, a holiday is referenced by nothing, so it may be
 * deleted outright. Every change is still audited.
 */
class HolidayController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $year = (int) $request->query('year', now()->format('Y'));
        abort_unless($year >= 2000 && $year <= 2100, 404);

        return view('admin.holidays.index', [
            'year' => $year,
            'holidays' => Holiday::where('year', $year)->orderBy('date')->get(),
            'types' => Holiday::TYPES,
            'editing' => $request->filled('edit') ? Holiday::find((int) $request->query('edit')) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        $holiday = DB::transaction(function () use ($data, $request) {
            $h = Holiday::create($data);
            $this->audit->record($h, 'HOLIDAY_CREATED', $request->user(), metadata: $this->meta($h));

            return $h;
        });

        return redirect()->route('admin.holidays.index', ['year' => $holiday->year])
            ->with('success', "{$holiday->name} added on {$holiday->date->format('d/m/Y')}.");
    }

    public function update(Request $request, Holiday $holiday): RedirectResponse
    {
        $data = $this->validated($request, $holiday);

        DB::transaction(function () use ($holiday, $data, $request) {
            $holiday->update($data);
            $this->audit->record($holiday, 'HOLIDAY_UPDATED', $request->user(), metadata: $this->meta($holiday));
        });

        return redirect()->route('admin.holidays.index', ['year' => $holiday->year])
            ->with('success', "{$holiday->name} updated.");
    }

    public function destroy(Request $request, Holiday $holiday): RedirectResponse
    {
        $year = $holiday->year;

        DB::transaction(function () use ($holiday, $request) {
            // Audit first: the row it describes is about to disappear.
            $this->audit->record($holiday, 'HOLIDAY_DELETED', $request->user(), metadata: $this->meta($holiday));
            $holiday->delete();
        });

        return redirect()->route('admin.holidays.index', ['year' => $year])
            ->with('success', "{$holiday->name} removed from the calendar.");
    }

    /** @return array{date: string, name: string, type: string} */
    private function validated(Request $request, ?Holiday $holiday): array
    {
        return $request->validate([
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')->ignore($holiday?->id)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(Holiday::TYPES))],
        ], [
            'date.unique' => 'There is already a holiday on that date.',
        ]);
    }

    /** @return array<string, string> */
    private function meta(Holiday $h): array
    {
        return ['date' => $h->date->format('Y-m-d'), 'name' => $h->name, 'type' => $h->type];
    }
}
