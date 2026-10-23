<?php

namespace Modules\Codingfor\Imagehost;

use App\Extension\AbstractModule;

class Module extends AbstractModule
{
    public function getPermissions(): array
    {
        return [
            'name' => [
                'ko' => '이미지 호스팅',
                'en' => 'Image Host',
            ],
            'description' => [
                    'ko' => '이미지 자산 관리 권한',
                    'en' => 'Image asset management permissions',
            ],
            'categories' => [
                [
                    'identifier' => 'assets',
                    'resource_route_key' => 'image',
                    'owner_key' => 'created_by',
                    'name' => [
                        'ko' => '이미지 자산',
                        'en' => 'Image Assets',
                    ],
                    'description' => [
                        'ko' => '업로드된 이미지 자산 관리',
                        'en' => 'Manage uploaded image assets',
                    ],
                    'permissions' => [
                        [
                            'action' => 'read',
                            'name' => ['ko' => '이미지 조회', 'en' => 'View images'],
                            'description' => ['ko' => '이미지 자산을 조회합니다.', 'en' => 'View image assets.'],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                        [
                            'action' => 'delete',
                            'name' => ['ko' => '이미지 삭제', 'en' => 'Delete images'],
                            'description' => ['ko' => '이미지 자산을 삭제합니다.', 'en' => 'Delete image assets.'],
                            'type' => 'admin',
                            'roles' => ['admin', 'manager'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function getSchedules(): array
    {
        return [
            [
                'command' => 'codingfor-imagehost:prune-temp',
                'schedule' => 'daily',
                'description' => 'Clean up stale image-host uploads and deleted assets',
            ],
        ];
    }
}
