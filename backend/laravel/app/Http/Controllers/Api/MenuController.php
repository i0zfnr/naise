<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MenuController extends Controller
{
    public function index(Request $request, AdminWorkspace $workspace): JsonResponse
    {
        $catalogUrl = config('services.naise.catalog_url');
        $catalog = $catalogUrl
            ? Http::timeout(5)->get($catalogUrl)->throw()->json()
            : $workspace->menu();
        $category = $request->query('category');
        $search = mb_strtolower((string) $request->query('search', ''));
        $sort = $request->query('sort');
        $items = array_values(array_filter(
            $catalog,
            static fn (array $item): bool => ($item['available'] ?? true) && ! ($item['archived'] ?? false) && (! $category || ($category === 'Best Seller' ? $item['bestSeller'] : $item['category'] === $category))
                && str_contains(mb_strtolower($item['name']), $search),
        ));

        if ($sort === 'asc') {
            usort($items, static fn (array $a, array $b): int => $a['price'] <=> $b['price']);
        }

        if ($sort === 'desc') {
            usort($items, static fn (array $a, array $b): int => $b['price'] <=> $a['price']);
        }

        return response()->json(['data' => $items]);
    }
}
