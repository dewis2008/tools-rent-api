<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\BookingPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\LockCodePolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ToolImagePolicy;
use App\Policies\ToolPolicy;
use App\Policies\UserPolicy;
use App\Policies\VendorProfilePolicy;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Modules\Bookings\Models\Booking;
use Modules\Categories\Models\Category;
use Modules\LockCodes\Models\LockCode;
use Modules\Payments\Models\Payment;
use Modules\ToolImages\Models\ToolImage;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        VerifyEmail::createUrlUsing(function (User $user): string {
            $verificationUrl = URL::temporarySignedRoute(
                'api.verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id' => $user->getKey(),
                    'hash' => sha1($user->getEmailForVerification()),
                ],
            );

            $query = http_build_query(['verification_url' => $verificationUrl]);
            $resultUrl = rtrim((string) config('services.frontend.email_verification_url'), '/');

            return "{$resultUrl}?{$query}";
        });

        Gate::policy(Booking::class, BookingPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(LockCode::class, LockCodePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Tool::class, ToolPolicy::class);
        Gate::policy(ToolImage::class, ToolImagePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(VendorProfile::class, VendorProfilePolicy::class);
    }
}
