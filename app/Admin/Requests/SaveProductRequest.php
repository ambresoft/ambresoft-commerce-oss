<?php

namespace App\Admin\Requests;

use App\Catalog\Infrastructure\ProductInput;
use Illuminate\Foundation\Http\FormRequest;

final class SaveProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.update') ?? false;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return app(ProductInput::class)->attributes();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...app(ProductInput::class)->rules(), 'image_uploads' => ['sometimes', 'array', 'max:10'], 'image_uploads.*' => ['file', 'max:5120'], 'file_uploads' => ['sometimes', 'array', 'max:10'], 'file_uploads.*' => ['file', 'max:'.config('products.private_files.max_kilobytes')]];
    }
}
