<?php

namespace Modules\Codingfor\Imagehost\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxSizeMb = max(1, (int) floor((int) config('codingfor-imagehost.limits.guest_image_max_bytes', 50 * 1024 * 1024) / 1024 / 1024));

        return [
            'file' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.($maxSizeMb * 1024),
            ],
        ];
    }

    public function messages(): array
    {
        $maxSizeMb = max(1, (int) floor((int) config('codingfor-imagehost.limits.guest_image_max_bytes', 50 * 1024 * 1024) / 1024 / 1024));

        return [
            'file.required' => __('codingfor-imagehost::validation.file_required'),
            'file.file' => __('codingfor-imagehost::validation.file_invalid'),
            'file.image' => __('codingfor-imagehost::validation.file_invalid'),
            'file.mimes' => __('codingfor-imagehost::validation.file_mimes'),
            'file.max' => __('codingfor-imagehost::validation.file_max', ['maxMB' => $maxSizeMb]),
        ];
    }
}
