<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Seeds the RBAC baseline: permission catalog, six default roles with their
 * permission sets, and one Super Admin user.
 *
 * Idempotent — safe to re-run; existing rows are matched by slug/email.
 */
class AuthSeeder extends Seeder
{
    /** module => [ [slug, label], ... ] */
    private array $catalog = [
        'dashboard'     => [['dashboard.view', 'View dashboard']],
        'servers'       => [['servers.view', 'View servers'], ['servers.manage', 'Manage servers']],
        'models'        => [['models.view', 'View models'], ['models.manage', 'Manage models']],
        'chat'          => [['chat.use', 'Use chat playground']],
        'apikeys'       => [['apikeys.view', 'View API keys'], ['apikeys.manage', 'Manage API keys']],
        'monitoring'    => [['monitoring.view', 'View monitoring']],
        'logs'          => [['logs.view', 'View logs']],
        'analytics'     => [['analytics.view', 'View analytics']],
        'users'         => [['users.view', 'View users'], ['users.manage', 'Manage users']],
        'roles'         => [['roles.view', 'View roles'], ['roles.manage', 'Manage roles']],
        'prompts'       => [['prompts.view', 'View prompts'], ['prompts.manage', 'Manage prompts']],
        'knowledge'     => [['knowledge.view', 'View knowledge base'], ['knowledge.manage', 'Manage knowledge base']],
        'agents'        => [['agents.view', 'View agents'], ['agents.manage', 'Manage agents']],
        'queues'        => [['queues.view', 'View queue'], ['queues.manage', 'Manage queue']],
        'notifications' => [['notifications.view', 'View notifications'], ['notifications.manage', 'Manage notifications']],
        'settings'      => [['settings.view', 'View settings'], ['settings.manage', 'Manage settings']],
        'audit'         => [['audit.view', 'View audit log']],
        'compute'       => [['portal.use', 'Use the training portal'], ['compute.manage', 'Manage compute allocations']],
    ];

    /** role slug => label, description, [permission slugs | '*' for all] */
    private array $roles = [
        'super_admin' => ['Super Admin', 'Full unrestricted access', '*'],
        'admin'       => ['Admin', 'Administrative access to all modules', '*'],
        'developer'   => ['Developer', 'Build with servers, models, chat and the API', [
            'dashboard.view', 'servers.view', 'servers.manage', 'models.view', 'models.manage',
            'chat.use', 'apikeys.view', 'apikeys.manage', 'monitoring.view', 'logs.view',
            'analytics.view', 'prompts.view', 'prompts.manage', 'knowledge.view', 'knowledge.manage',
            'agents.view', 'agents.manage', 'queues.view', 'portal.use',
        ]],
        'support'     => ['Support', 'Assist users; read-only on most modules', [
            'dashboard.view', 'chat.use', 'logs.view', 'monitoring.view', 'notifications.view',
        ]],
        'customer'    => ['Customer', 'Consume chat + API gateway', [
            'chat.use', 'apikeys.view', 'portal.use',
        ]],
        'viewer'      => ['Viewer', 'Read-only dashboards and reports', [
            'dashboard.view', 'servers.view', 'models.view', 'monitoring.view', 'analytics.view', 'logs.view',
        ]],
    ];

    public function run()
    {
        $now = date('Y-m-d H:i:s');
        $db  = $this->db;

        // 1. Permissions
        $permId = []; // slug => id
        foreach ($this->catalog as $module => $perms) {
            foreach ($perms as [$slug, $label]) {
                $existing = $db->table('permissions')->where('name', $slug)->get()->getRowArray();
                if ($existing) {
                    $permId[$slug] = (int) $existing['id'];
                    continue;
                }
                $db->table('permissions')->insert([
                    'name' => $slug, 'label' => $label, 'module' => $module, 'created_at' => $now,
                ]);
                $permId[$slug] = (int) $db->insertID();
            }
        }
        $allSlugs = array_keys($permId);

        // 2. Roles + role_permissions
        $roleId = [];
        foreach ($this->roles as $slug => [$label, $desc, $perms]) {
            $existing = $db->table('roles')->where('name', $slug)->get()->getRowArray();
            if ($existing) {
                $rid = (int) $existing['id'];
            } else {
                $db->table('roles')->insert([
                    'name' => $slug, 'label' => $label, 'description' => $desc,
                    'is_system' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $rid = (int) $db->insertID();
            }
            $roleId[$slug] = $rid;

            $grant = ($perms === '*') ? $allSlugs : $perms;
            $db->table('role_permissions')->where('role_id', $rid)->delete();
            $rows = [];
            foreach ($grant as $pslug) {
                if (isset($permId[$pslug])) {
                    $rows[] = ['role_id' => $rid, 'permission_id' => $permId[$pslug]];
                }
            }
            if ($rows) {
                $db->table('role_permissions')->insertBatch($rows);
            }
        }

        // 3. Super Admin user
        $email = 'admin@ollamahub.local';
        if (! $db->table('users')->where('email', $email)->get()->getRowArray()) {
            $db->table('users')->insert([
                'name'          => 'Super Admin',
                'email'         => $email,
                'password_hash' => password_hash('ChangeMe!2026', PASSWORD_DEFAULT),
                'role_id'       => $roleId['super_admin'],
                'status'        => 'active',
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
            echo "Created Super Admin: {$email} / ChangeMe!2026\n";
        }
    }
}
