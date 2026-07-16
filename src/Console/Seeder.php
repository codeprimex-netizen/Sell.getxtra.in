<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Persistence\ConnectionManager;
use PDO;

/**
 * Seeds baseline reference data: the RBAC role/permission matrix and a set
 * of default feature flags. Idempotent — safe to run repeatedly.
 */
final class Seeder
{
    /** @var array<string, string> role name => label */
    private const ROLES = [
        'buyer'       => 'Buyer',
        'seller'      => 'Seller',
        'support'     => 'Support Agent',
        'moderator'   => 'Content Moderator',
        'finance'     => 'Finance / Accounts',
        'admin'       => 'Administrator',
        'super_admin' => 'Super Administrator',
    ];

    /** @var array<int, string> */
    private const PERMISSIONS = [
        'product.create', 'product.update', 'product.approve', 'product.suspend',
        'order.view', 'order.refund',
        'payout.request', 'payout.process',
        'review.moderate',
        'user.view', 'user.suspend', 'user.assign_role',
        'category.manage', 'coupon.manage',
        'settings.manage', 'feature_flag.manage',
        'dispute.handle', 'ticket.handle',
    ];

    /** @var array<string, array<int, string>> role => permissions ('*' = all) */
    private const ROLE_PERMISSIONS = [
        'buyer'     => ['order.view'],
        'seller'    => ['product.create', 'product.update', 'order.view', 'payout.request'],
        'support'   => ['order.view', 'ticket.handle', 'dispute.handle'],
        'moderator' => ['product.approve', 'product.suspend', 'review.moderate'],
        'finance'   => ['order.view', 'order.refund', 'payout.process'],
        'admin'     => [
            'product.approve', 'product.suspend', 'order.view', 'order.refund',
            'review.moderate', 'user.view', 'user.suspend', 'category.manage',
            'coupon.manage', 'dispute.handle', 'ticket.handle',
        ],
        'super_admin' => ['*'],
    ];

    /** @var array<int, string> */
    private const FEATURE_FLAGS = [
        'affiliate_program', 'social_login', 'multi_currency', 'public_api',
    ];

    public function __construct(private ConnectionManager $connection)
    {
    }

    public function run(): void
    {
        $pdo = $this->connection->write();

        $this->seedRoles($pdo);
        $this->seedPermissions($pdo);
        $this->assignRolePermissions($pdo);
        $this->seedFeatureFlags($pdo);

        fwrite(STDOUT, "Seeding complete.\n");
    }

    private function seedRoles(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO roles (name, label) VALUES (:name, :label)
             ON DUPLICATE KEY UPDATE label = VALUES(label)'
        );
        foreach (self::ROLES as $name => $label) {
            $stmt->execute(['name' => $name, 'label' => $label]);
        }
        fwrite(STDOUT, "  ✔ roles seeded\n");
    }

    private function seedPermissions(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO permissions (name) VALUES (:name)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        foreach (self::PERMISSIONS as $permission) {
            $stmt->execute(['name' => $permission]);
        }
        fwrite(STDOUT, "  ✔ permissions seeded\n");
    }

    private function assignRolePermissions(PDO $pdo): void
    {
        $roleIds = $this->lookup($pdo, 'SELECT id, name FROM roles');
        $permIds = $this->lookup($pdo, 'SELECT id, name FROM permissions');

        $link = $pdo->prepare(
            'INSERT IGNORE INTO role_permission (role_id, permission_id) VALUES (:r, :p)'
        );

        foreach (self::ROLE_PERMISSIONS as $role => $perms) {
            $roleId = $roleIds[$role] ?? null;
            if ($roleId === null) {
                continue;
            }
            $names = ($perms === ['*']) ? array_keys($permIds) : $perms;
            foreach ($names as $permName) {
                $permId = $permIds[$permName] ?? null;
                if ($permId !== null) {
                    $link->execute(['r' => $roleId, 'p' => $permId]);
                }
            }
        }
        fwrite(STDOUT, "  ✔ role/permission matrix linked\n");
    }

    private function seedFeatureFlags(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO feature_flags (name, is_enabled, rollout_percent) VALUES (:n, 0, 0)'
        );
        foreach (self::FEATURE_FLAGS as $flag) {
            $stmt->execute(['n' => $flag]);
        }
        fwrite(STDOUT, "  ✔ feature flags seeded\n");
    }

    /** @return array<string, int> name => id */
    private function lookup(PDO $pdo, string $sql): array
    {
        $map = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['name']] = (int) $row['id'];
        }
        return $map;
    }
}
