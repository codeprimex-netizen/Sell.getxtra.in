<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Application\Admin\AdminException;
use App\Application\Admin\CouponAdminService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/** Coupon management (Req 12.2 / 20.1). */
final class CouponController extends Controller
{
    public function __construct(private CouponAdminService $coupons)
    {
    }

    public function index(Request $request): Response
    {
        return $this->view($request, 'admin.coupons', [
            'coupons' => $this->coupons->all(),
            'wide'    => true,
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $this->coupons->create($request->all());
            $this->flash($request, 'success', 'Coupon created.');
        } catch (AdminException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/admin/coupons');
    }

    public function toggle(Request $request, string $id): Response
    {
        try {
            $this->coupons->setActive((int) $id, (string) $request->input('active', '1') === '1');
            $this->flash($request, 'success', 'Coupon updated.');
        } catch (AdminException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }
        return $this->redirect('/admin/coupons');
    }
}
