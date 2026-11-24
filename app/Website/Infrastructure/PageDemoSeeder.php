<?php

namespace App\Website\Infrastructure;

use App\Localization\Infrastructure\LocaleCatalog;
use App\Storefront\Infrastructure\LayoutSettings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Five local sample pages (about us, terms of use, privacy policy, returns and exchanges, contact us with a form) in Greek and English,
 * each linked to its own storefront layout so it opens at its address. The texts are generic
 * examples, not legal advice. Explicit opt-in, never in production; tracked by stable keys so reruns create nothing
 * and keep administrator edits.
 */
final class PageDemoSeeder
{
    public function __construct(private PageAdministration $pages) {}

    /** @return array{pages: int} Pages created by this run. */
    public function run(string $actor): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Page demo seeding is restricted to local and testing environments.');
        }
        $created = ['pages' => 0];
        $this->record('page.about', $created, fn (): string => $this->pages->save($actor, null, null, $this->page(5, 'website',
            ['About us', 'About us', 'Who we are and how we choose the devices we sell.', 'about, store, team', '<p>We are a small team of people who love good technology. Every phone, tablet, laptop and accessory in our store is one we would use ourselves.</p><h2>What we promise</h2><ul><li>Honest advice before you buy</li><li>Fast delivery and free returns within 30 days</li><li>Real people answering your questions</li></ul>'],
            ['Σχετικά με εμάς', 'Σχετικά με εμάς', 'Ποιοι είμαστε και πώς επιλέγουμε τις συσκευές που πουλάμε.', 'σχετικά, κατάστημα, ομάδα', '<p>Είμαστε μια μικρή ομάδα που αγαπά την καλή τεχνολογία. Κάθε κινητό, tablet, laptop και αξεσουάρ του καταστήματος είναι κάτι που θα χρησιμοποιούσαμε κι εμείς.</p><h2>Τι υποσχόμαστε</h2><ul><li>Ειλικρινείς συμβουλές πριν αγοράσετε</li><li>Γρήγορη παράδοση και δωρεάν επιστροφές εντός 30 ημερών</li><li>Πραγματικούς ανθρώπους που απαντούν στις ερωτήσεις σας</li></ul>'],
        )), $actor);
        $this->record('page.terms', $created, fn (): string => $this->pages->save($actor, null, null, $this->page(8, 'website',
            ['Terms of use', 'Terms of use', 'The terms that apply when you use this store.', 'terms, conditions', '<p>These sample terms explain how orders, prices, delivery and returns work in this demo store. Replace them with your own terms before you open your store.</p><h2>Orders and prices</h2><p>Prices include VAT. An order is confirmed when we send the confirmation email.</p><h2>Delivery and returns</h2><p>See our returns and exchanges page for details.</p>'],
            ['Όροι χρήσης', 'Όροι χρήσης', 'Οι όροι που ισχύουν όταν χρησιμοποιείτε το κατάστημα.', 'όροι, προϋποθέσεις', '<p>Αυτοί οι ενδεικτικοί όροι εξηγούν πώς λειτουργούν οι παραγγελίες, οι τιμές, η παράδοση και οι επιστροφές στο demo κατάστημα. Αντικαταστήστε τους με τους δικούς σας πριν ανοίξετε το κατάστημα.</p><h2>Παραγγελίες και τιμές</h2><p>Οι τιμές περιλαμβάνουν ΦΠΑ. Μια παραγγελία επιβεβαιώνεται όταν σας στείλουμε το email επιβεβαίωσης.</p><h2>Παράδοση και επιστροφές</h2><p>Δείτε τη σελίδα επιστροφών και αλλαγών.</p>'],
        )), $actor);
        $this->record('page.privacy', $created, fn (): string => $this->pages->save($actor, null, null, $this->page(10, 'website',
            ['Privacy policy', 'Privacy policy', 'How we collect, use and protect your personal data.', 'privacy, personal data, GDPR', $this->privacyEn()],
            ['Πολιτική Απορρήτου', 'Πολιτική Απορρήτου', 'Πώς συλλέγουμε, χρησιμοποιούμε και προστατεύουμε τα προσωπικά σας δεδομένα.', 'απόρρητο, προσωπικά δεδομένα, GDPR', $this->privacyEl()],
        )), $actor);
        $this->record('page.returns', $created, fn (): string => $this->pages->save($actor, null, null, $this->page(20, 'article',
            ['Returns and exchanges', 'Returns and exchanges', 'Return or exchange your order within 14 days.', 'returns, exchanges, refund', $this->returnsEn()],
            ['Επιστροφές και αλλαγές', 'Επιστροφές και αλλαγές', 'Επιστρέψτε ή αλλάξτε την παραγγελία σας μέσα σε 14 ημέρες.', 'επιστροφές, αλλαγές, επιστροφή χρημάτων', $this->returnsEl()],
        )), $actor);

        $this->record('page.contact', $created, fn (): string => $this->pages->save($actor, null, null, [...$this->page(30, 'website',
            ['Contact us', 'Contact us', 'Send us a message and we will reply as soon as possible.', 'contact, form, support', $this->contactEn()],
            ['Επικοινωνία', 'Επικοινωνία', 'Στείλτε μας μήνυμα και θα απαντήσουμε το συντομότερο δυνατό.', 'επικοινωνία, φόρμα, υποστήριξη', $this->contactEl()],
        ), 'form' => $this->contactForm()]), $actor);

        return $created;
    }

    /** @return array<string, mixed> Contact form: name, surname, phone, e-mail, topic (subject) and message. */
    private function contactForm(): array
    {
        $codes = app(LocaleCatalog::class)->active()->pluck('code')->all();
        $both = fn (string $english, string $greek): array => array_combine($codes, array_map(fn (string $code): string => $code === 'el' ? $greek : $english, $codes));
        $translations = [];
        foreach ($codes as $code) {
            $translations[$code] = ['custom_subject' => '', 'submit_text' => $code === 'el'
                ? '<p>Ευχαριστούμε! Λάβαμε το μήνυμά σας και θα επικοινωνήσουμε σύντομα μαζί σας.</p>'
                : '<p>Thank you! We received your message and will get back to you shortly.</p>'];
        }

        return [
            'enabled' => true, 'placement' => 'after', 'subject_mode' => 'field', 'subject_field' => 'topic', 'recipients' => 'info@example.test',
            'translations' => $translations,
            'fields' => [
                ['key' => 'first', 'position' => 10, 'type' => 'input', 'required' => true, 'status' => 'active', 'names' => $both('First name', 'Όνομα')],
                ['key' => 'last', 'position' => 20, 'type' => 'input', 'required' => true, 'status' => 'active', 'names' => $both('Last name', 'Επώνυμο')],
                ['key' => 'phone', 'position' => 30, 'type' => 'phone', 'required' => false, 'status' => 'active', 'names' => $both('Phone', 'Τηλέφωνο')],
                ['key' => 'email', 'position' => 40, 'type' => 'email', 'required' => true, 'status' => 'active', 'names' => $both('E-mail', 'E-mail')],
                ['key' => 'topic', 'position' => 50, 'type' => 'select', 'required' => true, 'status' => 'active', 'names' => $both('Topic', 'Θέμα'), 'variants' => [
                    ['position' => 10, 'labels' => $both('Order question', 'Ερώτηση για παραγγελία')],
                    ['position' => 20, 'labels' => $both('Product information', 'Πληροφορίες προϊόντος')],
                    ['position' => 30, 'labels' => $both('Wholesale', 'Χονδρική')],
                    ['position' => 40, 'labels' => $both('Other', 'Άλλο')],
                ]],
                ['key' => 'message', 'position' => 60, 'type' => 'textarea', 'required' => true, 'status' => 'active', 'names' => $both('Message', 'Μήνυμα')],
            ],
        ];
    }

    private function contactEn(): string
    {
        return '<h1>Contact us</h1><p>Have a question about an order or our products? Fill in the form below and we will reply within one working day.</p><p><strong>Phone:</strong> <a href="tel:+302101234567">210 123 4567</a><br><strong>E-mail:</strong> <a href="mailto:info@example.test">info@example.test</a></p>';
    }

    private function contactEl(): string
    {
        return '<h1>Επικοινωνία</h1><p>Έχετε απορία για μια παραγγελία ή για τα προϊόντα μας; Συμπληρώστε τη φόρμα και θα απαντήσουμε μέσα σε μία εργάσιμη ημέρα.</p><p><strong>Τηλέφωνο:</strong> <a href="tel:+302101234567">210 123 4567</a><br><strong>E-mail:</strong> <a href="mailto:info@example.test">info@example.test</a></p>';
    }

    /**
     * @param  array{string, string, string, string, string}  $english  name, page title, meta description, keywords, content
     * @param  array{string, string, string, string, string}  $greek
     * @return array<string, mixed>
     */
    private function page(int $position, string $facebook, array $english, array $greek): array
    {
        $translations = [];
        foreach (app(LocaleCatalog::class)->active()->pluck('code') as $code) {
            [$name, $title, $description, $keywords, $content] = $code === 'el' ? $greek : $english;
            $translations[$code] = ['name' => $name, 'seo_title' => $title, 'meta_description' => $description, 'meta_keywords' => $keywords, 'content' => $content, 'slug' => ''];
        }

        return ['parent_id' => '', 'position' => $position, 'status' => 'active', 'created_on' => now()->toDateString(), 'facebook_type' => $facebook, 'translations' => $translations];
    }

    private function privacyEn(): string
    {
        return <<<'HTML'
<h1>Privacy Policy</h1>
<p><em>Sample text. Review it with your legal advisor before publishing.</em></p>
<h2>What information do we collect?</h2>
<p>We collect information from you when you register on our site, place an order, subscribe to our newsletter or fill out a form. When ordering or registering you may be asked for your name, e-mail address, postal address and phone number. You may, however, visit our site anonymously.</p>
<h2>How do we use your information?</h2>
<ul>
<li>To process your orders and deliver the products you bought.</li>
<li>To send you information about your order and answer your questions.</li>
<li>To improve our website and the service we offer.</li>
<li>To send newsletters, only if you have agreed to receive them.</li>
</ul>
<h2>How do we protect your information?</h2>
<p>We apply technical and organisational security measures to keep your personal data safe. Payments are processed by certified payment providers; we do not store card details.</p>
<h2>Your rights</h2>
<p>Under the General Data Protection Regulation (GDPR) you can ask for access, correction, deletion or portability of your data and object to its processing. Contact us at <a href="mailto:privacy@example.test">privacy@example.test</a>.</p>
<hr>
<p style="text-align: right"><em>Last updated: October 2026</em></p>
HTML;
    }

    private function privacyEl(): string
    {
        return <<<'HTML'
<h1>Πολιτική Απορρήτου</h1>
<p><em>Ενδεικτικό κείμενο. Ελέγξτε το με τον νομικό σας σύμβουλο πριν τη δημοσίευση.</em></p>
<h2>Ποιες πληροφορίες συλλέγουμε;</h2>
<p>Συλλέγουμε πληροφορίες όταν εγγράφεστε στο κατάστημα, κάνετε παραγγελία, εγγράφεστε στο newsletter ή συμπληρώνετε μια φόρμα. Κατά την παραγγελία ή την εγγραφή μπορεί να σας ζητηθούν το ονοματεπώνυμο, το e-mail, η ταχυδρομική διεύθυνση και το τηλέφωνό σας. Μπορείτε πάντως να περιηγηθείτε στο κατάστημα ανώνυμα.</p>
<h2>Πώς χρησιμοποιούμε τις πληροφορίες σας;</h2>
<ul>
<li>Για την εκτέλεση των παραγγελιών και την αποστολή των προϊόντων.</li>
<li>Για την ενημέρωσή σας σχετικά με την παραγγελία και την απάντηση στις ερωτήσεις σας.</li>
<li>Για τη βελτίωση του καταστήματος και των υπηρεσιών μας.</li>
<li>Για την αποστολή newsletter, μόνο εφόσον έχετε συναινέσει.</li>
</ul>
<h2>Πώς προστατεύουμε τις πληροφορίες σας;</h2>
<p>Εφαρμόζουμε τεχνικά και οργανωτικά μέτρα ασφαλείας για την προστασία των προσωπικών σας δεδομένων. Οι πληρωμές γίνονται μέσω πιστοποιημένων παρόχων· δεν αποθηκεύουμε στοιχεία καρτών.</p>
<h2>Τα δικαιώματά σας</h2>
<p>Σύμφωνα με τον Γενικό Κανονισμό Προστασίας Δεδομένων (GDPR) έχετε δικαίωμα πρόσβασης, διόρθωσης, διαγραφής και φορητότητας των δεδομένων σας, καθώς και εναντίωσης στην επεξεργασία τους. Επικοινωνήστε μαζί μας στο <a href="mailto:privacy@example.test">privacy@example.test</a>.</p>
<hr>
<p style="text-align: right"><em>Τελευταία ενημέρωση: Οκτώβριος 2026</em></p>
HTML;
    }

    private function returnsEn(): string
    {
        return <<<'HTML'
<h1>Returns and exchanges</h1>
<p><em>Sample text. Adapt the periods and costs to your store.</em></p>
<p>You can return or exchange any product within <strong>14 days</strong> of delivery, as long as it is unused and in its original packaging.</p>
<h2>How to return a product</h2>
<ol>
<li>Contact us at <a href="mailto:returns@example.test">returns@example.test</a> or <a href="tel:+302101234567">210 123 4567</a> with your order number.</li>
<li>Pack the product securely in its original packaging.</li>
<li>Send it with the courier we arrange for you.</li>
</ol>
<h2>Costs and refunds</h2>
<table>
<thead><tr><th>Case</th><th>Shipping cost</th><th>Refund</th></tr></thead>
<tbody>
<tr><td>Defective or wrong product</td><td>Free</td><td>Full amount within 5 working days</td></tr>
<tr><td>Change of mind</td><td>Paid by the customer</td><td>Product value within 14 days</td></tr>
<tr><td>Exchange for another size or colour</td><td>Free once per order</td><td>Not applicable</td></tr>
</tbody>
</table>
<p>Custom printed products (for example bags with your logo) cannot be returned unless they are defective.</p>
HTML;
    }

    private function returnsEl(): string
    {
        return <<<'HTML'
<h1>Επιστροφές και αλλαγές</h1>
<p><em>Ενδεικτικό κείμενο. Προσαρμόστε τις προθεσμίες και τα κόστη στο κατάστημά σας.</em></p>
<p>Μπορείτε να επιστρέψετε ή να αλλάξετε οποιοδήποτε προϊόν μέσα σε <strong>14 ημέρες</strong> από την παράδοση, εφόσον δεν έχει χρησιμοποιηθεί και βρίσκεται στην αρχική του συσκευασία.</p>
<h2>Πώς επιστρέφω ένα προϊόν</h2>
<ol>
<li>Επικοινωνήστε μαζί μας στο <a href="mailto:returns@example.test">returns@example.test</a> ή στο <a href="tel:+302101234567">210 123 4567</a> με τον αριθμό της παραγγελίας σας.</li>
<li>Συσκευάστε το προϊόν με ασφάλεια στην αρχική του συσκευασία.</li>
<li>Παραδώστε το στην εταιρεία courier που θα σας στείλουμε.</li>
</ol>
<h2>Κόστη και επιστροφή χρημάτων</h2>
<table>
<thead><tr><th>Περίπτωση</th><th>Μεταφορικά</th><th>Επιστροφή χρημάτων</th></tr></thead>
<tbody>
<tr><td>Ελαττωματικό ή λάθος προϊόν</td><td>Δωρεάν</td><td>Ολόκληρο το ποσό σε 5 εργάσιμες</td></tr>
<tr><td>Αλλαγή γνώμης</td><td>Με χρέωση του πελάτη</td><td>Η αξία του προϊόντος σε 14 ημέρες</td></tr>
<tr><td>Αλλαγή μεγέθους ή χρώματος</td><td>Δωρεάν μία φορά ανά παραγγελία</td><td>Δεν εφαρμόζεται</td></tr>
</tbody>
</table>
<p>Προϊόντα με εκτύπωση κατά παραγγελία (π.χ. σακούλες με το λογότυπό σας) δεν επιστρέφονται, εκτός αν είναι ελαττωματικά.</p>
HTML;
    }

    /** @param array<string, int> $created */
    private function record(string $key, array &$created, callable $create, string $actor): void
    {
        $key = 'pages.'.$key;
        $existing = DB::table('feature_demo_records')->where('key', $key)->first();
        if ($existing !== null) {
            if ($existing->entity !== 'pages' || ! DB::table('pages')->where('id', $existing->entity_id)->exists()) {
                throw new RuntimeException('Demo record was removed: '.$key.'. No data was changed.');
            }

            return;
        }
        $id = $create();
        // A page opens at its address only through a storefront layout linked to it.
        $name = (string) DB::table('page_translations')->where('page_id', $id)->orderBy('locale_id')->value('name');
        app(LayoutSettings::class)->create($actor, ['page_id' => (int) $id, 'position' => null, 'custom_html' => null, 'indexable' => true, 'translations' => [app(LocaleCatalog::class)->defaultCode() => ['name' => $name]]]);
        DB::table('feature_demo_records')->insert(['key' => $key, 'entity' => 'pages', 'entity_id' => $id]);
        $created['pages']++;
    }
}
