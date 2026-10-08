<?php

namespace App\Http\Controllers\Internal;

use App\Enums\MediaKind;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateHlsJob;
use App\Models\MediaItem;
use App\Services\ImageSanitizer;
use App\Services\MediaUploadTokenService;
use App\Support\AllowedMedia;
use App\Support\UploadInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TusHookController extends Controller
{
    public function __invoke(Request $request, MediaUploadTokenService $tokens, ?string $hook = null): JsonResponse
    {
        $name = strtolower($hook ?: (string) $request->header('Hook-Name', (string) $request->input('Type', '')));

        return match ($name) {
            'pre-create' => $this->preCreate($request, $tokens),
            'post-finish' => $this->postFinish($request, $tokens),
            'post-terminate' => $this->postTerminate($request),
            default => response()->json(['ok' => true]),
        };
    }

    private function preCreate(Request $request, MediaUploadTokenService $tokens): JsonResponse
    {
        $upload = $this->upload($request);
        $item = $this->authorizedItem($request, $tokens, $upload);

        if ($item instanceof JsonResponse) {
            return $item;
        }

        $filename = (string) ($upload['MetaData']['filename'] ?? '');
        $mime = (string) ($upload['MetaData']['filetype'] ?? '');
        $size = (int) ($upload['Size'] ?? 0);
        $deferred = (bool) ($upload['SizeIsDeferred'] ?? false);

        if ($deferred || $size < 1) {
            return $this->reject(422, 'The upload must include a total length.');
        }

        $reason = AllowedMedia::rejectionReason($filename, $mime, $size);

        if ($reason !== null) {
            return $this->reject(422, $reason);
        }

        if (AllowedMedia::extension($filename) !== $item->extension) {
            return $this->reject(422, 'The uploaded file does not match this library item.');
        }

        if ($item->status !== VideoStatus::AwaitingUpload) {
            return $this->reject(409, 'This file is not waiting for an upload.');
        }

        $tusId = (string) ($upload['ID'] ?? '');

        if ($tusId !== '') {
            $item->forceFill(['tus_id' => $tusId])->save();
        }

        return response()->json(['ok' => true]);
    }

    private function postFinish(Request $request, MediaUploadTokenService $tokens): JsonResponse
    {
        $upload = $this->upload($request);
        $item = $this->itemFromMetadata($upload);

        if ($item === null) {
            return $this->reject(404, 'Media was not found.');
        }

        if ($item->status !== VideoStatus::AwaitingUpload) {
            return response()->json(['ok' => true]);
        }

        $granted = $tokens->find($this->bearer($request));

        if ($granted === null || $granted['media_id'] !== $item->id) {
            return $this->reject(403, 'Upload token is invalid.');
        }

        $path = $upload['Storage']['Path'] ?? null;

        if (! is_string($path) || ! is_file($path)) {
            return $this->reject(422, 'Finished upload file is missing.');
        }

        $filename = (string) ($upload['MetaData']['filename'] ?? 'file.bin');
        $reason = app(UploadInspector::class)->rejectionReason($path, $filename);

        if ($reason !== null) {
            @unlink($path);
            @unlink($path.'.info');
            $item->forceFill([
                'status' => VideoStatus::Failed,
                'error_message' => $reason,
            ])->save();

            return $this->reject(422, $reason);
        }

        $storePath = $path;

        if ($item->kind === MediaKind::Image) {
            try {
                $storePath = app(ImageSanitizer::class)->reencode($path, (string) $item->extension);
            } catch (\RuntimeException $exception) {
                @unlink($path);
                @unlink($path.'.info');
                $item->forceFill([
                    'status' => VideoStatus::Failed,
                    'error_message' => $exception->getMessage(),
                ])->save();

                return $this->reject(422, $exception->getMessage());
            }
        }

        $storedName = $this->storedFilename($filename);

        $item->addMedia($storePath)
            ->usingFileName($storedName)
            ->toMediaCollection('source');

        if ($storePath !== $path) {
            @unlink($path);
        }

        @unlink($path.'.info');

        $source = $item->getFirstMedia('source');
        $dimensions = $this->imageDimensions($item);

        $item->forceFill([
            'status' => $item->kind->streamsAsHls() ? VideoStatus::Uploaded : VideoStatus::Ready,
            'progress' => $item->kind->streamsAsHls() ? 0 : 100,
            'tus_id' => (string) ($upload['ID'] ?? $item->tus_id),
            'size' => $source?->size ?: $item->size,
            'mime' => $source?->mime_type ?: $item->mime,
            'width' => $dimensions['width'] ?? $item->width,
            'height' => $dimensions['height'] ?? $item->height,
            'error_message' => null,
        ])->save();

        $tokens->forget($this->bearer($request));

        if ($item->kind->streamsAsHls()) {
            GenerateHlsJob::dispatch($item->id);
        }

        return response()->json(['ok' => true]);
    }

    private function postTerminate(Request $request): JsonResponse
    {
        $upload = $this->upload($request);
        $item = $this->itemFromMetadata($upload);

        if ($item === null) {
            $tusId = (string) ($upload['ID'] ?? '');
            $item = $tusId === '' ? null : MediaItem::query()->where('tus_id', $tusId)->first();
        }

        if ($item !== null && $item->status === VideoStatus::AwaitingUpload) {
            $item->delete();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $upload
     */
    private function authorizedItem(Request $request, MediaUploadTokenService $tokens, array $upload): MediaItem|JsonResponse
    {
        $granted = $tokens->find($this->bearer($request));

        if ($granted === null) {
            return $this->reject(403, 'Upload token is invalid.');
        }

        $item = $this->itemFromMetadata($upload);

        if ($item === null || $granted['media_id'] !== $item->id || $granted['user_id'] !== (int) $item->uploaded_by) {
            return $this->reject(403, 'Upload token is invalid.');
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(Request $request): array
    {
        $upload = $request->input('Event.Upload', []);

        return is_array($upload) ? $upload : [];
    }

    /**
     * @param  array<string, mixed>  $upload
     */
    private function itemFromMetadata(array $upload): ?MediaItem
    {
        $metadata = $upload['MetaData'] ?? [];
        $mediaId = is_array($metadata) ? (int) ($metadata['media_id'] ?? $metadata['video_id'] ?? 0) : 0;

        if ($mediaId < 1) {
            return null;
        }

        return MediaItem::query()->find($mediaId);
    }

    private function bearer(Request $request): string
    {
        $headers = $request->input('Event.HTTPRequest.Header', []);

        if (! is_array($headers)) {
            return '';
        }

        foreach ($headers as $name => $values) {
            if (strcasecmp((string) $name, 'Authorization') !== 0) {
                continue;
            }

            $value = is_array($values) ? ($values[0] ?? '') : $values;

            if (is_string($value) && str_starts_with($value, 'Bearer ')) {
                return substr($value, 7);
            }
        }

        return '';
    }

    private function storedFilename(string $original): string
    {
        $extension = AllowedMedia::extension($original);
        $base = Str::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'media';

        return $extension === '' ? $base : $base.'.'.$extension;
    }

    /**
     * @return array{width: int, height: int}|null
     */
    private function imageDimensions(MediaItem $item): ?array
    {
        if ($item->kind !== MediaKind::Image) {
            return null;
        }

        $media = $item->getFirstMedia('source');
        $path = $media?->getPath();

        if (! is_string($path) || ! is_file($path)) {
            return null;
        }

        $size = @getimagesize($path);

        if (! is_array($size)) {
            return null;
        }

        return ['width' => (int) $size[0], 'height' => (int) $size[1]];
    }

    private function reject(int $status, string $message): JsonResponse
    {
        return response()->json([
            'RejectUpload' => true,
            'HTTPResponse' => [
                'StatusCode' => $status,
                'Body' => $message,
                'Header' => [
                    'Content-Type' => 'text/plain',
                ],
            ],
            'message' => $message,
        ], $status);
    }
}
