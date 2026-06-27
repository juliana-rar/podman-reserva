<?php

namespace App\Http\Controllers;

use App\Models\BusinessHour;
use App\Models\Employee;
use App\Models\Service;
use App\Models\Slot;
use App\Models\Stock;
use App\Models\StockCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SlotController extends Controller
{
    /**
     * Pàgina d'usuari: franges disponibles per reservar i reserves pròpies.
     */
    public function index(Request $request): Response
    {
        // Hores ja ocupades (futures): per cada reserva, l'inici, l'empleat i la durada
        // (en minuts) del servei/opció, perquè el frontend bloquegi tot el tram ocupat.
        $reservedTimes = Slot::query()
            ->has('reservation')
            ->where('starts_at', '>=', now()->startOfDay())
            ->with([
                'reservation:id,slot_id,employee_id,service_id,service_option_id',
                'reservation.service:id,duration_minutes',
                'reservation.serviceOption:id,duration_minutes',
            ])
            ->orderBy('starts_at')
            ->get()
            ->map(function (Slot $slot) {
                $reservation = $slot->reservation;
                $optionMinutes = $reservation?->serviceOption?->duration_minutes ?? 0;
                $serviceMinutes = $reservation?->service?->duration_minutes ?? 0;

                return [
                    'start' => $slot->starts_at->format('Y-m-d H:i'),
                    'employee_id' => $reservation?->employee_id,
                    'minutes' => $optionMinutes > 0 ? $optionMinutes : $serviceMinutes,
                ];
            })
            ->all();

        $myReservations = $request->user()
            ->reservations()
            ->with('slot:id,starts_at,notes', 'service:id,name')
            ->get()
            ->sortBy('slot.starts_at')
            ->values();

        return Inertia::render('Reservar', [
            // Horari d'atenció per dia (0 = dilluns … 6 = diumenge) i hores ja ocupades.
            'businessHours' => BusinessHour::orderBy('weekday')->get(['weekday', 'closed', 'opens', 'closes']),
            'reservedTimes' => $reservedTimes,
            'slotMinutes' => 30,
            'myReservations' => $myReservations,
            'services' => Service::with('category:id,name,description,image_path,images', 'options:id,service_id,name,price,duration_minutes,description,image_path,images')
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'duration_minutes', 'description', 'image_path', 'images', 'service_category_id']),
            'employees' => Employee::with('services:id', 'serviceOptions:id')
                ->orderBy('name')
                ->get(['id', 'name', 'image_path'])
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'url' => $e->url,
                    'service_ids' => $e->services->pluck('id'),
                    'option_ids' => $e->serviceOptions->pluck('id'),
                ]),
            // Articles d'stock disponibles (quantitat > 0) per oferir-los en fer la reserva,
            // agrupats per categoria; els que no en tenen van al grup «sense categoria».
            'stockCategories' => StockCategory::with(['stocks' => function ($query) {
                $query->where('quantity', '>', 0)
                    ->orderBy('name');
            }])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->filter(fn (StockCategory $c) => $c->stocks->isNotEmpty())
                ->values()
                ->map(fn (StockCategory $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'products' => $c->stocks->map(fn (Stock $s) => $this->stockPayload($s)),
                ]),
            'uncategorizedStock' => Stock::whereNull('stock_category_id')
                ->where('quantity', '>', 0)
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'quantity', 'image_path', 'images'])
                ->map(fn (Stock $s) => $this->stockPayload($s)),
        ]);
    }

    /**
     * Dades mínimes d'un article d'stock per a la pàgina de reserva.
     *
     * @return array{id: int, name: string, price: string, quantity: int, url: string|null}
     */
    private function stockPayload(Stock $stock): array
    {
        return [
            'id' => $stock->id,
            'name' => $stock->name,
            'price' => $stock->price,
            'quantity' => $stock->quantity,
            'url' => $stock->url,
        ];
    }

    /**
     * Pàgina d'usuari: historial de reserves ja fetes (franges ja passades).
     */
    public function reserves(Request $request): Response
    {
        $reservations = $request->user()
            ->reservations()
            ->whereHas('slot', fn ($query) => $query->where('starts_at', '<', now()))
            ->with('slot:id,starts_at,notes', 'service:id,name')
            ->get()
            ->sortByDesc('slot.starts_at')
            ->values();

        return Inertia::render('Reserves', [
            'reservations' => $reservations,
        ]);
    }

    /**
     * Pàgina d'admin: gestió de totes les franges horàries.
     */
    public function manage(): Response
    {
        // Només les franges d'avui en endavant; les de dies passats no es mostren.
        $slots = Slot::query()
            ->with('reservation.user:id,name,email', 'reservation.service:id,name,duration_minutes', 'reservation.serviceOption:id,duration_minutes', 'reservation.employee:id,name')
            ->whereDate('starts_at', '>=', now()->toDateString())
            ->orderBy('starts_at')
            ->get();

        // Franges per al resum/estadístiques: finestra amb passat i futur.
        $statsSlots = Slot::query()
            ->with('reservation.user:id,name,email', 'reservation.service:id,name,duration_minutes', 'reservation.serviceOption:id,duration_minutes', 'reservation.employee:id,name')
            ->whereBetween('starts_at', [now()->subDays(61)->startOfDay(), now()->addDays(62)->endOfDay()])
            ->orderBy('starts_at')
            ->get();

        return Inertia::render('admin/Horas', [
            'slots' => $slots,
            'statsSlots' => $statsSlots,
        ]);
    }

    /**
     * L'admin crea una nova franja horària disponible.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date', 'after:now', 'unique:slots,starts_at'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        Slot::create([
            'starts_at' => $validated['starts_at'],
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Franja afegida.']);

        return back();
    }

    /**
     * L'admin elimina una franja horària.
     */
    public function destroy(Slot $slot): RedirectResponse
    {
        $slot->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Franja eliminada.']);

        return back();
    }
}
