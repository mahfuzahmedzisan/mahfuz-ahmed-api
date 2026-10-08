<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\MediaKind;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreMediaRequest;
use App\Http\Requests\Api\V1\Admin\StoreMediaThumbnailRequest;
use App\Http\Requests\Api\V1\Admin\UpdateMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\MediaItem;
use App\Services\ImageSanitizer;
use App\Services\MediaUploadTokenService;
use App\Support\AllowedMedia;
use App\Support\Query\BuildsApiListQuery;
use App\Support\UploadInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;

class MediaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = BuildsApiListQuery::make(MediaItem::query()->latest(), [
            AllowedFilter::partial('title'),
            AllowedFilter::exact('status'),
            AllowedFilter::exact('kind'),
            AllowedFilter::callback('q', function ($query, $value): void {
                $term = trim((string) $value);

                if ($term === '') {
                    return;
                }

                $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
                $query->where('search_text', 'like', $like);
            }),
        ], [
            'title',
            'status',
            'kind',
            'created_at',
        ]);

        $page = $list->paginate(BuildsApiListQuery::perPage($request))->withQueryString();

        return $this->apiSuccess('Media retrieved successfully.', [
            'media' => MediaResource::collection($page->items())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function slugPreview(Request $request): JsonResponse
    {
        $title = trim($request->string('title')->toString());
        $count = min(10, max(1, $request->integer('count', 1)));

        return $this->apiSuccess('Slugs prepared.', [
            'slugs' => $title === '' ? [] : MediaItem::nextSlugs($title, $count),
        ]);
    }

    public function store(StoreMediaRequest $request, MediaUploadTokenService $tokens): JsonResponse
    {
        $title = $request->string('title')->toString();
        $slug = $request->filled('slug')
            ? $request->string('slug')->toString()
            : MediaItem::uniqueSlug($title);

        if (MediaItem::query()->where('slug', $slug)->exists()) {
            return $this->apiUnprocessable('That slug is already in use.');
        }

        $filename = $request->string('filename')->toString();
        $kind = AllowedMedia::kindFor($filename);

        if (! $kind instanceof MediaKind) {
            return $this->apiUnprocessable('This file type is not allowed.');
        }

        $keywords = MediaItem::normalizeKeywords($request->input('keywords', []) ?? []);

        $item = MediaItem::query()->create([
            'title' => $title,
            'slug' => $slug,
            'kind' => $kind,
            'alt' => $request->input('alt'),
            'keywords' => $keywords,
            'mime' => strtolower($request->string('mime')->toString()),
            'extension' => AllowedMedia::extension($filename),
            'size' => $request->integer('size'),
            'status' => VideoStatus::AwaitingUpload,
            'uploaded_by' => $request->user()->id,
        ]);

        $configured = config('media-hls.endpoint');
        $endpoint = is_string($configured) && $configured !== ''
            ? $configured
            : rtrim((string) config('app.url'), '/').'/tus/';

        if ($request->headers->get('X-Forwarded-Proto') === 'https') {
            $endpoint = (string) preg_replace('#^http://#i', 'https://', $endpoint);
        }

        $endpoint = rtrim($endpoint, '/').'/';

        return $this->apiCreated('Media created.', [
            'media_id' => $item->id,
            'tus_endpoint' => $endpoint,
            'upload_token' => $tokens->issue($item),
            'media' => (new MediaResource($item))->resolve(),
        ]);
    }

    public function show(MediaItem $mediaItem): JsonResponse
    {
        return $this->apiSuccess('Media retrieved successfully.', [
            'media' => (new MediaResource($mediaItem))->resolve(),
        ]);
    }

    public function update(UpdateMediaRequest $request, MediaItem $mediaItem): JsonResponse
    {
        $slug = $request->string('slug')->toString();

        if (MediaItem::query()->where('slug', $slug)->whereKeyNot($mediaItem->id)->exists()) {
            return $this->apiUnprocessable('That slug is already in use.');
        }

        $mediaItem->forceFill([
            'title' => $request->string('title')->toString(),
            'slug' => $slug,
            'alt' => $request->input('alt'),
            'keywords' => MediaItem::normalizeKeywords($request->input('keywords', []) ?? []),
        ])->save();

        return $this->apiSuccess('Media updated successfully.', [
            'media' => (new MediaResource($mediaItem))->resolve(),
        ]);
    }

    public function destroy(MediaItem $mediaItem): JsonResponse
    {
        $mediaItem->delete();

        return $this->apiSuccess('Media deleted successfully.');
    }

    public function thumbnail(
        StoreMediaThumbnailRequest $request,
        MediaItem $mediaItem,
        UploadInspector $inspector,
        ImageSanitizer $images,
    ): JsonResponse {
        if (! $mediaItem->kind->streamsAsHls()) {
            return $this->apiUnprocessable('Only audio and video accept a thumbnail.');
        }

        $file = $request->file('thumbnail');

        if ($file === null) {
            return $this->apiUnprocessable('A thumbnail image is required.');
        }

        $filename = $file->getClientOriginalName();
        $reason = AllowedMedia::rejectionReason($filename, (string) $file->getMimeType(), (int) $file->getSize());

        if ($reason !== null || AllowedMedia::kindFor($filename) !== MediaKind::Image) {
            return $this->apiUnprocessable($reason ?? 'Thumbnails must be jpeg, png, webp, or gif.');
        }

        $path = $file->getRealPath();

        if (! is_string($path)) {
            return $this->apiUnprocessable('The thumbnail could not be read.');
        }

        $inspected = $inspector->rejectionReason($path, $filename);

        if ($inspected !== null) {
            return $this->apiUnprocessable($inspected);
        }

        try {
            $clean = $images->reencode($path, AllowedMedia::extension($filename));
        } catch (\RuntimeException $exception) {
            return $this->apiUnprocessable($exception->getMessage());
        }

        $mediaItem->addMedia($clean)
            ->usingFileName('thumbnail.'.AllowedMedia::extension($filename))
            ->toMediaCollection('poster');

        return $this->apiSuccess('Thumbnail saved.', [
            'media' => (new MediaResource($mediaItem->fresh() ?? $mediaItem))->resolve(),
        ]);
    }
}
