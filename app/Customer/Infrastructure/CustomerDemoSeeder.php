<?php

namespace App\Customer\Infrastructure;

use App\Customer\Application\Command\SaveAddressData;
use App\Customer\Application\Command\SaveCustomerData;
use App\Customer\Application\Command\SaveCustomerGroupData;
use App\Customer\Application\Contract\CustomerAdministration;
use App\Customer\Application\Contract\CustomerBlacklistAdministration;
use App\Customer\Application\Contract\CustomerGroupAdministration;
use App\Geography\Application\Contract\ShippingGeography;
use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Local sample customers with Greek addresses. All records are fictitious and use the reserved example.test domain.
 * Explicit opt-in only, never in production. Records are tracked by stable keys in the shared demo-record table, so
 * reruns create nothing and keep administrator edits.
 */
final class CustomerDemoSeeder
{
    /** @return array{groups: int, customers: int, blacklist: int} Records created by this run. */
    public function run(string $actor): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Customer demo seeding is restricted to local and testing environments.');
        }

        return DB::transaction(function () use ($actor): array {
            $created = ['groups' => 0, 'customers' => 0, 'blacklist' => 0];
            $groups = app(CustomerGroupAdministration::class);
            $customers = app(CustomerAdministration::class);
            // A merchant's own default group is respected: the sample "Retail" group is only the default when none exists.
            $hasDefault = DB::table('customer_groups')->where('is_default', true)->exists();
            $retail = $this->record('group.retail', 'customer_groups', $created, 'groups', fn (): string => $groups->create($actor, new SaveCustomerGroupData(
                $this->texts('Retail (sample)', 'Λιανική (δείγμα)'), [], 'active', ! $hasDefault,
            )));
            $vip = $this->record('group.vip', 'customer_groups', $created, 'groups', fn (): string => $groups->create($actor, new SaveCustomerGroupData(
                $this->texts('VIP (sample)', 'VIP (δείγμα)'), [], 'active', false,
            )));
            $wholesale = $this->record('group.wholesale', 'customer_groups', $created, 'groups', fn (): string => $groups->create($actor, new SaveCustomerGroupData(
                $this->texts('Wholesale (sample)', 'Χονδρική (δείγμα)'), [], 'active', false,
            )));

            // [key, first, last, email, phone, exempt, groups, address (label, line1, city, postal, country, region), disabled]
            $people = [
                ['maria', 'Μαρία', 'Παπαδοπούλου', 'maria.papadopoulou@example.test', '+30 210 123 4567', false, [$retail], ['Σπίτι', 'Ερμού 12', 'Αθήνα', '10563', 'GR', 'I']],
                ['nikos', 'Νίκος', 'Γεωργίου', 'nikos.georgiou@example.test', '+30 2310 555 010', false, [$retail, $vip], ['Γραφείο', 'Εγνατία 45', 'Θεσσαλονίκη', '54630', 'GR', 'B']],
                ['eleni', 'Ελένη', 'Κωνσταντίνου', 'eleni.konstantinou@example.test', '+30 22510 44 120', false, [$retail], ['Σπίτι', 'Παλαιολόγου 8', 'Μυτιλήνη', '81100', 'GR', 'K']],
                ['dimitris', 'Δημήτρης', 'Αλεξίου', 'dimitris.alexiou@example.test', null, false, [$wholesale], ['Αποθήκη', 'Βιομηχανική Περιοχή 3', 'Πάτρα', '26500', 'GR', 'G']],
                ['sofia', 'Σοφία', 'Ιωάννου', 'sofia.ioannou@example.test', '+30 28210 33 777', false, [$vip], ['Σπίτι', 'Καλλέργων 21', 'Χανιά', '73100', 'GR', 'M']],
                ['andreas', 'Andreas', 'Charalambous', 'andreas.charalambous@example.test', '+357 22 123456', true, [$wholesale], ['Office', 'Makariou III 5', 'Nicosia', '1065', 'CY', null]],
                ['katerina', 'Κατερίνα', 'Βλάχου', 'katerina.vlachou@example.test', null, false, [], null],
                ['giorgos', 'Γιώργος', 'Μαρκόπουλος', 'giorgos.markopoulos@example.test', '+30 6900 000 000', false, [$retail], ['Σπίτι', 'Αγίου Δημητρίου 7', 'Ηράκλειο', '71201', 'GR', 'M'], true],
            ];
            // Sample regions are used only where this store's Geography lists them (A100); otherwise none.
            $regions = app(ShippingGeography::class)->regionOptions();
            foreach ($people as $person) {
                [$key, $first, $last, $email, $phone, $exempt, $memberships, $address] = $person;
                $disabled = $person[8] ?? false;
                $this->record('customer.'.$key, 'customers', $created, 'customers', function () use ($actor, $customers, $first, $last, $email, $phone, $exempt, $memberships, $address, $disabled, $regions): string {
                    $id = $customers->create($actor, new SaveCustomerData($first, $last, $email, $phone, $exempt, null, $memberships, address: $address === null ? null
                        : new SaveAddressData($address[0], $first, $last, null, $address[1], null, $address[2], $address[3], $address[4], in_array($address[5], array_column($regions[$address[4]] ?? [], 'code'), true) ? $address[5] : null, null)));
                    if ($disabled) {
                        $customers->changeStatus($actor, $id, 1, 'disabled');
                    }

                    return $id;
                });
            }

            $this->blacklist($actor, $created);

            return $created;
        });
    }

    /**
     * Sample blacklist entries: one linked to the disabled sample customer, the rest manual. Reasons are fictitious.
     *
     * @param  array<string, int>  $created
     */
    private function blacklist(string $actor, array &$created): void
    {
        $blacklist = app(CustomerBlacklistAdministration::class);
        $entries = [
            ['giannis', 'email', 'giannis.k@example.test', 'Επιστροφή χρημάτων με κατάχρηση (chargeback fraud)'],
            ['phone142', 'phone', '+30 690 000 0142', 'Επαναλαμβανόμενες ψευδείς παραγγελίες'],
            ['maria', 'email', 'maria.p@example.test', 'Απειλητικά μηνύματα προς το προσωπικό'],
            ['orders', 'email', 'orders@example.test', 'Εταιρικό email που χρησιμοποιήθηκε για αποφυγή αποκλεισμού'],
            ['phone377', 'phone', '+30 690 000 0377', 'Λάθος καταχώριση'],
            ['bot', 'email', 'test123@example.test', 'Δοκιμαστικές παραγγελίες από bot'],
            ['phone518', 'phone', '+30 690 000 0518', 'Άρνηση παραλαβής σε 3 παραγγελίες'],
        ];
        foreach ($entries as [$key, $kind, $value, $reason]) {
            $this->entry('blacklist.'.$key, $created, fn (): string => $blacklist->add($actor, $kind, $value, $reason));
        }
        // Linked to the disabled sample customer, so the customer page and list show the flag.
        $customer = DB::table('feature_demo_records')->where('key', 'phase9.customer.giorgos')->value('entity_id');
        if ($customer !== null) {
            $this->entry('blacklist.giorgos', $created, fn (): string => $blacklist->addForCustomer($actor, (string) $customer, ['email'], 'Πολλαπλοί λογαριασμοί για κατάχρηση κουπονιών')[0]);
        }
    }

    /**
     * @param  array<string, int>  $created
     * @param  callable(): string  $create
     */
    private function entry(string $key, array &$created, callable $create): void
    {
        $key = 'phase9.'.$key;
        if (DB::table('feature_demo_records')->where('key', $key)->exists()) {
            return;
        }
        try {
            $id = $create();
        } catch (ValidationException) {
            // Already blacklisted by the merchant: leave it untouched and do not claim it.
            return;
        }
        // The shared mapping table keeps entity names short.
        DB::table('feature_demo_records')->insert(['key' => $key, 'entity' => 'blacklist', 'entity_id' => $id]);
        $created['blacklist']++;
    }

    /** @return array<string, string> English text for every active language except Greek. */
    private function texts(string $en, string $el): array
    {
        $names = [];
        foreach (app(LocaleCatalog::class)->active()->pluck('code') as $code) {
            $names[$code] = $code === 'el' ? $el : $en;
        }

        return $names;
    }

    /**
     * @param  array<string, int>  $created
     * @param  callable(): string  $create
     */
    private function record(string $key, string $table, array &$created, string $counter, callable $create): string
    {
        $key = 'phase9.'.$key;
        $existing = DB::table('feature_demo_records')->where('key', $key)->first();
        if ($existing !== null) {
            if ($existing->entity !== $table || ! DB::table($table)->where('id', $existing->entity_id)->exists()) {
                throw new RuntimeException('Demo dependency was removed: '.$key.'. No data was changed.');
            }

            return (string) $existing->entity_id;
        }
        $id = $create();
        DB::table('feature_demo_records')->insert(['key' => $key, 'entity' => $table, 'entity_id' => $id]);
        $created[$counter]++;

        return $id;
    }
}
