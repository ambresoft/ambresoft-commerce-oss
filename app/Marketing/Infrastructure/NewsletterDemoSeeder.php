<?php

namespace App\Marketing\Infrastructure;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Local sample newsletter data: two mailing lists, a campaign, a template, a confirmation autoresponder, two
 * newsletters (one draft, one a ready-to-send copy) and fictitious subscribers on example.test addresses. Nothing is
 * sent. Explicit opt-in, never in production; samples are found by their names so reruns create nothing new.
 */
final class NewsletterDemoSeeder
{
    public function __construct(private NewsletterLists $lists, private NewsletterContent $content) {}

    /** @return array{lists: int, templates: int, autoresponders: int, newsletters: int, subscribers: int} Records created by this run. */
    public function run(string $actor): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Newsletter demo seeding is restricted to local and testing environments.');
        }
        $created = ['lists' => 0, 'templates' => 0, 'autoresponders' => 0, 'newsletters' => 0, 'subscribers' => 0];

        $template = $this->findContent('template', 'Simple layout (sample)') ?? $this->make($created, 'templates', fn () => $this->content->save($actor, 'template', null, null, ['is_active' => true, 'translations' => [
            'en' => ['name' => 'Simple layout (sample)', 'body_html' => $this->layout('Your store news', '<p>Write your message here.</p>')],
            'el' => ['name' => 'Απλή διάταξη (δείγμα)', 'body_html' => $this->layout('Τα νέα του καταστήματός σας', '<p>Γράψτε εδώ το μήνυμά σας.</p>')],
        ]]));
        $welcome = $this->findContent('autoresponder', 'Confirm your subscription (sample)') ?? $this->make($created, 'autoresponders', fn () => $this->content->save($actor, 'autoresponder', null, null, ['is_active' => true, 'translations' => [
            'en' => ['name' => 'Confirm your subscription (sample)', 'subject' => 'Please confirm your subscription', 'body_html' => $this->layout('Almost done', '<p>Thanks for subscribing with {{subscriber_email}}. Please confirm your subscription:</p><p>{{activation_link}}</p>')],
            'el' => ['name' => 'Επιβεβαίωση συνδρομής (δείγμα)', 'subject' => 'Παρακαλούμε επιβεβαιώστε τη συνδρομή σας', 'body_html' => $this->layout('Σχεδόν έτοιμο', '<p>Ευχαριστούμε που εγγραφήκατε με το {{subscriber_email}}. Επιβεβαιώστε τη συνδρομή σας:</p><p>{{activation_link}}</p>')],
        ]]));
        $weekly = $this->findList('Weekly offers (sample)') ?? $this->makeList($created, $actor, 'Weekly offers (sample)', 'Εβδομαδιαίες προσφορές (δείγμα)', $welcome, true);
        $arrivals = $this->findList('New arrivals (sample)') ?? $this->makeList($created, $actor, 'New arrivals (sample)', 'Νέες αφίξεις (δείγμα)', null, false);

        $campaign = DB::table('newsletter_campaigns')->whereRaw('lower(name) = ?', ['spring campaign (sample)'])->value('id')
            ?? $this->lists->saveCampaign($actor, null, null, ['name' => 'Spring campaign (sample)']);
        $this->findContent('newsletter', 'Spring offers (sample)') ?? $this->make($created, 'newsletters', fn () => $this->content->save($actor, 'newsletter', null, null, [
            'is_active' => true, 'campaign_id' => (string) $campaign, 'list_ids' => [$weekly, $arrivals],
            'translations' => [
                'en' => ['name' => 'Spring offers (sample)', 'subject' => 'Spring offers are here', 'random_subjects' => "Fresh spring offers inside\nSave this spring", 'body_html' => $this->layout('Spring offers', '<p>Hello {{subscriber_email}},</p><p>Discover this week’s offers at {{company_name}}.</p><p>{{company_phone}}</p>')],
                'el' => ['name' => 'Ανοιξιάτικες προσφορές (δείγμα)', 'subject' => 'Έφτασαν οι ανοιξιάτικες προσφορές', 'random_subjects' => '', 'body_html' => $this->layout('Ανοιξιάτικες προσφορές', '<p>Γεια σας {{subscriber_email}},</p><p>Ανακαλύψτε τις προσφορές της εβδομάδας στο {{company_name}}.</p>')],
            ],
        ]));
        unset($template);

        $locales = ['en', 'el'];
        foreach ([['anna', true, [$weekly, $arrivals]], ['nikos', true, [$weekly]], ['maria', true, [$arrivals]], ['george', true, [$weekly]], ['eleni', true, [$weekly, $arrivals]],
            ['dimitris', true, [$arrivals]], ['sofia', false, [$weekly]], ['petros', false, [$arrivals]]] as $n => [$name, $confirmed, $lists]) {
            $email = 'sample.'.$name.'@example.test';
            if (DB::table('newsletter_subscribers')->whereRaw('lower(email) = ?', [$email])->exists()) {
                continue;
            }
            $id = (string) DB::table('newsletter_subscribers')->insertGetId(['email' => $email, 'locale_id' => DB::table('locales')->where('code', $locales[$n % 2])->value('id'), 'status' => 'active', 'source' => $n % 3 === 0 ? 'import' : 'admin', 'created_at' => now()->subDays(20 - $n), 'updated_at' => now()]);
            foreach ($lists as $list) {
                DB::table('newsletter_list_subscribers')->insert(['list_id' => $list, 'subscriber_id' => $id, 'confirmed_at' => $confirmed ? now()->subDays(19 - $n) : null, 'created_at' => now()->subDays(20 - $n)]);
            }
            $created['subscribers']++;
        }
        if (! DB::table('newsletter_subscribers')->where('status', 'unsubscribed')->whereRaw("email like 'sample.%@example.test'")->exists()) {
            DB::table('newsletter_subscribers')->insert(['email' => 'sample.former@example.test', 'status' => 'unsubscribed', 'source' => 'admin', 'unsubscribed_at' => now()->subDays(3), 'created_at' => now()->subDays(30), 'updated_at' => now()]);
            $created['subscribers']++;
        }

        return $created;
    }

    private function findContent(string $type, string $name): ?string
    {
        $id = DB::table('newsletters as n')->join('newsletter_translations as t', 't.newsletter_id', '=', 'n.id')->where('n.type', $type)->where('t.name', $name)->value('n.id');

        return $id === null ? null : (string) $id;
    }

    private function findList(string $name): ?string
    {
        $id = DB::table('newsletter_list_translations')->where('name', $name)->value('list_id');

        return $id === null ? null : (string) $id;
    }

    /** @param array<string, int> $created */
    private function make(array &$created, string $key, callable $make): string
    {
        $created[$key]++;

        return $make();
    }

    /** @param array<string, int> $created */
    private function makeList(array &$created, string $actor, string $en, string $el, ?string $confirmation, bool $registration): string
    {
        return $this->make($created, 'lists', fn () => $this->lists->save($actor, null, null, [
            'status' => 'active', 'confirmation_id' => $confirmation ?? '', 'show_on_registration' => $registration, 'show_at_checkout' => false,
            'translations' => ['en' => ['name' => $en, 'description' => 'Sample list'], 'el' => ['name' => $el, 'description' => 'Δείγμα λίστας']],
        ]));
    }

    /** A small, email-safe layout with the standard variables in its footer. */
    private function layout(string $heading, string $body): string
    {
        return '<h2>'.$heading.'</h2>'.$body.'<p>{{company_name}} · {{company_address}}</p><p>{{unsubscribe_link}}</p>';
    }
}
