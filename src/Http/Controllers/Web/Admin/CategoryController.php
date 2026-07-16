<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Application\Admin\AdminException;
use App\Application\Admin\CategoryAdminService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/** Category management (Req 12.2). */
final class CategoryController extends Controller
{
    public function __construct(private CategoryAdminService $categories)
    {
    }

    public function index(Request $request): Response
    {
        return $this->view($request, 'admin.categories', [
            'categories' => $this->categories->all(),
            'wide'       => true,
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $this->categories->create((string) $request->input('name', ''));
            $this->flash($request, 'success', 'Category created.');
        } catch (AdminException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/admin/categories');
    }

    public function toggle(Request $request, string $id): Response
    {
        $this->categories->toggleActive((int) $id, (string) $request->input('active', '1') === '1');
        $this->flash($request, 'success', 'Category updated.');
        return $this->redirect('/admin/categories');
    }

    public function delete(Request $request, string $id): Response
    {
        $this->categories->delete((int) $id);
        $this->flash($request, 'success', 'Category deleted.');
        return $this->redirect('/admin/categories');
    }
}
