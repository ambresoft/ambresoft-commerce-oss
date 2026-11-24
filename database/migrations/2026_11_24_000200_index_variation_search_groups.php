<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION cms_search_variation_member() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM cms_search_enqueue(ARRAY[CASE WHEN TG_OP = 'DELETE' THEN OLD.product_id ELSE NEW.product_id END], true);
    RETURN NULL;
END;
$$;
CREATE TRIGGER cms_search_change AFTER INSERT OR UPDATE OR DELETE ON variation_group_members FOR EACH ROW EXECUTE FUNCTION cms_search_variation_member();
CREATE OR REPLACE FUNCTION cms_search_variation_group() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.single_search IS DISTINCT FROM NEW.single_search THEN
        PERFORM cms_search_enqueue(ARRAY(SELECT product_id FROM variation_group_members WHERE group_id = NEW.id), true);
    END IF;
    RETURN NULL;
END;
$$;
CREATE TRIGGER cms_search_change AFTER UPDATE ON variation_groups FOR EACH ROW EXECUTE FUNCTION cms_search_variation_group();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS cms_search_change ON variation_group_members; DROP TRIGGER IF EXISTS cms_search_change ON variation_groups; DROP FUNCTION IF EXISTS cms_search_variation_member(); DROP FUNCTION IF EXISTS cms_search_variation_group();');
    }
};
