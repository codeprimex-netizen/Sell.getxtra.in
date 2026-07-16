<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;

/**
 * Landing page. Full storefront UI arrives in later phases; this renders a
 * lightweight placeholder confirming the platform is running.
 */
final class HomeController
{
    public function index(Request $request): Response
    {
        $name = (string) Config::get('app.name', 'Sell.getxtra.in');
        $env = (string) Config::get('app.env', 'production');

        $html = <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>{$name}</title>
          <style>
            :root{color-scheme:dark}
            body{font-family:system-ui,-apple-system,sans-serif;margin:0;background:#0f172a;color:#e2e8f0}
            .wrap{max-width:760px;margin:0 auto;padding:4rem 1.5rem}
            .badge{display:inline-block;background:#1e293b;color:#38bdf8;border-radius:999px;
              padding:.35rem .9rem;font-size:.8rem;letter-spacing:.04em}
            h1{font-size:2.6rem;margin:1.2rem 0 .4rem;background:linear-gradient(90deg,#38bdf8,#818cf8);
              -webkit-background-clip:text;background-clip:text;color:transparent}
            p{color:#94a3b8;line-height:1.6}
            code{background:#1e293b;padding:.15rem .4rem;border-radius:6px;color:#e2e8f0}
            .grid{display:grid;gap:.75rem;margin-top:2rem}
            .row{background:#1e293b;border-radius:10px;padding:1rem 1.2rem}
          </style>
        </head>
        <body>
          <div class="wrap">
            <span class="badge">Phase 1 · Platform Core</span>
            <h1>{$name}</h1>
            <p>Enterprise digital products marketplace — custom PHP core is up and running
            in the <code>{$env}</code> environment.</p>
            <div class="grid">
              <div class="row">Routing, DI container &amp; middleware pipeline online.</div>
              <div class="row">Health probes: <code>/healthz</code> &amp; <code>/readyz</code>.</div>
              <div class="row">Next phases: identity/RBAC, catalog, payments, downloads.</div>
            </div>
          </div>
        </body>
        </html>
        HTML;

        return Response::html($html);
    }
}
