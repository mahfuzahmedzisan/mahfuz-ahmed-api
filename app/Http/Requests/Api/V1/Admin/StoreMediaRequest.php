<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\AllowedMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'mime' => ['required', 'string', 'max:255'],
            'alt' => ['nullable', 'string', 'max:300'],
            'keywords' => ['nullable', 'array', 'max:30'],
            'keywords.*' => ['string', 'max:40'],
            'file' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $reason = AllowedMedia::rejectionReason(
                $this->string('filename')->toString(),
                $this->string('mime')->toString(),
                $this->integer('size'),
            );

            if ($reason !== null) {
                $validator->errors()->add('filename', $reason);
            }
        });
    }
}
