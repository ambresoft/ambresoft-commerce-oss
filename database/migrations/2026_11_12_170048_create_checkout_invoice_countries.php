<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('checkout_invoice_countries', function (Blueprint $table) {
            $table->char('country_code', 2)->primary();
        });
        DB::statement("ALTER TABLE checkout_invoice_countries ADD CONSTRAINT checkout_invoice_country_code CHECK (country_code ~ '^[A-Z]{2}$')");
        // Start with this shop's existing shipping choices, then keep invoice coverage independent.
        DB::statement('INSERT INTO checkout_invoice_countries (country_code) SELECT country_code FROM shipping_countries');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON checkout_invoice_countries FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('checkout_invoice_country', 'country_code')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkout_invoice_countries');
    }
};
