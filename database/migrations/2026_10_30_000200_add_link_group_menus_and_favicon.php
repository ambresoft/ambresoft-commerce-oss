<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECK = <<<'SQL'
    IF (SELECT style FROM website_menus WHERE id = NEW.menu_id) = 'link_groups' THEN
        IF NEW.kind = 'category' OR NEW.generate_children THEN
            RAISE EXCEPTION 'Link group menus contain only links and headings' USING ERRCODE = '23514';
        END IF;
        IF NEW.parent_id IS NOT NULL AND EXISTS (SELECT 1 FROM website_menu_items WHERE id = NEW.parent_id AND parent_id IS NOT NULL) THEN
            RAISE EXCEPTION 'Link group menus have at most two levels' USING ERRCODE = '23514';
        END IF;
        IF NEW.parent_id IS NOT NULL AND TG_OP = 'UPDATE' AND EXISTS (SELECT 1 FROM website_menu_items WHERE parent_id = NEW.id) THEN
            RAISE EXCEPTION 'Link group menus have at most two levels' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
SQL;

    public function up(): void
    {
        // "Link groups" menus are free manual lists (headings with links, two levels) shown as a directory block.
        Schema::table('website_menus', fn (Blueprint $t) => $t->string('style', 12)->default('standard'));
        DB::statement("ALTER TABLE website_menus ADD CONSTRAINT website_menus_style CHECK (style IN ('standard','link_groups') AND (style = 'standard' OR source = 'manual'))");
        $definition = DB::selectOne("SELECT pg_get_functiondef('cms_validate_menu_item()'::regprocedure) AS def")->def;
        DB::unprepared(str_replace('    RETURN NEW;', self::CHECK, $definition));
        $this->audit('website_menus', fn (string $fields): string => $fields.',style');

        // The storefront favicon; the platform icon is used until a merchant uploads one.
        Schema::table('company_settings', function (Blueprint $t): void {
            $t->foreignId('favicon_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $t->index('favicon_media_id');
        });
        $this->audit('company_settings', fn (string $fields): string => $fields.',favicon_media_id');
    }

    public function down(): void
    {
        $this->audit('company_settings', fn (string $fields): string => implode(',', array_diff(explode(',', $fields), ['favicon_media_id'])));
        Schema::table('company_settings', fn (Blueprint $t) => $t->dropConstrainedForeignId('favicon_media_id'));
        $definition = DB::selectOne("SELECT pg_get_functiondef('cms_validate_menu_item()'::regprocedure) AS def")->def;
        DB::unprepared(str_replace(self::CHECK, '    RETURN NEW;', $definition));
        $this->audit('website_menus', fn (string $fields): string => implode(',', array_diff(explode(',', $fields), ['style'])));
        DB::statement('ALTER TABLE website_menus DROP CONSTRAINT website_menus_style');
        Schema::table('website_menus', fn (Blueprint $t) => $t->dropColumn('style'));
    }

    /** Extends (or shrinks) the field list that earlier additive migrations left on a table's audit trigger. */
    private function audit(string $table, callable $fields): void
    {
        $definition = DB::selectOne("SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgname = 'cms_audit_mutation' AND tgrelid = '$table'::regclass")->def;
        if (preg_match("/cms_capture_audit\('([^']+)', '([^']+)'\)/", $definition, $m)) {
            DB::statement("DROP TRIGGER cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('{$m[1]}', '{$fields($m[2])}')");
        }
    }
};
