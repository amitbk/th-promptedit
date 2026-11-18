<?php

namespace App\Http\Requests\Generations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Limits come from the Pixelforge API reference, via config/pixelforge.php.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.config('pixelforge.max_prompt_length')],
            'size' => ['required', 'string', Rule::in(config('pixelforge.sizes'))],
            'quality' => ['required', 'string', Rule::in(config('pixelforge.qualities'))],
            'reference_image_urls' => ['nullable', 'array', 'max:'.config('pixelforge.max_reference_images')],
            'reference_image_urls.*' => ['string', 'url:http,https'],
        ];
    }

    /**
     * Readable names for error messages, instead of "reference_image_urls.0".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reference_image_urls' => 'reference images',
            'reference_image_urls.*' => 'reference image URL',
        ];
    }
}
