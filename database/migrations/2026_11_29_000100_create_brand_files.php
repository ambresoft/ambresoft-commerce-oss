<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('private_file_id')->constrained('private_product_files')->restrictOnDelete();
            $table->string('display_name');
            $table->unsignedInteger('position');
            $table->unique(['brand_id', 'position']);
            $table->unique(['brand_id', 'private_file_id']);
            $table->index('private_file_id');
        });
        DB::statement("ALTER TABLE brand_files ADD CONSTRAINT brand_file_values CHECK (position > 0 AND btrim(display_name) <> '')");
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON brand_files FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('brand_file', 'brand_id,private_file_id,display_name,position')");
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_files');
    }
};
