<?php

namespace App\Http\Requests\Pixelforge;

use App\Services\Pixelforge\PixelforgeWebhookSignature;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PixelforgeWebhookRequest extends FormRequest
{
    /**
     * Webhooks are not user-authenticated; the HMAC signature over the raw body is the authorization.
     */
    public function authorize(PixelforgeWebhookSignature $signature): bool
    {
        return $signature->verify($this->getContent(), $this->header('Pixelforge-Signature'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'max:100'],
            'data' => ['nullable', 'array'],
        ];
    }
}
