<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // NULL targets all customers; retain existing selected groups and their restrictive foreign key.
        DB::statement('ALTER TABLE product_sales ALTER COLUMN customer_group_id DROP NOT NULL');
    }

    public function down(): void
    {
        // PostgreSQL refuses rollback while all-customer rules exist instead of silently retargeting them.
        DB::statement('ALTER TABLE product_sales ALTER COLUMN customer_group_id SET NOT NULL');
    }
};
