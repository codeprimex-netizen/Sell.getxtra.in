<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Application\Catalog\CatalogException;
use App\Application\Catalog\ModerationService;
use App\Application\Catalog\ProductIndexer;
use App\Domain\Catalog\ProductVersionRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Moderation queue for reviewing pending products. Guarded by the
 * product.approve permission (see routes). See Req 12.1.
 */
final class ModerationController extends Controller
{
    public function __construct(
        private ModerationService $moderation,
        private ProductVersionRepositoryInterface $versions,
        private ProductIndexer $indexer,
    ) {
    }

    public function queue(Request $request): Response
    {
        $items = $this->moderation->queue();
        // Attach current-version scan status for reviewer visibility.
        foreach ($items as &$item) {
            $current = $this->versions->currentForProduct((int) $item['id']);
            $item['current_scan'] = $current['scan_status'] ?? 'none';
        }

        return $this->view($request, 'admin.moderation', ['items' => $items, 'wide' => true]);
    }

    public function approve(Request $request, string $id): Response
    {
        try {
            $this->moderation->approve((int) $id);
            $this->indexer->sync((int) $id); // add to search index
            $this->flash($request, 'success', 'Product approved and published.');
        } catch (CatalogException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/admin/moderation');
    }

    public function reject(Request $request, string $id): Response
    {
        try {
            $this->moderation->reject((int) $id, (string) $request->input('reason', ''));
            $this->indexer->sync((int) $id); // remove from search index
            $this->flash($request, 'success', 'Product rejected.');
        } catch (CatalogException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/admin/moderation');
    }
}
