<?php

namespace Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Payments\Http\Requests\IndexPaymentRequest;
use Modules\Payments\Http\Requests\StorePaymentRequest;
use Modules\Payments\Http\Requests\UpdatePaymentRequest;
use Modules\Payments\Models\Payment;
use Modules\Payments\Services\PaymentService;

class PaymentsController extends Controller
{
    public function index(IndexPaymentRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $query = Payment::query()->with(['booking', 'customer']);
        $user = $request->user();

        if ($user->role === 'vendor') {
            $query->whereHas('booking', fn ($query) => $query->where('vendor_id', $user->vendorProfile?->id ?? 0));
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
                        ->orWhereLike('provider_payment_id', $search)
                        ->orWhereLike('provider_refund_id', $search)
                        ->orWhereHas('customer', function (Builder $query) use ($search): void {
                            $query
                                ->whereLike('name', $search)
                                ->orWhereLike('email', $search);
                        })
                        ->orWhereHas('booking.tool', fn (Builder $query) => $query->whereLike('title', $search))
                        ->orWhereHas('booking.vendor', fn (Builder $query) => $query->whereLike('business_name', $search));
                });
            })
            ->when(
                $request->filled('status'),
                fn (Builder $query) => $query->where('status', $request->validated('status')),
            )
            ->when(
                $request->filled('provider'),
                fn (Builder $query) => $query->where('provider', $request->validated('provider')),
            )
            ->when(
                $request->filled('currency'),
                fn (Builder $query) => $query->whereLike('currency', $request->validated('currency')),
            )
            ->when(
                $request->filled('booking_id'),
                fn (Builder $query) => $query->where('booking_id', $request->integer('booking_id')),
            )
            ->when(
                $request->filled('customer_id'),
                fn (Builder $query) => $query->where('customer_id', $request->integer('customer_id')),
            )
            ->when(
                $request->filled('vendor_id'),
                fn (Builder $query) => $query->whereHas(
                    'booking',
                    fn (Builder $query) => $query->where('vendor_id', $request->integer('vendor_id')),
                ),
            )
            ->when(
                $request->filled('min_amount'),
                fn (Builder $query) => $query->where('amount', '>=', $request->float('min_amount')),
            )
            ->when(
                $request->filled('max_amount'),
                fn (Builder $query) => $query->where('amount', '<=', $request->float('max_amount')),
            )
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id', $request->sortDirection());

        return response()->json($query->paginate($request->pageSize())->withQueryString());
    }

    public function store(StorePaymentRequest $request, PaymentService $payments): JsonResponse
    {
        $this->authorize('create', Payment::class);

        $payment = $payments->create($request->validated(), $request->user());

        return response()->json($payment->load(['booking', 'customer']), Response::HTTP_CREATED);
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return response()->json($payment->load(['booking', 'customer']));
    }

    public function update(UpdatePaymentRequest $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        $this->authorize('update', $payment);

        $payment = $payments->transition($payment, $request->validated());

        return response()->json($payment->refresh()->load(['booking', 'customer']));
    }
}
