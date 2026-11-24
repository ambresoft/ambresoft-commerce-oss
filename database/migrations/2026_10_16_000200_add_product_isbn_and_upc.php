<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('isbn', 13)->nullable();
            $table->string('upc', 12)->nullable();
        });
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION catalog_valid_isbn(value text) RETURNS boolean
LANGUAGE plpgsql IMMUTABLE STRICT AS $$
DECLARE total integer := 0; i integer; digit integer;
BEGIN
    IF value ~ '^(978|979)[0-9]{10}$' THEN RETURN catalog_valid_ean(value); END IF;
    IF value !~ '^[0-9]{9}[0-9X]$' THEN RETURN false; END IF;
    FOR i IN 1..10 LOOP
        digit := CASE WHEN substring(value, i, 1) = 'X' THEN 10 ELSE substring(value, i, 1)::integer END;
        total := total + digit * (11 - i);
    END LOOP;
    RETURN total % 11 = 0;
END;
$$
SQL);
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_isbn_valid CHECK (isbn IS NULL OR catalog_valid_isbn(isbn))');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_upc_valid CHECK (upc IS NULL OR (upc ~ '^[0-9]{12}$' AND catalog_valid_ean('0' || upc)))");
        [$entity, $fields] = AuditTables::productIdentifiers()['products'];
        DB::statement('DROP TRIGGER cms_audit_mutation ON products');
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON products FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER cms_audit_mutation ON products');
        [$entity, $fields] = AuditTables::availability()['products'];
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON products FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_isbn_valid, DROP CONSTRAINT products_upc_valid');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['isbn', 'upc']));
        DB::statement('DROP FUNCTION catalog_valid_isbn(text)');
    }
};
