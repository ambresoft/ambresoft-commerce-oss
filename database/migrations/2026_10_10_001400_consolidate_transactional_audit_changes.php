<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable for historical rows; only new records receive the server transaction identity.
        DB::statement('ALTER TABLE audit_logs ADD COLUMN transaction_id bigint');
        DB::statement('ALTER TABLE audit_logs ALTER COLUMN transaction_id SET DEFAULT txid_current()');
        DB::statement("CREATE UNIQUE INDEX audit_transaction_entity ON audit_logs (transaction_id,request_id,entity_type,entity_id) NULLS NOT DISTINCT WHERE transaction_id IS NOT NULL AND jsonb_exists(metadata, 'table')");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION cms_audit_merge(original jsonb, incoming jsonb) RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE result jsonb := original; field text; entry jsonb; first_value jsonb; last_value jsonb;
BEGIN
    FOR field,entry IN SELECT * FROM jsonb_each(incoming) LOOP
        first_value := CASE WHEN original ? field THEN original->field->'before' ELSE entry->'before' END;
        last_value := entry->'after';
        IF first_value = last_value AND (field <> 'password' OR first_value = 'null'::jsonb) AND NOT coalesce((first_value->>'redacted')::boolean,false) THEN
            result := result - field;
        ELSE
            result := result || jsonb_build_object(field,jsonb_build_object('before',first_value,'after',last_value));
        END IF;
    END LOOP;
    RETURN result;
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
    saved_id bigint;
    saved_changes jsonb;
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
        (ctx->>'request_id')::uuid,(ctx->>'ip_address')::inet,ctx->>'user_agent',coalesce(ctx->>'source','system'))
    ON CONFLICT (transaction_id,request_id,entity_type,entity_id) WHERE transaction_id IS NOT NULL AND jsonb_exists(metadata, 'table')
    DO UPDATE SET changes = cms_audit_merge(audit_logs.changes,EXCLUDED.changes),
        action = CASE
            WHEN audit_logs.action LIKE '%.created' THEN audit_logs.action
            WHEN audit_logs.action LIKE '%.deleted' AND EXCLUDED.action LIKE '%.created' THEN EXCLUDED.entity_type || '.updated'
            ELSE EXCLUDED.action END,
        description = CASE WHEN audit_logs.action LIKE '%.created' THEN audit_logs.description ELSE EXCLUDED.description END,
        entity_label = coalesce(EXCLUDED.entity_label,audit_logs.entity_label)
    RETURNING id,changes INTO saved_id,saved_changes;
    IF saved_changes = '{}'::jsonb THEN DELETE FROM audit_logs WHERE id = saved_id; END IF;
    RETURN NULL;
END $$;
CREATE OR REPLACE FUNCTION cms_guard_audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE days integer;
BEGIN
    -- Only a business capture trigger may consolidate its still-uncommitted row.
    IF pg_trigger_depth() > 1 AND OLD.transaction_id = txid_current() THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
    END IF;
    days := nullif(current_setting('cms.audit_retention_days', true), '')::integer;
    IF TG_OP = 'DELETE' AND days > 0 AND OLD.occurred_at < clock_timestamp() - make_interval(days => days) THEN RETURN OLD; END IF;
    RAISE EXCEPTION 'Audit records are append-only' USING ERRCODE = '42501';
END $$;
SQL);
    }

    public function down(): void
    {
        // Restore the preceding capture/guard functions before dropping consolidation state.
        DB::unprepared(<<<'SQL'
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
SQL);
        DB::statement('DROP INDEX audit_transaction_entity');
        DB::statement('ALTER TABLE audit_logs DROP COLUMN transaction_id');
        DB::statement('DROP FUNCTION cms_audit_merge(jsonb,jsonb)');
    }
};
