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
     * Limits (4,000 prompt characters, 4 reference images) come from the Pixelforge API reference.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', 'string', Rule::in(config('pixelforge.sizes'))],
            'quality' => ['required', 'string', Rule::in(config('pixelforge.qualities'))],
            'reference_image_urls' => ['nullable', 'array', 'max:4'],
            'reference_image_urls.*' => ['string', 'url:http,https'],
        ];
    }
}
