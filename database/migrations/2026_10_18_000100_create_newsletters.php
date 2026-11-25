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
        Schema::create('newsletter_lists', function (Blueprint $t): void {
            $t->id();
            $t->string('status', 10)->default('active');
            // Sender of the list's messages; empty values fall back to the store's e-mail sender.
            $t->string('from_name', 100)->nullable();
            $t->string('from_email', 254)->nullable();
            $t->string('reply_to', 254)->nullable();
            // Autoresponder sent to a subscriber when they join the list.
            $t->unsignedBigInteger('confirmation_id')->nullable();
            $t->boolean('show_at_checkout')->default(false);
            $t->boolean('show_on_registration')->default(false);
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        Schema::create('newsletter_list_translations', function (Blueprint $t): void {
            $t->foreignId('list_id')->constrained('newsletter_lists')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 150);
            $t->string('description', 500)->default('');
            $t->primary(['list_id', 'locale_id']);
        });
        Schema::create('newsletter_campaigns', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 150);
            $t->string('status', 10)->default('active');
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        // Newsletters, reusable templates and autoresponders share one shape: a type, locale-linked texts and optional links.
        Schema::create('newsletters', function (Blueprint $t): void {
            $t->id();
            $t->string('type', 15);
            $t->string('state', 10)->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('campaign_id')->nullable()->constrained('newsletter_campaigns')->nullOnDelete();
            $t->timestampTz('sent_at')->nullable();
            $t->bigInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['type', 'id']);
        });
        Schema::create('newsletter_translations', function (Blueprint $t): void {
            $t->foreignId('newsletter_id')->constrained('newsletters')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->restrictOnDelete();
            $t->string('name', 150);
            $t->string('subject', 300)->default('');
            // Extra subjects, one per line: each delivery uses one of them (or the main subject) at random.
            $t->text('random_subjects')->default('');
            $t->text('body_html')->default('');
            $t->primary(['newsletter_id', 'locale_id']);
        });
        Schema::create('newsletter_audiences', function (Blueprint $t): void {
            $t->foreignId('newsletter_id')->constrained('newsletters')->cascadeOnDelete();
            $t->foreignId('list_id')->constrained('newsletter_lists')->cascadeOnDelete();
            $t->primary(['newsletter_id', 'list_id']);
        });
        // Registered customers addressed directly, without a mailing list membership.
        Schema::create('newsletter_audience_customers', function (Blueprint $t): void {
            $t->foreignId('newsletter_id')->constrained('newsletters')->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $t->primary(['newsletter_id', 'customer_id']);
        });
        Schema::create('newsletter_subscribers', function (Blueprint $t): void {
            $t->id();
            $t->string('email', 254);
            $t->foreignId('locale_id')->nullable()->constrained('locales')->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->string('status', 15)->default('active');
            $t->string('source', 10)->default('admin');
            $t->timestampTz('unsubscribed_at')->nullable();
            $t->bigInteger('lock_version')->default(1);
            $t->timestampsTz();
        });
        Schema::create('newsletter_list_subscribers', function (Blueprint $t): void {
            $t->foreignId('list_id')->constrained('newsletter_lists')->cascadeOnDelete();
            $t->foreignId('subscriber_id')->constrained('newsletter_subscribers')->cascadeOnDelete();
            // Subscribed = a row exists; only confirmed memberships receive list messages.
            $t->timestampTz('confirmed_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->primary(['list_id', 'subscriber_id']);
            $t->index('subscriber_id');
        });
        // One delivery per newsletter and subscriber: sending again only reaches subscribers that were not sent to yet.
        Schema::create('newsletter_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('newsletter_id')->constrained('newsletters')->cascadeOnDelete();
            $t->foreignId('subscriber_id')->nullable()->constrained('newsletter_subscribers')->nullOnDelete();
            $t->foreignId('list_id')->nullable()->constrained('newsletter_lists')->nullOnDelete();
            $t->foreignId('locale_id')->nullable()->constrained('locales')->nullOnDelete();
            $t->string('email', 254);
            // Newsletters: the lower-cased address; autoresponders: address and list (one confirmation per list).
            $t->string('dedupe_key', 280);
            $t->string('state', 10)->default('pending');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestampTz('last_attempt_at')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->string('error', 60)->nullable();
            $t->timestampsTz();
            $t->index(['state', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX newsletter_delivery_recipient ON newsletter_deliveries (newsletter_id, dedupe_key)');
        DB::statement('ALTER TABLE newsletter_lists ADD CONSTRAINT newsletter_list_confirmation FOREIGN KEY (confirmation_id) REFERENCES newsletters (id) ON DELETE RESTRICT');

        DB::statement("ALTER TABLE newsletter_lists ADD CONSTRAINT newsletter_list_status CHECK (status IN ('active','disabled') AND lock_version > 0)");
        DB::statement("ALTER TABLE newsletter_list_translations ADD CONSTRAINT newsletter_list_name CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE newsletter_campaigns ADD CONSTRAINT newsletter_campaign_status CHECK (status IN ('active','disabled') AND btrim(name) <> '' AND lock_version > 0)");
        DB::statement('CREATE UNIQUE INDEX newsletter_campaign_name_unique ON newsletter_campaigns (lower(name))');
        DB::statement("ALTER TABLE newsletters ADD CONSTRAINT newsletter_shape CHECK (
            type IN ('newsletter','template','autoresponder') AND lock_version > 0
            AND ((type = 'newsletter' AND state IN ('draft','sending','sent')) OR (type <> 'newsletter' AND state IS NULL))
            AND (type = 'newsletter' OR campaign_id IS NULL))");
        DB::statement("ALTER TABLE newsletter_translations ADD CONSTRAINT newsletter_translation_name CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE newsletter_subscribers ADD CONSTRAINT newsletter_subscriber_shape CHECK (status IN ('active','unsubscribed') AND source IN ('admin','import','storefront') AND btrim(email) <> '' AND lock_version > 0)");
        DB::statement('CREATE UNIQUE INDEX newsletter_subscriber_email_unique ON newsletter_subscribers (lower(email))');
        DB::statement("ALTER TABLE newsletter_deliveries ADD CONSTRAINT newsletter_delivery_state CHECK (state IN ('pending','sending','sent','failed'))");
        $this->trigger(AuditTables::newsletters());
    }

    public function down(): void
    {
        foreach (array_keys(AuditTables::newsletters()) as $table) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
        }
        DB::statement('ALTER TABLE newsletter_lists DROP CONSTRAINT IF EXISTS newsletter_list_confirmation');
        foreach (['newsletter_deliveries', 'newsletter_list_subscribers', 'newsletter_subscribers', 'newsletter_audience_customers', 'newsletter_audiences', 'newsletter_translations', 'newsletters', 'newsletter_campaigns', 'newsletter_list_translations', 'newsletter_lists'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** @param array<string, array{string, string}> $tables */
    private function trigger(array $tables): void
    {
        foreach ($tables as $table => [$entity, $fields]) {
            DB::statement("DROP TRIGGER IF EXISTS cms_audit_mutation ON $table");
            DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('$entity', '$fields')");
        }
    }
};
