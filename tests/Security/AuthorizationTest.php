<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Application\Identity\AccessControl;
use App\Infrastructure\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryRoleRepository;

/**
 * Security tests for authorization + credential hashing (Req 24.3).
 * XSS/CSRF/authn gates are additionally exercised end-to-end through the
 * Kernel in tests/security_authz.php.
 */
final class AuthorizationTest extends TestCase
{
    public function testRolePermissionsAreEnforced(): void
    {
        $roles = new InMemoryRoleRepository();
        $access = new AccessControl($roles);
        $roles->assignRoleByName(1, 'buyer');
        $roles->assignRoleByName(2, 'admin');

        $this->assertFalse($access->can(1, 'product.approve'), 'buyer must not approve products');
        $this->assertTrue($access->can(1, 'order.view'));
        $this->assertTrue($access->can(2, 'product.approve'));
        $this->assertFalse($access->can(2, 'ledger.adjust'), 'admin must not exceed granted scope');
    }

    public function testSuperAdminWildcard(): void
    {
        $roles = new InMemoryRoleRepository();
        $access = new AccessControl($roles);
        $roles->assignRoleByName(3, 'super_admin');
        $this->assertTrue($access->can(3, 'anything.at.all'));
    }

    public function testPrivilegeIsNotSharedAcrossUsers(): void
    {
        $roles = new InMemoryRoleRepository();
        $access = new AccessControl($roles);
        $roles->assignRoleByName(2, 'admin');
        $this->assertFalse($access->can(1, 'user.suspend'));
    }

    public function testPasswordsAreHashedWithAStrongAlgorithm(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('Str0ngPass!x');
        $this->assertNotSame('Str0ngPass!x', $hash);
        $this->assertMatchesRegularExpression('/^\$(2y|argon2)/i', $hash);
        $this->assertTrue($hasher->verify('Str0ngPass!x', $hash));
        $this->assertFalse($hasher->verify('wrong', $hash));
    }
}
