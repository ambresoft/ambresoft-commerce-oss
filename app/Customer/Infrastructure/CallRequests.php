<?php

namespace App\Customer\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Customer\Domain\Model\CallRequestStatus;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Notification\Infrastructure\AdminNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CallRequests
{
    /** A visitor can provide contact details only; staff workflow fields are always server-owned.
     * @param array<string, mixed> $input */
    public function submit(array $input): void
    {
        $times = Validator::make($input, [
            'call_from' => ['nullable', 'required_with:call_to', 'date_format:H:i'],
            'call_to' => ['nullable', 'required_with:call_from', 'date_format:H:i', 'after:call_from'],
        ], [], ['call_from' => __('storefront.call_request_from'), 'call_to' => __('storefront.call_request_to')])->validate();
        $time = empty($times['call_from']) ? null : $times['call_from'].'–'.$times['call_to'];
        $fields = $this->validated([...array_intersect_key($input, array_flip(['name', 'phone', 'order_number'])), 'convenient_time' => $time], true);
        DB::transaction(function () use ($fields): void {
            $number = $fields['order_number'] ?? null;
            // Keep the caller's reference even if mistyped, without revealing whether an order exists.
            $order = $number === null ? null : DB::table('orders')->where('order_number', $number)->sharedLock()->first(['id']);
            $id = DB::table('call_requests')->insertGetId([
                ...$fields, 'order_id' => $order?->id, 'phone_digits' => ContactNormalizer::phone($fields['phone']),
                'status' => CallRequestStatus::New->value, 'created_at' => now(), 'updated_at' => now(),
            ]);
            // The name is optional; the phone always identifies the caller.
            app(AdminNotifications::class)->callRequested((string) $id, (string) (($fields['name'] ?? '') ?: $fields['phone']));
        });
    }

    /** @param array<string, mixed> $input */
    public function save(string $actor, ?string $id, array $input): string
    {
        return app(AuditLogger::class)->operation('call_request', $actor, fn (): string => DB::transaction(function () use ($actor, $id, $input): string {
            $this->authorize($actor, 'call-requests.manage');
            $version = $id === null ? null : Validator::make($input, ['version' => ['required', 'integer', 'min:1']])->validate()['version'];
            $old = $id === null ? null : $this->row($id, (int) $version);
            $fields = $this->validated($input, $id === null);
            if ($old !== null && array_key_exists('products', $fields)) {
                throw ValidationException::withMessages(['products' => __('calls.products_immutable')]);
            }
            if (array_key_exists('responsible_id', $fields)) {
                $responsible = $fields['responsible_id'] === null ? null : (string) $fields['responsible_id'];
                if ($responsible !== null && $responsible !== (string) ($old->responsible_id ?? '')) {
                    $user = UserRecord::query()->sharedLock()->find($responsible);
                    if ($user === null || ! $this->assignable($user)) {
                        throw ValidationException::withMessages(['responsible_id' => __('calls.responsible_invalid')]);
                    }
                }
            }
            $values = array_intersect_key($fields, array_flip(['name', 'phone', 'convenient_time', 'notes', 'status', 'responsible_id']));
            if (array_key_exists('phone', $values)) {
                $values['phone_digits'] = ContactNormalizer::phone($values['phone']);
            }
            if (array_key_exists('order_number', $fields)) {
                $number = $fields['order_number'] === null ? null : (string) $fields['order_number'];
                if ($number === null) {
                    $values['order_id'] = $values['order_number'] = null;
                } elseif ($number !== (string) ($old->order_number ?? '')) {
                    $order = DB::table('orders')->where('order_number', $number)->sharedLock()->first(['id', 'order_number']);
                    if ($order === null) {
                        throw ValidationException::withMessages(['order_number' => __('calls.order_invalid')]);
                    }
                    $values['order_id'] = $order->id;
                    $values['order_number'] = $order->order_number;
                }
            }
            $values = [...$values, 'updated_by' => $actor, 'updated_at' => now()];
            if ($id === null) {
                $id = (string) DB::table('call_requests')->insertGetId([...$values, 'created_by' => $actor, 'created_at' => now()]);
                $ids = array_map(strval(...), array_column($fields['products'] ?? [], 'product_id'));
                DB::table('products')->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id']);
                $products = app(CallRequestQueries::class)->products($ids);
                foreach ($fields['products'] ?? [] as $position => $context) {
                    $product = $products[(string) $context['product_id']] ?? null;
                    if ($product === null) {
                        throw ValidationException::withMessages(['products.'.$position.'.product_id' => __('calls.product_invalid')]);
                    }
                    DB::table('call_request_products')->insert(['call_request_id' => $id, 'product_id' => $product['id'], 'name' => $product['name'], 'sku' => $product['sku'], 'quantity' => $context['quantity'], 'position' => $position + 1]);
                }
            } else {
                DB::table('call_requests')->where('id', $id)->update([...$values, 'lock_version' => (int) $old->lock_version + 1]);
            }

            return $id;
        }));
    }

    /** @param list<array{id: string, version: int}> $rows */
    public function delete(string $actor, array $rows): void
    {
        app(AuditLogger::class)->operation('call_request', $actor, fn () => DB::transaction(function () use ($actor, $rows): void {
            $this->authorize($actor, 'call-requests.delete');
            $data = Validator::make(['rows' => $rows], ['rows' => ['required', 'array', 'min:1', 'max:100'], 'rows.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'rows.*.version' => ['required', 'integer', 'min:1']])->validate();
            usort($data['rows'], fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
            foreach ($data['rows'] as $row) {
                $this->row((string) $row['id'], (int) $row['version']);
            }
            DB::table('call_requests')->whereIn('id', array_column($data['rows'], 'id'))->delete();
        }));
    }

    public function assignable(UserRecord $user): bool
    {
        return Gate::forUser($user)->allows('admin.access') && Gate::forUser($user)->allows('call-requests.view') && Gate::forUser($user)->allows('call-requests.manage');
    }

    private function row(string $id, int $version): object
    {
        $row = DB::table('call_requests')->where('id', $id)->lockForUpdate()->first();
        if ($row === null || (int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('calls.stale')]);
        }

        return $row;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validated(array $input, bool $creating): array
    {
        foreach (['name', 'phone', 'notes', 'convenient_time', 'responsible_id', 'order_number'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
                if ($input[$field] === '') {
                    $input[$field] = null;
                }
            }
        }
        $plain = 'not_regex:/[\x00-\x1f\x7f]/';
        $rules = ['name' => ['sometimes', 'nullable', 'string', 'max:200', $plain], 'phone' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:40', app(CustomerInput::class)->phoneRule()], 'convenient_time' => ['sometimes', 'nullable', 'string', 'max:200', $plain], 'notes' => ['sometimes', 'nullable', 'string', 'max:10000', 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/'], 'status' => ['sometimes', 'required', Rule::in(CallRequestStatus::values())], 'responsible_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'order_number' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9223372036854775807'], 'products' => ['sometimes', 'array', 'max:100'], 'products.*.product_id' => ['required', 'integer', 'min:1', 'distinct'], 'products.*.quantity' => ['required', 'integer', 'between:1,1000000']];
        $attributes = [];
        foreach (['name', 'phone', 'convenient_time', 'notes', 'status', 'responsible_id', 'order_number', 'products'] as $field) {
            $attributes[$field] = __('calls.'.$field);
        }
        $fields = Validator::make($input, $rules, ['phone.regex' => __('customers.phone_invalid')], $attributes)->validate();
        if (array_key_exists('phone', $fields) && ContactNormalizer::phone($fields['phone']) === null) {
            throw ValidationException::withMessages(['phone' => __('customers.phone_invalid')]);
        }
        if (! $creating && array_intersect(array_keys($fields), ['name', 'phone', 'convenient_time', 'notes', 'status', 'responsible_id', 'order_number']) === []) {
            throw ValidationException::withMessages(['request' => __('calls.no_changes')]);
        }

        return $fields;
    }

    private function authorize(string $actor, string $ability): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize($ability);
    }
}
