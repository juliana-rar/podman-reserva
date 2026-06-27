<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesImages;
use App\Models\BusinessHour;
use App\Models\Cancellation;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Models\Slot;
use App\Models\Stock;
use App\Models\StockCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    use ManagesImages;

    /**
     * Historial de totes les reserves fetes (només admin).
     */
    public function history(): Response
    {
        return Inertia::render('admin/Historial', [
            'reservations' => Reservation::query()
                ->with([
                    'user:id,name,email,phone',
                    'slot:id,starts_at,notes',
                    'service:id,name,price,vat_rate,service_category_id',
                    'service.category:id,name',
                    'serviceOption:id,name,price',
                    'stocks:id,name,price,vat_rate',
                ])
                ->latest()
                ->get(['id', 'slot_id', 'user_id', 'service_id', 'service_option_id', 'employee_id', 'note', 'created_at']),
            'services' => Service::with('options:id,service_id,name,price')
                ->orderBy('name')
                ->get(['id', 'name', 'price']),
            'employees' => Employee::orderBy('name')->get(['id', 'name']),
            // Catàleg d'stock (tots els articles, agrupats) per poder editar els productes
            // d'una reserva des del modal.
            'stockCategories' => StockCategory::with(['stocks' => fn ($query) => $query->orderBy('name')])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->filter(fn (StockCategory $c) => $c->stocks->isNotEmpty())
                ->values()
                ->map(fn (StockCategory $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'products' => $c->stocks->map(fn (Stock $s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'price' => $s->price,
                        'quantity' => $s->quantity,
                    ]),
                ]),
            'uncategorizedStock' => Stock::whereNull('stock_category_id')
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'quantity'])
                ->map(fn (Stock $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'price' => $s->price,
                    'quantity' => $s->quantity,
                ]),
        ]);
    }

    /**
     * L'admin edita una reserva ja feta (servei, opció, empleat o nota poden canviar).
     */
    public function update(Request $request, Reservation $reservation): RedirectResponse
    {
        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'service_option_id' => [
                'nullable',
                'integer',
                Rule::exists('service_options', 'id')->where('service_id', $request->input('service_id')),
            ],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'note' => ['required', 'string', 'max:1000'],
            'products' => ['nullable', 'array'],
            'products.*.stock_id' => ['required_with:products', 'integer', 'distinct', 'exists:stocks,id'],
            'products.*.quantity' => ['required_with:products', 'integer', 'min:1'],
        ]);

        $reservation->update([
            'service_id' => $validated['service_id'],
            'service_option_id' => $validated['service_option_id'] ?? null,
            'employee_id' => $validated['employee_id'],
            'note' => $validated['note'],
        ]);

        $sync = [];

        foreach ($validated['products'] ?? [] as $product) {
            $sync[$product['stock_id']] = ['quantity' => $product['quantity']];
        }

        $reservation->stocks()->sync($sync);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reserva actualitzada.']);

        return back();
    }

    /**
     * L'usuari reserva una hora lliure dins l'horari d'atenció.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'service_option_id' => [
                'nullable',
                'integer',
                Rule::exists('service_options', 'id')->where('service_id', $request->input('service_id')),
            ],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'products' => ['nullable', 'array'],
            'products.*.stock_id' => ['required_with:products', 'integer', 'distinct', 'exists:stocks,id'],
            'products.*.quantity' => ['required_with:products', 'integer', 'min:1'],
        ]);

        $startsAt = Carbon::parse($validated['starts_at'])->startOfMinute();

        $this->ensureWithinBusinessHours($startsAt);

        $this->ensureNoOverlap(
            $startsAt,
            (int) $validated['employee_id'],
            (int) $validated['service_id'],
            isset($validated['service_option_id']) ? (int) $validated['service_option_id'] : null,
        );

        // Reutilitza la franja si ja existeix (creada per l'admin o per una reserva prèvia).
        $slot = Slot::firstOrCreate(
            ['starts_at' => $startsAt],
            ['created_by' => $request->user()->id],
        );

        if ($slot->reservation()->exists()) {
            throw ValidationException::withMessages([
                'starts_at' => 'Aquesta hora ja està reservada.',
            ]);
        }

        $reservation = Reservation::create([
            'slot_id' => $slot->id,
            'user_id' => $request->user()->id,
            'service_id' => $validated['service_id'],
            'service_option_id' => $validated['service_option_id'] ?? null,
            'employee_id' => $validated['employee_id'],
            'note' => $validated['note'] ?? null,
        ]);

        $attach = $this->stockAttachments($validated['products'] ?? []);
        $reservation->stocks()->attach($attach);

        // Notificació automàtica amb els detalls de la reserva al fil de xat de l'usuari.
        Message::create([
            'user_id' => $reservation->user_id,
            'sender' => 'system',
            'body' => $this->reservationSummary($slot, $validated, $attach),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reserva feta!']);

        return back();
    }

    /**
     * Comprova que l'hora triada sigui reservable: futura i dins l'horari
     * d'atenció del dia (qualsevol minut entre l'obertura i el tancament).
     */
    private function ensureWithinBusinessHours(Carbon $startsAt): void
    {
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['starts_at' => 'Has de triar una hora futura.']);
        }

        // weekday a business_hours: 0 = dilluns … 6 = diumenge.
        $hours = BusinessHour::where('weekday', $startsAt->dayOfWeekIso - 1)->first();

        if (! $hours || $hours->closed || ! $hours->opens || ! $hours->closes) {
            throw ValidationException::withMessages(['starts_at' => 'Aquell dia està tancat.']);
        }

        $minute = $startsAt->hour * 60 + $startsAt->minute;
        $opens = $this->minutesOfTime($hours->opens);
        $closes = $this->minutesOfTime($hours->closes);

        if ($minute < $opens || $minute >= $closes) {
            throw ValidationException::withMessages(['starts_at' => "Aquesta hora és fora de l'horari d'atenció."]);
        }
    }

    /**
     * Comprova que el servei (amb la seva durada) no se solapi amb cap altra reserva
     * del mateix empleat el mateix dia.
     */
    private function ensureNoOverlap(Carbon $startsAt, int $employeeId, int $serviceId, ?int $serviceOptionId): void
    {
        $newStart = $startsAt->copy();
        $newEnd = $startsAt->copy()->addMinutes($this->durationMinutes($serviceId, $serviceOptionId));

        $reservations = Reservation::query()
            ->where('employee_id', $employeeId)
            ->whereHas('slot', fn ($query) => $query->whereDate('starts_at', $startsAt->toDateString()))
            ->with(['slot:id,starts_at', 'service:id,duration_minutes', 'serviceOption:id,duration_minutes'])
            ->get();

        foreach ($reservations as $reservation) {
            $existingStart = Carbon::parse($reservation->slot->starts_at);
            $existingMinutes = (int) ($reservation->serviceOption?->duration_minutes
                ?: $reservation->service?->duration_minutes
                ?: 0);
            $existingEnd = $existingStart->copy()->addMinutes(max($existingMinutes, 1));

            if ($newStart->lt($existingEnd) && $existingStart->lt($newEnd)) {
                throw ValidationException::withMessages([
                    'starts_at' => "Aquesta hora se solapa amb una altra reserva de l'empleat.",
                ]);
            }
        }
    }

    /**
     * Durada en minuts d'un servei o opció (l'opció mana si en té; mínim 1).
     */
    private function durationMinutes(int $serviceId, ?int $serviceOptionId): int
    {
        $optionMinutes = $serviceOptionId ? (int) (ServiceOption::find($serviceOptionId)?->duration_minutes ?? 0) : 0;

        if ($optionMinutes > 0) {
            return $optionMinutes;
        }

        $serviceMinutes = (int) (Service::find($serviceId)?->duration_minutes ?? 0);

        return $serviceMinutes > 0 ? $serviceMinutes : 1;
    }

    /**
     * Converteix una hora "HH:MM" o "HH:MM:SS" en minuts des de mitjanit.
     */
    private function minutesOfTime(string $time): int
    {
        [$h, $m] = explode(':', $time);

        return (int) $h * 60 + (int) $m;
    }

    /**
     * Resum llegible amb els detalls d'una reserva per al missatge de xat.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int, array{quantity: int}>  $attach
     */
    private function reservationSummary(Slot $slot, array $validated, array $attach): string
    {
        $service = Service::find($validated['service_id']);
        $option = ! empty($validated['service_option_id']) ? ServiceOption::find($validated['service_option_id']) : null;
        $employee = Employee::find($validated['employee_id']);

        $serviceLine = $service?->name ?? 'servei';
        if ($option) {
            $serviceLine .= ' ('.$option->name.')';
        }

        $lines = [
            '📅 Nova reserva',
            'Servei: '.$serviceLine,
            'Empleat: '.($employee?->name ?? '—'),
            'Data: '.Carbon::parse($slot->starts_at)->format('d/m/Y H:i'),
        ];

        if ($attach !== []) {
            $names = Stock::whereIn('id', array_keys($attach))->pluck('name', 'id');
            $products = [];
            foreach ($attach as $id => $pivot) {
                $products[] = $pivot['quantity'].'× '.($names[$id] ?? '');
            }
            $lines[] = 'Productes: '.implode(', ', $products);
        }

        if (! empty($validated['note'])) {
            $lines[] = 'Nota: '.$validated['note'];
        }

        return implode("\n", $lines);
    }

    /**
     * Prepara els productes a lligar a la reserva, limitant la quantitat a l'stock
     * disponible i descartant els que ja no en tinguin.
     *
     * @param  list<array{stock_id: int, quantity: int}>  $products
     * @return array<int, array{quantity: int}>
     */
    private function stockAttachments(array $products): array
    {
        $attach = [];

        foreach ($products as $product) {
            $stock = Stock::find($product['stock_id']);
            $quantity = min($product['quantity'], $stock?->quantity ?? 0);

            if ($quantity > 0) {
                $attach[$product['stock_id']] = ['quantity' => $quantity];
            }
        }

        return $attach;
    }

    /**
     * Llistat de valoracions fetes pels usuaris (només admin).
     */
    public function reviews(): Response
    {
        return Inertia::render('admin/ReservesAdmin', [
            'reviews' => Reservation::query()
                ->whereNotNull('rating')
                ->with(['user:id,name,email', 'slot:id,starts_at', 'service:id,name'])
                ->orderByDesc('updated_at')
                ->paginate(10, ['id', 'user_id', 'slot_id', 'service_id', 'rating', 'review', 'review_images', 'review_published', 'note', 'updated_at'])
                ->withQueryString(),
        ]);
    }

    /**
     * L'admin decideix si una valoració es mostra (o no) a la pàgina d'inici.
     */
    public function toggleReviewPublished(Reservation $reservation): RedirectResponse
    {
        if ($reservation->rating === null) {
            abort(404);
        }

        $reservation->review_published = ! $reservation->review_published;
        $reservation->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $reservation->review_published ? 'Valoració publicada a l\'inici.' : 'Valoració retirada de l\'inici.',
        ]);

        return back();
    }

    /**
     * L'usuari valora una reserva ja feta: puntuació, comentari i imatges.
     */
    public function review(Request $request, Reservation $reservation): RedirectResponse
    {
        if ($reservation->user_id !== $request->user()->id) {
            abort(403);
        }

        $reservation->loadMissing('slot:id,starts_at');

        if (! $reservation->slot || $reservation->slot->starts_at->isFuture()) {
            throw ValidationException::withMessages([
                'rating' => 'Només es poden valorar les reserves ja fetes.',
            ]);
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review' => ['nullable', 'string', 'max:2000'],
            ...$this->imageRules(),
        ]);

        $paths = $this->syncImages($request, 'reviews', $reservation->review_images ?? []);

        $reservation->rating = $validated['rating'];
        $reservation->review = $validated['review'] ?? null;
        $reservation->review_images = $paths ?: null;
        $reservation->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Valoració desada.']);

        return back();
    }

    /**
     * L'usuari (o l'admin) cancel·la una reserva.
     */
    public function destroy(Request $request, Reservation $reservation): RedirectResponse
    {
        if ($reservation->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $reservation->loadMissing('slot:id,starts_at', 'service:id,name');

        Cancellation::create([
            'user_id' => $reservation->user_id,
            'service_name' => $reservation->service?->name,
            'slot_starts_at' => $reservation->slot?->starts_at,
            'note' => $reservation->note,
            'reason' => $validated['reason'],
        ]);

        $reservation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reserva cancel·lada.']);

        return back();
    }
}
