<?php

namespace App\Website\Infrastructure;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Website\Application\Command\SaveFaqData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/** Explicit local samples; stable mappings preserve subsequent merchant edits. */
final class FaqDemoSeeder
{
    public function __construct(private FaqAdministration $faqs) {}

    public function run(string $actor): int
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('FAQ demo seeding is restricted to local and testing environments.');
        }
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('faqs.manage');

        return DB::transaction(function () use ($actor): int {
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            $created = 0;
            foreach ($this->samples() as $index => $sample) {
                $key = 'faqs.sample.'.($index + 1);
                $existing = DB::table('feature_demo_records')->where('key', $key)->first();
                if ($existing !== null) {
                    if ($existing->entity !== 'faqs' || ! DB::table('faqs')->where('id', $existing->entity_id)->exists()) {
                        throw new RuntimeException('Demo record was removed: '.$key.'. No data was changed.');
                    }

                    continue;
                }
                $translations = [];
                foreach (app(LocaleCatalog::class)->active() as $locale) {
                    if (! isset($sample['text'][$locale->code])) {
                        throw new RuntimeException('No FAQ sample translation for active language '.$locale->code.'. No data was changed.');
                    }
                    [$question, $answer] = $sample['text'][$locale->code];
                    $translations[$locale->code] = ['question' => $question, 'answer' => '<p>'.$answer.'</p>'];
                }
                $id = $this->faqs->save($actor, null, new SaveFaqData($sample['type'], 'active', ($index + 1) * 10, $translations));
                DB::table('feature_demo_records')->insert(['key' => $key, 'entity' => 'faqs', 'entity_id' => $id]);
                $created++;
            }

            return $created;
        });
    }

    /** @return list<array{type: string, text: array<string, array{string, string}>}> */
    private function samples(): array
    {
        return [
            ['type' => 'general', 'text' => [
                'en' => ['(sample) How can I contact the store?', 'Use the contact details on our Contact page to ask the store a question.'],
                'el' => ['(δείγμα) Πώς μπορώ να επικοινωνήσω με το κατάστημα;', 'Χρησιμοποιήστε τα στοιχεία στη σελίδα Επικοινωνία για να στείλετε την ερώτησή σας στο κατάστημα.'],
                'es' => ['(ejemplo) ¿Cómo puedo contactar con la tienda?', 'Utilice los datos de nuestra página de contacto para enviar su pregunta a la tienda.'],
                'fr' => ['(exemple) Comment contacter la boutique ?', 'Utilisez les coordonnées de notre page Contact pour poser votre question à la boutique.'],
                'it' => ['(esempio) Come posso contattare il negozio?', 'Utilizza i recapiti nella nostra pagina Contatti per inviare una domanda al negozio.'],
                'nl' => ['(voorbeeld) Hoe kan ik contact opnemen met de winkel?', 'Gebruik de contactgegevens op onze contactpagina om een vraag aan de winkel te stellen.'],
            ]],
            ['type' => 'general', 'text' => [
                'en' => ['(sample) Where can I find delivery and returns information?', 'Read the delivery and returns policies published by the store. Contact the store if you need clarification before ordering.'],
                'el' => ['(δείγμα) Πού θα βρω πληροφορίες για παράδοση και επιστροφές;', 'Διαβάστε τις πολιτικές παράδοσης και επιστροφών του καταστήματος. Επικοινωνήστε μαζί μας για διευκρινίσεις πριν από την παραγγελία.'],
                'es' => ['(ejemplo) ¿Dónde encuentro información sobre entregas y devoluciones?', 'Consulte las políticas de entrega y devolución de la tienda. Contacte con la tienda si necesita aclaraciones antes de pedir.'],
                'fr' => ['(exemple) Où trouver les informations de livraison et de retour ?', 'Consultez les politiques de livraison et de retour de la boutique. Contactez-la pour toute précision avant de commander.'],
                'it' => ['(esempio) Dove trovo informazioni su consegne e resi?', 'Leggi le condizioni di consegna e reso del negozio. Contatta il negozio per chiarimenti prima di ordinare.'],
                'nl' => ['(voorbeeld) Waar vind ik informatie over levering en retouren?', 'Lees het leverings- en retourbeleid van de winkel. Neem voor het bestellen contact op als u uitleg nodig heeft.'],
            ]],
            ['type' => 'category', 'text' => [
                'en' => ['(sample) How do I choose a product in this category?', 'Compare the descriptions and specifications of the available products. Check dimensions, sizing and compatibility where relevant, and ask the store if you need help.'],
                'el' => ['(δείγμα) Πώς επιλέγω προϊόν σε αυτή την κατηγορία;', 'Συγκρίνετε τις περιγραφές και τις προδιαγραφές των προϊόντων. Ελέγξτε διαστάσεις, μέγεθος και συμβατότητα όπου χρειάζεται και ζητήστε βοήθεια από το κατάστημα.'],
                'es' => ['(ejemplo) ¿Cómo elijo un producto de esta categoría?', 'Compare las descripciones y especificaciones. Compruebe dimensiones, tallas y compatibilidad cuando corresponda y pida ayuda a la tienda.'],
                'fr' => ['(exemple) Comment choisir un produit dans cette catégorie ?', 'Comparez les descriptions et les caractéristiques. Vérifiez les dimensions, les tailles et la compatibilité si nécessaire, et demandez conseil à la boutique.'],
                'it' => ['(esempio) Come scelgo un prodotto in questa categoria?', 'Confronta descrizioni e specifiche. Verifica dimensioni, taglie e compatibilità quando necessario e chiedi aiuto al negozio.'],
                'nl' => ['(voorbeeld) Hoe kies ik een product in deze categorie?', 'Vergelijk de beschrijvingen en specificaties. Controleer zo nodig afmetingen, maten en compatibiliteit en vraag de winkel om hulp.'],
            ]],
            ['type' => 'product', 'text' => [
                'en' => ['(sample) How do I check whether this product is suitable for me?', 'Review the product description, specifications and care instructions. Check the dimensions and compatibility you need, and contact the store if anything is unclear.'],
                'el' => ['(δείγμα) Πώς ελέγχω αν αυτό το προϊόν είναι κατάλληλο για εμένα;', 'Διαβάστε την περιγραφή, τις προδιαγραφές και τις οδηγίες φροντίδας. Ελέγξτε τις διαστάσεις και τη συμβατότητα που χρειάζεστε και επικοινωνήστε με το κατάστημα για τυχόν απορίες.'],
                'es' => ['(ejemplo) ¿Cómo sé si este producto es adecuado para mí?', 'Revise la descripción, las especificaciones y las instrucciones de cuidado. Compruebe dimensiones y compatibilidad y consulte cualquier duda con la tienda.'],
                'fr' => ['(exemple) Comment savoir si ce produit me convient ?', 'Lisez la description, les caractéristiques et les conseils d’entretien. Vérifiez les dimensions et la compatibilité, et contactez la boutique en cas de doute.'],
                'it' => ['(esempio) Come verifico se questo prodotto fa per me?', 'Leggi descrizione, specifiche e istruzioni di manutenzione. Controlla dimensioni e compatibilità e contatta il negozio in caso di dubbi.'],
                'nl' => ['(voorbeeld) Hoe controleer ik of dit product geschikt is voor mij?', 'Lees de productbeschrijving, specificaties en onderhoudsinstructies. Controleer afmetingen en compatibiliteit en neem bij vragen contact op met de winkel.'],
            ]],
        ];
    }
}
