<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Catalog\CatalogService;
use App\Config\Config;
use App\Domain\Catalog\CategoryRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use Throwable;

/**
 * Storefront landing page: hero + featured products + category shortcuts.
 * Reads are best-effort — if the datastore is briefly unavailable the page
 * still renders (empty rails) so the marketing entry point never hard-fails
 * (Req 17.4 graceful degradation).
 */
final class HomeController extends Controller
{
    public function __construct(
        private CatalogService $catalog,
        private CategoryRepositoryInterface $categories,
    ) {
    }

    public function index(Request $request): Response
    {
        $featured = [];
        $categories = [];
        try {
            // listApproved is ordered featured-first, then most-recently published.
            $featured = $this->catalog->listApproved(null, 8, 0);
            $categories = $this->categories->allActive();
        } catch (Throwable) {
            // Degrade gracefully: render the landing without live rails.
        }

        $appName = (string) Config::get('app.name', 'Code.getxtra.in');

        return $this->view($request, 'home', [
            'featured'         => $featured,
            'categories'       => $categories,
            'wide'             => true,
            'title'            => $appName . ' — ' . __('app.tagline'),
            'canonical'        => rtrim((string) Config::get('app.url', ''), '/') . '/',
            'meta_description' => __('app.tagline'),
            'seo_type'         => 'website',
        ]);
    }
}
