<?php

use App\Customer\Presentation\Http\Controllers\AccountAddressController;
use App\Customer\Presentation\Http\Controllers\AccountController;
use App\Customer\Presentation\Http\Controllers\AccountPasswordController;
use App\Customer\Presentation\Http\Controllers\AccountReviewController;
use App\Customer\Presentation\Http\Controllers\CustomerInvitationController;
use App\Customer\Presentation\Http\Controllers\CustomerRegistrationController;
use App\Customer\Presentation\Http\Controllers\CustomerSessionController;
use App\Customer\Presentation\Http\Controllers\CustomerVerificationController;
use App\Customer\Presentation\Http\Controllers\SocialSignInController;
use App\Customer\Presentation\Http\Controllers\WishlistController;
use App\Customer\Presentation\Http\EnsureCustomerAccount;
use App\Customer\Presentation\Http\EnsureWishlistsEnabled;
use App\Identity\Presentation\Http\Controllers\AccessPendingController;
use App\Identity\Presentation\Http\Controllers\EmailVerificationController;
use App\Identity\Presentation\Http\Controllers\NewPasswordController;
use App\Identity\Presentation\Http\Controllers\PasswordResetLinkController;
use App\Identity\Presentation\Http\Controllers\RegisteredUserController;
use App\Identity\Presentation\Http\Controllers\SessionController;
use App\Identity\Presentation\Http\Controllers\StaffPasskeyController;
use App\Order\Presentation\Http\OrderCancelController;
use App\Storefront\Presentation\Http\AccountNewsletterController;
use App\Storefront\Presentation\Http\AccountPageController;
use App\Storefront\Presentation\Http\WishlistPageController;
use Illuminate\Support\Facades\Route;

// Staff sign-in for the administration (A21: the same identities as customers, never an implied grant).
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('login', [SessionController::class, 'create'])->name('login');
        Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
        Route::post('passkeys/login/options', [StaffPasskeyController::class, 'loginOptions'])->middleware('throttle:20,1')->name('passkeys.login.options');
        Route::post('passkeys/login', [StaffPasskeyController::class, 'login'])->middleware('throttle:login')->name('passkeys.login');
        Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
        Route::post('register', [RegisteredUserController::class, 'store'])->middleware('throttle:register')->name('register.store');
        Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
        Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:password-email')->name('password.email');
        Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
        Route::post('reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-reset')->name('password.update');
    });

    Route::middleware(['auth', 'auth.session'])->group(function (): void {
        Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
        Route::get('email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed:relative', 'throttle:verification'])->name('verification.verify');
        Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:verification')->name('verification.send');
        Route::get('access-pending', AccessPendingController::class)->middleware('verified:admin.verification.notice')->name('access.pending');
    });
});

// Customer accounts.
Route::middleware('guest')->group(function (): void {
    Route::get('login', [CustomerSessionController::class, 'create'])->name('login');
    Route::post('login', [CustomerSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('auth/social/{provider}/redirect', [SocialSignInController::class, 'redirect'])->middleware('throttle:login')->name('social.redirect');
    Route::get('auth/social/{provider}/callback', [SocialSignInController::class, 'callback'])->middleware('throttle:login')->name('social.callback');
    Route::get('register', [CustomerRegistrationController::class, 'create'])->name('register');
    Route::post('register', [CustomerRegistrationController::class, 'store'])->middleware('throttle:register')->name('register.store');
    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:password-email')->name('password.email');
    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-reset')->name('password.update');
});

Route::middleware(['auth', 'auth.session'])->group(function (): void {
    Route::post('logout', [CustomerSessionController::class, 'destroy'])->name('logout');
    Route::get('email/verify', [CustomerVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('email/verify/{id}/{hash}', [CustomerVerificationController::class, 'verify'])
        ->middleware(['signed:relative', 'throttle:verification'])->name('verification.verify');
    Route::post('email/verification-notification', [CustomerVerificationController::class, 'send'])
        ->middleware('throttle:verification')->name('verification.send');
    Route::middleware('verified')->group(function (): void {
        Route::get('account/profile', [AccountController::class, 'profile'])->name('account.profile');
        Route::post('account/profile', [AccountController::class, 'storeProfile'])->middleware('throttle:10,1')->name('account.profile.store');
        // The customer's own account (A100): the customer is always the profile linked to this session.
        Route::middleware(EnsureCustomerAccount::class)->group(function (): void {
            // The account pages are the Account layout; its main content block shows the section.
            Route::get('account', AccountPageController::class)->name('account');
            Route::get('account/orders', AccountPageController::class)->defaults('section', 'orders')->name('account.orders');
            Route::get('account/orders/{order}', AccountPageController::class)->defaults('section', 'order')->whereNumber('order')->name('account.order');
            Route::get('account/orders/{order}/invoice', [AccountPageController::class, 'invoice'])->whereNumber('order')->middleware('throttle:30,1')->name('account.order.invoice');
            Route::post('account/orders/{order}/cancel', [OrderCancelController::class, 'account'])->whereNumber('order')->middleware('throttle:10,1')->name('account.order.cancel');
            Route::get('account/addresses', AccountPageController::class)->defaults('section', 'addresses')->name('account.addresses');
            Route::patch('account', [AccountController::class, 'update'])->middleware('throttle:30,1')->name('account.update');
            Route::delete('account', [AccountController::class, 'destroy'])->middleware('throttle:5,1')->name('account.destroy');
            Route::middleware('throttle:30,1')->group(function (): void {
                Route::post('account/addresses', [AccountAddressController::class, 'store'])->name('account.addresses.store');
                Route::put('account/addresses/{address}', [AccountAddressController::class, 'update'])->whereNumber('address')->name('account.addresses.update');
                Route::delete('account/addresses/{address}', [AccountAddressController::class, 'destroy'])->whereNumber('address')->name('account.addresses.destroy');
                Route::post('account/addresses/{address}/default', [AccountAddressController::class, 'makeDefault'])->whereNumber('address')->name('account.addresses.default');
            });
            Route::get('account/password', AccountPageController::class)->defaults('section', 'password')->name('account.password');
            Route::put('account/password', [AccountPasswordController::class, 'update'])->middleware('throttle:password-reset')->name('account.password.update');
            Route::get('account/reviews', AccountPageController::class)->defaults('section', 'reviews')->name('account.reviews');
            Route::get('account/newsletters', AccountPageController::class)->defaults('section', 'newsletters')->name('account.newsletters');
            Route::delete('account/newsletters/{list}', [AccountNewsletterController::class, 'destroy'])->whereNumber('list')->middleware('throttle:30,1')->name('account.newsletters.destroy');
            // The review entry point the storefront product page will also use.
            Route::post('products/{product}/reviews', [AccountReviewController::class, 'store'])->whereNumber('product')->middleware('throttle:6,1')->name('reviews.store');
            // Wishlists: the page, and JSON for the "add to list" window (every list is found through the signed-in customer).
            Route::middleware(EnsureWishlistsEnabled::class)->group(function (): void {
                Route::get('wishlist', WishlistPageController::class)->name('wishlist');
                Route::middleware('throttle:90,1')->prefix('wishlists')->group(function (): void {
                    Route::get('product/{product}', [WishlistController::class, 'forProduct'])->whereNumber('product')->name('wishlists.product');
                    Route::post('/', [WishlistController::class, 'store'])->name('wishlists.store');
                    Route::patch('{wishlist}', [WishlistController::class, 'update'])->whereNumber('wishlist')->name('wishlists.update');
                    Route::delete('{wishlist}', [WishlistController::class, 'destroy'])->whereNumber('wishlist')->name('wishlists.destroy');
                    Route::put('{wishlist}/products/{product}', [WishlistController::class, 'add'])->whereNumber(['wishlist', 'product'])->name('wishlists.add');
                    Route::delete('{wishlist}/products/{product}', [WishlistController::class, 'remove'])->whereNumber(['wishlist', 'product'])->name('wishlists.remove');
                });
            });
        });
    });
});

// Staff invitations (signed, 7 days, single use): set a password, verify the e-mail and claim the profile.
Route::middleware(['guest', 'signed:relative', 'throttle:20,1'])->group(function (): void {
    Route::get('account/invitation/{user}/{hash}', [CustomerInvitationController::class, 'show'])->whereNumber('user')->name('account.invitation');
    Route::post('account/invitation/{user}/{hash}', [CustomerInvitationController::class, 'accept'])->whereNumber('user')->middleware('throttle:password-reset')->name('account.invitation.accept');
});
