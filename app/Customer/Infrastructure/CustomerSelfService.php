<?php

namespace App\Customer\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Authorization\Infrastructure\AccessChecker;
use App\Customer\Application\Command\SaveAddressData;
use App\Geography\Application\Contract\ShippingGeography;
use App\Identity\Domain\Model\PasswordPolicy;
use App\Identity\Infrastructure\Persistence\UserRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * What a signed-in customer may change about their own account (A100). The customer is always the profile linked to
 * the authenticated identity, never an ID from the request. Writes share staff administration's customer lock, version
 * checks and address-book rules; e-mail, groups, tax exemption, status and notes stay staff-managed.
 */
final class CustomerSelfService
{
    public function __construct(private CustomerInput $input, private CustomerAddressBook $book) {}

    /** @param array<string, mixed> $input */
    public function updateProfile(UserRecord $user, int $version, array $input): void
    {
        $this->write($user, $version, function (object $customer) use ($input): void {
            $fields = [];
            foreach (['first_name', 'last_name', 'phone'] as $field) {
                $value = $input[$field] ?? null;
                $fields[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
            }
            $rules = array_intersect_key($this->input->rules([], false), $fields);
            $valid = Validator::make($fields, $rules, $this->input->messages(), ['first_name' => __('customers.first_name'), 'last_name' => __('customers.last_name'), 'phone' => __('customers.phone')])->validate();
            DB::table('customers')->where('id', $customer->id)->update(['first_name' => $valid['first_name'], 'last_name' => $valid['last_name'], 'phone' => $valid['phone'] ?? null]);
        });
    }

    /** @param array<string, mixed> $input */
    public function addAddress(UserRecord $user, int $version, array $input): void
    {
        $this->write($user, $version, function (object $customer) use ($input): void {
            $id = $this->book->insert((int) $customer->id, $this->address($input, []));
            $this->book->ensureDefaults((int) $customer->id);
            $this->chooseDefault((int) $customer->id, $id, $input);
        });
    }

    /** @param array<string, mixed> $input */
    public function updateAddress(UserRecord $user, string $addressId, int $version, array $input): void
    {
        $this->write($user, $version, function (object $customer) use ($addressId, $input): void {
            $saved = $this->book->owned((string) $customer->id, $addressId);
            $this->book->update((string) $customer->id, $addressId, $this->address($input, $this->input->savedRegion($saved)));
            $this->chooseDefault((int) $customer->id, (int) $addressId, $input);
        });
    }

    /**
     * The "default address" checkbox: the address becomes both the default billing and shipping address (checkout uses it unless changed).
     *
     * @param  array<string, mixed>  $input
     */
    private function chooseDefault(int $customerId, int $addressId, array $input): void
    {
        if (filter_var($input['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            DB::table('customers')->where('id', $customerId)->update(['default_billing_address_id' => $addressId, 'default_shipping_address_id' => $addressId]);
        }
    }

    public function deleteAddress(UserRecord $user, string $addressId, int $version): void
    {
        $this->write($user, $version, function (object $customer) use ($addressId): void {
            $this->book->owned((string) $customer->id, $addressId);
            $this->book->delete($customer, $addressId);
        });
    }

    public function makeDefault(UserRecord $user, string $addressId, int $version, string $type): void
    {
        if (! in_array($type, ['billing', 'shipping'], true)) {
            throw ValidationException::withMessages(['type' => __('account.default_invalid')]);
        }
        $this->write($user, $version, function (object $customer) use ($addressId, $type): void {
            $this->book->owned((string) $customer->id, $addressId);
            DB::table('customers')->where('id', $customer->id)->update(['default_'.$type.'_address_id' => $addressId]);
        });
    }

    /**
     * Deleting one's own account is a soft delete: the profile is disabled and stamped, and everything it owns (orders,
     * addresses, reviews, lists) stays untouched. Typing the account's e-mail address confirms the choice. Staff can bring
     * the account back by activating the profile.
     */
    public function deleteAccount(UserRecord $user, int $version, string $email): void
    {
        $this->write($user, $version, function (object $customer) use ($email): void {
            if (mb_strtolower(trim($email)) !== mb_strtolower((string) $customer->email)) {
                throw ValidationException::withMessages(['confirmation' => __('account.delete_confirmation_wrong')]);
            }
            DB::table('customers')->where('id', $customer->id)->update(['status' => 'disabled', 'deleted_at' => now()]);
        });
    }

    /**
     * The current password proves the session's owner. Identities with administration access keep the staff minimum.
     * Other sessions of the identity end on their next request (password fingerprint), this one stays signed in.
     */
    public function changePassword(UserRecord $user, #[SensitiveParameter] string $current, #[SensitiveParameter] string $password, #[SensitiveParameter] string $confirmation): void
    {
        $staff = app(AccessChecker::class)->allows($user, 'admin.access');
        $rule = $staff ? Password::defaults() : Password::min(PasswordPolicy::CUSTOMER_MIN)->max(PasswordPolicy::MAX);
        Validator::make(['current_password' => $current, 'password' => $password, 'password_confirmation' => $confirmation],
            ['current_password' => ['required', 'string', 'max:128'], 'password' => ['required', 'string', 'confirmed', $rule]], [], ['password' => __('auth.new_password')])->validate();
        if (! Hash::check($current, $user->getAuthPassword())) {
            throw ValidationException::withMessages(['current_password' => __('account.current_password_wrong')]);
        }
        DB::transaction(function () use ($user, $password): void {
            app(AuditContext::class)->actor((string) $user->getKey());
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        });
    }

    /**
     * Customers choose among the countries the store ships to (any country while none is configured yet); the region
     * comes from that country's Geography regions.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keep
     */
    private function address(array $input, array $keep): SaveAddressData
    {
        $shipping = app(ShippingGeography::class)->countries();
        $country = is_string($input['country_code'] ?? null) ? strtoupper(trim($input['country_code'])) : null;
        if ($shipping !== [] && ! in_array($country, $shipping, true)) {
            throw ValidationException::withMessages(['country_code' => __('account.country_not_shipped')]);
        }

        return $this->input->address($input, $keep);
    }

    /** One transaction under the customer lock: the identity's own active profile at the expected version. */
    private function write(UserRecord $user, int $version, callable $change): void
    {
        DB::transaction(function () use ($user, $version, $change): void {
            DB::select('SELECT pg_advisory_xact_lock(2142514)');
            app(AuditContext::class)->actor((string) $user->getKey());
            $customer = DB::table('customers')->where('user_id', $user->getKey())->lockForUpdate()->first() ?? throw new ModelNotFoundException('Customer profile not found.');
            if ($customer->status !== 'active') {
                throw ValidationException::withMessages(['account' => __('auth.customer_disabled')]);
            }
            if ((int) $customer->lock_version !== $version) {
                throw ValidationException::withMessages(['version' => __('account.stale')]);
            }
            $change($customer);
            DB::table('customers')->where('id', $customer->id)->update(['lock_version' => (int) $customer->lock_version + 1, 'updated_by' => $user->getKey(), 'updated_at' => now()]);
        });
    }

    /**
     * Countries offered to customers: the shipping countries, or null (every country) while none is configured.
     *
     * @return list<string>|null
     */
    public static function countryCodes(): ?array
    {
        $codes = app(ShippingGeography::class)->countries();

        return $codes === [] ? null : $codes;
    }
}
