<?php

namespace Modules\Bookings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Bookings\Http\Requests\IndexBookingRequest;
use Modules\Bookings\Http\Requests\StoreBookingRequest;
use Modules\Bookings\Http\Requests\UpdateBookingRequest;
use Modules\Bookings\Models\Booking;
use Modules\Bookings\Services\BookingService;

class BookingsController extends Controller
{
    public function index(IndexBookingRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        $query = Booking::query()->with(['tool', 'customer', 'vendor']);
        $user = $request->user();

        if ($user->role === 'vendor') {
            $query->where('vendor_id', $user->vendorProfile?->id ?? 0);
        }

        if ($user->role === 'customer') {
            $query->where('customer_id', $user->id);
        }

        $query
            ->when($request->filled('query'), function (Builder $query) use ($request): void {
                $searchTerm = trim((string) $request->validated('query'));
                $search = "%{$searchTerm}%";

                $query->where(function (Builder $query) use ($search, $searchTerm): void {
                    if (ctype_digit($searchTerm)) {
                        $query->whereKey((int) $searchTerm);
                    }

                    $query
                        ->orWhereHas('tool', fn (Builder $query) => $query->whereLike('title', $search))
                        ->orWhereHas('customer', function (Builder $query) use ($search): void {
                            $query
                                ->whereLike('name', $search)
                                ->orWhereLike('email', $search);
                        })
                        ->orWhereHas('vendor', fn (Builder $query) => $query->whereLike('business_name', $search));
                });
            })
            ->when(
                $request->filled('status'),
                fn (Builder $query) => $query->where('status', $request->validated('status')),
            )
            ->when(
                $request->filled('tool_id'),
                fn (Builder $query) => $query->where('tool_id', $request->integer('tool_id')),
            )
            ->when(
                $request->filled('customer_id'),
                fn (Builder $query) => $query->where('customer_id', $request->integer('customer_id')),
            )
            ->when(
                $request->filled('vendor_id'),
                fn (Builder $query) => $query->where('vendor_id', $request->integer('vendor_id')),
            )
            ->when(
                $request->filled('date_from'),
                fn (Builder $query) => $query->whereDate('end_at', '>=', $request->validated('date_from')),
            )
            ->when(
                $request->filled('date_to'),
                fn (Builder $query) => $query->whereDate('start_at', '<=', $request->validated('date_to')),
            )
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id', $request->sortDirection());

        return response()->json($query->paginate($request->pageSize())->withQueryString());
    }

    public function store(StoreBookingRequest $request, BookingService $bookings): JsonResponse
    {
        $this->authorize('create', Booking::class);

        $booking = $bookings->create($request->validated(), $request->user());

        return response()->json($booking->load(['tool', 'customer', 'vendor']), Response::HTTP_CREATED);
    }

    public function show(Booking $booking): JsonResponse
    {
        $this->authorize('view', $booking);

        return response()->json($booking->load(['tool', 'customer', 'vendor', 'payment', 'lockCode']));
    }

    public function update(UpdateBookingRequest $request, Booking $booking, BookingService $bookings): JsonResponse
    {
        $this->authorize('update', $booking);

        $booking = $bookings->transition($booking, $request->validated('status'), $request->user());

        return response()->json($booking->refresh()->load(['tool', 'customer', 'vendor', 'payment', 'lockCode']));
    }

    public function destroy(Booking $booking, BookingService $bookings): Response
    {
        $this->authorize('delete', $booking);

        $bookings->delete($booking);

        return response()->noContent();
    }
}
