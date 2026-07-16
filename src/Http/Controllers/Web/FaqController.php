<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Application\Seo\StructuredData;
use App\Config\Config;
use App\Http\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;

/**
 * Public FAQ / help page. Renders human-readable answers and emits schema.org
 * FAQPage structured data so the questions are eligible for Google's FAQ rich
 * results. Content is data-driven (see {@see self::items()}), keeping the view
 * and the JSON-LD perfectly in sync.
 */
final class FaqController extends Controller
{
    public function index(Request $request): Response
    {
        $items = $this->items();
        $baseUrl = rtrim((string) Config::get('app.url', ''), '/');
        $appName = (string) Config::get('app.name', 'Code.getxtra.in');

        return $this->view($request, 'faq', [
            'wide'             => true,
            'faqs'             => $items,
            'title'            => 'Frequently asked questions — ' . $appName,
            'meta_description' => 'Answers about buying and selling digital products on ' . $appName
                . ': payments, instant downloads, licenses, refunds, seller payouts and support.',
            'seo_keywords'     => 'faq, help, digital products, downloads, licenses, refunds, payouts',
            'canonical'        => $baseUrl . '/faq',
            'breadcrumbs'      => [
                ['name' => 'Home', 'url' => $baseUrl . '/'],
                ['name' => 'FAQ', 'url' => $baseUrl . '/faq'],
            ],
            'schema'           => [StructuredData::faqPage($items)],
        ]);
    }

    /**
     * Question/answer content. Answers are plain text (safe for both the
     * rendered page and the JSON-LD Answer.text).
     *
     * @return array<int, array{q:string, a:string}>
     */
    private function items(): array
    {
        $appName = (string) Config::get('app.name', 'Code.getxtra.in');
        $currency = (string) Config::get('commerce.currency', 'INR');
        $refundDays = (int) Config::get('commerce.refund_window_days', 14);

        return [
            [
                'q' => "What is {$appName}?",
                'a' => "{$appName} is a marketplace for premium digital products — source code, templates, "
                    . 'UI kits, plugins and other downloadable assets — created by independent sellers and '
                    . 'delivered instantly after purchase.',
            ],
            [
                'q' => 'How do I buy a product?',
                'a' => 'Add the product to your cart, proceed to checkout, and complete payment. Your purchase '
                    . 'is added to your account library immediately, ready to download.',
            ],
            [
                'q' => 'Which payment methods are supported?',
                'a' => "Payments are processed by trusted, PCI-compliant gateways and are charged in {$currency}. "
                    . 'Card, UPI and netbanking options are available depending on your region.',
            ],
            [
                'q' => 'How do I download what I purchased?',
                'a' => 'Open your account library and use the secure, time-limited download links generated for '
                    . 'each purchase. Links are signed and tied to your account for your protection.',
            ],
            [
                'q' => 'Do products include a license?',
                'a' => 'Yes. Every purchase includes a license and a unique license key that you can verify at '
                    . 'any time. Check each product page for the specific license tier and usage terms.',
            ],
            [
                'q' => 'What is the refund policy?',
                'a' => "Eligible purchases can be refunded within {$refundDays} days, subject to our refund policy. "
                    . 'Because products are digital, some items may be non-refundable once downloaded.',
            ],
            [
                'q' => 'How do I become a seller?',
                'a' => 'Create an account, open a seller profile, and complete identity (KYC) verification. Once '
                    . 'approved you can list products, upload files and manage versions from your seller dashboard.',
            ],
            [
                'q' => 'When and how are sellers paid?',
                'a' => "Seller earnings clear after the {$refundDays}-day refund window, then become available for "
                    . 'withdrawal via your configured payout method. Request a payout from your seller payouts page.',
            ],
            [
                'q' => 'Is my payment and data secure?',
                'a' => 'Card details are handled entirely by the payment gateway and never stored on our servers. '
                    . 'The site enforces HTTPS, a strict Content-Security-Policy and modern security headers.',
            ],
            [
                'q' => 'How do I get support?',
                'a' => 'Sign in and use the notifications and account tools, or contact our support team by email. '
                    . 'We aim to respond to every request as quickly as possible.',
            ],
        ];
    }
}
