<?php

namespace App\Website\Infrastructure;

use App\Shared\Application\Command\ImageUpload;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Two local sample blogs (news, buying guides) with eight posts in Greek and English. Covers are the bundled
 * CC0 demo photos in database/demo/images; nothing is fetched. Explicit opt-in, never in production; tracked by
 * stable keys so reruns create nothing and keep administrator edits.
 */
final class BlogDemoSeeder
{
    private const BLOGS = [
        'news' => [10, ['News', 'News and announcements from our store.'], ['Νέα', 'Νέα και ανακοινώσεις από το κατάστημά μας.']],
        'guides' => [20, ['Buying guides', 'Advice to help you choose the right product.'], ['Οδηγοί αγοράς', 'Συμβουλές για να επιλέξετε το σωστό προϊόν.']],
    ];

    /** Cover photo of each post (bundled CC0 demo photos in database/demo/images). */
    private const COVERS = ['new-store' => 'category-accessories', 'free-shipping' => 'kova-commuter-backpack', 'black-friday' => 'category-laptops', 'gift-cards' => 'aster-tab-8-mini',
        'smartphone' => 'category-phones', 'laptop' => 'nimbus-air-13', 'tv' => 'category-tablets', 'headphones' => 'category-audio'];

    /** blog, days ago, [en title, excerpt, tags], [el title, excerpt, tags] */
    private const POSTS = [
        'new-store' => ['news', 2, ['Our new online store is live', 'A faster store, easier checkout and order tracking from your account.', 'store, news'], ['Το νέο μας ηλεκτρονικό κατάστημα είναι εδώ', 'Πιο γρήγορο κατάστημα, ευκολότερη ολοκλήρωση αγοράς και παρακολούθηση παραγγελιών.', 'κατάστημα, νέα']],
        'free-shipping' => ['news', 6, ['Free shipping on orders over €50', 'From this month every order over €50 ships free across Greece.', 'shipping, offers'], ['Δωρεάν αποστολή για παραγγελίες άνω των 50 €', 'Από αυτόν τον μήνα κάθε παραγγελία άνω των 50 € αποστέλλεται δωρεάν σε όλη την Ελλάδα.', 'αποστολή, προσφορές']],
        'black-friday' => ['news', 11, ['Black Friday is coming', 'Mark your calendar: our biggest discounts of the year start soon.', 'offers, black friday'], ['Έρχεται η Black Friday', 'Σημειώστε την ημερομηνία: οι μεγαλύτερες εκπτώσεις της χρονιάς ξεκινούν σύντομα.', 'προσφορές, black friday']],
        'gift-cards' => ['news', 17, ['Gift cards are now available', 'Let them choose: send a gift card by e-mail in a few clicks.', 'gift cards'], ['Διαθέσιμες οι δωροκάρτες', 'Αφήστε τους να διαλέξουν: στείλτε δωροκάρτα με e-mail σε λίγα κλικ.', 'δωροκάρτες']],
        'smartphone' => ['guides', 4, ['How to choose a smartphone in 2026', 'Screen, battery, camera and storage: what really matters before you buy.', 'smartphones, guide'], ['Πώς να διαλέξετε smartphone το 2026', 'Οθόνη, μπαταρία, κάμερα και αποθηκευτικός χώρος: τι μετράει πραγματικά.', 'smartphones, οδηγός']],
        'laptop' => ['guides', 9, ['Laptop buying guide for students', 'Light, long battery life and enough power for study and play.', 'laptops, guide'], ['Οδηγός αγοράς laptop για φοιτητές', 'Ελαφρύ, με μεγάλη αυτονομία και αρκετή ισχύ για μελέτη και ψυχαγωγία.', 'laptop, οδηγός']],
        'tv' => ['guides', 14, ['Tablet or laptop? Picking the right one', 'We compare portability, battery life and price to help you decide.', 'tablets, laptops, guide'], ['Tablet ή laptop; Διαλέγοντας το σωστό', 'Συγκρίνουμε φορητότητα, αυτονομία και τιμή για να αποφασίσετε.', 'tablets, laptops, οδηγός']],
        'headphones' => ['guides', 21, ['Wireless headphones: what to look for', 'Noise cancelling, comfort and battery life explained in plain words.', 'headphones, audio'], ['Ασύρματα ακουστικά: τι να προσέξετε', 'Ακύρωση θορύβου, άνεση και αυτονομία εξηγημένα απλά.', 'ακουστικά, ήχος']],
    ];

    public function __construct(private BlogAdministration $blogs) {}

    /** @return array{blogs: int, posts: int} Records created by this run. */
    public function run(string $actor): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Blog demo seeding is restricted to local and testing environments.');
        }
        $created = ['blogs' => 0, 'posts' => 0];
        $blogIds = [];
        foreach (self::BLOGS as $key => [$position, $en, $el]) {
            $blogIds[$key] = $this->record('blog.'.$key, 'blogs', $created, 'blogs', fn (): string => $this->blogs->save('blog', $actor, null, null, [
                'status' => 'active', 'position' => $position, 'facebook_type' => 'blog',
                'translations' => ['en' => ['name' => $en[0], 'content' => '<p>'.$en[1].'</p>', 'meta_description' => $en[1]], 'el' => ['name' => $el[0], 'content' => '<p>'.$el[1].'</p>', 'meta_description' => $el[1]]],
            ]));
        }
        foreach (self::POSTS as $key => [$blog, $days, $en, $el]) {
            $this->record('post.'.$key, 'blog_posts', $created, 'posts', function () use ($actor, $blogIds, $key, $blog, $days, $en, $el): string {
                $path = tempnam(sys_get_temp_dir(), 'blog-demo');
                copy(database_path('demo/images/'.self::COVERS[$key].'.jpg'), $path);
                try {
                    return $this->blogs->save('post', $actor, null, null, [
                        'blog_id' => $blogIds[$blog], 'status' => 'active', 'published_at' => now()->subDays($days)->toIso8601String(), 'facebook_type' => 'article',
                        'translations' => ['en' => $this->text($en), 'el' => $this->text($el)],
                    ], new ImageUpload($path));
                } finally {
                    @unlink($path);
                }
            });
        }

        return $created;
    }

    /**
     * @param  array{string, string, string}  $text  title, excerpt, tags
     * @return array<string, string>
     */
    private function text(array $text): array
    {
        [$title, $excerpt, $tags] = $text;

        return ['name' => $title, 'excerpt' => $excerpt, 'cover_alt' => $title, 'tags' => $tags, 'meta_description' => $excerpt,
            'content' => '<p>'.$excerpt.'</p><h2>'.$title.'</h2><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p><ul><li>Lorem ipsum dolor sit amet</li><li>Consectetur adipiscing elit</li><li>Sed do eiusmod tempor</li></ul><p>Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>'];
    }

    /** @param array<string, int> $created */
    private function record(string $key, string $table, array &$created, string $counter, callable $create): string
    {
        $key = 'blogs.'.$key;
        $existing = DB::table('feature_demo_records')->where('key', $key)->first();
        if ($existing !== null) {
            if ($existing->entity !== $table || ! DB::table($table)->where('id', $existing->entity_id)->exists()) {
                throw new RuntimeException('Demo record was removed: '.$key.'. No data was changed.');
            }

            return (string) $existing->entity_id;
        }
        $id = $create();
        DB::table('feature_demo_records')->insert(['key' => $key, 'entity' => $table, 'entity_id' => $id]);
        $created[$counter]++;

        return $id;
    }
}
