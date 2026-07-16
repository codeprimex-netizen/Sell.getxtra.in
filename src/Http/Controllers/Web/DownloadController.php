<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Download\DownloadException;
use App\Application\Download\DownloadService;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Storage\StorageManager;

/**
 * Serves purchased deliverables securely (Req 10). "request" mints a
 * short-lived signed link for an owned entitlement; "serve" redeems it and
 * streams the file from private storage. Paths are never exposed.
 */
final class DownloadController extends Controller
{
    public function __construct(
        private DownloadService $downloads,
        private StorageManager $storage,
    ) {
    }

    /** GET /downloads/{entitlement} — mint a signed link and redirect to it. */
    public function request(Request $request, string $entitlement): Response
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->redirect('/login');
        }

        try {
            $link = $this->downloads->createLink((int) $entitlement, $userId);
        } catch (DownloadException $e) {
            $this->flash($request, 'error', $e->getMessage());
            return $this->redirect('/account/library');
        }

        return $this->redirect($link);
    }

    /** GET /download/{token} — validate the token and stream the deliverable. */
    public function serve(Request $request, string $token): Response
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return $this->redirect('/login');
        }

        try {
            $deliverable = $this->downloads->resolve(
                $token,
                $userId,
                $request->ip(),
                is_string($request->attribute('request_id')) ? $request->attribute('request_id') : null,
            );
        } catch (DownloadException $e) {
            return $this->deny($request, $e);
        }

        $path = $this->storage->private()->path($deliverable->storageKey);
        if ($path === null || !is_file($path)) {
            return $this->deny($request, DownloadException::unavailable());
        }

        return $this->stream($path, $deliverable->filename, $deliverable->sizeBytes);
    }

    private function stream(string $path, string $filename, int $size): Response
    {
        // Stream directly to the client without buffering the whole file.
        $sendFile = static function () use ($path): void {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return;
            }
            while (!feof($handle)) {
                echo fread($handle, 8192);
                @ob_flush();
                flush();
            }
            fclose($handle);
        };

        $response = new Response('', 200, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $filename) . '"',
            'Content-Length'      => (string) $size,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'       => 'private, no-store',
        ]);
        $response->setStreamer($sendFile);

        return $response;
    }

    private function deny(Request $request, DownloadException $e): Response
    {
        if ($request->wantsJson()) {
            return Response::apiError($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return Response::html(
            '<!doctype html><meta charset="utf-8"><title>' . $e->httpStatus . '</title>'
            . '<div style="font-family:system-ui;text-align:center;padding:4rem">'
            . '<h1 style="font-size:3rem">' . $e->httpStatus . '</h1><p>' . e($e->getMessage()) . '</p>'
            . '<p><a href="/account/library">Back to downloads</a></p></div>',
            $e->httpStatus,
        );
    }
}
