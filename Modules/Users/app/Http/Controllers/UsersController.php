<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Users\Http\Requests\IndexUserRequest;
use Modules\Users\Http\Requests\StoreUserRequest;
use Modules\Users\Http\Requests\UpdateUserRequest;
use Modules\Users\Http\Resources\UsersResource;

class UsersController extends Controller
{
    public function index(IndexUserRequest $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()
            ->when($request->filled('query'), function (Builder $query) use ($request): void {
                $search = '%'.trim((string) $request->validated('query')).'%';

                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereLike('name', $search)
                        ->orWhereLike('email', $search)
                        ->orWhereLike('phone', $search);
                });
            })
            ->when(
                $request->filled('role'),
                fn (Builder $query) => $query->where('role', $request->validated('role')),
            )
            ->when(
                $request->filled('status'),
                fn (Builder $query) => $query->where('status', $request->validated('status')),
            )
            ->when(
                $request->has('email_verified'),
                fn (Builder $query) => $request->boolean('email_verified')
                    ? $query->whereNotNull('email_verified_at')
                    : $query->whereNull('email_verified_at'),
            )
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id', $request->sortDirection());

        return UsersResource::collection(
            $query->paginate($request->pageSize())->withQueryString(),
        )->response();
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = User::create($request->validated());

        event(new Registered($user));

        return (new UsersResource($user))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return (new UsersResource($user))->response();
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $emailChanged = DB::transaction(function () use ($request, $user): bool {
            $validated = $request->validated();
            $user->fill($validated);

            $emailChanged = $user->isDirty('email');
            $authenticationOrAuthorizationChanged = $user->isDirty([
                'email',
                'password',
                'role',
            ]);

            if ($emailChanged) {
                $user->forceFill(['email_verified_at' => null]);
            }

            $user->save();

            if (! $user->isEligibleVendor()) {
                $user->vendorProfile?->tools()
                    ->where('status', 'active')
                    ->update(['status' => 'inactive']);
            }

            if ($authenticationOrAuthorizationChanged
                || (array_key_exists('status', $validated) && $validated['status'] !== 'active')) {
                $user->tokens()->delete();
            }

            return $emailChanged;
        });

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return (new UsersResource($user->refresh()))->response();
    }

    public function destroy(User $user): Response
    {
        $this->authorize('delete', $user);

        if ($user->hasBookingHistory()) {
            throw new AuthorizationException(__('Users with booking history cannot be deleted.'));
        }

        $user->delete();

        return response()->noContent();
    }
}
