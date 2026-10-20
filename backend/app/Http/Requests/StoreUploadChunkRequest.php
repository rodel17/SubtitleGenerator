<?php

namespace App\Http\Requests;

use App\Models\Upload;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreUploadChunkRequest extends FormRequest
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
        $upload = $this->upload();

        return [
            'index' => ['required', 'integer', 'min:0', 'max:'.($upload->total_chunks - 1)],
            'chunk' => ['required', 'file'],
        ];
    }

    /**
     * Each chunk must be exactly the size the server asked for, which catches
     * truncated requests before they corrupt the assembled file.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['index', 'chunk'])) {
                    return;
                }

                $expected = $this->upload()->expectedChunkSize($this->integer('index'));
                $actual = $this->file('chunk')->getSize();

                if ($actual !== $expected) {
                    $validator->errors()->add('chunk', "Chunk must be {$expected} bytes, got {$actual}.");
                }
            },
        ];
    }

    private function upload(): Upload
    {
        return $this->route('upload');
    }
}
