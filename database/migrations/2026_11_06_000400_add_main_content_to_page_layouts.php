<?php

use App\Storefront\Infrastructure\LayoutSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Layouts already linked to a static page get the main content block that shows the page.
        foreach (DB::table('storefront_layouts')->whereNotNull('page_id')->where('page_type', 'like', 'custom.%')->pluck('id') as $layout) {
            app(LayoutSettings::class)->syncMainContent((int) $layout, true);
        }
    }

    public function down(): void
    {
        foreach (DB::table('storefront_layouts')->where('page_type', 'like', 'custom.%')->pluck('id') as $layout) {
            app(LayoutSettings::class)->syncMainContent((int) $layout, false);
        }
    }
};
