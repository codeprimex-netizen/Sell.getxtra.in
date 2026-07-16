<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Seller;

use App\Application\Catalog\CatalogException;
use App\Application\Catalog\ProductVersionService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Uploads a new deliverable version for a seller's product. The archive is
 * validated + queued for an antivirus scan by the service. See Req 5 / 4.4.
 */
final class ProductVersionController extends Controller
{
    public function __construct(private ProductVersionService $versions)
    {
    }

    public function store(Request $request, string $id): Response
    {
        $sellerId = $this->currentUserId($request) ?? 0;

        if (!$request->hasFile('deliverable')) {
            $this->flash($request, 'error', 'Please choose a ZIP file to upload.');
            return $this->redirect('/seller/products/' . $id . '/edit');
        }

        try {
            $this->versions->addVersion(
                (int) $id,
                $sellerId,
                (string) $request->input('version_number', ''),
                $request->input('changelog') !== null ? (string) $request->input('changelog') : null,
                $request->file('deliverable'),
            );
            $this->flash($request, 'success', 'Version uploaded and queued for a security scan.');
        } catch (CatalogException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->redirect('/seller/products/' . $id . '/edit');
    }
}
