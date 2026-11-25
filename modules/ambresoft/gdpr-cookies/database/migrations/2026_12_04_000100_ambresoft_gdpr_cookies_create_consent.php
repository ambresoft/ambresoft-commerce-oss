<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CATEGORY = "category IN ('necessary', 'preferences', 'statistics', 'marketing')";

    public function up(): void
    {
        Schema::create('ambresoft_gdpr_settings', function (Blueprint $t): void {
            $t->smallInteger('id')->primary();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedSmallInteger('consent_months')->default(6);
            $t->string('privacy_url', 2048)->nullable();
            $t->boolean('reopen_button')->default(true);
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE ambresoft_gdpr_settings ADD CONSTRAINT ambresoft_gdpr_settings_check CHECK (id = 1 AND version > 0 AND consent_months BETWEEN 1 AND 12 AND lock_version > 0)');
        DB::table('ambresoft_gdpr_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // Merchant text per language; anything left empty falls back to the add-on's own wording.
        Schema::create('ambresoft_gdpr_texts', function (Blueprint $t): void {
            $t->foreignId('locale_id')->primary()->constrained('locales')->cascadeOnDelete();
            $t->string('title', 200)->default('');
            $t->text('body')->default('');
            $t->text('about')->default('');
        });
        Schema::create('ambresoft_gdpr_category_texts', function (Blueprint $t): void {
            $t->string('category', 12);
            $t->foreignId('locale_id')->constrained('locales')->cascadeOnDelete();
            $t->string('name', 100)->default('');
            $t->text('description')->default('');
            $t->primary(['category', 'locale_id']);
        });
        DB::statement('ALTER TABLE ambresoft_gdpr_category_texts ADD CONSTRAINT ambresoft_gdpr_category_texts_check CHECK ('.self::CATEGORY.')');

        // Declared cookies, listed to visitors under their category.
        Schema::create('ambresoft_gdpr_cookies', function (Blueprint $t): void {
            $t->id();
            $t->string('category', 12);
            $t->string('name', 120);
            $t->string('provider', 120)->default('');
            $t->unsignedInteger('position')->default(0);
            $t->unique(['category', 'name']);
        });
        DB::statement('ALTER TABLE ambresoft_gdpr_cookies ADD CONSTRAINT ambresoft_gdpr_cookies_check CHECK ('.self::CATEGORY." AND btrim(name) <> '')");
        Schema::create('ambresoft_gdpr_cookie_texts', function (Blueprint $t): void {
            $t->foreignId('cookie_id')->constrained('ambresoft_gdpr_cookies')->cascadeOnDelete();
            $t->foreignId('locale_id')->constrained('locales')->cascadeOnDelete();
            $t->text('purpose')->default('');
            $t->string('expiry', 60)->default('');
            $t->primary(['cookie_id', 'locale_id']);
        });

        // Snippets that run only after consent to their category, on public pages (A149). Audited.
        Schema::create('ambresoft_gdpr_scripts', function (Blueprint $t): void {
            $t->id();
            $t->string('category', 12);
            $t->string('name', 120);
            $t->text('code');
            $t->boolean('enabled')->default(true);
            $t->unsignedInteger('position')->default(0);
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE ambresoft_gdpr_scripts ADD CONSTRAINT ambresoft_gdpr_scripts_check CHECK (category IN ('preferences', 'statistics', 'marketing') AND btrim(name) <> '' AND length(code) BETWEEN 1 AND 20000)");
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON ambresoft_gdpr_scripts FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('gdpr_cookie_script', 'category,name,code,enabled,position')");
        DB::statement("CREATE TRIGGER cms_audit_mutation AFTER INSERT OR UPDATE OR DELETE ON ambresoft_gdpr_settings FOR EACH ROW EXECUTE FUNCTION cms_capture_audit('gdpr_cookie_settings', 'version,consent_months,privacy_url,reopen_button')");

        // Proof of consent: one row per decision, no IP address or other personal data.
        Schema::create('ambresoft_gdpr_consents', function (Blueprint $t): void {
            $t->id();
            $t->uuid('consent_id')->index();
            $t->unsignedInteger('version');
            $t->boolean('preferences');
            $t->boolean('statistics');
            $t->boolean('marketing');
            $t->string('action', 12);
            $t->timestampTz('created_at')->index();
        });
        DB::statement("ALTER TABLE ambresoft_gdpr_consents ADD CONSTRAINT ambresoft_gdpr_consents_check CHECK (action IN ('accept_all', 'reject_all', 'custom') AND version > 0)");

        $this->seedCookies();
    }

    /** The CMS's own cookies, plus Google Analytics', so the declaration starts truthful. */
    private function seedCookies(): void
    {
        $locales = DB::table('locales')->whereIn('code', ['en', 'el'])->pluck('id', 'code');
        // [category, name, provider, [en purpose, en duration], [el purpose, el duration]]
        $cookies = [
            ['necessary', (string) config('session.cookie'), 'Store', ['Keeps the visitor signed in and the session secure.', 'Session'], ['Διατηρεί τη σύνδεση του επισκέπτη και την ασφάλεια της συνεδρίας.', 'Συνεδρία']],
            ['necessary', 'XSRF-TOKEN', 'Store', ['Protects forms against cross-site request forgery.', 'Session'], ['Προστατεύει τις φόρμες από επιθέσεις cross-site request forgery.', 'Συνεδρία']],
            ['necessary', 'cart', 'Store', ['Remembers the shopping cart.', '30 days'], ['Θυμάται το καλάθι αγορών.', '30 ημέρες']],
            ['necessary', 'cms_consent', 'Store', ['Stores your cookie choices.', '6 months'], ['Αποθηκεύει τις επιλογές σας για τα cookies.', '6 μήνες']],
            ['necessary', 'remember_web_*', 'Store', ['Keeps you signed in when you choose "remember me".', '400 days'], ['Σας κρατά συνδεδεμένους όταν επιλέγετε «να με θυμάσαι».', '400 ημέρες']],
            ['necessary', 'age_verified', 'Store', ['Remembers a confirmed age for age-restricted products.', '1 year'], ['Θυμάται την επιβεβαιωμένη ηλικία για προϊόντα με περιορισμό ηλικίας.', '1 έτος']],
            ['statistics', '_ga', 'Google', ['Distinguishes visitors for Google Analytics.', '2 years'], ['Διακρίνει τους επισκέπτες για το Google Analytics.', '2 έτη']],
            ['statistics', '_ga_*', 'Google', ['Keeps the Google Analytics session state.', '2 years'], ['Διατηρεί την κατάσταση της συνεδρίας του Google Analytics.', '2 έτη']],
            ['statistics', 'cms_ga', 'Store', ['Links a purchase to the visit Google Analytics measured.', '2 hours'], ['Συνδέει μια αγορά με την επίσκεψη που μέτρησε το Google Analytics.', '2 ώρες']],
        ];
        foreach ($cookies as $position => [$category, $name, $provider, $en, $el]) {
            $id = DB::table('ambresoft_gdpr_cookies')->insertGetId(['category' => $category, 'name' => $name, 'provider' => $provider, 'position' => $position]);
            foreach (['en' => $en, 'el' => $el] as $code => [$purpose, $expiry]) {
                if (isset($locales[$code])) {
                    DB::table('ambresoft_gdpr_cookie_texts')->insert(['cookie_id' => $id, 'locale_id' => $locales[$code], 'purpose' => $purpose, 'expiry' => $expiry]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['ambresoft_gdpr_consents', 'ambresoft_gdpr_scripts', 'ambresoft_gdpr_cookie_texts', 'ambresoft_gdpr_cookies', 'ambresoft_gdpr_category_texts', 'ambresoft_gdpr_texts', 'ambresoft_gdpr_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
