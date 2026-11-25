<?php

namespace App\Website\Infrastructure;

use App\Audit\Infrastructure\AuditContext;
use App\Audit\Infrastructure\AuditLogger;
use App\Identity\Infrastructure\Persistence\UserRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use App\Shared\Infrastructure\Content\RichTextSanitizer;
use App\Website\Application\Command\SaveFaqData;
use App\Website\Domain\Service\FaqRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FaqAdministration
{
    public function save(string $actor, ?string $id, SaveFaqData $data): string
    {
        return app(AuditLogger::class)->operation('faq', $actor, fn (): string => DB::transaction(function () use ($actor, $id, $data): string {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            $row = $id === null ? null : $this->row($id, (int) $data->version);
            if ($row !== null && $row->type !== $data->type && app(FaqLinks::class)->usage($id) > 0) {
                throw ValidationException::withMessages(['type' => __('faqs.type_linked')]);
            }
            $values = ['type' => $data->type, 'status' => $data->status, 'position' => $data->position];
            Validator::make($values, ['type' => ['required', Rule::in(FaqRules::TYPES)], 'status' => ['required', Rule::in(FaqRules::STATUSES)], 'position' => ['integer', 'between:0,2147483647']])->validate();
            $translations = $this->translations($data->translations);
            if ($id === null) {
                $id = (string) DB::table('faqs')->insertGetId([...$values, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('faqs')->where('id', $id)->update([...$values, 'updated_by' => $actor, 'updated_at' => now(), 'lock_version' => (int) $row->lock_version + 1]);
            }
            foreach (app(LocaleCatalog::class)->active() as $locale) {
                DB::table('faq_translations')->updateOrInsert(['faq_id' => $id, 'locale_id' => $locale->id], $translations[$locale->code]);
            }

            return $id;
        }));
    }

    /** @param array<string, mixed> $input
     * @return array<string, array{question: string, answer: string}>
     */
    public function translations(array $input): array
    {
        $codes = app(LocaleCatalog::class)->active()->pluck('code')->all();
        $rules = ['translations' => ['required', 'array:'.implode(',', $codes)]];
        foreach ($codes as $code) {
            $rules['translations.'.$code] = ['required', 'array:question,answer'];
            $rules['translations.'.$code.'.question'] = ['required', 'string', 'max:'.FaqRules::QUESTION_MAX, 'not_regex:/[\x00-\x1f\x7f]/'];
            $rules['translations.'.$code.'.answer'] = ['required', 'string', 'max:'.FaqRules::ANSWER_MAX];
        }
        $valid = Validator::make(['translations' => $input], $rules)->validate()['translations'];
        foreach ($valid as $code => &$text) {
            $text['question'] = trim($text['question']);
            $text['answer'] = app(RichTextSanitizer::class)->sanitize($text['answer']);
            if (trim(html_entity_decode(strip_tags($text['answer']), ENT_QUOTES | ENT_HTML5)) === '') {
                throw ValidationException::withMessages(['translations.'.$code.'.answer' => __('faqs.answer_required')]);
            }
        }

        return $valid;
    }

    public function delete(string $actor, string $id, int $version): void
    {
        app(AuditLogger::class)->operation('faq', $actor, fn () => DB::transaction(function () use ($actor, $id, $version): void {
            $this->authorize($actor);
            DB::select('SELECT pg_advisory_xact_lock(2142513)');
            $this->row($id, $version);
            if (app(FaqLinks::class)->usage($id) > 0) {
                throw ValidationException::withMessages(['faq' => __('faqs.delete_linked')]);
            }
            DB::table('faqs')->where('id', $id)->delete();
        }));
    }

    private function row(string $id, int $version): object
    {
        $row = DB::table('faqs')->where('id', $id)->lockForUpdate()->first();
        if ($row === null || (int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['version' => __('faqs.stale')]);
        }

        return $row;
    }

    private function authorize(string $actor): void
    {
        app(AuditContext::class)->actor($actor);
        Gate::forUser(UserRecord::findOrFail($actor))->authorize('faqs.manage');
    }
}
