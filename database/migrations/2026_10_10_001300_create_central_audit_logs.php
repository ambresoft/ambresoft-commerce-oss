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
        Schema::create('audit_logs', function (Blueprint $t): void {
            $t->id();
            $t->timestampTz('occurred_at')->useCurrent();
            $t->string('actor_id', 40)->nullable();
            $t->string('actor_email', 254)->nullable();
            $t->jsonb('actor_roles')->default('[]');
            $t->string('action', 100);
            $t->string('entity_type', 60);
            $t->string('entity_id', 200)->nullable();
            $t->string('entity_label', 254)->nullable();
            $t->string('description', 300);
            $t->string('status', 16);
            $t->jsonb('changes')->default('{}');
            $t->jsonb('metadata')->default('{}');
            $t->uuid('request_id')->nullable();
            $t->ipAddress('ip_address')->nullable();
            $t->string('user_agent', 254)->nullable();
            $t->string('source', 24);
            $t->index(['occurred_at', 'id']);
            foreach (['actor_id', 'action', 'entity_type', 'status', 'request_id'] as $field) {
                $t->index([$field, 'occurred_at', 'id']);
            }
            $t->index(['entity_type', 'entity_id', 'occurred_at']);
        });
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_outcome CHECK (status IN ('success','failure','denied') AND source IN ('admin_ui','web','api','background_job','system'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION cms_audit_safe_value(field text, value jsonb) RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE text_value text;
BEGIN
    IF value IS NULL OR value = 'null'::jsonb THEN RETURN 'null'::jsonb; END IF;
    IF field = 'password' THEN RETURN '"[REDACTED]"'::jsonb; END IF;
    text_value := value #>> '{}';
    IF field IN ('description','seo_description','age_warning_message','value') OR length(text_value) > 254 THEN
        RETURN jsonb_build_object('redacted', true, 'characters', length(text_value));
    END IF;
    IF field IN ('price','rate','weight','length','width','height','number_value') OR field LIKE '%\_id' ESCAPE '\' THEN
        RETURN to_jsonb(text_value);
    END IF;
    IF jsonb_typeof(value) = 'string' THEN
        RETURN to_jsonb(regexp_replace(text_value, '[[:cntrl:]]', '', 'g'));
    END IF;
    RETURN value;
END $$;
CREATE OR REPLACE FUNCTION cms_capture_audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    before_row jsonb := '{}'::jsonb;
    after_row jsonb := '{}'::jsonb;
    row_value jsonb;
    ctx jsonb;
    field text;
    delta jsonb := '{}'::jsonb;
    identity text;
    event_action text;
BEGIN
    IF TG_OP <> 'INSERT' THEN before_row := to_jsonb(OLD); END IF;
    IF TG_OP <> 'DELETE' THEN after_row := to_jsonb(NEW); END IF;
    row_value := CASE WHEN TG_OP = 'DELETE' THEN before_row ELSE after_row END;
    FOREACH field IN ARRAY string_to_array(TG_ARGV[1], ',') LOOP
        IF TG_OP <> 'UPDATE' OR before_row->field IS DISTINCT FROM after_row->field THEN
            delta := delta || jsonb_build_object(field, jsonb_build_object('before', cms_audit_safe_value(field, before_row->field), 'after', cms_audit_safe_value(field, after_row->field)));
        END IF;
    END LOOP;
    IF delta = '{}'::jsonb THEN RETURN NULL; END IF;
    ctx := coalesce(nullif(current_setting('cms.audit_context', true), '')::jsonb, '{}'::jsonb);
    identity := row_value->>'id';
    IF identity IS NULL THEN
        SELECT string_agg(key || '=' || value, ',' ORDER BY key) INTO identity FROM jsonb_each_text(row_value) WHERE key LIKE '%\_id' ESCAPE '\';
    END IF;
    event_action := CASE TG_OP WHEN 'INSERT' THEN 'created' WHEN 'DELETE' THEN 'deleted' ELSE 'updated' END;
    IF TG_OP = 'UPDATE' AND delta ? 'deleted_at' THEN
        event_action := CASE WHEN after_row->>'deleted_at' IS NULL THEN 'restored' ELSE 'trashed' END;
    ELSIF TG_OP = 'UPDATE' AND delta ? 'status' THEN
        event_action := CASE WHEN after_row->>'status' = 'archived' THEN 'archived' WHEN before_row->>'status' = 'archived' THEN 'restored' ELSE 'status_changed' END;
    END IF;
    INSERT INTO audit_logs(actor_id,actor_email,actor_roles,action,entity_type,entity_id,entity_label,description,status,changes,metadata,request_id,ip_address,user_agent,source)
    VALUES(ctx->>'actor_id',ctx->>'actor_email',coalesce(ctx->'actor_roles','[]'::jsonb),TG_ARGV[0] || '.' || event_action,TG_ARGV[0],identity,
        left(regexp_replace(coalesce(row_value->>'name',row_value->>'label',row_value->>'sku',row_value->>'code'),'[[:cntrl:]]','','g'),254),
        TG_ARGV[0] || ' ' || event_action,'success',delta,
        jsonb_build_object('table',TG_TABLE_NAME,'self_service',ctx->>'actor_id' = identity AND TG_TABLE_NAME = 'users'),
        (ctx->>'request_id')::uuid,(ctx->>'ip_address')::inet,ctx->>'user_agent',coalesce(ctx->>'source','system'));
    RETURN NULL;
END $$;
CREATE OR REPLACE FUNCTION cms_guard_audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE days integer;
BEGIN
    days := nullif(current_setting('cms.audit_retention_days', true), '')::integer;
    IF TG_OP = 'DELETE' AND days > 0 AND OLD.occurred_at < clock_timestamp() - make_interval(days => days) THEN RETURN OLD; END IF;
    RAISE EXCEPTION 'Audit records are append-only' USING ERRCODE = '42501';
END $$;
CREATE TRIGGER audit_append_only BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION cms_guard_audit();
SQL);
        foreach (AuditTables::all() as $table => [$entity, $fields]) {
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }

    public function down(): void
    {
        foreach (array_keys(AuditTables::all()) as $table) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
        }
        Schema::dropIfExists('audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS cms_guard_audit()');
        DB::statement('DROP FUNCTION IF EXISTS cms_capture_audit()');
        DB::statement('DROP FUNCTION IF EXISTS cms_audit_safe_value(text,jsonb)');
    }
};
