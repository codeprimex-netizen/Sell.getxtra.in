<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Seller;

use App\Application\Catalog\CatalogException;
use App\Application\Catalog\ProductMediaService;
use App\Application\Catalog\ProductService;
use App\Domain\Catalog\CategoryRepositoryInterface;
use App\Domain\Catalog\Difficulty;
use App\Domain\Catalog\LicenseTierRepositoryInterface;
use App\Domain\Catalog\ProductRepositoryInterface;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProductVersionRepositoryInterface;
use App\Domain\Catalog\TagRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Support\Validation\Validator;

/**
 * Seller-facing product management: list, create/edit drafts, submit for
 * review, and archive. Every action is scoped to the authenticated seller.
 * See Req 4 / 3.7.
 */
final class ProductController extends Controller
{
    public function __construct(
        private ProductService $products,
        private ProductRepositoryInterface $productRepo,
        private CategoryRepositoryInterface $categories,
        private LicenseTierRepositoryInterface $tiers,
        private TagRepositoryInterface $tags,
        private ProductVersionRepositoryInterface $versions,
        private ProductMediaService $media,
    ) {
    }

    public function index(Request $request): Response
    {
        $sellerId = $this->currentUserId($request) ?? 0;

        return $this->view($request, 'seller.products.index', [
            'products' => $this->productRepo->forSeller($sellerId),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view($request, 'seller.products.form', [
            'mode'        => 'create',
            'product'     => null,
            'categories'  => $this->categories->allActive(),
            'difficulties' => Difficulty::values(),
            'tags_value'  => '',
        ]);
    }

    public function store(Request $request): Response
    {
        $sellerId = $this->currentUserId($request) ?? 0;
        $data = $request->all();

        $validator = Validator::make($data, [
            'title'      => 'required|max:200',
            'base_price' => 'required|numeric',
        ]);
        if ($validator->fails()) {
            return $this->formError($request, 'create', null, $validator->errors(), $data);
        }

        try {
            $productId = $this->products->createDraft($sellerId, $data);
        } catch (CatalogException $e) {
            return $this->formError($request, 'create', null, ['title' => [$e->getMessage()]], $data);
        }

        if ($request->hasFile('thumbnail')) {
            try {
                $this->media->setThumbnail($productId, $sellerId, $request->file('thumbnail'));
            } catch (CatalogException $e) {
                $this->flash($request, 'error', 'Product saved, but thumbnail failed: ' . $e->getMessage());
            }
        }

        $this->flash($request, 'success', 'Product draft created. Upload a version, then submit for review.');
        return $this->redirect('/seller/products/' . $productId . '/edit');
    }

    public function edit(Request $request, string $id): Response
    {
        $sellerId = $this->currentUserId($request) ?? 0;
        $product = $this->productRepo->findById((int) $id);

        if ($product === null || (int) $product['seller_id'] !== $sellerId) {
            return $this->notFound($request);
        }

        $tagIds = $this->productRepo->tagIds((int) $id);

        return $this->view($request, 'seller.products.form', [
            'mode'         => 'edit',
            'product'      => $product,
            'categories'   => $this->categories->allActive(),
            'difficulties' => Difficulty::values(),
            'tiers'        => $this->tiers->forProduct((int) $id),
            'versions'     => $this->versions->forProduct((int) $id),
            'screenshots'  => $this->media->screenshots((int) $id),
            'tags_value'   => implode(', ', $this->tags->namesFor($tagIds)),
        ]);
    }

    public function addScreenshot(Request $request, string $id): Response
    {
        $sellerId = $this->currentUserId($request) ?? 0;

        if (!$request->hasFile('screenshot')) {
            $this->flash($request, 'error', 'Please choose an image to upload.');
            return $this->redirect('/seller/products/' . $id . '/edit');
        }

        try {
            $this->media->addScreenshot((int) $id, $sellerId, $request->file('screenshot'));
            $this->flash($request, 'success', 'Screenshot added to the gallery.');
        } catch (CatalogException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->redirect('/seller/products/' . $id . '/edit');
    }

    public function deleteScreenshot(Request $request, string $id, string $fileId): Response
    {
        $sellerId = $this->currentUserId($request) ?? 0;

        try {
            $this->media->deleteScreenshot((int) $id, $sellerId, (int) $fileId);
            $this->flash($request, 'success', 'Screenshot removed.');
        } catch (CatalogException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->redirect('/seller/products/' . $id . '/edit');
    }

    public function update(Request $request, string $id): Response
    {
        $sellerId = $this->currentUserId($request) ?? 0;
        $data = $request->all();

        $validator = Validator::make($data, [
            'title'      => 'required|max:200',
            'base_price' => 'required|numeric',
        ]);
        if ($validator->fails()) {
            return $this->formError($request, 'edit', $this->productRepo->findById((int) $id), $validator->errors(), $data);
        }

        try {
            $this->products->update((int) $id, $sellerId, $data);
            if ($request->hasFile('thumbnail')) {
                $this->media->setThumbnail((int) $id, $sellerId, $request->file('thumbnail'));
            }
        } catch (CatalogException $e) {
            $this->flash($request, 'error', $e->getMessage());
            return $this->redirect('/seller/products/' . $id . '/edit');
        }

        $this->flash($request, 'success', 'Product updated.');
        return $this->redirect('/seller/products/' . $id . '/edit');
    }

    public function submit(Request $request, string $id): Response
    {
        $sellerId = $this->currentUserId($request) ?? 0;
        try {
            $this->products->submit((int) $id, $sellerId);
            $this->flash($request, 'success', 'Submitted for review. You will be notified once it is approved.');
        } catch (CatalogException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/seller/products/' . $id . '/edit');
    }

    public function archive(Request $request, string $id): Response
    {
        $sellerId = $this->currentUserId($request) ?? 0;
        try {
            $this->products->archive((int) $id, $sellerId);
            $this->flash($request, 'success', 'Product archived.');
        } catch (CatalogException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/seller/products');
    }

    /**
     * @param array<string,mixed>|null $product
     * @param array<string,array<int,string>> $errors
     * @param array<string,mixed> $old
     */
    private function formError(Request $request, string $mode, ?array $product, array $errors, array $old): Response
    {
        return $this->view($request, 'seller.products.form', [
            'mode'         => $mode,
            'product'      => $product,
            'categories'   => $this->categories->allActive(),
            'difficulties' => Difficulty::values(),
            'tags_value'   => (string) ($old['tags'] ?? ''),
            'errors'       => $errors,
            'old'          => $old,
        ], 422);
    }

    private function notFound(Request $request): Response
    {
        return $this->view($request, 'errors.catalog-404', [], 404);
    }
}
