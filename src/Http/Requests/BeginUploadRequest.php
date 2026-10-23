<?php

namespace Modules\Codingfor\Imagehost\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BeginUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:100'],
            'size' => ['required', 'integer', 'min:1'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:256'],
            'batch_uuid' => ['nullable', 'uuid'],
        ];
    }
}
