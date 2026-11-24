<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $t): void {
            $t->id();
            $t->char('code', 3)->unique();
            $t->string('name', 80);
            $t->string('symbol', 10);
            $t->string('symbol_position', 6)->default('before');
            $t->string('thousands_separator', 3)->default(',');
            $t->string('decimal_separator', 3)->default('.');
            $t->smallInteger('decimals')->default(2);
            // Units of this currency per one unit of the primary currency.
            $t->decimal('rate', 20, 10);
            $t->boolean('is_primary')->default(false);
            $t->string('status', 16)->default('active');
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE currencies ADD CONSTRAINT currencies_values_check CHECK (code ~ '^[A-Z]{3}$' AND length(btrim(name)) > 0 AND length(btrim(symbol)) > 0 AND symbol_position IN ('before','after') AND decimal_separator IN ('.',',') AND thousands_separator IN (',','.','''',E'\\u00A0','') AND thousands_separator <> decimal_separator AND decimals BETWEEN 0 AND 6 AND rate > 0 AND status IN ('active','disabled') AND lock_version > 0 AND (NOT is_primary OR (rate = 1 AND status = 'active')))");
        DB::statement('CREATE UNIQUE INDEX currencies_one_primary ON currencies (is_primary) WHERE is_primary');
        // At most one primary is immediate; exactly one is verified when each transaction commits.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION currencies_require_one_primary() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT count(*) FROM currencies WHERE is_primary) <> 1 THEN
                    RAISE EXCEPTION 'Exactly one primary currency is required' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER currencies_exactly_one_primary AFTER INSERT OR UPDATE OR DELETE ON currencies
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION currencies_require_one_primary();
            SQL);
        // Required reference data: Phase 4 prices were entered at two decimals with no recorded currency.
        // EUR is the deployment's initial accounting currency; change it only through the converting primary-currency operation.
        DB::table('currencies')->insert(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'symbol_position' => 'before', 'thousands_separator' => ',', 'decimal_separator' => '.', 'decimals' => 2, 'rate' => '1', 'is_primary' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('currency_audits', function (Blueprint $t): void {
            $t->id();
            // Deliberately no currency FK: audit evidence survives permitted deletion.
            $t->unsignedBigInteger('currency_id')->nullable()->index();
            $t->char('currency_code', 3);
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 60);
            $t->jsonb('context');
            $t->timestampTz('occurred_at')->useCurrent();
            $t->index(['currency_code', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_audits');
        Schema::dropIfExists('currencies');
        DB::unprepared('DROP FUNCTION IF EXISTS currencies_require_one_primary()');
    }
};
