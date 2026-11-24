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
        Schema::create('availability_statuses', function (Blueprint $t): void {
            $t->id();
            $t->integer('position')->default(0);
            $t->boolean('default_in_stock')->default(false);
            $t->boolean('default_out_of_stock')->default(false);
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE availability_statuses ADD CONSTRAINT availability_status_valid CHECK (position >= 0 AND lock_version > 0)');
        DB::statement('CREATE UNIQUE INDEX availability_default_in ON availability_statuses (default_in_stock) WHERE default_in_stock');
        DB::statement('CREATE UNIQUE INDEX availability_default_out ON availability_statuses (default_out_of_stock) WHERE default_out_of_stock');
        Schema::create('availability_status_translations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('status_id')->constrained('availability_statuses')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 250);
            $t->unique(['status_id', 'locale_id']);
            $t->index('locale_id');
        });
        DB::statement("ALTER TABLE availability_status_translations ADD CONSTRAINT availability_name_valid CHECK (btrim(name) <> '')");
        $labels = [
            'en' => ['Immediately available', 'Awaiting supplier', 'Available in 1–3 days', 'Available in 4–6 days', 'Unavailable', 'Pre-order'],
            'el' => ['Άμεσα διαθέσιμο', 'Αναμονή από προμηθευτή', 'Διαθέσιμο σε 1 έως 3 ημέρες', 'Διαθέσιμο σε 4 έως 6 ημέρες', 'Μη διαθέσιμο', 'Προπαραγγελία'],
            'es' => ['Disponible inmediatamente', 'Pendiente del proveedor', 'Disponible en 1–3 días', 'Disponible en 4–6 días', 'No disponible', 'Reserva'],
            'fr' => ['Disponible immédiatement', 'En attente du fournisseur', 'Disponible sous 1 à 3 jours', 'Disponible sous 4 à 6 jours', 'Indisponible', 'Précommande'],
            'it' => ['Disponibile subito', 'In attesa dal fornitore', 'Disponibile in 1–3 giorni', 'Disponibile in 4–6 giorni', 'Non disponibile', 'Preordine'],
            'nl' => ['Direct beschikbaar', 'Wachten op leverancier', 'Beschikbaar binnen 1–3 dagen', 'Beschikbaar binnen 4–6 dagen', 'Niet beschikbaar', 'Voorbestelling'],
        ];
        for ($index = 0; $index < 6; $index++) {
            $id = DB::table('availability_statuses')->insertGetId(['position' => ($index + 1) * 10, 'default_in_stock' => $index === 0, 'default_out_of_stock' => $index === 4, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('locales')->get(['id', 'code']) as $locale) {
                DB::table('availability_status_translations')->insert(['status_id' => $id, 'locale_id' => $locale->id, 'name' => ($labels[$locale->code] ?? $labels['en'])[$index]]);
            }
        }
        Schema::table('products', function (Blueprint $t): void {
            $t->foreignId('in_stock_status_id')->nullable()->constrained('availability_statuses')->restrictOnDelete();
            $t->foreignId('out_of_stock_status_id')->nullable()->constrained('availability_statuses')->restrictOnDelete();
            $t->index('in_stock_status_id');
            $t->index('out_of_stock_status_id');
        });
        foreach (AuditTables::availability() as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER cms_audit_mutation ON products');
        [$entity, $fields] = AuditTables::catalogVisibility()['products'];
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON products FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        Schema::table('products', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('in_stock_status_id');
            $t->dropConstrainedForeignId('out_of_stock_status_id');
        });
        Schema::dropIfExists('availability_status_translations');
        Schema::dropIfExists('availability_statuses');
    }
};
