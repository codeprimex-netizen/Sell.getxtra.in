<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Application\Admin\AdminException;
use App\Application\Admin\ProductAdminService;
use App\Application\Catalog\CatalogException;
use App\Application\Catalog\ProductIndexer;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/** Featured toggle + suspension for live products (Req 12.2). */
final class ProductAdminController extends Controller
{
    public function __construct(
        private ProductAdminService $products,
        private ProductIndexer $indexer,
    ) {
    }

    public function feature(Request $request, string $id): Response
    {
        $featured = (string) $request->input('featured', '1') === '1';
        try {
            $this->products->setFeatured((int) $id, $featured, $this->actor($request), $request->ip());
            $this->flash($request, 'success', $featured ? 'Product featured.' : 'Product unfeatured.');
        } catch (AdminException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/admin/moderation');
    }

    public function suspend(Request $request, string $id): Response
    {
        try {
            $this->products->suspend((int) $id, (string) $request->input('reason', 'Policy violation'), $this->actor($request), $request->ip());
            $this->indexer->sync((int) $id); // remove from search index
            $this->flash($request, 'success', 'Product suspended.');
        } catch (AdminException | CatalogException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/admin/moderation');
    }

    private function actor(Request $request): int
    {
        return $this->currentUserId($request) ?? 0;
    }
}
