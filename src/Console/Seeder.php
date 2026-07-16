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
        'category.manage', 'coupon.manage', 'product.feature',
        'settings.manage', 'feature_flag.manage', 'report.view',
        'dispute.handle', 'ticket.handle', 'kyc.review',
    ];

    /** @var array<string, array<int, string>> role => permissions ('*' = all) */
    private const ROLE_PERMISSIONS = [
        'buyer'     => ['order.view'],
        'seller'    => ['product.create', 'product.update', 'order.view', 'payout.request'],
        'support'   => ['order.view', 'ticket.handle', 'dispute.handle'],
        'moderator' => ['product.approve', 'product.suspend', 'review.moderate'],
        'finance'   => ['order.view', 'order.refund', 'payout.process', 'kyc.review'],
        'admin'     => [
            'product.approve', 'product.suspend', 'product.feature', 'order.view', 'order.refund',
            'review.moderate', 'user.view', 'user.suspend', 'user.assign_role', 'category.manage',
            'coupon.manage', 'settings.manage', 'feature_flag.manage', 'report.view',
            'dispute.handle', 'ticket.handle', 'kyc.review', 'payout.process',
        ],
        'super_admin' => ['*'],
    ];

    /** @var array<int, string> */
    private const FEATURE_FLAGS = [
        'affiliate_program', 'social_login', 'multi_currency', 'public_api',
    ];

    /** @var array<int, string> default top-level catalog categories */
    private const CATEGORIES = [
        'PHP Scripts', 'WordPress', 'HTML Templates', 'Mobile Apps',
        'UI Kits & Themes', 'Plugins & Add-ons', 'Ebooks & Docs', 'Graphics',
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
        $this->seedCategories($pdo);
        $this->seedCoupons($pdo);

        fwrite(STDOUT, "Seeding complete.\n");
    }

    private function seedCoupons(PDO $pdo): void
    {
        try {
            $pdo->query('SELECT 1 FROM coupons LIMIT 1');
        } catch (\Throwable) {
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO coupons (code, type, value, scope, min_order, max_uses, is_active)
             VALUES (:code, :type, :value, 'all', :min_order, :max_uses, 1)"
        );
        $stmt->execute(['code' => 'WELCOME10', 'type' => 'percent', 'value' => 10, 'min_order' => 100, 'max_uses' => 1000]);
        $stmt->execute(['code' => 'FLAT50', 'type' => 'fixed', 'value' => 50, 'min_order' => 200, 'max_uses' => 500]);
        fwrite(STDOUT, "  ✔ coupons seeded\n");
    }

    private function seedCategories(PDO $pdo): void
    {
        // Skip gracefully if the catalog migration hasn't run yet.
        try {
            $pdo->query('SELECT 1 FROM categories LIMIT 1');
        } catch (\Throwable) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO categories (name, slug, sort_order) VALUES (:n, :s, :o)'
        );
        foreach (self::CATEGORIES as $i => $name) {
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '';
            $stmt->execute(['n' => $name, 's' => trim($slug, '-'), 'o' => $i]);
        }
        fwrite(STDOUT, "  ✔ categories seeded\n");
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
