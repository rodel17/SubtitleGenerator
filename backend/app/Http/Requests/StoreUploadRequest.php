<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreUploadRequest extends FormRequest
{
    /**
     * Uploads are public until authentication is added.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.config('uploads.max_size')],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'last_modified' => ['nullable', 'integer', 'min:0'],
            'language' => ['nullable', 'string', 'max:10'],
            'subtitle_format' => ['required', Rule::in(['srt', 'vtt', 'both'])],
            'email_when_ready' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('name')) {
                    return;
                }

                $extension = strtolower(pathinfo($this->string('name')->toString(), PATHINFO_EXTENSION));
                $allowed = config('uploads.allowed_extensions');

                if (! in_array($extension, $allowed, true)) {
                    $validator->errors()->add(
                        'name',
                        'Unsupported format. Allowed: '.strtoupper(implode(', ', $allowed)).'.',
                    );
                }
            },
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'size.max' => 'The file is larger than the '.$this->maxSizeLabel().' limit.',
        ];
    }

    /**
     * The upload limit for messages, e.g. "500 MB" or "2 GB".
     */
    private function maxSizeLabel(): string
    {
        $bytes = config('uploads.max_size');

        return $bytes >= 1024 ** 3
            ? round($bytes / 1024 ** 3, 1).' GB'
            : round($bytes / 1024 ** 2, 1).' MB';
    }
}
