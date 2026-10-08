<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreVideoRequest;
use App\Http\Requests\Api\V1\Admin\UpdateVideoRequest;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Services\VideoUploadTokenService;
use App\Support\Query\BuildsApiListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;

class VideoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = BuildsApiListQuery::make(Video::query()->latest(), [
            AllowedFilter::partial('title'),
            AllowedFilter::exact('status'),
        ], [
            'title',
            'status',
            'created_at',
        ]);

        $page = $list->paginate(BuildsApiListQuery::perPage($request))->withQueryString();

        return $this->apiSuccess('Videos retrieved successfully.', [
            'videos' => VideoResource::collection($page->items())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(StoreVideoRequest $request, VideoUploadTokenService $tokens): JsonResponse
    {
        $video = Video::query()->create([
            'title' => $request->string('title')->toString(),
            'slug' => Video::uniqueSlug($request->string('title')->toString()),
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

        return $this->apiCreated('Video created.', [
            'video_id' => $video->id,
            'tus_endpoint' => $endpoint,
            'upload_token' => $tokens->issue($video),
            'video' => (new VideoResource($video))->resolve(),
        ]);
    }

    public function show(Video $video): JsonResponse
    {
        return $this->apiSuccess('Video retrieved successfully.', [
            'video' => (new VideoResource($video))->resolve(),
        ]);
    }

    public function update(UpdateVideoRequest $request, Video $video): JsonResponse
    {
        $title = $request->string('title')->toString();

        $video->forceFill([
            'title' => $title,
            'slug' => Video::uniqueSlug($title, $video->id),
        ])->save();

        return $this->apiSuccess('Video updated successfully.', [
            'video' => (new VideoResource($video))->resolve(),
        ]);
    }

    public function destroy(Video $video): JsonResponse
    {
        $video->delete();

        return $this->apiSuccess('Video deleted successfully.');
    }
}
