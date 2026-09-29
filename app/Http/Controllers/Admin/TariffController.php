<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Services\RoomAdminService;
use App\Domain\Enums\VisitPurpose;
use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Models\Tariff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Tariffs — ROUTES.md `/admin/tariffs`.
 *
 * Deliberately NOT a full resource: there is no edit or delete. A rate is
 * history (REPORTS.md section 3), so it is revised by adding a new row and
 * ended by setting its end date. Editing an amount in place would make the
 * tariff table disagree with the rates already snapshotted on allotments.
 */
class TariffController extends Controller
{
    public function __construct(
        private readonly RoomAdminService $rooms,
    ) {}

    public function index(): View
    {
        return view('admin.tariffs.index', [
            'types' => RoomType::query()
                ->with(['tariffs' => fn ($q) => $q->orderByRaw('purpose IS NULL DESC')->orderBy('purpose')->orderByDesc('effective_from')])
                ->orderBy('sort_order')
                ->get(),
            'purposes' => VisitPurpose::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', 'integer', Rule::exists('room_types', 'id')],
            'purpose' => ['nullable', Rule::enum(VisitPurpose::class)],
            'amount_per_night' => ['required', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ]);

        try {
            $this->rooms->addTariff($data, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['tariff' => $e->getMessage()]);
        }

        return back()->with('success', 'Tariff added. Existing allotments keep the rate they were given.');
    }

    public function end(Request $request, Tariff $tariff): RedirectResponse
    {
        $data = $request->validate([
            'effective_to' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $this->rooms->endTariff($tariff, $data['effective_to'], $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['tariff' => $e->getMessage()]);
        }

        return back()->with('success', 'Tariff end date set.');
    }
}
