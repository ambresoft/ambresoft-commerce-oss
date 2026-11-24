<?php

use App\Marketing\Presentation\Http\NewsletterPageController;
use App\Marketing\Presentation\Http\NewsletterSignupController;
use App\Order\Presentation\Http\OrderCancelController;
use App\Payment\Presentation\Http\PaymentPageController;
use App\Storefront\Presentation\Http\AgeVerificationController;
use App\Storefront\Presentation\Http\CallRequestController;
use App\Storefront\Presentation\Http\CartController;
use App\Storefront\Presentation\Http\CheckoutController;
use App\Storefront\Presentation\Http\ComparisonController;
use App\Storefront\Presentation\Http\DirectoryController;
use App\Storefront\Presentation\Http\FaviconController;
use App\Storefront\Presentation\Http\HomepageController;
use App\Storefront\Presentation\Http\MediaController;
use App\Storefront\Presentation\Http\OrderTrackingController;
use App\Storefront\Presentation\Http\ProductDocumentController;
use App\Storefront\Presentation\Http\ProductPageController;
use App\Storefront\Presentation\Http\ReelController;
use App\Storefront\Presentation\Http\SearchController;
use App\Storefront\Presentation\Http\StorefrontLocaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomepageController::class)->name('foundation');
Route::get('/storefront/reels/{reel}', [ReelController::class, 'show'])->whereNumber('reel')->middleware('throttle:120,1,reels-show')->name('storefront.reels.show');
Route::get('/storefront/reels/{reel}/video/{video}', [ReelController::class, 'video'])->whereNumber(['reel', 'video'])->name('storefront.reels.video');
Route::post('/storefront/reels/{reel}/views', [ReelController::class, 'view'])->whereNumber('reel')->middleware('throttle:60,1,reels-views')->name('storefront.reels.view');
Route::get('/sitemap', DirectoryController::class)->defaults('directory', 'sitemap')->name('storefront.sitemap');
Route::get('/brands', DirectoryController::class)->defaults('directory', 'brands')->name('storefront.brands');
Route::post('/newsletter/subscribe', NewsletterSignupController::class)->middleware('throttle:6,1')->name('newsletter.subscribe');
Route::post('/call-requests', CallRequestController::class)->middleware('throttle:6,1,storefront-calls')->name('storefront.call-requests.store');
Route::get('/storefront/favicon', FaviconController::class)->name('storefront.favicon');
Route::get('/locale/{code}', StorefrontLocaleController::class)->where('code', '[a-z]{2,3}(-[A-Za-z0-9]{2,8})?')->middleware('throttle:60,1')->name('storefront.locale');
Route::get('/storefront/products/{product}/documents/{file}', ProductDocumentController::class)->whereNumber(['product', 'file'])->middleware('throttle:60,1')->name('storefront.product-document');
Route::get('/storefront/products/{product}/brand-documents/{file}', [ProductDocumentController::class, 'brand'])->whereNumber(['product', 'file'])->middleware('throttle:60,1')->name('storefront.brand-document');
Route::get('/storefront/media/{kind}/{owner}/{media}', MediaController::class)->whereNumber('media')->where('owner', '[0-9]+|[0-9a-f-]{36}')->name('storefront.media');

// The cart: open to guests and customers; a guest's cart is named by an encrypted cookie.
Route::get('/cart', [CartController::class, 'show'])->name('cart');
Route::middleware('throttle:120,1')->prefix('cart')->group(function (): void {
    Route::get('/items', [CartController::class, 'items'])->name('cart.items');
    Route::post('/package', [CartController::class, 'package'])->name('cart.package');
    Route::post('/items', [CartController::class, 'add'])->name('cart.add');
    Route::patch('/items/{product}', [CartController::class, 'update'])->whereNumber('product')->name('cart.update');
    Route::delete('/items/{product}', [CartController::class, 'remove'])->whereNumber('product')->name('cart.remove');
    Route::delete('/items', [CartController::class, 'clear'])->name('cart.clear');
    // Tighter: a code that does not exist is refused, so the endpoint must not become a way to guess coupon codes.
    Route::post('/codes', [CartController::class, 'applyCode'])->middleware('throttle:20,1,cart-codes')->name('cart.codes.apply');
    Route::delete('/codes/{kind}', [CartController::class, 'removeCode'])->whereIn('kind', ['coupon', 'gift_card'])->name('cart.codes.remove');
});

// Checkout: guests (unless the store requires an account) and customers; the confirmation only behind a signed link.
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');
// Own counters: unprefixed limits share one per visitor, so cart, search and quote requests would use up "place order".
Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:10,1,checkout-place')->name('checkout.store');
Route::post('/checkout/invoice/verify', [CheckoutController::class, 'verifyInvoice'])->middleware('throttle:6,1,checkout-invoice')->name('checkout.invoice.verify');
Route::post('/checkout/quote', [CheckoutController::class, 'quote'])->middleware('throttle:120,1,checkout-quote')->name('checkout.quote');
Route::get('/checkout/complete/{order}', [CheckoutController::class, 'complete'])->whereNumber('order')->middleware(['signed', 'throttle:30,1'])->name('checkout.complete');

// Cancelling an order from the signed link in its e-mails: a confirmation page, and its form.
Route::middleware(['signed', 'throttle:20,1'])->group(function (): void {
    Route::get('/orders/{order}/cancel', [OrderCancelController::class, 'show'])->whereNumber('order')->name('orders.cancel');
    Route::post('/orders/{order}/cancel', [OrderCancelController::class, 'cancel'])->whereNumber('order')->name('orders.cancel.store');
});

// Product search through the active search add-on; suggestions as JSON for the header's search field.
Route::get('/search', [SearchController::class, 'show'])->middleware('throttle:60,1')->name('storefront.search');
Route::get('/search/recently-viewed', [SearchController::class, 'recentlyViewed'])->middleware('throttle:120,1')->name('storefront.search.recently-viewed');
Route::get('/search/suggest', [SearchController::class, 'suggest'])->middleware('throttle:120,1')->name('storefront.search.suggest');

// Age-restricted categories: the visitor confirms their age once.
Route::post('/age-verification', AgeVerificationController::class)->middleware('throttle:30,1')->name('age-verification');

// Order tracking by number: guests' orders by number alone, registered customers' orders only to them, signed in.
Route::get('/order-status', OrderTrackingController::class)->middleware('throttle:20,1')->name('order-tracking');

// Product comparison: open to guests and customers, kept in the visitor's session.
Route::get('/comparison', [ComparisonController::class, 'show'])->name('comparison');
Route::middleware('throttle:60,1')->prefix('comparison')->group(function (): void {
    Route::put('/products/{product}', [ComparisonController::class, 'add'])->whereNumber('product')->name('comparison.add');
    Route::delete('/products/{product}', [ComparisonController::class, 'remove'])->whereNumber('product')->name('comparison.remove');
    Route::delete('/', [ComparisonController::class, 'clear'])->name('comparison.clear');
});

// Customer payment page behind signed links (payment link shared by staff, provider return and cancel URLs).
Route::middleware('throttle:30,1')->group(function (): void {
    Route::get('/pay/{order}', [PaymentPageController::class, 'show'])->whereNumber('order')->middleware('signed')->name('payments.pay');
    Route::post('/pay/{order}', [PaymentPageController::class, 'start'])->whereNumber('order')->middleware('signed')->name('payments.start');
    Route::get('/pay/return/{payment}', [PaymentPageController::class, 'complete'])->whereNumber('payment')->name('payments.return');
    Route::get('/pay/cancel/{payment}', [PaymentPageController::class, 'cancel'])->whereNumber('payment')->name('payments.cancel');
});

// Newsletter links in e-mails (signed; they change data only on POST).
Route::middleware(['throttle:30,1', 'signed'])->group(function (): void {
    Route::match(['get', 'post'], '/newsletter/unsubscribe/{subscriber}', [NewsletterPageController::class, 'unsubscribe'])->whereNumber('subscriber')->name('newsletter.unsubscribe');
    Route::match(['get', 'post'], '/newsletter/confirm/{subscriber}/{list}', [NewsletterPageController::class, 'confirm'])->whereNumber(['subscriber', 'list'])->name('newsletter.confirm');
});

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';

// Store-wide listings with filters: new products, best sellers and offers.
foreach (['new', 'bestsellers', 'offers'] as $collection) {
    Route::get('/'.$collection, [ProductPageController::class, 'collection'])->defaults('collection', $collection)->name('storefront.'.$collection);
}

// Anything no other route matches may be a product address (category path plus product slug); otherwise 404.
Route::fallback(ProductPageController::class)->name('storefront.product');
