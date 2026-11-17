<?php

namespace App\Http\Requests\Generations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A live estimate while the user fills in the form: the prompt may still be empty, and only the
 * number of reference images matters, not whether the URLs are valid yet.
 */
class GenerationEstimateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['nullable', 'string', 'max:'.config('pixelforge.max_prompt_length')],
            'size' => ['required', 'string', Rule::in(config('pixelforge.sizes'))],
            'quality' => ['required', 'string', Rule::in(config('pixelforge.qualities'))],
            'reference_image_count' => ['nullable', 'integer', 'min:0', 'max:'.config('pixelforge.max_reference_images')],
        ];
    }
}
