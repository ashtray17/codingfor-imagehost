<?php

namespace Modules\Codingfor\Imagehost\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $chunkSizeKb = (int) ceil((int) config('codingfor-imagehost.limits.chunk_size', 16 * 1024 * 1024) / 1024);

        return [
            'chunk' => ['required', 'file', 'max:'.($chunkSizeKb + 1024)],
            'chunk_index' => ['required', 'integer', 'min:0', 'max:255'],
        ];
    }
}
