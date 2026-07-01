<?php

namespace Modules\Vendors\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Vendors\Http\Requests\IndexVendorRequest;
use Modules\Vendors\Http\Requests\StoreVendorRequest;
use Modules\Vendors\Http\Requests\UpdateVendorRequest;
use Modules\Vendors\Models\VendorProfile;

class VendorsController extends Controller
{
    private const VerificationFields = [
        'business_name',
        'company_code',
        'vat_code',
    ];

    public function index(IndexVendorRequest $request): JsonResponse
    {
        $this->authorize('viewAny', VendorProfile::class);

        $query = VendorProfile::query();
        $user = $request->user();

        if ($user->role === 'vendor') {
            $query->where('user_id', $user->id);
        }

        $query
            ->when($request->filled('query'), function (Builder $query) use ($request): void {
                $search = '%'.trim((string) $request->validated('query')).'%';

                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereLike('business_name', $search)
                        ->orWhereLike('company_code', $search)
                        ->orWhereLike('vat_code', $search)
                        ->orWhereHas('user', function (Builder $query) use ($search): void {
                            $query
                                ->whereLike('name', $search)
                                ->orWhereLike('email', $search);
                        });
                });
            })
            ->when(
                $request->filled('verification_status'),
                fn (Builder $query) => $query->where('verification_status', $request->validated('verification_status')),
            )
            ->when(
                $request->filled('user_status'),
                fn (Builder $query) => $query->whereHas(
                    'user',
                    fn (Builder $query) => $query->where('status', $request->validated('user_status')),
                ),
            )
            ->when(
                $request->filled('min_rating'),
                fn (Builder $query) => $query->where('rating', '>=', $request->float('min_rating')),
            )
            ->when(
                $request->filled('max_rating'),
                fn (Builder $query) => $query->where('rating', '<=', $request->float('max_rating')),
            )
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id', $request->sortDirection());

        return response()->json($query->paginate($request->pageSize())->withQueryString());
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $this->authorize('create', VendorProfile::class);

        $validated = $request->validated();

        $vendor = DB::transaction(function () use ($validated): VendorProfile {
            $vendor = VendorProfile::withTrashed()
                ->where('user_id', $validated['user_id'])
                ->lockForUpdate()
                ->first();

            if (! $vendor) {
                return VendorProfile::create($validated);
            }

            $vendor->fill([
                ...$validated,
                'verification_status' => 'pending',
                'rating' => null,
            ]);
            $vendor->restore();

            return $vendor;
        });

        return response()->json($vendor->refresh(), Response::HTTP_CREATED);
    }

    public function show(VendorProfile $vendor): JsonResponse
    {
        $this->authorize('view', $vendor);

        return response()->json($vendor);
    }

    public function update(UpdateVendorRequest $request, VendorProfile $vendor): JsonResponse
    {
        $this->authorize('update', $vendor);

        $validated = $request->validated();
        $actor = $request->user();

        $vendor = DB::transaction(function () use ($validated, $vendor, $actor): VendorProfile {
            $vendor = VendorProfile::query()->lockForUpdate()->findOrFail($vendor->id);

            if (! $actor->can('update', $vendor)) {
                throw new AuthorizationException;
            }

            $previousUserId = $vendor->user_id;
            $vendor->fill($validated);

            if ($actor->role !== 'admin'
                && $vendor->verification_status === 'approved'
                && $vendor->isDirty(self::VerificationFields)) {
                $validated['verification_status'] = 'pending';
            }

            $ownerOrStatusChanged = array_key_exists('user_id', $validated)
                || array_key_exists('verification_status', $validated);
            $previousUser = $ownerOrStatusChanged
                ? User::query()->find($previousUserId)
                : null;

            if (array_key_exists('verification_status', $validated)
                && $validated['verification_status'] !== 'approved') {
                $vendor->tools()
                    ->where('status', 'active')
                    ->update(['status' => 'inactive']);
            }

            $vendor->update($validated);

            if (! $ownerOrStatusChanged) {
                return $vendor;
            }

            $owner = $vendor->user()->firstOrFail();

            if ($previousUser?->role === 'vendor' && ! $previousUser->is($owner)) {
                $previousUser->update(['status' => 'pending']);
                $previousUser->tokens()->delete();
            }

            $status = match ($vendor->verification_status) {
                'approved' => 'active',
                'pending' => 'pending',
                'rejected' => 'blocked',
            };

            $owner->update(['status' => $status]);
            $owner->tokens()->delete();

            return $vendor;
        });

        return response()->json($vendor);
    }

    public function destroy(VendorProfile $vendor): Response
    {
        $this->authorize('delete', $vendor);

        DB::transaction(function () use ($vendor): void {
            $vendor = VendorProfile::query()->lockForUpdate()->findOrFail($vendor->id);

            if ($vendor->hasBookingHistory()) {
                throw new AuthorizationException(__('Vendors with booking history cannot be deleted.'));
            }

            $user = $vendor->user()->first();

            $vendor->delete();

            if ($user?->role !== 'vendor') {
                return;
            }

            $user->update(['status' => 'pending']);
            $user->tokens()->delete();
        });

        return response()->noContent();
    }
}
