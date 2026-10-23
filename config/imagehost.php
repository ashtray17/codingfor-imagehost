<?php

return [
    // Optional public origin for generated image URLs, e.g. https://photo.60-60.net.
    'public_url' => env('CODINGFOR_IMAGEHOST_PUBLIC_URL', ''),
    'vipsheader_binary' => env('CODINGFOR_IMAGEHOST_VIPSHEADER', 'vipsheader'),
    'vips_binary' => env('CODINGFOR_IMAGEHOST_VIPS', 'vips'),
    'limits' => [
        'guest_image_max_bytes' => (int) env('CODINGFOR_IMAGEHOST_GUEST_IMAGE_MAX_BYTES', 50 * 1024 * 1024),
        'member_image_max_bytes' => (int) env('CODINGFOR_IMAGEHOST_MEMBER_IMAGE_MAX_BYTES', 250 * 1024 * 1024),
        'guest_image_max_pixels' => (int) env('CODINGFOR_IMAGEHOST_GUEST_IMAGE_MAX_PIXELS', 100_000_000),
        'member_image_max_pixels' => (int) env('CODINGFOR_IMAGEHOST_MEMBER_IMAGE_MAX_PIXELS', 250_000_000),
        'chunk_size' => (int) env('CODINGFOR_IMAGEHOST_CHUNK_SIZE', 16 * 1024 * 1024),
        'max_chunks' => (int) env('CODINGFOR_IMAGEHOST_MAX_CHUNKS', 256),
    ],
];
