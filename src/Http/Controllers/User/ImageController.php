<?php

namespace Modules\Codingfor\Imagehost\Http\Controllers\User;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Codingfor\Imagehost\Http\Requests\BeginUploadRequest;
use Modules\Codingfor\Imagehost\Http\Requests\UploadImageRequest;
use Modules\Codingfor\Imagehost\Http\Requests\UploadChunkRequest;
use Modules\Codingfor\Imagehost\Services\ImageHostService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImageController extends PublicBaseController
{
    private const GUEST_TOKEN_COOKIE = 'codingfor_imagehost_guest';

    private const GUEST_TOKEN_LENGTH = 64;

    public function __construct(private ImageHostService $service)
    {
        parent::__construct();
    }

    public function index(Request $request): JsonResponse
    {
        $owner = $this->resolveOwner($request);

        return $this->success(
            'codingfor-imagehost::messages.list_success',
            ['data' => $owner['user_id'] === null && $owner['guest_token_hash'] === null
                ? []
                : $this->service->recentForOwner($owner['user_id'], $owner['guest_token_hash'])]
        );
    }

    public function upload(UploadImageRequest $request): JsonResponse
    {
        $owner = $this->resolveOwner($request, true);
        $asset = $this->service->upload(
            $request->file('file'),
            $owner['user_id'],
            $owner['guest_token_hash'],
        );

        $response = $this->success(
            'codingfor-imagehost::messages.upload_success',
            ['data' => $this->service->payload($asset)],
            202
        );

        return $this->withGuestCookie($response, $owner['guest_token']);
    }

    public function begin(BeginUploadRequest $request): JsonResponse
    {
        $asset = $this->service->beginUpload(
            (int) Auth::id(),
            (string) $request->string('filename'),
            (string) $request->string('mime_type'),
            (int) $request->integer('size'),
            (int) $request->integer('total_chunks'),
            $request->input('batch_uuid'),
        );

        return $this->success(
            'codingfor-imagehost::messages.upload_session_started',
            ['data' => $this->service->payload($asset)],
            201,
        );
    }

    public function chunk(UploadChunkRequest $request, int $id): JsonResponse
    {
        $owner = $this->resolveOwner($request);
        $asset = $this->service->findForOwner($id, $owner['user_id'], $owner['guest_token_hash']);
        if (! $asset || $owner['user_id'] === null) {
            return $this->notFound('codingfor-imagehost::messages.not_found');
        }

        $asset = $this->service->appendChunk($asset, $request->file('chunk'), (int) $request->integer('chunk_index'));

        return $this->success(
            'codingfor-imagehost::messages.chunk_received',
            ['data' => $this->service->payload($asset)],
        );
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $owner = $this->resolveOwner($request);
        $asset = $this->service->findForOwner($id, $owner['user_id'], $owner['guest_token_hash']);
        if (! $asset || $owner['user_id'] === null) {
            return $this->notFound('codingfor-imagehost::messages.not_found');
        }

        $asset = $this->service->completeUpload($asset);

        return $this->success(
            'codingfor-imagehost::messages.upload_queued',
            ['data' => $this->service->payload($asset)],
            202,
        );
    }

    public function batchStatus(Request $request): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn (string $id): int => (int) trim($id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->take(100)
            ->values()
            ->all();
        $owner = $this->resolveOwner($request);

        return $this->success(
            'codingfor-imagehost::messages.status_success',
            ['data' => $this->service->batchForOwner($ids, $owner['user_id'], $owner['guest_token_hash'])],
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $owner = $this->resolveOwner($request);
        $asset = $this->service->findForOwner($id, $owner['user_id'], $owner['guest_token_hash']);

        if (! $asset) {
            return $this->notFound('codingfor-imagehost::messages.not_found');
        }

        return $this->success(
            'codingfor-imagehost::messages.status_success',
            ['data' => $this->service->payload($asset)]
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $owner = $this->resolveOwner($request);
        $asset = $this->service->findForOwner($id, $owner['user_id'], $owner['guest_token_hash']);

        if (! $asset) {
            return $this->notFound('codingfor-imagehost::messages.not_found');
        }

        $this->service->delete($asset);

        return $this->success('codingfor-imagehost::messages.delete_success');
    }

    public function serveDisplay(string $hash): StreamedResponse|Response|JsonResponse
    {
        return $this->serve($hash, 'display');
    }

    public function serveThumb(string $hash): StreamedResponse|Response|JsonResponse
    {
        return $this->serve($hash, 'thumb');
    }

    private function serve(string $hash, string $variantKey): StreamedResponse|Response|JsonResponse
    {
        $file = $this->service->serveVariant($hash, $variantKey);

        if (! $file) {
            return $this->notFound('codingfor-imagehost::messages.not_found');
        }

        return $this->streamedFileResponse($file['response'], $file['etag_source'], $file['cache_ttl']);
    }

    /**
     * Resolve a logged-in user or the anonymous browser's asset owner.
     *
     * @return array{user_id: int|null, guest_token_hash: string|null, guest_token: string|null}
     */
    private function resolveOwner(Request $request, bool $issueGuestToken = false): array
    {
        $userId = Auth::id();
        if ($userId !== null) {
            return [
                'user_id' => (int) $userId,
                'guest_token_hash' => null,
                'guest_token' => null,
            ];
        }

        $guestToken = $request->cookie(self::GUEST_TOKEN_COOKIE);
        if (! is_string($guestToken) || ! preg_match('/^[A-Za-z0-9]{64}$/', $guestToken)) {
            $guestToken = $issueGuestToken ? Str::random(self::GUEST_TOKEN_LENGTH) : null;
        }

        return [
            'user_id' => null,
            'guest_token_hash' => $guestToken !== null ? hash('sha256', $guestToken) : null,
            'guest_token' => $guestToken,
        ];
    }

    private function withGuestCookie(JsonResponse $response, ?string $guestToken): JsonResponse
    {
        if ($guestToken === null) {
            return $response;
        }

        return $response->withCookie(cookie(
            self::GUEST_TOKEN_COOKIE,
            $guestToken,
            60 * 24 * 365,
            '/',
            null,
            config('session.secure') ?? request()->isSecure(),
            true,
            false,
            'lax',
        ));
    }
}
