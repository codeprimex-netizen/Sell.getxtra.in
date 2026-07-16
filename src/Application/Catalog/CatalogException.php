<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use RuntimeException;

/**
 * Raised for expected catalog failures (validation, ownership, invalid
 * lifecycle transition). Carries a machine-readable code for the HTTP layer.
 */
final class CatalogException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'catalog_error')
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('Product not found.', 'not_found');
    }

    public static function forbidden(): self
    {
        return new self('You do not have access to this product.', 'forbidden');
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Cannot move product from {$from} to {$to}.", 'invalid_transition');
    }

    public static function notEditable(): self
    {
        return new self('This product can only be edited while in draft or after rejection.', 'not_editable');
    }
}
