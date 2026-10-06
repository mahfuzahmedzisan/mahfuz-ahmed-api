<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Search\ModelSearchService;
use App\Support\Query\BuildsApiListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;

class UserController extends Controller
{
    public function index(Request $request, ModelSearchService $search): JsonResponse
    {
        $fromQuery = trim((string) $request->query('q', ''));
        $fromFilter = trim((string) $request->input('filter.q', ''));
        $term = $fromQuery !== '' ? $fromQuery : $fromFilter;
        $applied = false;
        $preferRelevance = ! $request->filled('sort');

        $users = User::query();

        if ($fromQuery !== '' && $fromFilter === '') {
            $users = $search->applyTextSearch($users, User::class, $term, $preferRelevance);
            $applied = true;
        }

        $list = BuildsApiListQuery::make($users, [
            AllowedFilter::callback('q', function ($query, $value) use (&$applied, $search, $preferRelevance): void {
                if ($applied) {
                    return;
                }

                $applied = true;
                $search->applyTextSearch($query, User::class, (string) $value, $preferRelevance);
            }),
            AllowedFilter::exact('role'),
        ], [
            'name',
            'email',
            'role',
            'created_at',
        ]);

        $page = $list->paginate(BuildsApiListQuery::perPage($request))->withQueryString();

        return $this->apiSuccess('Users retrieved successfully.', [
            'users' => UserResource::collection($page->items())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
