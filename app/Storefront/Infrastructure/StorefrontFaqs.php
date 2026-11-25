<?php

namespace App\Storefront\Infrastructure;

use App\Shared\Infrastructure\Content\RichTextSanitizer;
use Illuminate\Support\Facades\DB;

/** The questions and answers a product or a category shows: active ones, in the merchant's order, complete in the visitor's language (or the default). */
final class StorefrontFaqs
{
    /**
     * @param  'product'|'category'|'page'  $owner
     * @return list<array{id:string,question:string,answer:string}>
     */
    public function for(string $owner, string $id, string $locale): array
    {
        $ids = DB::table($owner.'_faqs as l')->join('faqs as f', 'f.id', '=', 'l.faq_id')->where('l.'.$owner.'_id', $id)->where('f.status', 'active')
            ->orderBy('l.position')->orderBy('l.faq_id')->pluck('f.id')->map(strval(...))->all();
        $content = app(BlockContent::class);
        $texts = $ids === [] ? [] : $content->translations('faq_translations', 'faq_id', $ids);
        $sanitizer = app(RichTextSanitizer::class);
        $out = [];
        foreach ($ids as $faq) {
            $question = $content->text($texts[$faq] ?? [], $locale, 'question');
            $answer = $content->text($texts[$faq] ?? [], $locale, 'answer');
            if ($question !== '' && $answer !== '') {
                $out[] = ['id' => $faq, 'question' => $question, 'answer' => $sanitizer->sanitize($answer)];
            }
        }

        return $out;
    }
}
