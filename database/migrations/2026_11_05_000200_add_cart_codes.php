<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The coupon and the gift card a visitor entered in the cart. Neither is reserved or redeemed here: whether
        // they apply, and by how much, is worked out each time the cart is priced.
        Schema::table('carts', function (Blueprint $t): void {
            $t->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $t->foreignId('gift_card_id')->nullable()->constrained('gift_cards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('coupon_id');
            $t->dropConstrainedForeignId('gift_card_id');
        });
    }
};
