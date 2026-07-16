<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Admin\FeatureFlagRepositoryInterface;
use App\Domain\Admin\SettingsRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/** Feature flags + platform settings (Req 12 / 1.7). */
final class SettingsController extends Controller
{
    public function __construct(
        private FeatureFlagRepositoryInterface $flags,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->view($request, 'admin.settings', [
            'flags'    => $this->flags->all(),
            'settings' => $this->settings->all(),
            'wide'     => true,
        ]);
    }

    public function toggleFlag(Request $request): Response
    {
        $name = (string) $request->input('name', '');
        $enabled = (string) $request->input('enabled', '0') === '1';
        if ($name !== '') {
            $this->flags->setEnabled($name, $enabled, (int) $request->input('rollout', 100));
            $this->flash($request, 'success', "Feature '{$name}' updated.");
        }
        return $this->redirect('/admin/settings');
    }

    public function setSetting(Request $request): Response
    {
        $key = (string) $request->input('key', '');
        if ($key !== '') {
            $this->settings->set($key, $request->input('value'));
            $this->flash($request, 'success', 'Setting saved.');
        }
        return $this->redirect('/admin/settings');
    }
}
