<?php

use App\Audit\Infrastructure\AuditTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Settings of a search provider add-on, declared by the provider. Secrets are one encrypted JSON object.
        Schema::create('search_provider_settings', function (Blueprint $t): void {
            $t->id();
            $t->string('provider', 60)->unique();
            $t->jsonb('settings')->default('{}');
            $t->text('secrets')->nullable();
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE search_provider_settings ADD CONSTRAINT search_provider_settings_values CHECK (provider ~ '^[a-z][a-z0-9_.-]{1,59}$' AND jsonb_typeof(settings) = 'object' AND lock_version > 0)");

        // Products whose search document must be rebuilt or removed: written by triggers in the same transaction as the
        // catalog change, so no change can be lost between a commit and a job. One row per product; a newer change
        // replaces the row's version, and a worker deletes the row only for the version it indexed. `critical` changes
        // (publication, category, customer groups, stock reaching zero) hide the product from search until indexed.
        DB::statement('CREATE SEQUENCE search_outbox_version');
        Schema::create('search_outbox', function (Blueprint $t): void {
            $t->unsignedBigInteger('product_id')->primary();
            $t->bigInteger('version');
            $t->boolean('critical')->default(false);
            // Microseconds: whole seconds would round a just-queued row into the future and delay it.
            $t->timestampTz('queued_at', 6);
            $t->timestampTz('available_at', 6);
            $t->integer('attempts')->default(0);
            $t->timestampTz('failed_at', 6)->nullable();
            $t->string('last_error', 500)->nullable();
            $t->index(['failed_at', 'available_at']);
        });
        // Owned by its column, so dropping the table (or a fresh migration) drops the sequence too.
        DB::statement('ALTER SEQUENCE search_outbox_version OWNED BY search_outbox.version');
        DB::statement('CREATE INDEX search_outbox_critical ON search_outbox (product_id) WHERE critical');
        DB::statement('ALTER TABLE search_outbox ADD CONSTRAINT search_outbox_values CHECK (attempts >= 0)');

        // Full rebuilds into a new index, resumable batch by batch; at most one runs at a time.
        Schema::create('search_index_runs', function (Blueprint $t): void {
            $t->id();
            $t->string('provider', 60);
            $t->string('target', 120);
            $t->string('status', 16);
            $t->unsignedBigInteger('cursor')->default(0);
            $t->integer('expected')->default(0);
            $t->integer('indexed')->default(0);
            $t->integer('failed')->default(0);
            $t->jsonb('errors')->default('[]');
            $t->string('message', 500)->nullable();
            $t->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('started_at');
            $t->timestampTz('finished_at')->nullable();
            $t->timestampTz('updated_at');
        });
        DB::statement("ALTER TABLE search_index_runs ADD CONSTRAINT search_index_runs_values CHECK (status IN ('running', 'completed', 'failed', 'cancelled') AND expected >= 0 AND indexed >= 0 AND failed >= 0)");
        DB::statement("CREATE UNIQUE INDEX search_index_runs_one_running ON search_index_runs ((true)) WHERE status = 'running'");

        // The index's operational state for the administration: last successful sync and reconciliation, last error.
        Schema::create('search_index_state', function (Blueprint $t): void {
            $t->unsignedSmallInteger('id')->primary();
            // The provider whose index was last built completely; until then the storefront says search is being prepared.
            $t->string('built_provider', 60)->nullable();
            $t->timestampTz('built_at')->nullable();
            $t->timestampTz('last_sync_at')->nullable();
            $t->timestampTz('last_reconcile_at')->nullable();
            $t->jsonb('last_reconcile')->nullable();
            $t->timestampTz('last_error_at')->nullable();
            $t->string('last_error', 500)->nullable();
        });
        DB::statement('ALTER TABLE search_index_state ADD CONSTRAINT search_index_state_single CHECK (id = 1)');
        DB::table('search_index_state')->insert(['id' => 1]);

        $this->triggers();
        foreach (AuditTables::search() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }

        // The search results page: the Category layout's shape (filters beside the protected main content block).
        if (! DB::table('storefront_layouts')->where('page_type', 'search')->exists()) {
            $layout = DB::table('storefront_layouts')->insertGetId(['page_type' => 'search', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
            foreach ([1 => [4, 'product_filters', 'Product filters'], 2 => [12, 'main_content', 'Main content']] as $position => [$width, $type, $name]) {
                $grid = (string) Str::uuid();
                $block = (string) Str::uuid();
                DB::table('storefront_grids')->insert(['id' => $grid, 'layout_id' => $layout, 'region' => 'content', 'width' => $width, 'row' => 1, 'position' => $position]);
                DB::table('storefront_blocks')->insert(['id' => $block, 'layout_id' => $layout, 'type' => $type, 'name' => $name, 'template' => $type, 'settings' => '{}']);
                DB::table('storefront_placements')->insert(['id' => (string) Str::uuid(), 'layout_id' => $layout, 'grid_id' => $grid, 'block_id' => $block, 'position' => 1]);
                foreach (DB::table('locales')->pluck('id') as $locale) {
                    DB::table('storefront_block_translations')->insert(['block_id' => $block, 'locale_id' => $locale, 'title' => '']);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('storefront_layouts')->where('page_type', 'search')->delete();
        foreach (self::TRIGGERS as $table => $function) {
            DB::statement("DROP TRIGGER IF EXISTS cms_search_change ON $table");
            DB::statement("DROP FUNCTION IF EXISTS $function()");
        }
        DB::statement('DROP FUNCTION IF EXISTS cms_search_enqueue(bigint[], boolean)');
        Schema::dropIfExists('search_index_state');
        Schema::dropIfExists('search_index_runs');
        Schema::dropIfExists('search_outbox');
        DB::statement('DROP SEQUENCE IF EXISTS search_outbox_version');
        Schema::dropIfExists('search_provider_settings');
    }

    /** Table => trigger function that queues the products a change affects. */
    private const TRIGGERS = [
        'products' => 'cms_search_products', 'product_translations' => 'cms_search_product_translations', 'product_customer_groups' => 'cms_search_product_groups',
        'product_feature_assignments' => 'cms_search_product_features', 'product_feature_choices' => 'cms_search_product_choices', 'product_reviews' => 'cms_search_product_reviews',
        'brands' => 'cms_search_brands', 'brand_translations' => 'cms_search_brand_translations', 'categories' => 'cms_search_categories',
        'category_translations' => 'cms_search_category_translations', 'feature_option_translations' => 'cms_search_option_translations',
        'availability_statuses' => 'cms_search_availability', 'general_settings' => 'cms_search_settings',
    ];

    private function triggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION cms_search_enqueue(ids bigint[], is_critical boolean) RETURNS void LANGUAGE sql AS $$
    INSERT INTO search_outbox (product_id, version, critical, queued_at, available_at, attempts, failed_at, last_error)
    SELECT d.id, nextval('search_outbox_version'), is_critical, now(), now(), 0, NULL, NULL FROM (SELECT DISTINCT unnest(ids) AS id) d WHERE d.id IS NOT NULL
    ON CONFLICT (product_id) DO UPDATE SET version = EXCLUDED.version, critical = search_outbox.critical OR EXCLUDED.critical,
        queued_at = EXCLUDED.queued_at, available_at = EXCLUDED.available_at, attempts = 0, failed_at = NULL, last_error = NULL;
$$;

-- Publication, category, identity and stock reaching zero decide who may find a product: critical. Prices, codes,
-- brand and availability labels only change what is found: queued. Other columns (weights, shipping, versions) do not
-- reach the search document.
CREATE OR REPLACE FUNCTION cms_search_products() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM cms_search_enqueue(ARRAY[OLD.id], true);
    ELSIF TG_OP = 'INSERT' THEN
        PERFORM cms_search_enqueue(ARRAY[NEW.id], false);
    ELSIF (NEW.status, NEW.deleted_at, NEW.category_id, NEW.sku IS NULL, NEW.price IS NULL, NEW.quantity > 0)
        IS DISTINCT FROM (OLD.status, OLD.deleted_at, OLD.category_id, OLD.sku IS NULL, OLD.price IS NULL, OLD.quantity > 0) THEN
        PERFORM cms_search_enqueue(ARRAY[NEW.id], true);
    ELSIF (NEW.sku, NEW.price, NEW.ean, NEW.mpn, NEW.isbn, NEW.upc, NEW.brand_id, NEW.in_stock_status_id, NEW.out_of_stock_status_id, NEW.created_at, NEW.type)
        IS DISTINCT FROM (OLD.sku, OLD.price, OLD.ean, OLD.mpn, OLD.isbn, OLD.upc, OLD.brand_id, OLD.in_stock_status_id, OLD.out_of_stock_status_id, OLD.created_at, OLD.type) THEN
        PERFORM cms_search_enqueue(ARRAY[NEW.id], false);
    END IF;
    RETURN NULL;
END $$;

-- A removed or emptied name can make a product incomplete (unpublished): critical.
CREATE OR REPLACE FUNCTION cms_search_product_translations() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM cms_search_enqueue(ARRAY[OLD.product_id], true);
    ELSIF TG_OP = 'INSERT' OR (NEW.name, NEW.description, NEW.short_description, NEW.locale_id, NEW.product_id) IS DISTINCT FROM (OLD.name, OLD.description, OLD.short_description, OLD.locale_id, OLD.product_id) THEN
        PERFORM cms_search_enqueue(ARRAY[NEW.product_id], btrim(coalesce(NEW.name, '')) = '');
    END IF;
    RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION cms_search_product_groups() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM cms_search_enqueue(ARRAY[CASE WHEN TG_OP = 'DELETE' THEN OLD.product_id ELSE NEW.product_id END], true);
    RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION cms_search_product_features() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM cms_search_enqueue(ARRAY[CASE WHEN TG_OP = 'DELETE' THEN OLD.product_id ELSE NEW.product_id END], false);
    RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION cms_search_product_choices() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM cms_search_enqueue(ARRAY(SELECT product_id FROM product_feature_assignments WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.assignment_id ELSE NEW.assignment_id END), false);
    RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION cms_search_product_reviews() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM cms_search_enqueue(ARRAY[OLD.product_id], false);
    ELSIF TG_OP = 'INSERT' OR (NEW.status, NEW.rating, NEW.product_id) IS DISTINCT FROM (OLD.status, OLD.rating, OLD.product_id) THEN
        PERFORM cms_search_enqueue(ARRAY[NEW.product_id, CASE WHEN TG_OP = 'UPDATE' THEN OLD.product_id END], false);
    END IF;
    RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION cms_search_brands() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.status IS DISTINCT FROM OLD.status THEN
        PERFORM cms_search_enqueue(ARRAY(SELECT id FROM products WHERE brand_id = NEW.id), false);
    END IF;
    RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION cms_search_brand_translations() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'UPDATE' OR NEW.name IS DISTINCT FROM OLD.name THEN
        PERFORM cms_search_enqueue(ARRAY(SELECT id FROM products WHERE brand_id = CASE WHEN TG_OP = 'DELETE' THEN OLD.brand_id ELSE NEW.brand_id END), false);
    END IF;
    RETURN NULL;
END $$;

-- Category visibility, search exclusion and leaf status apply when searching; only the category names a document
-- carries (its path) change with a move.
CREATE OR REPLACE FUNCTION cms_search_categories() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.parent_id IS DISTINCT FROM OLD.parent_id THEN
        PERFORM cms_search_enqueue(ARRAY(WITH RECURSIVE tree AS (SELECT NEW.id AS id UNION ALL SELECT c.id FROM categories c JOIN tree ON c.parent_id = tree.id)
            SELECT p.id FROM products p WHERE p.category_id IN (SELECT id FROM tree)), false);
    END IF;
    RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION cms_search_category_translations() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    category bigint := CASE WHEN TG_OP = 'DELETE' THEN OLD.category_id ELSE NEW.category_id END;
BEGIN
    IF TG_OP <> 'UPDATE' OR NEW.name IS DISTINCT FROM OLD.name THEN
        PERFORM cms_search_enqueue(ARRAY(WITH RECURSIVE tree AS (SELECT category AS id UNION ALL SELECT c.id FROM categories c JOIN tree ON c.parent_id = tree.id)
            SELECT p.id FROM products p WHERE p.category_id IN (SELECT id FROM tree)), false);
    END IF;
    RETURN NULL;
END $$;

-- Option labels are matched as product text.
CREATE OR REPLACE FUNCTION cms_search_option_translations() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'UPDATE' OR NEW.label IS DISTINCT FROM OLD.label THEN
        PERFORM cms_search_enqueue(ARRAY(SELECT a.product_id FROM product_feature_choices c JOIN product_feature_assignments a ON a.id = c.assignment_id
            WHERE c.option_id = CASE WHEN TG_OP = 'DELETE' THEN OLD.option_id ELSE NEW.option_id END), false);
    END IF;
    RETURN NULL;
END $$;

-- The availability label a document carries uses the store defaults and whether stock is tracked.
CREATE OR REPLACE FUNCTION cms_search_availability() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' OR TG_OP = 'INSERT' OR (NEW.default_in_stock, NEW.default_out_of_stock) IS DISTINCT FROM (OLD.default_in_stock, OLD.default_out_of_stock) THEN
        PERFORM cms_search_enqueue(ARRAY(SELECT id FROM products), false);
    END IF;
    RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION cms_search_settings() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.track_inventory IS DISTINCT FROM OLD.track_inventory THEN
        PERFORM cms_search_enqueue(ARRAY(SELECT id FROM products), false);
    END IF;
    RETURN NULL;
END $$;
SQL);
        foreach (self::TRIGGERS as $table => $function) {
            $events = $table === 'general_settings' ? 'UPDATE' : 'INSERT OR UPDATE OR DELETE';
            DB::statement("CREATE TRIGGER cms_search_change AFTER $events ON $table FOR EACH ROW EXECUTE FUNCTION $function()");
        }
    }
};
