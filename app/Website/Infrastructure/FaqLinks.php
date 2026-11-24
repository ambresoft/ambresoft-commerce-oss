<?php

namespace App\Website\Infrastructure;

use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Website\Application\Command\SaveFaqData;
use App\Website\Domain\Service\FaqRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Entity owners invoke this inside their authorized, versioned transaction under the Catalog lock. */
final class FaqLinks
{
    /** @return list<string> */
    public function assigned(string $owner, string $id): array
    {
        return DB::table($owner.'_faqs')->where($owner.'_id', $id)->orderBy('position')->orderBy('faq_id')->pluck('faq_id')->map(strval(...))->all();
    }

    public function usage(string $faq): int
    {
        return array_sum(array_map(fn (string $owner): int => DB::table($owner.'_faqs')->where('faq_id', $faq)->count(), ['category', 'product', 'page']));
    }

    /** @param array<int, mixed> $ids
     * @param  array<int, mixed>  $drafts  Reviewed AI entries; created only when the owner is saved successfully.
     */
    public function sync(string $owner, string $id, array $ids, string $actor, array $drafts = []): void
    {
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('faqs.view');
        DB::select('SELECT pg_advisory_xact_lock(2142513)');
        Validator::make(['faq_ids' => $ids, 'faq_drafts' => $drafts], [
            'faq_ids' => ['array', 'max:100'], 'faq_ids.*' => ['integer', 'min:1', 'distinct'],
            'faq_drafts' => ['array', 'max:10'], 'faq_drafts.*' => ['array:translations'], 'faq_drafts.*.translations' => ['required', 'array'],
        ])->validate();
        $ids = array_map(strval(...), $ids);
        $current = $this->assigned($owner, $id);
        $rows = DB::table('faqs')->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $faq) {
            $row = $rows->get($faq);
            if ($row === null || ! FaqRules::supports($row->type, $owner) || ($row->status !== 'active' && ! in_array($faq, $current, true))) {
                throw ValidationException::withMessages(['faq_ids' => __('faqs.link_invalid')]);
            }
        }
        foreach ($drafts as $index => $draft) {
            try {
                $faq = app(FaqAdministration::class)->save($actor, null, new SaveFaqData($owner === 'page' ? 'general' : $owner, 'active', 0, $draft['translations']));
                $ids[] = $faq;
                $rows->put($faq, DB::table('faqs')->where('id', $faq)->first());
            } catch (ValidationException $failure) {
                throw ValidationException::withMessages(collect($failure->errors())->mapWithKeys(fn ($messages, $field): array => ['faq_drafts.'.$index.'.'.$field => $messages])->all());
            }
        }
        if (count($ids) > 100) {
            throw ValidationException::withMessages(['faq_ids' => __('faqs.link_limit')]);
        }
        DB::table($owner.'_faqs')->where($owner.'_id', $id)->whereNotIn('faq_id', $ids)->delete();
        foreach ($ids as $position => $faq) {
            DB::table($owner.'_faqs')->updateOrInsert([$owner.'_id' => $id, 'faq_id' => $faq], ['faq_type' => $rows->get($faq)->type, 'position' => $position + 1]);
        }
    }

    public function copyProduct(string $from, string $to): void
    {
        $rows = DB::table('product_faqs')->where('product_id', $from)->orderBy('position')->get();
        foreach ($rows as $row) {
            DB::table('product_faqs')->insert(['product_id' => $to, 'faq_id' => $row->faq_id, 'faq_type' => $row->faq_type, 'position' => $row->position]);
        }
    }
}
