<?php

declare(strict_types=1);

namespace Cms\Core\Auth;

use PDO;

final class MemberRoleService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function has(int $memberId, string $capability): bool
    {
        if ($memberId <= 0 || !in_array($capability, ['content.own_draft', 'seo.read'], true)) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM cms_front_users AS member
             JOIN cms_member_roles AS assignment ON assignment.member_id = member.id
             JOIN cms_role_capabilities AS grant_row ON grant_row.role_id = assignment.role_id
             WHERE member.id = :member_id AND member.status = 'active'
               AND assignment.role_id = 'ai_operator' AND grant_row.capability = :capability LIMIT 1"
        );
        $stmt->execute([':member_id' => $memberId, ':capability' => $capability]);

        return (bool) $stmt->fetchColumn();
    }

    public function assignOperator(int $memberId, int $adminId, bool $enabled): void
    {
        $firstAdminId = (int) $this->pdo->query('SELECT id FROM cms_admin_users ORDER BY id ASC LIMIT 1')->fetchColumn();
        $member = $this->pdo->prepare("SELECT 1 FROM cms_front_users WHERE id = :id AND status = 'active' LIMIT 1");
        $member->execute([':id' => $memberId]);
        if (!$member->fetchColumn() || $adminId <= 0 || $adminId !== $firstAdminId) {
            throw new \InvalidArgumentException('Active member and Owner are required.');
        }
        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare('SELECT role_id FROM cms_member_roles WHERE member_id = :member_id LIMIT 1');
            $existing->execute([':member_id' => $memberId]);
            $role = $existing->fetchColumn();
            if ($role !== false && $role !== 'ai_operator') {
                throw new \InvalidArgumentException('Another member role is already assigned.');
            }
            $this->pdo->prepare("DELETE FROM cms_member_roles WHERE member_id = :member_id AND role_id = 'ai_operator'")
                ->execute([':member_id' => $memberId]);
            if ($enabled) {
                $this->pdo->prepare('INSERT INTO cms_member_roles (member_id, role_id, assigned_by, assigned_at) VALUES (:member_id, :role_id, :assigned_by, :assigned_at)')
                    ->execute([':member_id' => $memberId, ':role_id' => 'ai_operator', ':assigned_by' => $adminId, ':assigned_at' => gmdate('c')]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function members(): array
    {
        return $this->pdo->query(
            'SELECT member.id, member.email, member.display_name, member.status, assignment.role_id
             FROM cms_front_users AS member
             LEFT JOIN cms_member_roles AS assignment ON assignment.member_id = member.id
             ORDER BY member.id DESC LIMIT 200'
        )->fetchAll();
    }
}
