<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('ean', 13)->nullable();
            $table->boolean('returnable')->default(false);
        });
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION catalog_valid_ean(value text) RETURNS boolean
LANGUAGE plpgsql IMMUTABLE STRICT AS $$
DECLARE total integer := 0; weight integer := 3; i integer;
BEGIN
    IF value !~ '^([0-9]{8}|[0-9]{13})$' THEN RETURN false; END IF;
    FOR i IN REVERSE length(value) - 1..1 LOOP
        total := total + substring(value, i, 1)::integer * weight;
        weight := CASE WHEN weight = 3 THEN 1 ELSE 3 END;
    END LOOP;
    RETURN (10 - total % 10) % 10 = right(value, 1)::integer;
END;
$$
SQL);
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_ean_valid CHECK (ean IS NULL OR catalog_valid_ean(ean))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_ean_valid');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['ean', 'returnable']));
        DB::statement('DROP FUNCTION catalog_valid_ean(text)');
    }
};
