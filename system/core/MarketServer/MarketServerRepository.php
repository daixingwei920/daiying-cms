<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

use Cms\Core\Security\PasswordHasher;
use PDO;
use PDOException;

final class MarketServerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function registerDeveloper(string $developerKey, string $displayName, string $email, string $password = '', string $role = 'Developer'): DeveloperIdentity
    {
        $role = $this->normalizeDeveloperRole($role);
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_developers (developer_key, display_name, email, password_hash, role, status, created_at) VALUES (:developer_key, :display_name, :email, :password_hash, :role, :status, :created_at)');
        $stmt->execute([
            ':developer_key' => $developerKey,
            ':display_name' => $displayName,
            ':email' => $email,
            ':password_hash' => $password === '' ? null : PasswordHasher::hash($password),
            ':role' => $role,
            ':status' => 'Active',
            ':created_at' => gmdate('c'),
        ]);

        return new DeveloperIdentity((int) $this->pdo->lastInsertId(), $developerKey, $displayName, $email, 'Active', $role);
    }

    public function developerByCredentials(string $developerKey, string $email, string $password = ''): DeveloperIdentity
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_developers WHERE developer_key = :developer_key AND email = :email AND status = :status');
        $stmt->execute([':developer_key' => $developerKey, ':email' => $email, ':status' => 'Active']);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MarketServerException('Developer credentials were not accepted.');
        }
        $passwordHash = (string) ($row['password_hash'] ?? '');
        if ($passwordHash !== '' && ($password === '' || !PasswordHasher::verify($password, $passwordHash))) {
            throw new MarketServerException('Developer credentials were not accepted.');
        }

        return $this->developerFromRow($row);
    }

    public function setDeveloperPassword(string $developerKey, string $password): void
    {
        if ($password === '') {
            throw new MarketServerException('Developer password is required.');
        }
        $stmt = $this->pdo->prepare('UPDATE cms_market_developers SET password_hash = :password_hash WHERE developer_key = :developer_key');
        $stmt->execute([
            ':password_hash' => PasswordHasher::hash($password),
            ':developer_key' => $developerKey,
        ]);
        if ($stmt->rowCount() < 1) {
            throw new MarketServerException('Developer was not found.');
        }
    }

    public function developerByKey(string $developerKey): DeveloperIdentity
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_developers WHERE developer_key = :developer_key AND status = :status');
        $stmt->execute([':developer_key' => $developerKey, ':status' => 'Active']);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MarketServerException('Developer was not found.');
        }

        return $this->developerFromRow($row);
    }

    /** @return list<array<string, mixed>> */
    public function developers(): array
    {
        $stmt = $this->pdo->query('SELECT id, developer_key, display_name, email, role, status, created_at FROM cms_market_developers ORDER BY id ASC');

        return $stmt->fetchAll();
    }

    /** @param list<string> $roles */
    public function assertDeveloperRole(string $developerKey, array $roles): DeveloperIdentity
    {
        $developer = $this->developerByKey($developerKey);
        if (!in_array($developer->role, $roles, true)) {
            throw new MarketServerException('Developer role is not allowed to perform this action.');
        }

        return $developer;
    }

    public function createProject(int $developerId, string $marketId, string $extensionType, string $name): MarketProject
    {
        if (!in_array($extensionType, ['plugin', 'theme'], true)) {
            throw new MarketServerException('Market project type must be plugin or theme.');
        }

        $stmt = $this->pdo->prepare('INSERT INTO cms_market_projects (developer_id, market_id, extension_type, name, status, created_at) VALUES (:developer_id, :market_id, :extension_type, :name, :status, :created_at)');
        $stmt->execute([
            ':developer_id' => $developerId,
            ':market_id' => $marketId,
            ':extension_type' => $extensionType,
            ':name' => $name,
            ':status' => 'Draft',
            ':created_at' => gmdate('c'),
        ]);
        $projectId = (int) $this->pdo->lastInsertId();
        $this->addProjectMember($projectId, $developerId, 'Owner');

        return new MarketProject($projectId, $developerId, $marketId, $extensionType, $name, 'Draft');
    }

    /** @return list<array<string, mixed>> */
    public function projects(): array
    {
        $stmt = $this->pdo->query('SELECT p.*, d.display_name AS developer_name FROM cms_market_projects p INNER JOIN cms_market_developers d ON d.id = p.developer_id ORDER BY p.id DESC');

        return $stmt->fetchAll();
    }

    public function project(int $projectId): MarketProject
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_projects WHERE id = :id');
        $stmt->execute([':id' => $projectId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MarketServerException('Market project was not found.');
        }

        return new MarketProject((int) $row['id'], (int) $row['developer_id'], (string) $row['market_id'], (string) $row['extension_type'], (string) $row['name'], (string) $row['status']);
    }

    public function assertProjectDeveloper(int $projectId, string $developerKey): void
    {
        $stmt = $this->pdo->prepare('SELECT d.developer_key FROM cms_market_projects p INNER JOIN cms_market_developers d ON d.id = p.developer_id WHERE p.id = :id');
        $stmt->execute([':id' => $projectId]);
        if ((string) $stmt->fetchColumn() === $developerKey) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cms_market_project_members m INNER JOIN cms_market_developers d ON d.id = m.developer_id WHERE m.project_id = :project_id AND d.developer_key = :developer_key AND m.status = :status');
        $stmt->execute([':project_id' => $projectId, ':developer_key' => $developerKey, ':status' => 'Active']);
        if ((int) $stmt->fetchColumn() < 1) {
            throw new MarketServerException('Developer is not allowed to access this market project.');
        }
    }

    public function assertProjectDeveloperCanSubmit(int $projectId, string $developerKey): DeveloperIdentity
    {
        $member = $this->projectMemberByDeveloperKey($projectId, $developerKey);
        if (!in_array((string) ($member['role'] ?? ''), ['Owner', 'Maintainer', 'Developer'], true)) {
            throw new MarketServerException('Developer role is not allowed to perform this action.');
        }

        return $this->developerByKey($developerKey);
    }

    /** @return array<string, mixed> */
    public function addProjectMember(int $projectId, int $developerId, string $role): array
    {
        $role = $this->normalizeDeveloperRole($role);
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_project_members (project_id, developer_id, role, status, created_at) VALUES (:project_id, :developer_id, :role, :status, :created_at)');
        $stmt->execute([
            ':project_id' => $projectId,
            ':developer_id' => $developerId,
            ':role' => $role,
            ':status' => 'Active',
            ':created_at' => gmdate('c'),
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'project_id' => $projectId,
            'developer_id' => $developerId,
            'role' => $role,
            'status' => 'Active',
        ];
    }

    /** @return array<string, mixed> */
    public function addProjectMemberByKey(int $projectId, string $developerKey, string $role): array
    {
        $developer = $this->developerByKey($developerKey);

        return $this->addProjectMember($projectId, $developer->id, $role);
    }

    public function updateProjectMemberRole(int $memberId, string $role): void
    {
        $role = $this->normalizeDeveloperRole($role);
        $stmt = $this->pdo->prepare('UPDATE cms_market_project_members SET role = :role WHERE id = :id AND status = :status');
        $stmt->execute([':role' => $role, ':id' => $memberId, ':status' => 'Active']);
        if ($stmt->rowCount() < 1) {
            throw new MarketServerException('Project member was not found.');
        }
    }

    public function removeProjectMember(int $memberId): void
    {
        $stmt = $this->pdo->prepare('UPDATE cms_market_project_members SET status = :removed WHERE id = :id AND role <> :owner');
        $stmt->execute([':removed' => 'Removed', ':id' => $memberId, ':owner' => 'Owner']);
        if ($stmt->rowCount() < 1) {
            throw new MarketServerException('Project member could not be removed.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function projectMembers(int $projectId): array
    {
        $stmt = $this->pdo->prepare('SELECT m.*, d.developer_key, d.display_name, d.email FROM cms_market_project_members m INNER JOIN cms_market_developers d ON d.id = m.developer_id WHERE m.project_id = :project_id ORDER BY m.id ASC');
        $stmt->execute([':project_id' => $projectId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    private function projectMemberByDeveloperKey(int $projectId, string $developerKey): array
    {
        $stmt = $this->pdo->prepare('SELECT m.*, d.developer_key FROM cms_market_project_members m INNER JOIN cms_market_developers d ON d.id = m.developer_id WHERE m.project_id = :project_id AND d.developer_key = :developer_key AND m.status = :status ORDER BY m.id DESC LIMIT 1');
        $stmt->execute([':project_id' => $projectId, ':developer_key' => $developerKey, ':status' => 'Active']);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MarketServerException('Developer is not allowed to access this market project.');
        }

        return $row;
    }

    public function submitVersion(int $projectId, string $version, string $packagePath, string $changelog = ''): MarketVersion
    {
        if (!is_file($packagePath)) {
            throw new MarketServerException('Submitted package file does not exist.');
        }

        $sha256 = hash_file('sha256', $packagePath);
        if (!is_string($sha256)) {
            throw new MarketServerException('Unable to hash submitted package.');
        }

        $stmt = $this->pdo->prepare('INSERT INTO cms_market_versions (project_id, version, package_path, package_sha256, changelog, status, submitted_at) VALUES (:project_id, :version, :package_path, :package_sha256, :changelog, :status, :submitted_at)');
        $stmt->execute([
            ':project_id' => $projectId,
            ':version' => $version,
            ':package_path' => $packagePath,
            ':package_sha256' => $sha256,
            ':changelog' => $changelog,
            ':status' => ReviewState::SUBMITTED,
            ':submitted_at' => gmdate('c'),
        ]);

        return new MarketVersion((int) $this->pdo->lastInsertId(), $projectId, $version, $packagePath, $sha256, ReviewState::SUBMITTED);
    }

    public function confirmUpload(int $projectId, string $objectKey, string $packagePath, string $originalName = ''): array
    {
        if (!is_file($packagePath)) {
            throw new MarketServerException('Uploaded package object was not found.');
        }
        $sha256 = hash_file('sha256', $packagePath);
        if (!is_string($sha256)) {
            throw new MarketServerException('Unable to hash uploaded package.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_market_uploads (project_id, object_key, original_name, byte_size, sha256_hash, status, created_at, confirmed_at)
             VALUES (:project_id, :object_key, :original_name, :byte_size, :sha256_hash, :status, :created_at, :confirmed_at)'
        );
        $now = gmdate('c');
        $stmt->execute([
            ':project_id' => $projectId,
            ':object_key' => $objectKey,
            ':original_name' => $originalName === '' ? basename($objectKey) : $originalName,
            ':byte_size' => filesize($packagePath) ?: 0,
            ':sha256_hash' => $sha256,
            ':status' => 'Confirmed',
            ':created_at' => $now,
            ':confirmed_at' => $now,
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'project_id' => $projectId,
            'object_key' => $objectKey,
            'package_path' => $packagePath,
            'package_sha256' => $sha256,
            'status' => 'Confirmed',
        ];
    }

    public function submitVersionFromUpload(int $projectId, string $version, string $objectKey, string $packagePath, string $originalName = '', string $changelog = ''): MarketVersion
    {
        $this->confirmUpload($projectId, $objectKey, $packagePath, $originalName);

        return $this->submitVersion($projectId, $version, $packagePath, $changelog);
    }

    /** @param array<string, mixed> $payload */
    public function recordWebhookEvent(string $eventId, array $payload): void
    {
        if ($eventId === '') {
            throw new MarketServerException('Webhook event_id is required.');
        }
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_webhook_events (event_id, payload_json, created_at) VALUES (:event_id, :payload_json, :created_at)');
        try {
            $stmt->execute([
                ':event_id' => $eventId,
                ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':created_at' => gmdate('c'),
            ]);
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'UNIQUE') || str_contains($exception->getMessage(), 'Duplicate')) {
                throw new MarketServerException('Webhook event was already processed.');
            }
            throw $exception;
        }
    }

    public function confirmUploadWebhook(int $projectId, string $objectKey, string $packagePath, string $sha256Hash, int $byteSize = 0): array
    {
        $upload = $this->confirmUpload($projectId, $objectKey, $packagePath, basename($objectKey));
        if (!hash_equals($upload['package_sha256'], $sha256Hash)) {
            throw new MarketServerException('Uploaded package hash does not match webhook payload.');
        }
        if ($byteSize > 0) {
            $stmt = $this->pdo->prepare('UPDATE cms_market_uploads SET byte_size = :byte_size WHERE id = :id');
            $stmt->execute([':byte_size' => $byteSize, ':id' => (int) $upload['id']]);
        }

        return $upload + ['webhook' => true];
    }

    /** @return list<array<string, mixed>> */
    public function uploadsForProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_uploads WHERE project_id = :project_id ORDER BY id DESC');
        $stmt->execute([':project_id' => $projectId]);

        return $stmt->fetchAll();
    }

    public function runScan(int $versionId, PackageScanner $scanner): string
    {
        $version = $this->version($versionId);

        return $this->recordScan($versionId, $scanner->scanZip($version->packagePath));
    }

    /** @return array{processed:int,passed:int,failed:int,errors:list<string>} */
    public function processScanQueue(PackageScanner $scanner, int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare('SELECT id FROM cms_market_versions WHERE status = :status ORDER BY id ASC LIMIT ' . $limit);
        $stmt->execute([':status' => ReviewState::SUBMITTED]);

        $processed = 0;
        $passed = 0;
        $failed = 0;
        $errors = [];
        foreach ($stmt->fetchAll() as $row) {
            $versionId = (int) ($row['id'] ?? 0);
            if ($versionId <= 0) {
                continue;
            }
            try {
                $status = $this->runScan($versionId, $scanner);
                $processed++;
                $status === 'Passed' ? $passed++ : $failed++;
            } catch (Throwable $exception) {
                $errors[] = '#' . $versionId . ': ' . $exception->getMessage();
            }
        }

        return [
            'processed' => $processed,
            'passed' => $passed,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    public function version(int $versionId): MarketVersion
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_versions WHERE id = :id');
        $stmt->execute([':id' => $versionId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MarketServerException('Market version was not found.');
        }

        return new MarketVersion((int) $row['id'], (int) $row['project_id'], (string) $row['version'], (string) $row['package_path'], (string) $row['package_sha256'], (string) $row['status']);
    }

    /** @return list<array<string, mixed>> */
    public function versionsForProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_versions WHERE project_id = :project_id ORDER BY id DESC');
        $stmt->execute([':project_id' => $projectId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function reviewQueue(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'v.status = :status';
            $params[':status'] = $filters['status'];
        } else {
            $where[] = 'v.status IN (:submitted, :ai_pending, :ai_running, :ai_failed, :codex_pending, :codex_running, :codex_failed, :needs_review, :manual_review, :needs_fix, :needs_security, :approved)';
            $params = [
                ':submitted' => ReviewState::SUBMITTED,
                ':ai_pending' => ReviewState::AI_REVIEW_PENDING,
                ':ai_running' => ReviewState::AI_REVIEW_RUNNING,
                ':ai_failed' => ReviewState::AI_REVIEW_FAILED,
                ':codex_pending' => ReviewState::CODEX_REVIEW_PENDING,
                ':codex_running' => ReviewState::CODEX_REVIEW_RUNNING,
                ':codex_failed' => ReviewState::CODEX_REVIEW_FAILED,
                ':needs_review' => ReviewState::NEEDS_REVIEW,
                ':manual_review' => ReviewState::MANUAL_REVIEW,
                ':needs_fix' => ReviewState::NEEDS_FIX,
                ':needs_security' => ReviewState::NEEDS_SECURITY_REVIEW,
                ':approved' => ReviewState::APPROVED,
            ];
        }
        if (($filters['type'] ?? '') !== '') {
            $where[] = 'p.extension_type = :type';
            $params[':type'] = $filters['type'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(p.market_id LIKE :q OR p.name LIKE :q OR d.display_name LIKE :q OR v.version LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }
        $stmt = $this->pdo->prepare('SELECT v.*, p.market_id, p.extension_type, p.name, d.display_name AS developer_name FROM cms_market_versions v INNER JOIN cms_market_projects p ON p.id = v.project_id INNER JOIN cms_market_developers d ON d.id = p.developer_id WHERE ' . implode(' AND ', $where) . ' ORDER BY v.id ASC');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int} */
    public function reviewQueuePage(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $items = $this->reviewQueue($filters);
        $total = count($items);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $pages = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function latestScanFindings(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT findings_json FROM cms_market_scan_jobs WHERE version_id = :version_id ORDER BY id DESC');
        $stmt->execute([':version_id' => $versionId]);
        $json = $stmt->fetchColumn();
        $decoded = json_decode(is_string($json) ? $json : '[]', true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param list<array{severity: string, code: string, message: string, path: string}> $findings */
    public function recordScan(int $versionId, array $findings): string
    {
        $status = $findings === [] ? 'Passed' : 'Failed';
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_scan_jobs (version_id, status, findings_json, created_at, completed_at) VALUES (:version_id, :status, :findings_json, :created_at, :completed_at)');
        $now = gmdate('c');
        $stmt->execute([
            ':version_id' => $versionId,
            ':status' => $status,
            ':findings_json' => json_encode($findings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => $now,
            ':completed_at' => $now,
        ]);

        $this->transitionVersion($versionId, ReviewState::SCANNING);
        $this->transitionVersion($versionId, $status === 'Passed' ? ReviewState::NEEDS_REVIEW : ReviewState::REJECTED);

        return $status;
    }

    /** @param array<string,mixed> $inputSummary */
    public function createAiReviewTask(int $versionId, string $reviewType, string $provider, string $model, string $status, string $requestId, array $inputSummary): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_market_ai_review_tasks
                (version_id, review_type, provider, model, status, attempts, request_id, input_summary_json, error_message, created_at, updated_at)
             VALUES (:version_id, :review_type, :provider, :model, :status, 0, :request_id, :input_summary_json, :error_message, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':version_id' => $versionId,
            ':review_type' => $reviewType,
            ':provider' => $provider,
            ':model' => $model,
            ':status' => $status,
            ':request_id' => $requestId,
            ':input_summary_json' => json_encode($inputSummary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':error_message' => '',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateAiReviewTask(int $taskId, string $status, string $errorMessage = ''): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cms_market_ai_review_tasks
             SET status = :status, attempts = attempts + 1, error_message = :error_message, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $taskId,
            ':status' => $status,
            ':error_message' => substr($errorMessage, 0, 500),
            ':updated_at' => gmdate('c'),
        ]);
    }

    /** @param array<string,mixed> $evidence */
    public function recordAiReviewEvidence(int $taskId, int $versionId, string $reviewType, string $provider, string $model, string $requestId, array $evidence): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_market_ai_review_evidence
                (task_id, version_id, review_type, provider, model, request_id, status, decision_suggestion, risk_level, confidence, violations_json, warnings_json, manual_review_focus_json, required_fixes_json, output_json, created_at)
             VALUES
                (:task_id, :version_id, :review_type, :provider, :model, :request_id, :status, :decision_suggestion, :risk_level, :confidence, :violations_json, :warnings_json, :manual_review_focus_json, :required_fixes_json, :output_json, :created_at)'
        );
        $stmt->execute([
            ':task_id' => $taskId,
            ':version_id' => $versionId,
            ':review_type' => $reviewType,
            ':provider' => $provider,
            ':model' => $model,
            ':request_id' => $requestId,
            ':status' => (string) ($evidence['status'] ?? 'completed'),
            ':decision_suggestion' => (string) ($evidence['decision_suggestion'] ?? 'NEEDS_MANUAL_REVIEW'),
            ':risk_level' => (string) ($evidence['risk_level'] ?? 'medium'),
            ':confidence' => (string) ($evidence['confidence'] ?? '0'),
            ':violations_json' => json_encode(array_values(is_array($evidence['violations'] ?? null) ? $evidence['violations'] : []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':warnings_json' => json_encode(array_values(is_array($evidence['warnings'] ?? null) ? $evidence['warnings'] : []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':manual_review_focus_json' => json_encode(array_values(is_array($evidence['manual_review_focus'] ?? null) ? $evidence['manual_review_focus'] : []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':required_fixes_json' => json_encode(array_values(is_array($evidence['required_fixes'] ?? null) ? $evidence['required_fixes'] : []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':output_json' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => gmdate('c'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function aiReviewTasks(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_ai_review_tasks WHERE version_id = :version_id ORDER BY id ASC');
        $stmt->execute([':version_id' => $versionId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function aiReviewEvidence(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_ai_review_evidence WHERE version_id = :version_id ORDER BY id ASC');
        $stmt->execute([':version_id' => $versionId]);

        return $stmt->fetchAll();
    }

    /** @return array{risk_level:string,decision_suggestion:string,needs_security_review:bool,evidence_count:int} */
    public function aiRiskAggregate(int $versionId): array
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $max = 0;
        $suggestion = 'NEEDS_MANUAL_REVIEW';
        $needsSecurity = false;
        $count = 0;
        foreach ($this->aiReviewEvidence($versionId) as $row) {
            $count++;
            $risk = strtolower((string) ($row['risk_level'] ?? 'medium'));
            $max = max($max, $rank[$risk] ?? 2);
            $decision = (string) ($row['decision_suggestion'] ?? '');
            if (in_array($decision, ['NEEDS_SECURITY_REVIEW', 'REJECT'], true)) {
                $suggestion = $decision;
            } elseif ($suggestion === 'NEEDS_MANUAL_REVIEW' && $decision === 'NEEDS_FIX') {
                $suggestion = 'NEEDS_FIX';
            }
            $needsSecurity = $needsSecurity || $risk === 'critical' || $decision === 'NEEDS_SECURITY_REVIEW';
        }
        $riskLevel = array_search($max === 0 ? 2 : $max, $rank, true);

        return [
            'risk_level' => is_string($riskLevel) ? $riskLevel : 'medium',
            'decision_suggestion' => $suggestion,
            'needs_security_review' => $needsSecurity,
            'evidence_count' => $count,
        ];
    }

    public function transitionVersion(int $versionId, string $to): void
    {
        $current = $this->versionStatus($versionId);
        (new ReviewStateMachine())->assertCanTransition($current, $to);
        $stmt = $this->pdo->prepare('UPDATE cms_market_versions SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $to, ':id' => $versionId]);
    }

    public function recordReview(int $versionId, int $reviewerId, string $decision, string $notes = ''): void
    {
        $target = match ($decision) {
            'approve' => ReviewState::APPROVED,
            'reject' => ReviewState::REJECTED,
            'return' => ReviewState::NEEDS_FIX,
            default => throw new MarketServerException('Unsupported review decision.'),
        };
        $this->transitionVersion($versionId, $target);
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_reviews (version_id, reviewer_id, decision, notes, created_at) VALUES (:version_id, :reviewer_id, :decision, :notes, :created_at)');
        $stmt->execute([
            ':version_id' => $versionId,
            ':reviewer_id' => $reviewerId,
            ':decision' => $decision,
            ':notes' => $notes,
            ':created_at' => gmdate('c'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function reviewsForProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, v.version, v.status AS version_status
             FROM cms_market_reviews r
             INNER JOIN cms_market_versions v ON v.id = r.version_id
             WHERE v.project_id = :project_id
             ORDER BY r.id DESC'
        );
        $stmt->execute([':project_id' => $projectId]);

        return $stmt->fetchAll();
    }

    /** @param list<int> $versionIds @return array{processed:int,errors:list<string>} */
    public function batchReview(array $versionIds, int $reviewerId, string $action, string $notes = '', ?PackageScanner $scanner = null, ?PackageSigner $signer = null): array
    {
        $processed = 0;
        $errors = [];
        foreach (array_values(array_unique(array_filter($versionIds, static fn (int $id): bool => $id > 0))) as $versionId) {
            try {
                if ($action === 'scan') {
                    $this->runScan($versionId, $scanner ?? new PackageScanner());
                } elseif (in_array($action, ['approve', 'reject', 'return'], true)) {
                    $this->recordReview($versionId, $reviewerId, $action, $notes);
                } elseif ($action === 'publish') {
                    if ($signer === null) {
                        throw new MarketServerException('Batch publish requires a package signer.');
                    }
                    $this->publishVersion($versionId, $this->signatureForVersion($versionId, $signer));
                } elseif ($action === 'suspend') {
                    $this->unpublishVersion($versionId, $reviewerId, $notes);
                } elseif ($action === 'remove') {
                    $this->deprecateVersion($versionId, $reviewerId, $notes);
                } else {
                    throw new MarketServerException('Unsupported batch review action.');
                }
                $processed++;
            } catch (MarketServerException $exception) {
                $errors[] = '#' . $versionId . ': ' . $exception->getMessage();
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /** @param array<string, mixed> $signature */
    public function publishVersion(int $versionId, array $signature): void
    {
        $version = $this->version($versionId);
        $currentHash = is_file($version->packagePath) ? hash_file('sha256', $version->packagePath) : false;
        if (!is_string($currentHash) || !hash_equals($version->packageSha256, $currentHash)) {
            throw new MarketServerException('Market package changed after submission and cannot be published.');
        }

        $this->transitionVersion($versionId, ReviewState::PUBLISHED);
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_package_signatures (version_id, algorithm, payload_json, signature, created_at) VALUES (:version_id, :algorithm, :payload_json, :signature, :created_at)');
        $stmt->execute([
            ':version_id' => $versionId,
            ':algorithm' => (string) ($signature['algorithm'] ?? ''),
            ':payload_json' => (string) ($signature['payload'] ?? ''),
            ':signature' => (string) ($signature['signature'] ?? ''),
            ':created_at' => gmdate('c'),
        ]);
        $this->notifyProjectMembers($this->version($versionId)->projectId, 'version_published', '版本已发布', '版本 #' . $versionId . ' 已发布。');
    }

    public function signatureForVersion(int $versionId, PackageSigner $signer): array
    {
        $version = $this->version($versionId);
        $project = $this->project($version->projectId);

        return $signer->sign([
            'market_id' => $project->marketId,
            'version' => $version->version,
            'package_sha256' => $version->packageSha256,
        ]);
    }

    public function withdrawVersion(int $versionId, int $developerId, string $notes = ''): void
    {
        $this->transitionVersion($versionId, ReviewState::WITHDRAWN);
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_reviews (version_id, reviewer_id, decision, notes, created_at) VALUES (:version_id, :reviewer_id, :decision, :notes, :created_at)');
        $stmt->execute([
            ':version_id' => $versionId,
            ':reviewer_id' => $developerId,
            ':decision' => 'withdraw',
            ':notes' => $notes,
            ':created_at' => gmdate('c'),
        ]);
        $this->notifyProjectMembers($this->version($versionId)->projectId, 'version_withdrawn', '版本已撤回', '版本 #' . $versionId . ' 已撤回。');
    }

    public function unpublishVersion(int $versionId, int $reviewerId, string $notes = ''): void
    {
        $this->transitionVersion($versionId, ReviewState::UNPUBLISHED);
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_reviews (version_id, reviewer_id, decision, notes, created_at) VALUES (:version_id, :reviewer_id, :decision, :notes, :created_at)');
        $stmt->execute([
            ':version_id' => $versionId,
            ':reviewer_id' => $reviewerId,
            ':decision' => 'unpublish',
            ':notes' => $notes,
            ':created_at' => gmdate('c'),
        ]);
        $this->notifyProjectMembers($this->version($versionId)->projectId, 'version_unpublished', '版本已下架', '版本 #' . $versionId . ' 已下架。');
    }

    public function deprecateVersion(int $versionId, int $reviewerId, string $notes = ''): void
    {
        $this->transitionVersion($versionId, ReviewState::DEPRECATED);
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_reviews (version_id, reviewer_id, decision, notes, created_at) VALUES (:version_id, :reviewer_id, :decision, :notes, :created_at)');
        $stmt->execute([
            ':version_id' => $versionId,
            ':reviewer_id' => $reviewerId,
            ':decision' => 'deprecate',
            ':notes' => $notes,
            ':created_at' => gmdate('c'),
        ]);
        $this->notifyProjectMembers($this->version($versionId)->projectId, 'version_deprecated', '版本已废弃', '版本 #' . $versionId . ' 已标记为 Deprecated。');
    }

    public function notifyDeveloper(int $developerId, ?int $projectId, string $type, string $title, string $body): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_developer_notifications (developer_id, project_id, type, title, body, dispatch_status, dispatch_attempts, next_attempt_at, read_at, created_at) VALUES (:developer_id, :project_id, :type, :title, :body, :dispatch_status, :dispatch_attempts, :next_attempt_at, :read_at, :created_at)');
        $stmt->execute([
            ':developer_id' => $developerId,
            ':project_id' => $projectId,
            ':type' => $type,
            ':title' => $title,
            ':body' => $body,
            ':dispatch_status' => 'Pending',
            ':dispatch_attempts' => 0,
            ':next_attempt_at' => null,
            ':read_at' => null,
            ':created_at' => gmdate('c'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function notificationsForDeveloper(int $developerId, bool $unreadOnly = false): array
    {
        $sql = 'SELECT * FROM cms_market_developer_notifications WHERE developer_id = :developer_id';
        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':developer_id' => $developerId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function pendingNotifications(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare('SELECT n.*, d.developer_key, d.display_name, d.email FROM cms_market_developer_notifications n INNER JOIN cms_market_developers d ON d.id = n.developer_id WHERE n.dispatch_status IN (\'Pending\', \'Retry\') AND (n.next_attempt_at IS NULL OR n.next_attempt_at <= :now) ORDER BY n.id ASC LIMIT ' . $limit);
        $stmt->execute([':now' => gmdate('c')]);

        return $stmt->fetchAll();
    }

    public function markNotificationDispatched(int $notificationId): void
    {
        $stmt = $this->pdo->prepare('UPDATE cms_market_developer_notifications SET dispatch_status = :status WHERE id = :id');
        $stmt->execute([':status' => 'Dispatched', ':id' => $notificationId]);
    }

    public function markNotificationDispatchFailed(int $notificationId): void
    {
        $attempts = $this->notificationDispatchAttemptsCount($notificationId) + 1;
        $delay = min(3600, 300 * $attempts);
        $stmt = $this->pdo->prepare('UPDATE cms_market_developer_notifications SET dispatch_status = :status, dispatch_attempts = :attempts, next_attempt_at = :next_attempt_at WHERE id = :id');
        $stmt->execute([
            ':status' => 'Failed',
            ':attempts' => $attempts,
            ':next_attempt_at' => gmdate('c', time() + $delay),
            ':id' => $notificationId,
        ]);
    }

    public function retryFailedNotifications(): int
    {
        $stmt = $this->pdo->prepare('UPDATE cms_market_developer_notifications SET dispatch_status = :retry, next_attempt_at = :next_attempt_at WHERE dispatch_status = :failed');
        $stmt->execute([':retry' => 'Retry', ':next_attempt_at' => gmdate('c'), ':failed' => 'Failed']);

        return $stmt->rowCount();
    }

    public function recordNotificationDispatchAttempt(int $notificationId, string $channel, string $status, string $errorMessage = ''): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_notification_dispatch_attempts (notification_id, channel, status, error_message, created_at) VALUES (:notification_id, :channel, :status, :error_message, :created_at)');
        $stmt->execute([
            ':notification_id' => $notificationId,
            ':channel' => $channel,
            ':status' => $status,
            ':error_message' => $errorMessage,
            ':created_at' => gmdate('c'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function notificationDispatchAttempts(int $notificationId = 0): array
    {
        $sql = 'SELECT * FROM cms_market_notification_dispatch_attempts';
        $params = [];
        if ($notificationId > 0) {
            $sql .= ' WHERE notification_id = :notification_id';
            $params[':notification_id'] = $notificationId;
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function notificationDispatchAttemptsCount(int $notificationId): int
    {
        $stmt = $this->pdo->prepare('SELECT dispatch_attempts FROM cms_market_developer_notifications WHERE id = :id');
        $stmt->execute([':id' => $notificationId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function latestNotificationDispatchAttempt(int $notificationId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_notification_dispatch_attempts WHERE notification_id = :notification_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':notification_id' => $notificationId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : [];
    }

    private function notifyProjectMembers(int $projectId, string $type, string $title, string $body): void
    {
        foreach ($this->projectMembers($projectId) as $member) {
            if ((string) ($member['status'] ?? '') !== 'Active') {
                continue;
            }
            $this->notifyDeveloper((int) $member['developer_id'], $projectId, $type, $title, $body);
        }
    }

    public function downloadToken(string $marketId, string $siteId, int $expiresAt, array $scopes = ['download']): string
    {
        sort($scopes);

        return hash('sha256', $marketId . '|' . $siteId . '|' . $expiresAt . '|' . implode(',', $scopes));
    }

    /** @param list<string> $requiredScopes */
    public function assertDownloadAuthorized(string $marketId, string $siteId, string $token, int $expiresAt, array $requiredScopes = ['download']): void
    {
        if ($siteId === '' || $token === '' || $expiresAt < time()) {
            throw new MarketServerException('Download authorization is invalid or expired.');
        }
        if (!hash_equals($this->downloadToken($marketId, $siteId, $expiresAt, $requiredScopes), $token)) {
            throw new MarketServerException('Download authorization is invalid or expired.');
        }
    }

    /** @param list<string> $requiredScopes */
    public function assertLicensedDownloadAuthorized(string $marketId, string $siteId, string $token, int $expiresAt, string $licenseKey, array $requiredScopes = ['download']): void
    {
        $this->assertDownloadAuthorized($marketId, $siteId, $token, $expiresAt, $requiredScopes);
        $this->assertLicenseActive($marketId, $siteId, $licenseKey);
    }

    public function recordDownloadAudit(string $marketId, string $version, string $siteId, string $packageSha256, string $ipAddress = '', string $userAgent = ''): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_download_audits (market_id, version, site_id, package_sha256, ip_address, user_agent, created_at) VALUES (:market_id, :version, :site_id, :package_sha256, :ip_address, :user_agent, :created_at)');
        $stmt->execute([
            ':market_id' => $marketId,
            ':version' => $version,
            ':site_id' => $siteId,
            ':package_sha256' => $packageSha256,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent,
            ':created_at' => gmdate('c'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function downloadAudits(string $marketId = ''): array
    {
        $sql = 'SELECT * FROM cms_market_download_audits';
        $params = [];
        if ($marketId !== '') {
            $sql .= ' WHERE market_id = :market_id';
            $params[':market_id'] = $marketId;
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int} */
    public function downloadAuditPage(string $marketId = '', int $page = 1, int $perPage = 20): array
    {
        $items = $this->downloadAudits($marketId);
        $total = count($items);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $pages = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    public function downloadAuditCsv(string $marketId = ''): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new MarketServerException('Unable to open CSV buffer.');
        }
        fputcsv($handle, ['market_id', 'version', 'site_id', 'package_sha256', 'ip_address', 'user_agent', 'created_at'], ',', '"', '\\');
        foreach ($this->downloadAudits($marketId) as $audit) {
            fputcsv($handle, [
                (string) $audit['market_id'],
                (string) $audit['version'],
                (string) $audit['site_id'],
                (string) $audit['package_sha256'],
                (string) $audit['ip_address'],
                (string) $audit['user_agent'],
                (string) $audit['created_at'],
            ], ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /** @return array<string, mixed> */
    public function recordPayment(string $marketId, string $siteId, int $amountCents, string $currency = 'USD', string $provider = 'manual', string $providerReference = '', string $planKey = '', string $licenseKey = '', string $subscriptionId = '', array $billing = []): array
    {
        if ($marketId === '' || $siteId === '' || $amountCents < 0) {
            throw new MarketServerException('Payment market, site and amount are required.');
        }
        $now = gmdate('c');
        $invoiceNumber = trim((string) ($billing['invoice_number'] ?? ''));
        if ($invoiceNumber === '') {
            $invoiceNumber = 'INV-' . gmdate('Ymd') . '-' . substr(hash('sha256', $marketId . $siteId . microtime(true)), 0, 8);
        }
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_payments (market_id, site_id, plan_key, license_key, subscription_id, invoice_number, billing_email, billing_country, customer_tax_id, tax_amount_cents, amount_cents, currency, status, provider, provider_reference, created_at, updated_at) VALUES (:market_id, :site_id, :plan_key, :license_key, :subscription_id, :invoice_number, :billing_email, :billing_country, :customer_tax_id, :tax_amount_cents, :amount_cents, :currency, :status, :provider, :provider_reference, :created_at, :updated_at)');
        $stmt->execute([
            ':market_id' => $marketId,
            ':site_id' => $siteId,
            ':plan_key' => $planKey,
            ':license_key' => $licenseKey,
            ':subscription_id' => $subscriptionId,
            ':invoice_number' => $invoiceNumber,
            ':billing_email' => (string) ($billing['billing_email'] ?? ''),
            ':billing_country' => strtoupper((string) ($billing['billing_country'] ?? '')),
            ':customer_tax_id' => (string) ($billing['customer_tax_id'] ?? ''),
            ':tax_amount_cents' => max(0, (int) ($billing['tax_amount_cents'] ?? 0)),
            ':amount_cents' => $amountCents,
            ':currency' => strtoupper($currency),
            ':status' => 'Pending',
            ':provider' => $provider,
            ':provider_reference' => $providerReference,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'market_id' => $marketId,
            'site_id' => $siteId,
            'plan_key' => $planKey,
            'license_key' => $licenseKey,
            'subscription_id' => $subscriptionId,
            'invoice_number' => $invoiceNumber,
            'billing_email' => (string) ($billing['billing_email'] ?? ''),
            'billing_country' => strtoupper((string) ($billing['billing_country'] ?? '')),
            'customer_tax_id' => (string) ($billing['customer_tax_id'] ?? ''),
            'tax_amount_cents' => max(0, (int) ($billing['tax_amount_cents'] ?? 0)),
            'amount_cents' => $amountCents,
            'currency' => strtoupper($currency),
            'status' => 'Pending',
            'provider' => $provider,
            'provider_reference' => $providerReference,
        ];
    }

    /** @return array<string, mixed> */
    public function recordPlanPayment(string $marketId, string $siteId, string $planKey, string $provider = 'manual', string $licenseKey = '', string $subscriptionId = '', array $billing = []): array
    {
        $plan = $this->plan($marketId, $planKey);

        return $this->recordPayment(
            $marketId,
            $siteId,
            (int) $plan['price_cents'],
            (string) $plan['currency'],
            $provider,
            '',
            $planKey,
            $licenseKey,
            $subscriptionId,
            $billing
        );
    }

    public function markPaymentPaid(int $paymentId, string $providerReference = ''): void
    {
        $this->markPaymentStatus($paymentId, 'Paid', $providerReference);
    }

    public function markPaymentStatus(int $paymentId, string $status, string $providerReference = ''): void
    {
        $stmt = $this->pdo->prepare('UPDATE cms_market_payments SET status = :status, provider_reference = CASE WHEN :provider_reference = \'\' THEN provider_reference ELSE :provider_reference END, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':status' => $this->normalizePaymentStatus($status),
            ':provider_reference' => $providerReference,
            ':updated_at' => gmdate('c'),
            ':id' => $paymentId,
        ]);
        if ($stmt->rowCount() < 1) {
            throw new MarketServerException('Payment was not found.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function payments(string $marketId = ''): array
    {
        $sql = 'SELECT * FROM cms_market_payments';
        $params = [];
        if ($marketId !== '') {
            $sql .= ' WHERE market_id = :market_id';
            $params[':market_id'] = $marketId;
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array{payments:int,gross_cents:int,tax_cents:int,net_cents:int,by_country:array<string, array{payments:int,gross_cents:int,tax_cents:int,net_cents:int}>} */
    public function billingSummary(string $marketId = '', string $status = 'Paid'): array
    {
        $summary = ['payments' => 0, 'gross_cents' => 0, 'tax_cents' => 0, 'net_cents' => 0, 'by_country' => []];
        foreach ($this->payments($marketId) as $payment) {
            if ($status !== '' && (string) ($payment['status'] ?? '') !== $status) {
                continue;
            }
            $country = strtoupper((string) ($payment['billing_country'] ?? ''));
            $country = $country === '' ? 'UNSPECIFIED' : $country;
            $gross = (int) ($payment['amount_cents'] ?? 0);
            $tax = (int) ($payment['tax_amount_cents'] ?? 0);
            $net = max(0, $gross - $tax);
            if (!isset($summary['by_country'][$country])) {
                $summary['by_country'][$country] = ['payments' => 0, 'gross_cents' => 0, 'tax_cents' => 0, 'net_cents' => 0];
            }
            $summary['payments']++;
            $summary['gross_cents'] += $gross;
            $summary['tax_cents'] += $tax;
            $summary['net_cents'] += $net;
            $summary['by_country'][$country]['payments']++;
            $summary['by_country'][$country]['gross_cents'] += $gross;
            $summary['by_country'][$country]['tax_cents'] += $tax;
            $summary['by_country'][$country]['net_cents'] += $net;
        }

        return $summary;
    }

    public function billingSummaryCsv(string $marketId = '', string $status = 'Paid'): string
    {
        $summary = $this->billingSummary($marketId, $status);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new MarketServerException('Unable to open CSV buffer.');
        }
        fputcsv($handle, ['country', 'payments', 'gross_cents', 'tax_cents', 'net_cents'], ',', '"', '\\');
        foreach ($summary['by_country'] as $country => $row) {
            fputcsv($handle, [$country, (string) $row['payments'], (string) $row['gross_cents'], (string) $row['tax_cents'], (string) $row['net_cents']], ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    public function exportBillingSummaryCsv(string $marketId = '', string $status = 'Paid', string $actor = 'system'): string
    {
        $csv = $this->billingSummaryCsv($marketId, $status);
        $this->recordCommercialAudit('billing_summary_exported', $marketId === '' ? 'all-markets' : $marketId, $actor, [
            'status' => $status,
            'bytes' => strlen($csv),
        ]);

        return $csv;
    }

    /** @param array<string, mixed> $payload */
    public function recordPaymentWebhookEvent(string $eventId, string $provider, array $payload): void
    {
        if ($eventId === '') {
            throw new MarketServerException('Payment webhook event_id is required.');
        }
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_payment_webhook_events (event_id, provider, payload_json, created_at) VALUES (:event_id, :provider, :payload_json, :created_at)');
        try {
            $stmt->execute([
                ':event_id' => $eventId,
                ':provider' => $provider,
                ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':created_at' => gmdate('c'),
            ]);
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'UNIQUE') || str_contains($exception->getMessage(), 'Duplicate')) {
                throw new MarketServerException('Payment webhook event was already processed.');
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function applyPaymentWebhook(array $payload): array
    {
        $paymentId = (int) ($payload['payment_id'] ?? 0);
        $providerReference = (string) ($payload['provider_reference'] ?? '');
        $status = $this->paymentStatusFromWebhook((string) ($payload['status'] ?? 'pending'));
        if ($paymentId <= 0) {
            $payment = $this->recordPayment(
                (string) ($payload['market_id'] ?? ''),
                (string) ($payload['site_id'] ?? ''),
                (int) ($payload['amount_cents'] ?? 0),
                (string) ($payload['currency'] ?? 'USD'),
                (string) ($payload['provider'] ?? 'webhook'),
                $providerReference,
                (string) ($payload['plan_key'] ?? ''),
                (string) ($payload['license_key'] ?? ''),
                (string) ($payload['subscription_id'] ?? ''),
                [
                    'invoice_number' => (string) ($payload['invoice_number'] ?? ''),
                    'billing_email' => (string) ($payload['billing_email'] ?? ''),
                    'billing_country' => (string) ($payload['billing_country'] ?? ''),
                    'customer_tax_id' => (string) ($payload['customer_tax_id'] ?? ''),
                    'tax_amount_cents' => (int) ($payload['tax_amount_cents'] ?? 0),
                ]
            );
            $paymentId = (int) $payment['id'];
        }
        $this->markPaymentStatus($paymentId, $status, $providerReference);
        if ((string) ($payload['subscription_id'] ?? '') !== '') {
            $this->syncSubscriptionFromWebhook($payload);
        }

        return $this->payment($paymentId);
    }

    /** @return array<string, mixed> */
    public function payment(int $paymentId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_payments WHERE id = :id');
        $stmt->execute([':id' => $paymentId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MarketServerException('Payment was not found.');
        }

        return $row;
    }

    public function paymentReceiptCsv(int $paymentId): string
    {
        $payment = $this->payment($paymentId);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new MarketServerException('Unable to open CSV buffer.');
        }
        fputcsv($handle, ['field', 'value'], ',', '"', '\\');
        foreach (['id', 'invoice_number', 'market_id', 'site_id', 'plan_key', 'license_key', 'subscription_id', 'billing_email', 'billing_country', 'customer_tax_id', 'tax_amount_cents', 'amount_cents', 'currency', 'status', 'provider', 'provider_reference', 'created_at', 'updated_at'] as $field) {
            fputcsv($handle, [$field, (string) ($payment[$field] ?? '')], ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    public function paymentInvoicePdf(int $paymentId): string
    {
        $payment = $this->payment($paymentId);
        $lines = [
            'PHP CMS Commercial Invoice',
            'Invoice: ' . (string) ($payment['invoice_number'] ?? ''),
            'Payment ID: ' . (string) $payment['id'],
            'Market ID: ' . (string) $payment['market_id'],
            'Site ID: ' . (string) $payment['site_id'],
            'Plan: ' . (string) ($payment['plan_key'] ?? ''),
            'Billing Email: ' . (string) ($payment['billing_email'] ?? ''),
            'Tax ID: ' . (string) ($payment['customer_tax_id'] ?? ''),
            'Tax: ' . number_format(((int) ($payment['tax_amount_cents'] ?? 0)) / 100, 2) . ' ' . (string) $payment['currency'],
            'Total: ' . number_format(((int) $payment['amount_cents']) / 100, 2) . ' ' . (string) $payment['currency'],
            'Status: ' . (string) $payment['status'],
        ];

        return $this->simplePdf($lines);
    }

    /** @return array<string, mixed> */
    public function createSettlement(int $developerId, int $amountCents, string $currency, string $periodStart, string $periodEnd): array
    {
        if ($developerId <= 0 || $amountCents < 0 || $periodStart === '' || $periodEnd === '') {
            throw new MarketServerException('Settlement developer, amount and period are required.');
        }
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_settlements (developer_id, amount_cents, currency, status, period_start, period_end, created_at, settled_at) VALUES (:developer_id, :amount_cents, :currency, :status, :period_start, :period_end, :created_at, :settled_at)');
        $stmt->execute([
            ':developer_id' => $developerId,
            ':amount_cents' => $amountCents,
            ':currency' => strtoupper($currency),
            ':status' => 'Pending',
            ':period_start' => $periodStart,
            ':period_end' => $periodEnd,
            ':created_at' => gmdate('c'),
            ':settled_at' => null,
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'developer_id' => $developerId,
            'amount_cents' => $amountCents,
            'currency' => strtoupper($currency),
            'status' => 'Pending',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function settlementsForDeveloper(int $developerId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_settlements WHERE developer_id = :developer_id ORDER BY id DESC');
        $stmt->execute([':developer_id' => $developerId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function settlements(): array
    {
        $stmt = $this->pdo->query('SELECT s.*, d.developer_key, d.display_name, d.email FROM cms_market_settlements s INNER JOIN cms_market_developers d ON d.id = s.developer_id ORDER BY s.id DESC');

        return $stmt->fetchAll();
    }

    public function settlementCsv(): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new MarketServerException('Unable to open CSV buffer.');
        }
        fputcsv($handle, ['developer_key', 'display_name', 'amount_cents', 'currency', 'status', 'period_start', 'period_end', 'settled_at'], ',', '"', '\\');
        foreach ($this->settlements() as $settlement) {
            fputcsv($handle, [
                (string) $settlement['developer_key'],
                (string) $settlement['display_name'],
                (string) $settlement['amount_cents'],
                (string) $settlement['currency'],
                (string) $settlement['status'],
                (string) $settlement['period_start'],
                (string) $settlement['period_end'],
                (string) ($settlement['settled_at'] ?? ''),
            ], ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    public function markSettlementStatus(int $settlementId, string $status): void
    {
        $status = $this->normalizeSettlementStatus($status);
        $stmt = $this->pdo->prepare('UPDATE cms_market_settlements SET status = :status, settled_at = :settled_at WHERE id = :id');
        $stmt->execute([
            ':status' => $status,
            ':settled_at' => $status === 'Paid' ? gmdate('c') : null,
            ':id' => $settlementId,
        ]);
        if ($stmt->rowCount() < 1) {
            throw new MarketServerException('Settlement was not found.');
        }
    }

    /** @return array<string, mixed> */
    public function saveAiSettings(string $provider, string $model, string $apiKeyRef, string $status = 'Active'): array
    {
        if ($provider === '' || $model === '') {
            throw new MarketServerException('AI provider and model are required.');
        }
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_ai_settings (provider, model, api_key_ref, status, updated_at) VALUES (:provider, :model, :api_key_ref, :status, :updated_at)');
        $stmt->execute([
            ':provider' => $provider,
            ':model' => $model,
            ':api_key_ref' => $apiKeyRef,
            ':status' => $status === '' ? 'Active' : $status,
            ':updated_at' => gmdate('c'),
        ]);

        return $this->aiSettings();
    }

    /** @return array<string, mixed> */
    public function aiSettings(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM cms_market_ai_settings ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch();

        return is_array($row) ? $row : [];
    }

    /** @return array<string, mixed> */
    public function saveAiPolicy(bool $autoApprove, int $maxFindings = 0, int $reviewerId = 1): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_ai_policies (auto_approve, max_findings, reviewer_id, updated_at) VALUES (:auto_approve, :max_findings, :reviewer_id, :updated_at)');
        $stmt->execute([
            ':auto_approve' => $autoApprove ? 1 : 0,
            ':max_findings' => max(0, $maxFindings),
            ':reviewer_id' => max(1, $reviewerId),
            ':updated_at' => gmdate('c'),
        ]);

        return $this->aiPolicy();
    }

    /** @return array<string, mixed> */
    public function aiPolicy(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM cms_market_ai_policies ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch();

        return is_array($row) ? $row : ['auto_approve' => 0, 'max_findings' => 0, 'reviewer_id' => 1];
    }

    /** @return array<string, int> */
    public function operationsSummary(): array
    {
        return [
            'projects' => $this->countTable('cms_market_projects'),
            'published_versions' => $this->countWhere('cms_market_versions', 'status', ReviewState::PUBLISHED),
            'pending_reviews' => $this->countWhere('cms_market_versions', 'status', ReviewState::NEEDS_REVIEW),
            'payments_paid' => $this->countWhere('cms_market_payments', 'status', 'Paid'),
            'settlements_pending' => $this->countWhere('cms_market_settlements', 'status', 'Pending'),
            'notifications_failed' => $this->countWhere('cms_market_developer_notifications', 'dispatch_status', 'Failed'),
            'active_licenses' => $this->countWhere('cms_market_licenses', 'status', 'Active'),
        ];
    }

    /** @return array{labels:list<string>,values:list<int>} */
    public function operationsChartData(): array
    {
        $summary = $this->operationsSummary();

        return ['labels' => array_keys($summary), 'values' => array_values($summary)];
    }

    /** @return array{labels:list<string>,payments:list<int>,downloads:list<int>,licenses:list<int>} */
    public function operationsTrendData(): array
    {
        $labels = [];
        $payments = [];
        $downloads = [];
        $licenses = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime('-' . $i . ' days'));
            $labels[] = $day;
            $payments[] = $this->countDatePrefix('cms_market_payments', 'created_at', $day);
            $downloads[] = $this->countDatePrefix('cms_market_download_audits', 'created_at', $day);
            $licenses[] = $this->countDatePrefix('cms_market_licenses', 'created_at', $day);
        }

        return ['labels' => $labels, 'payments' => $payments, 'downloads' => $downloads, 'licenses' => $licenses];
    }

    /** @return array<string, mixed> */
    public function issueLicense(string $marketId, string $siteId, int $seats = 1, string $expiresAt = '', string $planKey = '', bool $autoRenew = false): array
    {
        if ($marketId === '' || $siteId === '') {
            throw new MarketServerException('License market and site are required.');
        }
        $licenseKey = hash('sha256', $marketId . '|' . $siteId . '|' . microtime(true));
        $now = gmdate('c');
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_licenses (market_id, site_id, license_key, status, seats, plan_key, auto_renew, expires_at, created_at, updated_at) VALUES (:market_id, :site_id, :license_key, :status, :seats, :plan_key, :auto_renew, :expires_at, :created_at, :updated_at)');
        $stmt->execute([
            ':market_id' => $marketId,
            ':site_id' => $siteId,
            ':license_key' => $licenseKey,
            ':status' => 'Active',
            ':seats' => max(1, $seats),
            ':plan_key' => $planKey,
            ':auto_renew' => $autoRenew ? 1 : 0,
            ':expires_at' => $expiresAt === '' ? null : $expiresAt,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $this->recordLicenseAudit($licenseKey, 'issued', 'system', [
            'market_id' => $marketId,
            'site_id' => $siteId,
            'seats' => max(1, $seats),
            'plan_key' => $planKey,
            'auto_renew' => $autoRenew,
            'expires_at' => $expiresAt,
        ]);

        return $this->licenseByKey($licenseKey);
    }

    public function renewLicense(string $licenseKey, string $expiresAt, int $seats = 0): void
    {
        if ($expiresAt === '') {
            throw new MarketServerException('License renewal expiry is required.');
        }
        $stmt = $this->pdo->prepare('UPDATE cms_market_licenses SET status = :status, expires_at = :expires_at, seats = CASE WHEN :seats > 0 THEN :seats ELSE seats END, updated_at = :updated_at WHERE license_key = :license_key');
        $stmt->execute([
            ':status' => 'Active',
            ':expires_at' => $expiresAt,
            ':seats' => $seats,
            ':updated_at' => gmdate('c'),
            ':license_key' => $licenseKey,
        ]);
        if ($stmt->rowCount() < 1) {
            throw new MarketServerException('License was not found.');
        }
        $this->recordLicenseAudit($licenseKey, 'renewed', 'system', ['expires_at' => $expiresAt, 'seats' => $seats]);
    }

    public function revokeLicense(string $licenseKey): void
    {
        $stmt = $this->pdo->prepare('UPDATE cms_market_licenses SET status = :status, updated_at = :updated_at WHERE license_key = :license_key');
        $stmt->execute([':status' => 'Revoked', ':updated_at' => gmdate('c'), ':license_key' => $licenseKey]);
        if ($stmt->rowCount() < 1) {
            throw new MarketServerException('License was not found.');
        }
        $this->recordLicenseAudit($licenseKey, 'revoked', 'system');
    }

    public function changeLicensePlan(string $licenseKey, string $planKey, int $seats = 0): void
    {
        if ($planKey === '') {
            throw new MarketServerException('Plan key is required.');
        }
        $stmt = $this->pdo->prepare('UPDATE cms_market_licenses SET plan_key = :plan_key, seats = CASE WHEN :seats > 0 THEN :seats ELSE seats END, updated_at = :updated_at WHERE license_key = :license_key');
        $stmt->execute([
            ':plan_key' => $planKey,
            ':seats' => $seats,
            ':updated_at' => gmdate('c'),
            ':license_key' => $licenseKey,
        ]);
        if ($stmt->rowCount() < 1) {
            throw new MarketServerException('License was not found.');
        }
        $this->recordLicenseAudit($licenseKey, 'plan_changed', 'system', ['plan_key' => $planKey, 'seats' => $seats]);
    }

    public function setLicenseAutoRenew(string $licenseKey, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE cms_market_licenses SET auto_renew = :auto_renew, updated_at = :updated_at WHERE license_key = :license_key');
        $stmt->execute([
            ':auto_renew' => $enabled ? 1 : 0,
            ':updated_at' => gmdate('c'),
            ':license_key' => $licenseKey,
        ]);
        if ($stmt->rowCount() < 1) {
            throw new MarketServerException('License was not found.');
        }
        $this->recordLicenseAudit($licenseKey, $enabled ? 'auto_renew_enabled' : 'auto_renew_disabled', 'system');
    }

    public function processAutoRenewals(string $period = '+1 year'): int
    {
        $threshold = gmdate('c', time() + 30 * 86400);
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_licenses WHERE status = :status AND auto_renew = 1 AND expires_at IS NOT NULL AND expires_at <= :threshold ORDER BY id ASC');
        $stmt->execute([':status' => 'Active', ':threshold' => $threshold]);
        $count = 0;
        foreach ($stmt->fetchAll() as $license) {
            $base = strtotime((string) ($license['expires_at'] ?? '')) ?: time();
            $newExpiry = gmdate('c', strtotime($period, $base) ?: (time() + 365 * 86400));
            $payment = (string) ($license['plan_key'] ?? '') === ''
                ? $this->recordPayment((string) $license['market_id'], (string) $license['site_id'], 0, 'USD', 'auto_renewal')
                : $this->recordPlanPayment((string) $license['market_id'], (string) $license['site_id'], (string) $license['plan_key'], 'auto_renewal', (string) $license['license_key']);
            $this->markPaymentPaid((int) $payment['id'], 'auto-renew-' . (string) $license['license_key'] . '-' . gmdate('YmdHis'));
            $this->renewLicense((string) $license['license_key'], $newExpiry, (int) $license['seats']);
            $this->recordLicenseAudit((string) $license['license_key'], 'auto_renewed', 'system', ['payment_id' => (int) $payment['id'], 'expires_at' => $newExpiry]);
            $count++;
        }

        return $count;
    }

    public function recordLicenseAudit(string $licenseKey, string $action, string $actor = 'system', array $payload = []): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_license_audits (license_key, action, actor, payload_json, created_at) VALUES (:license_key, :action, :actor, :payload_json, :created_at)');
        $stmt->execute([
            ':license_key' => $licenseKey,
            ':action' => $action,
            ':actor' => $actor,
            ':payload_json' => is_string($json) ? $json : '{}',
            ':created_at' => gmdate('c'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function licenseAudits(string $licenseKey = ''): array
    {
        $sql = 'SELECT * FROM cms_market_license_audits';
        $params = [];
        if ($licenseKey !== '') {
            $sql .= ' WHERE license_key = :license_key';
            $params[':license_key'] = $licenseKey;
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    public function licenseByKey(string $licenseKey): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_licenses WHERE license_key = :license_key');
        $stmt->execute([':license_key' => $licenseKey]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MarketServerException('License was not found.');
        }

        return $row;
    }

    public function assertLicenseActive(string $marketId, string $siteId, string $licenseKey): void
    {
        $license = $this->licenseByKey($licenseKey);
        if ((string) $license['market_id'] !== $marketId || (string) $license['site_id'] !== $siteId || (string) $license['status'] !== 'Active') {
            throw new MarketServerException('License is not active for this site.');
        }
        $expiresAt = (string) ($license['expires_at'] ?? '');
        if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
            throw new MarketServerException('License is expired.');
        }
        if ((int) ($this->commercialPolicy()['enforce_seat_limits'] ?? 1) === 1) {
            $this->assertLicenseUsageWithinSeats($licenseKey);
        }
    }

    /** @return list<array<string, mixed>> */
    public function licenses(string $marketId = ''): array
    {
        $sql = 'SELECT * FROM cms_market_licenses';
        $params = [];
        if ($marketId !== '') {
            $sql .= ' WHERE market_id = :market_id';
            $params[':market_id'] = $marketId;
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    public function createPlan(string $marketId, string $planKey, string $name, int $priceCents, string $currency = 'USD', string $billingPeriod = 'one_time', int $trialDays = 0): array
    {
        if ($marketId === '' || $planKey === '' || $name === '' || $priceCents < 0) {
            throw new MarketServerException('Plan market, key, name and price are required.');
        }
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_plans (market_id, plan_key, name, price_cents, currency, billing_period, trial_days, status, created_at) VALUES (:market_id, :plan_key, :name, :price_cents, :currency, :billing_period, :trial_days, :status, :created_at)');
        $stmt->execute([
            ':market_id' => $marketId,
            ':plan_key' => $planKey,
            ':name' => $name,
            ':price_cents' => $priceCents,
            ':currency' => strtoupper($currency),
            ':billing_period' => $billingPeriod,
            ':trial_days' => max(0, $trialDays),
            ':status' => 'Active',
            ':created_at' => gmdate('c'),
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'market_id' => $marketId,
            'plan_key' => $planKey,
            'name' => $name,
            'price_cents' => $priceCents,
            'currency' => strtoupper($currency),
            'billing_period' => $billingPeriod,
            'trial_days' => max(0, $trialDays),
            'status' => 'Active',
        ];
    }

    /** @return array<string, mixed> */
    public function plan(string $marketId, string $planKey): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_plans WHERE market_id = :market_id AND plan_key = :plan_key AND status = :status ORDER BY id DESC LIMIT 1');
        $stmt->execute([':market_id' => $marketId, ':plan_key' => $planKey, ':status' => 'Active']);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MarketServerException('Commercial plan was not found.');
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function plans(string $marketId = ''): array
    {
        $sql = 'SELECT * FROM cms_market_plans';
        $params = [];
        if ($marketId !== '') {
            $sql .= ' WHERE market_id = :market_id';
            $params[':market_id'] = $marketId;
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    public function saveNotificationSchedule(string $scheduleKey, string $channel, int $intervalMinutes, bool $enabled = true): array
    {
        if ($scheduleKey === '' || $channel === '' || $intervalMinutes < 1) {
            throw new MarketServerException('Notification schedule key, channel and interval are required.');
        }
        $now = gmdate('c');
        $nextRunAt = gmdate('c', time());
        $existing = $this->notificationSchedule($scheduleKey);
        if ($existing !== []) {
            $stmt = $this->pdo->prepare('UPDATE cms_market_notification_schedules SET channel = :channel, interval_minutes = :interval_minutes, enabled = :enabled, next_run_at = :next_run_at WHERE schedule_key = :schedule_key');
            $stmt->execute([
                ':channel' => $channel,
                ':interval_minutes' => $intervalMinutes,
                ':enabled' => $enabled ? 1 : 0,
                ':next_run_at' => $nextRunAt,
                ':schedule_key' => $scheduleKey,
            ]);

            return $this->notificationSchedule($scheduleKey);
        }
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_notification_schedules (schedule_key, channel, interval_minutes, enabled, last_run_at, next_run_at, created_at) VALUES (:schedule_key, :channel, :interval_minutes, :enabled, :last_run_at, :next_run_at, :created_at)');
        $stmt->execute([
            ':schedule_key' => $scheduleKey,
            ':channel' => $channel,
            ':interval_minutes' => $intervalMinutes,
            ':enabled' => $enabled ? 1 : 0,
            ':last_run_at' => null,
            ':next_run_at' => $nextRunAt,
            ':created_at' => $now,
        ]);

        return $this->notificationSchedule($scheduleKey);
    }

    /** @return array<string, mixed> */
    public function notificationSchedule(string $scheduleKey): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_notification_schedules WHERE schedule_key = :schedule_key');
        $stmt->execute([':schedule_key' => $scheduleKey]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : [];
    }

    /** @return list<array<string, mixed>> */
    public function notificationSchedules(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM cms_market_notification_schedules ORDER BY id DESC');

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function dueNotificationSchedules(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_notification_schedules WHERE enabled = 1 AND next_run_at <= :now ORDER BY id ASC');
        $stmt->execute([':now' => gmdate('c')]);

        return $stmt->fetchAll();
    }

    public function markNotificationScheduleRun(int $scheduleId): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_notification_schedules WHERE id = :id');
        $stmt->execute([':id' => $scheduleId]);
        $schedule = $stmt->fetch();
        if (!is_array($schedule)) {
            throw new MarketServerException('Notification schedule was not found.');
        }
        $now = time();
        $next = gmdate('c', $now + max(1, (int) $schedule['interval_minutes']) * 60);
        $update = $this->pdo->prepare('UPDATE cms_market_notification_schedules SET last_run_at = :last_run_at, next_run_at = :next_run_at WHERE id = :id');
        $update->execute([':last_run_at' => gmdate('c', $now), ':next_run_at' => $next, ':id' => $scheduleId]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function syncSubscriptionFromWebhook(array $payload): array
    {
        $subscriptionId = (string) ($payload['subscription_id'] ?? $payload['provider_subscription_id'] ?? '');
        if ($subscriptionId === '') {
            throw new MarketServerException('Subscription id is required.');
        }
        $licenseKey = (string) ($payload['license_key'] ?? '');
        $status = $this->subscriptionStatusFromWebhook((string) ($payload['subscription_status'] ?? $payload['status'] ?? 'active'));
        $now = gmdate('c');
        $existing = $this->subscription($subscriptionId);
        if ($existing === []) {
            $stmt = $this->pdo->prepare('INSERT INTO cms_market_subscriptions (provider, provider_subscription_id, license_key, market_id, site_id, plan_key, status, current_period_end, cancel_at_period_end, created_at, updated_at) VALUES (:provider, :provider_subscription_id, :license_key, :market_id, :site_id, :plan_key, :status, :current_period_end, :cancel_at_period_end, :created_at, :updated_at)');
            $stmt->execute([
                ':provider' => (string) ($payload['provider'] ?? 'webhook'),
                ':provider_subscription_id' => $subscriptionId,
                ':license_key' => $licenseKey,
                ':market_id' => (string) ($payload['market_id'] ?? ''),
                ':site_id' => (string) ($payload['site_id'] ?? ''),
                ':plan_key' => (string) ($payload['plan_key'] ?? ''),
                ':status' => $status,
                ':current_period_end' => (string) ($payload['current_period_end'] ?? ''),
                ':cancel_at_period_end' => !empty($payload['cancel_at_period_end']) ? 1 : 0,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } else {
            $stmt = $this->pdo->prepare('UPDATE cms_market_subscriptions SET license_key = :license_key, market_id = :market_id, site_id = :site_id, plan_key = :plan_key, status = :status, current_period_end = :current_period_end, cancel_at_period_end = :cancel_at_period_end, updated_at = :updated_at WHERE provider_subscription_id = :provider_subscription_id');
            $stmt->execute([
                ':license_key' => $licenseKey !== '' ? $licenseKey : (string) $existing['license_key'],
                ':market_id' => (string) ($payload['market_id'] ?? $existing['market_id']),
                ':site_id' => (string) ($payload['site_id'] ?? $existing['site_id']),
                ':plan_key' => (string) ($payload['plan_key'] ?? $existing['plan_key']),
                ':status' => $status,
                ':current_period_end' => (string) ($payload['current_period_end'] ?? $existing['current_period_end']),
                ':cancel_at_period_end' => !empty($payload['cancel_at_period_end']) ? 1 : 0,
                ':updated_at' => $now,
                ':provider_subscription_id' => $subscriptionId,
            ]);
        }
        if ($licenseKey !== '') {
            if (in_array($status, ['Active', 'Trialing'], true)) {
                $expiresAt = (string) ($payload['current_period_end'] ?? '');
                if ($expiresAt !== '') {
                    $this->renewLicense($licenseKey, $expiresAt);
                }
            } elseif (in_array($status, ['Canceled', 'Past Due', 'Unpaid'], true)) {
                $this->revokeLicense($licenseKey);
            }
            $this->recordLicenseAudit($licenseKey, 'subscription_synced', 'webhook', ['subscription_id' => $subscriptionId, 'status' => $status]);
        }

        return $this->subscription($subscriptionId);
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        $subscription = $this->subscription($subscriptionId);
        if ($subscription === []) {
            throw new MarketServerException('Subscription was not found.');
        }
        $stmt = $this->pdo->prepare('UPDATE cms_market_subscriptions SET status = :status, cancel_at_period_end = 1, updated_at = :updated_at WHERE provider_subscription_id = :provider_subscription_id');
        $stmt->execute([':status' => 'Canceled', ':updated_at' => gmdate('c'), ':provider_subscription_id' => $subscriptionId]);
        if ((string) ($subscription['license_key'] ?? '') !== '') {
            $this->recordLicenseAudit((string) $subscription['license_key'], 'subscription_cancel_requested', 'admin', ['subscription_id' => $subscriptionId]);
        }
        $this->queueSubscriptionAction($subscriptionId, 'cancel', ['cancel_at_period_end' => true]);
    }

    public function restoreSubscription(string $subscriptionId): void
    {
        $subscription = $this->subscription($subscriptionId);
        if ($subscription === []) {
            throw new MarketServerException('Subscription was not found.');
        }
        $stmt = $this->pdo->prepare('UPDATE cms_market_subscriptions SET status = :status, cancel_at_period_end = 0, updated_at = :updated_at WHERE provider_subscription_id = :provider_subscription_id');
        $stmt->execute([':status' => 'Active', ':updated_at' => gmdate('c'), ':provider_subscription_id' => $subscriptionId]);
        if ((string) ($subscription['license_key'] ?? '') !== '') {
            $this->recordLicenseAudit((string) $subscription['license_key'], 'subscription_restored', 'admin', ['subscription_id' => $subscriptionId]);
        }
        $this->queueSubscriptionAction($subscriptionId, 'restore', ['cancel_at_period_end' => false]);
    }

    /** @return array<string, mixed> */
    public function subscription(string $subscriptionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_subscriptions WHERE provider_subscription_id = :provider_subscription_id');
        $stmt->execute([':provider_subscription_id' => $subscriptionId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : [];
    }

    /** @return list<array<string, mixed>> */
    public function subscriptions(string $licenseKey = ''): array
    {
        $sql = 'SELECT * FROM cms_market_subscriptions';
        $params = [];
        if ($licenseKey !== '') {
            $sql .= ' WHERE license_key = :license_key';
            $params[':license_key'] = $licenseKey;
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function recordLicenseUsage(string $licenseKey, string $siteId, int $activeSeats, array $payload = []): array
    {
        $this->licenseByKey($licenseKey);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_license_usage (license_key, site_id, active_seats, payload_json, reported_at) VALUES (:license_key, :site_id, :active_seats, :payload_json, :reported_at)');
        $stmt->execute([
            ':license_key' => $licenseKey,
            ':site_id' => $siteId,
            ':active_seats' => max(0, $activeSeats),
            ':payload_json' => is_string($json) ? $json : '{}',
            ':reported_at' => gmdate('c'),
        ]);
        $this->recordLicenseAudit($licenseKey, 'usage_reported', 'site', ['site_id' => $siteId, 'active_seats' => max(0, $activeSeats)]);

        return $this->latestLicenseUsage($licenseKey);
    }

    /** @return array<string, mixed> */
    public function latestLicenseUsage(string $licenseKey): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_license_usage WHERE license_key = :license_key ORDER BY id DESC LIMIT 1');
        $stmt->execute([':license_key' => $licenseKey]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : [];
    }

    /** @return list<array<string, mixed>> */
    public function licenseUsage(string $licenseKey = ''): array
    {
        $sql = 'SELECT * FROM cms_market_license_usage';
        $params = [];
        if ($licenseKey !== '') {
            $sql .= ' WHERE license_key = :license_key';
            $params[':license_key'] = $licenseKey;
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array{labels:list<string>,active_seats:list<int>} */
    public function licenseUsageTrendData(string $licenseKey, int $days = 7): array
    {
        $labels = [];
        $activeSeats = [];
        $days = max(1, min(30, $days));
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime('-' . $i . ' days'));
            $labels[] = $day;
            $stmt = $this->pdo->prepare('SELECT MAX(active_seats) FROM cms_market_license_usage WHERE license_key = :license_key AND reported_at LIKE :day');
            $stmt->execute([':license_key' => $licenseKey, ':day' => $day . '%']);
            $activeSeats[] = (int) $stmt->fetchColumn();
        }

        return ['labels' => $labels, 'active_seats' => $activeSeats];
    }

    public function assertLicenseUsageWithinSeats(string $licenseKey): void
    {
        $license = $this->licenseByKey($licenseKey);
        $usage = $this->latestLicenseUsage($licenseKey);
        if ($usage !== [] && (int) $usage['active_seats'] > (int) $license['seats']) {
            throw new MarketServerException('License seat usage exceeds the purchased seat count.');
        }
    }

    /** @return array<string, int> */
    public function saveCommercialPolicy(bool $requireInstall, bool $requireDownload, bool $enforceSeatLimits): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_commercial_policies (require_license_for_install, require_license_for_download, enforce_seat_limits, updated_at) VALUES (:require_license_for_install, :require_license_for_download, :enforce_seat_limits, :updated_at)');
        $stmt->execute([
            ':require_license_for_install' => $requireInstall ? 1 : 0,
            ':require_license_for_download' => $requireDownload ? 1 : 0,
            ':enforce_seat_limits' => $enforceSeatLimits ? 1 : 0,
            ':updated_at' => gmdate('c'),
        ]);

        return $this->commercialPolicy();
    }

    /** @return array<string, int> */
    public function commercialPolicy(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM cms_market_commercial_policies ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return ['require_license_for_install' => 0, 'require_license_for_download' => 0, 'enforce_seat_limits' => 1];
        }

        return [
            'require_license_for_install' => (int) $row['require_license_for_install'],
            'require_license_for_download' => (int) $row['require_license_for_download'],
            'enforce_seat_limits' => (int) $row['enforce_seat_limits'],
        ];
    }

    /** @return array<string, mixed> */
    public function customerLicensePortal(string $marketId, string $siteId, string $licenseKey): array
    {
        $license = $this->licenseByKey($licenseKey);
        if ((string) $license['market_id'] !== $marketId || (string) $license['site_id'] !== $siteId || (string) $license['status'] !== 'Active') {
            throw new MarketServerException('License is not active for this site.');
        }
        $expiresAt = (string) ($license['expires_at'] ?? '');
        if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
            throw new MarketServerException('License is expired.');
        }
        $payments = array_values(array_filter($this->payments($marketId), static fn (array $payment): bool => (string) ($payment['site_id'] ?? '') === $siteId && (string) ($payment['license_key'] ?? '') === $licenseKey));

        return [
            'license' => $license,
            'subscriptions' => $this->subscriptions($licenseKey),
            'latest_usage' => $this->latestLicenseUsage($licenseKey),
            'usage_trend' => $this->licenseUsageTrendData($licenseKey),
            'payments' => $payments,
        ];
    }

    public function customerLicensePortalHtml(string $marketId, string $siteId, string $licenseKey): string
    {
        $portal = $this->customerLicensePortal($marketId, $siteId, $licenseKey);
        $license = $portal['license'];
        $rows = '';
        foreach ($portal['payments'] as $payment) {
            $rows .= '<tr><td>' . htmlspecialchars((string) ($payment['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' .
                htmlspecialchars((string) $payment['status'], ENT_QUOTES, 'UTF-8') . '</td><td>' .
                number_format(((int) $payment['amount_cents']) / 100, 2) . ' ' . htmlspecialchars((string) $payment['currency'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="3">No payments</td></tr>';

        return '<!doctype html><html><head><meta charset="utf-8"><title>License Portal</title><style>body{font-family:Arial,sans-serif;margin:32px;color:#172033}table{border-collapse:collapse;width:100%;margin-top:16px}td,th{border:1px solid #d8dee8;padding:8px;text-align:left}.status{font-weight:700}</style></head><body><h1>License Portal</h1><p class="status">Status: ' .
            htmlspecialchars((string) $license['status'], ENT_QUOTES, 'UTF-8') . '</p><p>Market: ' . htmlspecialchars((string) $license['market_id'], ENT_QUOTES, 'UTF-8') .
            '</p><p>Site: ' . htmlspecialchars((string) $license['site_id'], ENT_QUOTES, 'UTF-8') . '</p><p>Seats: ' . (int) $license['seats'] .
            '</p><p>Expires: ' . htmlspecialchars((string) ($license['expires_at'] ?? ''), ENT_QUOTES, 'UTF-8') .
            '</p><h2>Payments</h2><table><thead><tr><th>Invoice</th><th>Status</th><th>Total</th></tr></thead><tbody>' . $rows . '</tbody></table></body></html>';
    }

    /** @return array{token:string,expires_at:string} */
    public function createPortalToken(string $licenseKey, string $siteId, int $ttlSeconds = 86400): array
    {
        $this->licenseByKey($licenseKey);
        $token = hash('sha256', $licenseKey . '|' . $siteId . '|' . random_int(1, PHP_INT_MAX) . '|' . microtime(true));
        $expiresAt = gmdate('c', time() + max(60, $ttlSeconds));
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_portal_tokens (license_key, site_id, token_hash, expires_at, created_at) VALUES (:license_key, :site_id, :token_hash, :expires_at, :created_at)');
        $stmt->execute([
            ':license_key' => $licenseKey,
            ':site_id' => $siteId,
            ':token_hash' => hash('sha256', $token),
            ':expires_at' => $expiresAt,
            ':created_at' => gmdate('c'),
        ]);
        $this->recordCommercialAudit('portal_token_created', $licenseKey, 'system', [
            'site_id' => $siteId,
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /** @return list<array<string, mixed>> */
    public function portalTokens(string $licenseKey = '', string $siteId = ''): array
    {
        $sql = 'SELECT id, license_key, site_id, token_hash, expires_at, created_at FROM cms_market_portal_tokens';
        $params = [];
        $where = [];
        if ($licenseKey !== '') {
            $where[] = 'license_key = :license_key';
            $params[':license_key'] = $licenseKey;
        }
        if ($siteId !== '') {
            $where[] = 'site_id = :site_id';
            $params[':site_id'] = $siteId;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    public function portalTokenById(int $tokenId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, license_key, site_id, token_hash, expires_at, created_at FROM cms_market_portal_tokens WHERE id = :id');
        $stmt->execute([':id' => $tokenId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MarketServerException('Portal token was not found.');
        }

        return $row;
    }

    public function revokePortalToken(int $tokenId): void
    {
        $token = $this->portalTokenById($tokenId);
        $stmt = $this->pdo->prepare('DELETE FROM cms_market_portal_tokens WHERE id = :id');
        $stmt->execute([':id' => $tokenId]);
        if ($stmt->rowCount() < 1) {
            throw new MarketServerException('Portal token was not found.');
        }
        $this->recordCommercialAudit('portal_token_revoked', (string) $token['license_key'], 'system', [
            'site_id' => (string) $token['site_id'],
            'token_id' => $tokenId,
        ]);
    }

    public function purgeExpiredPortalTokens(string $now = ''): int
    {
        $now = $now === '' ? gmdate('c') : $now;
        $stmt = $this->pdo->prepare('DELETE FROM cms_market_portal_tokens WHERE expires_at < :now');
        $stmt->execute([':now' => $now]);
        $count = $stmt->rowCount();
        if ($count > 0) {
            $this->recordCommercialAudit('portal_tokens_purged', 'portal-tokens', 'system', [
                'count' => $count,
                'before' => $now,
            ]);
        }

        return $count;
    }

    /** @return array<string, mixed> */
    public function portalToken(string $token): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_portal_tokens WHERE token_hash = :token_hash');
        $stmt->execute([':token_hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!is_array($row) || strtotime((string) $row['expires_at']) < time()) {
            throw new MarketServerException('Portal token is invalid or expired.');
        }

        return $row;
    }

    public function customerLicensePortalByToken(string $token): array
    {
        $row = $this->portalToken($token);
        $license = $this->licenseByKey((string) $row['license_key']);

        return $this->customerLicensePortal((string) $license['market_id'], (string) $row['site_id'], (string) $row['license_key']);
    }

    public function customerLicensePortalHtmlByToken(string $token): string
    {
        $row = $this->portalToken($token);
        $license = $this->licenseByKey((string) $row['license_key']);

        return $this->customerLicensePortalHtml((string) $license['market_id'], (string) $row['site_id'], (string) $row['license_key']);
    }

    /** @param array<string, mixed> $providerPayload @return array<string, mixed> */
    public function queueSubscriptionAction(string $subscriptionId, string $action, array $providerPayload = []): array
    {
        if ($subscriptionId === '' || !in_array($action, ['cancel', 'restore'], true)) {
            throw new MarketServerException('Subscription action is not supported.');
        }
        $json = json_encode($providerPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $now = gmdate('c');
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_subscription_action_jobs (provider_subscription_id, action, status, provider_payload_json, error_message, created_at, updated_at) VALUES (:provider_subscription_id, :action, :status, :provider_payload_json, :error_message, :created_at, :updated_at)');
        $stmt->execute([
            ':provider_subscription_id' => $subscriptionId,
            ':action' => $action,
            ':status' => 'Queued',
            ':provider_payload_json' => is_string($json) ? $json : '{}',
            ':error_message' => '',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->subscriptionActionJob((int) $this->pdo->lastInsertId());
    }

    public function processSubscriptionActionJobs(int $limit = 20): int
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_subscription_action_jobs WHERE status = :status ORDER BY id ASC LIMIT ' . $limit);
        $stmt->execute([':status' => 'Queued']);
        $processed = 0;
        foreach ($stmt->fetchAll() as $job) {
            $update = $this->pdo->prepare('UPDATE cms_market_subscription_action_jobs SET status = :status, updated_at = :updated_at WHERE id = :id');
            $update->execute([':status' => 'Completed', ':updated_at' => gmdate('c'), ':id' => (int) $job['id']]);
            $processed++;
        }

        return $processed;
    }

    /** @return array<string, mixed> */
    public function subscriptionActionJob(int $jobId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_subscription_action_jobs WHERE id = :id');
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MarketServerException('Subscription action job was not found.');
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function subscriptionActionJobs(string $status = ''): array
    {
        $sql = 'SELECT * FROM cms_market_subscription_action_jobs';
        $params = [];
        if ($status !== '') {
            $sql .= ' WHERE status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, int> */
    public function subscriptionActionSummary(): array
    {
        return $this->countGroupedStatuses('cms_market_subscription_action_jobs', 'status');
    }

    /** @param array<string, mixed> $payload */
    public function recordWebhookDeadLetter(string $eventId, string $provider, array $payload, string $errorMessage): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $now = gmdate('c');
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_webhook_dead_letters (event_id, provider, payload_json, error_message, status, retry_count, created_at, updated_at) VALUES (:event_id, :provider, :payload_json, :error_message, :status, :retry_count, :created_at, :updated_at)');
        $stmt->execute([
            ':event_id' => $eventId,
            ':provider' => $provider,
            ':payload_json' => is_string($json) ? $json : '{}',
            ':error_message' => $errorMessage,
            ':status' => 'Open',
            ':retry_count' => 0,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $deadLetterId = (int) $this->pdo->lastInsertId();
        $this->recordWebhookDeadLetterAlert($deadLetterId, 'admin', 'Queued');
    }

    /** @return list<array<string, mixed>> */
    public function webhookDeadLetters(string $status = ''): array
    {
        $sql = 'SELECT * FROM cms_market_webhook_dead_letters';
        $params = [];
        if ($status !== '') {
            $sql .= ' WHERE status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function retryWebhookDeadLetter(int $deadLetterId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_webhook_dead_letters WHERE id = :id');
        $stmt->execute([':id' => $deadLetterId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MarketServerException('Webhook dead letter was not found.');
        }
        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload)) {
            throw new MarketServerException('Webhook dead letter payload is invalid.');
        }
        $payment = $this->applyPaymentWebhook($payload);
        $update = $this->pdo->prepare('UPDATE cms_market_webhook_dead_letters SET status = :status, retry_count = retry_count + 1, updated_at = :updated_at WHERE id = :id');
        $update->execute([':status' => 'Resolved', ':updated_at' => gmdate('c'), ':id' => $deadLetterId]);

        return $payment;
    }

    public function recordWebhookDeadLetterAlert(int $deadLetterId, string $channel = 'admin', string $status = 'Queued'): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_webhook_dead_letter_alerts (dead_letter_id, channel, status, created_at) VALUES (:dead_letter_id, :channel, :status, :created_at)');
        $stmt->execute([
            ':dead_letter_id' => $deadLetterId,
            ':channel' => $channel,
            ':status' => $status,
            ':created_at' => gmdate('c'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function webhookDeadLetterAlerts(int $deadLetterId = 0): array
    {
        $sql = 'SELECT * FROM cms_market_webhook_dead_letter_alerts';
        $params = [];
        if ($deadLetterId > 0) {
            $sql .= ' WHERE dead_letter_id = :dead_letter_id';
            $params[':dead_letter_id'] = $deadLetterId;
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, int> */
    public function webhookDeadLetterAlertSummary(): array
    {
        return $this->countGroupedStatuses('cms_market_webhook_dead_letter_alerts', 'status');
    }

    public function dispatchWebhookDeadLetterAlerts(NotificationChannelInterface $channel, int $limit = 20): int
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare('SELECT a.*, d.event_id, d.provider, d.error_message FROM cms_market_webhook_dead_letter_alerts a INNER JOIN cms_market_webhook_dead_letters d ON d.id = a.dead_letter_id WHERE a.status = :status ORDER BY a.id ASC LIMIT ' . $limit);
        $stmt->execute([':status' => 'Queued']);
        $sent = 0;
        foreach ($stmt->fetchAll() as $alert) {
            if (!$channel->send([
                'id' => (int) $alert['id'],
                'type' => 'webhook_dead_letter',
                'title' => 'Webhook dead letter',
                'body' => (string) $alert['provider'] . ' ' . (string) $alert['event_id'] . ': ' . (string) $alert['error_message'],
            ])) {
                continue;
            }
            $update = $this->pdo->prepare('UPDATE cms_market_webhook_dead_letter_alerts SET status = :status WHERE id = :id');
            $update->execute([':status' => 'Dispatched', ':id' => (int) $alert['id']]);
            $sent++;
        }

        return $sent;
    }

    /** @return array<string, mixed> */
    public function createTaxRule(string $country, int $taxRateBasisPoints, string $status = 'Active'): array
    {
        if ($country === '' || $taxRateBasisPoints < 0) {
            throw new MarketServerException('Tax country and rate are required.');
        }
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_tax_rules (country, tax_rate_basis_points, status, created_at) VALUES (:country, :tax_rate_basis_points, :status, :created_at)');
        $stmt->execute([
            ':country' => strtoupper($country),
            ':tax_rate_basis_points' => $taxRateBasisPoints,
            ':status' => $status === '' ? 'Active' : $status,
            ':created_at' => gmdate('c'),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $normalizedStatus = $status === '' ? 'Active' : $status;
        $this->recordCommercialAudit('tax_rule_created', strtoupper($country), 'system', [
            'tax_rule_id' => $id,
            'tax_rate_basis_points' => $taxRateBasisPoints,
            'status' => $normalizedStatus,
        ]);

        return ['id' => $id, 'country' => strtoupper($country), 'tax_rate_basis_points' => $taxRateBasisPoints, 'status' => $normalizedStatus];
    }

    /** @return list<array<string, mixed>> */
    public function taxRules(string $country = ''): array
    {
        $sql = 'SELECT * FROM cms_market_tax_rules';
        $params = [];
        if ($country !== '') {
            $sql .= ' WHERE country = :country';
            $params[':country'] = strtoupper($country);
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    public function taxRule(int $ruleId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_tax_rules WHERE id = :id');
        $stmt->execute([':id' => $ruleId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MarketServerException('Tax rule was not found.');
        }

        return $row;
    }

    /** @param array<string, mixed> $payload */
    public function recordCommercialAudit(string $eventType, string $subjectKey, string $actor, array $payload = []): void
    {
        if ($eventType === '' || $subjectKey === '') {
            throw new MarketServerException('Commercial audit event and subject are required.');
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_commercial_audits (event_type, subject_key, actor, payload_json, created_at) VALUES (:event_type, :subject_key, :actor, :payload_json, :created_at)');
        $stmt->execute([
            ':event_type' => $eventType,
            ':subject_key' => $subjectKey,
            ':actor' => $actor === '' ? 'system' : $actor,
            ':payload_json' => is_string($json) ? $json : '{}',
            ':created_at' => gmdate('c'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function commercialAudits(string $eventType = '', string $subjectKey = ''): array
    {
        $sql = 'SELECT * FROM cms_market_commercial_audits';
        $params = [];
        $where = [];
        if ($eventType !== '') {
            $where[] = 'event_type = :event_type';
            $params[':event_type'] = $eventType;
        }
        if ($subjectKey !== '') {
            $where[] = 'subject_key = :subject_key';
            $params[':subject_key'] = $subjectKey;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    public function commercialAudit(int $auditId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_commercial_audits WHERE id = :id');
        $stmt->execute([':id' => $auditId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MarketServerException('Commercial audit event was not found.');
        }
        $payload = json_decode((string) $row['payload_json'], true);
        $row['payload'] = is_array($payload) ? $payload : [];

        return $row;
    }

    /** @return array<string, int> */
    public function commercialAuditSummary(): array
    {
        return $this->countGroupedStatuses('cms_market_commercial_audits', 'event_type');
    }

    public function commercialAuditCsv(string $eventType = '', string $subjectKey = '', string $actor = ''): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new MarketServerException('Unable to open CSV buffer.');
        }
        fputcsv($handle, ['id', 'event_type', 'subject_key', 'actor', 'payload_json', 'created_at'], ',', '"', '\\');
        foreach ($this->commercialAudits($eventType, $subjectKey) as $audit) {
            fputcsv($handle, [
                (string) $audit['id'],
                (string) $audit['event_type'],
                (string) $audit['subject_key'],
                (string) $audit['actor'],
                (string) $audit['payload_json'],
                (string) $audit['created_at'],
            ], ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $csv = is_string($csv) ? $csv : '';
        if ($actor !== '' && (int) $this->commercialAuditPolicy()['audit_exports'] === 1) {
            $this->recordCommercialAudit('commercial_audit_exported', $eventType === '' ? 'all-events' : $eventType, $actor, [
                'subject_key' => $subjectKey,
                'bytes' => strlen($csv),
            ]);
        }

        return $csv;
    }

    /** @return list<array<string, mixed>> */
    public function recentCommercialAuditExports(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_commercial_audits WHERE event_type = :event_type ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute([':event_type' => 'commercial_audit_exported']);

        return $stmt->fetchAll();
    }

    /** @return array{window_hours:int,risk_level:string,signals:array<string, int>,recommendations:list<string>} */
    public function commercialAuditRiskSummary(int $windowHours = 24): array
    {
        $windowHours = max(1, min(720, $windowHours));
        $since = gmdate('c', time() - ($windowHours * 3600));
        $signals = [
            'audit_exports' => $this->countCommercialAuditEventsSince('commercial_audit_exported', $since),
            'billing_exports' => $this->countCommercialAuditEventsSince('billing_summary_exported', $since),
            'audit_purges' => $this->countCommercialAuditEventsSince('commercial_audits_purged', $since),
            'portal_token_revocations' => $this->countCommercialAuditEventsSince('portal_token_revoked', $since),
            'tax_rule_changes' => $this->countCommercialAuditEventsSince('tax_rule_status_changed', $since),
        ];
        $score = 0;
        $recommendations = [];
        if ($signals['audit_exports'] >= 5 || $signals['billing_exports'] >= 5) {
            $score += 3;
            $recommendations[] = 'Review recent export actors and destinations.';
        } elseif ($signals['audit_exports'] >= 1 || $signals['billing_exports'] >= 1) {
            $score += 1;
            $recommendations[] = 'Review recent export actors and destinations.';
        }
        if ($signals['audit_purges'] >= 2) {
            $score += 2;
            $recommendations[] = 'Confirm audit purge jobs are scheduled and expected.';
        }
        if ($signals['portal_token_revocations'] >= 5) {
            $score += 1;
            $recommendations[] = 'Check customer portal token revocation reasons.';
        }
        if ($signals['tax_rule_changes'] >= 3) {
            $score += 1;
            $recommendations[] = 'Review tax rule changes before the next billing run.';
        }
        $riskLevel = $score >= 3 ? 'High' : ($score >= 1 ? 'Medium' : 'Low');

        return [
            'window_hours' => $windowHours,
            'risk_level' => $riskLevel,
            'signals' => $signals,
            'recommendations' => $recommendations,
        ];
    }

    public function dispatchCommercialAuditRiskNotification(int $windowHours = 24, string $minimumRiskLevel = 'Medium', int $cooldownMinutes = 60): int
    {
        $summary = $this->commercialAuditRiskSummary($windowHours);
        if (!$this->riskLevelMeets((string) $summary['risk_level'], $minimumRiskLevel)) {
            return 0;
        }
        if ($this->commercialAuditRiskNotificationInCooldown((string) $summary['risk_level'], $cooldownMinutes)) {
            $this->recordCommercialAudit('commercial_audit_risk_notification_suppressed', (string) $summary['risk_level'], 'system', [
                'window_hours' => (int) $summary['window_hours'],
                'cooldown_minutes' => max(1, $cooldownMinutes),
            ]);

            return 0;
        }
        $recipients = $this->commercialRiskNotificationRecipients();
        $body = 'Commercial audit risk is ' . (string) $summary['risk_level'] . ' for the last ' . (int) $summary['window_hours'] . ' hour(s). Signals: ' . json_encode($summary['signals'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($summary['recommendations'] !== []) {
            $body .= ' Recommendations: ' . implode(' ', $summary['recommendations']);
        }
        foreach ($recipients as $developer) {
            $this->notifyDeveloper((int) $developer['id'], null, 'commercial_audit_risk', 'Commercial audit risk: ' . (string) $summary['risk_level'], $body);
        }
        if ($recipients !== []) {
            $this->recordCommercialAudit('commercial_audit_risk_notified', (string) $summary['risk_level'], 'system', [
                'window_hours' => (int) $summary['window_hours'],
                'recipients' => count($recipients),
                'signals' => $summary['signals'],
            ]);
        }

        return count($recipients);
    }

    /** @return array{queued:int,dispatched:int,failed:int,suppressed:int,notified:int} */
    public function commercialAuditRiskNotificationSummary(): array
    {
        return [
            'queued' => $this->countNotificationsByTypeAndStatus('commercial_audit_risk', 'Pending') + $this->countNotificationsByTypeAndStatus('commercial_audit_risk', 'Retry'),
            'dispatched' => $this->countNotificationsByTypeAndStatus('commercial_audit_risk', 'Dispatched'),
            'failed' => $this->countNotificationsByTypeAndStatus('commercial_audit_risk', 'Failed'),
            'suppressed' => $this->countWhere('cms_market_commercial_audits', 'event_type', 'commercial_audit_risk_notification_suppressed'),
            'notified' => $this->countWhere('cms_market_commercial_audits', 'event_type', 'commercial_audit_risk_notified'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recentCommercialAuditRiskNotifications(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare('SELECT n.*, d.developer_key, d.display_name, d.email FROM cms_market_developer_notifications n INNER JOIN cms_market_developers d ON d.id = n.developer_id WHERE n.type = :type ORDER BY n.id DESC LIMIT ' . $limit);
        $stmt->execute([':type' => 'commercial_audit_risk']);

        return $stmt->fetchAll();
    }

    /** @return array{retry_due:int,retry_waiting:int,failed:int,max_attempts:int} */
    public function commercialAuditRiskNotificationIncidentSummary(): array
    {
        $now = gmdate('c');
        $retryDue = $this->pdo->prepare('SELECT COUNT(*) FROM cms_market_developer_notifications WHERE type = :type AND dispatch_status = :status AND (next_attempt_at IS NULL OR next_attempt_at <= :now)');
        $retryDue->execute([':type' => 'commercial_audit_risk', ':status' => 'Retry', ':now' => $now]);
        $retryWaiting = $this->pdo->prepare('SELECT COUNT(*) FROM cms_market_developer_notifications WHERE type = :type AND dispatch_status = :status AND next_attempt_at > :now');
        $retryWaiting->execute([':type' => 'commercial_audit_risk', ':status' => 'Retry', ':now' => $now]);
        $maxAttempts = $this->pdo->prepare('SELECT MAX(dispatch_attempts) FROM cms_market_developer_notifications WHERE type = :type AND dispatch_status IN (\'Retry\', \'Failed\')');
        $maxAttempts->execute([':type' => 'commercial_audit_risk']);

        return [
            'retry_due' => (int) $retryDue->fetchColumn(),
            'retry_waiting' => (int) $retryWaiting->fetchColumn(),
            'failed' => $this->countNotificationsByTypeAndStatus('commercial_audit_risk', 'Failed'),
            'max_attempts' => (int) $maxAttempts->fetchColumn(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recentCommercialAuditRiskNotificationIncidents(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare('SELECT n.*, d.developer_key, d.display_name, d.email FROM cms_market_developer_notifications n INNER JOIN cms_market_developers d ON d.id = n.developer_id WHERE n.type = :type AND n.dispatch_status IN (\'Retry\', \'Failed\') ORDER BY n.id DESC LIMIT ' . $limit);
        $stmt->execute([':type' => 'commercial_audit_risk']);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $attempt = $this->latestNotificationDispatchAttempt((int) $row['id']);
            $row['latest_dispatch_status'] = (string) ($attempt['status'] ?? '');
            $row['latest_dispatch_channel'] = (string) ($attempt['channel'] ?? '');
            $row['latest_dispatch_error'] = (string) ($attempt['error_message'] ?? '');
            $row['latest_dispatch_at'] = (string) ($attempt['created_at'] ?? '');
        }
        unset($row);

        return $rows;
    }

    public function retryFailedCommercialAuditRiskNotifications(): int
    {
        $stmt = $this->pdo->prepare('UPDATE cms_market_developer_notifications SET dispatch_status = :retry, next_attempt_at = :next_attempt_at WHERE type = :type AND dispatch_status = :failed');
        $stmt->execute([
            ':retry' => 'Retry',
            ':next_attempt_at' => gmdate('c'),
            ':type' => 'commercial_audit_risk',
            ':failed' => 'Failed',
        ]);

        return $stmt->rowCount();
    }

    public function commercialAuditRiskNotificationIncidentCsv(int $limit = 100): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new MarketServerException('Unable to open CSV buffer.');
        }
        fputcsv($handle, ['id', 'developer_key', 'display_name', 'email', 'dispatch_status', 'dispatch_attempts', 'next_attempt_at', 'latest_dispatch_status', 'latest_dispatch_channel', 'latest_dispatch_error', 'latest_dispatch_at', 'created_at'], ',', '"', '\\');
        foreach ($this->recentCommercialAuditRiskNotificationIncidents($limit) as $incident) {
            fputcsv($handle, [
                (string) $incident['id'],
                (string) $incident['developer_key'],
                (string) $incident['display_name'],
                (string) $incident['email'],
                (string) $incident['dispatch_status'],
                (string) $incident['dispatch_attempts'],
                (string) ($incident['next_attempt_at'] ?? ''),
                (string) ($incident['latest_dispatch_status'] ?? ''),
                (string) ($incident['latest_dispatch_channel'] ?? ''),
                (string) ($incident['latest_dispatch_error'] ?? ''),
                (string) ($incident['latest_dispatch_at'] ?? ''),
                (string) $incident['created_at'],
            ], ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    public function exportCommercialAuditRiskNotificationIncidentCsv(int $limit = 100, string $actor = 'system'): string
    {
        $csv = $this->commercialAuditRiskNotificationIncidentCsv($limit);
        $this->recordCommercialAudit('commercial_audit_risk_notification_incidents_exported', 'commercial-audit-risk-notifications', $actor, [
            'limit' => max(1, min(100, $limit)),
            'bytes' => strlen($csv),
        ]);

        return $csv;
    }

    /** @return list<array<string, mixed>> */
    public function recentCommercialAuditRiskNotificationIncidentExports(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM cms_market_commercial_audits WHERE event_type = :event_type AND subject_key = :subject_key ORDER BY id DESC LIMIT ' . $limit);
        $stmt->execute([
            ':event_type' => 'commercial_audit_risk_notification_incidents_exported',
            ':subject_key' => 'commercial-audit-risk-notifications',
        ]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $row['payload'] = is_array($payload) ? $payload : [];
        }
        unset($row);

        return $rows;
    }

    public function commercialAuditRiskNotificationIncidentExportHistoryCsv(int $limit = 100): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new MarketServerException('Unable to open CSV buffer.');
        }
        fputcsv($handle, ['id', 'actor', 'limit', 'bytes', 'created_at'], ',', '"', '\\');
        foreach ($this->recentCommercialAuditRiskNotificationIncidentExports($limit) as $export) {
            fputcsv($handle, [
                (string) $export['id'],
                (string) $export['actor'],
                (string) ($export['payload']['limit'] ?? ''),
                (string) ($export['payload']['bytes'] ?? ''),
                (string) $export['created_at'],
            ], ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /** @return list<array{actor:string,exports:int,total_bytes:int,last_exported_at:string}> */
    public function commercialAuditRiskNotificationIncidentExportActorSummary(): array
    {
        $stmt = $this->pdo->prepare('SELECT actor, COUNT(*) AS exports, MAX(created_at) AS last_exported_at FROM cms_market_commercial_audits WHERE event_type = :event_type AND subject_key = :subject_key GROUP BY actor ORDER BY exports DESC, actor ASC');
        $stmt->execute([
            ':event_type' => 'commercial_audit_risk_notification_incidents_exported',
            ':subject_key' => 'commercial-audit-risk-notifications',
        ]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $totalBytes = 0;
            foreach ($this->commercialAudits('commercial_audit_risk_notification_incidents_exported', 'commercial-audit-risk-notifications') as $audit) {
                if ((string) $audit['actor'] !== (string) $row['actor']) {
                    continue;
                }
                $payload = json_decode((string) ($audit['payload_json'] ?? ''), true);
                $totalBytes += (int) (is_array($payload) ? ($payload['bytes'] ?? 0) : 0);
            }
            $rows[] = [
                'actor' => (string) $row['actor'],
                'exports' => (int) $row['exports'],
                'total_bytes' => $totalBytes,
                'last_exported_at' => (string) $row['last_exported_at'],
            ];
        }

        return $rows;
    }

    public function commercialAuditRiskNotificationIncidentExportActorSummaryCsv(): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new MarketServerException('Unable to open CSV buffer.');
        }
        fputcsv($handle, ['actor', 'exports', 'total_bytes', 'last_exported_at'], ',', '"', '\\');
        foreach ($this->commercialAuditRiskNotificationIncidentExportActorSummary() as $row) {
            fputcsv($handle, [
                $row['actor'],
                (string) $row['exports'],
                (string) $row['total_bytes'],
                $row['last_exported_at'],
            ], ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    public function exportCommercialAuditRiskNotificationIncidentExportActorSummaryCsv(string $actor = 'system'): string
    {
        $csv = $this->commercialAuditRiskNotificationIncidentExportActorSummaryCsv();
        $this->recordCommercialAudit('commercial_audit_risk_notification_incident_export_actor_summary_exported', 'commercial-audit-risk-notification-export-actors', $actor, [
            'actors' => count($this->commercialAuditRiskNotificationIncidentExportActorSummary()),
            'bytes' => strlen($csv),
        ]);

        return $csv;
    }

    /** @return list<array<string, mixed>> */
    public function recentCommercialAuditRiskNotificationIncidentExportActorSummaryExports(int $limit = 10, string $actor = '', string $since = '', string $until = '', int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $actor = trim($actor);
        $since = trim($since);
        $until = trim($until);
        $sql = 'SELECT * FROM cms_market_commercial_audits WHERE event_type = :event_type AND subject_key = :subject_key';
        $params = [
            ':event_type' => 'commercial_audit_risk_notification_incident_export_actor_summary_exported',
            ':subject_key' => 'commercial-audit-risk-notification-export-actors',
        ];
        if ($actor !== '') {
            $sql .= ' AND actor = :actor';
            $params[':actor'] = $actor;
        }
        if ($since !== '') {
            $sql .= ' AND created_at >= :since';
            $params[':since'] = $since;
        }
        if ($until !== '') {
            $sql .= ' AND created_at <= :until';
            $params[':until'] = $until;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $row['payload'] = is_array($payload) ? $payload : [];
        }
        unset($row);

        return $rows;
    }

    public function countCommercialAuditRiskNotificationIncidentExportActorSummaryExports(string $actor = '', string $since = '', string $until = ''): int
    {
        $actor = trim($actor);
        $since = trim($since);
        $until = trim($until);
        $sql = 'SELECT COUNT(*) FROM cms_market_commercial_audits WHERE event_type = :event_type AND subject_key = :subject_key';
        $params = [
            ':event_type' => 'commercial_audit_risk_notification_incident_export_actor_summary_exported',
            ':subject_key' => 'commercial-audit-risk-notification-export-actors',
        ];
        if ($actor !== '') {
            $sql .= ' AND actor = :actor';
            $params[':actor'] = $actor;
        }
        if ($since !== '') {
            $sql .= ' AND created_at >= :since';
            $params[':since'] = $since;
        }
        if ($until !== '') {
            $sql .= ' AND created_at <= :until';
            $params[':until'] = $until;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{limit:int,offset:int,total:int,page:int,pages:int,page_label:string,from:int,to:int,range_label:string,filter_applied:bool,filter_label:string,filter_keys:array<int,string>,filter_values:array<string,string>,filter_query:string,page_query:string,next_page_query:string|null,prev_page_query:string|null,first_page_query:string,last_page_query:string,page_queries:array{current:string,next:string|null,prev:string|null,first:string,last:string},page_offsets:array{current:int,next:int|null,prev:int|null,first:int,last:int},page_availability:array{next:bool,prev:bool,first:bool,last:bool},page_navigation:array{current:array{query:string,offset:int,available:bool},next:array{query:string|null,offset:int|null,available:bool},prev:array{query:string|null,offset:int|null,available:bool},first:array{query:string,offset:int,available:bool},last:array{query:string,offset:int,available:bool}},page_counts:array{total:int,pages:int,current:int,active_filters:int,remaining:int},page_labels:array{page:string,range:string,filter:string,status:string},page_flags:array{empty:bool,out_of_range:bool,has_more:bool,has_previous:bool,filter_applied:bool},page_limits:array{limit:int,min:int,max:int,offset:int},page_sort:array{field:string,direction:string,label:string},page_export:array{format:string,query:string,available:bool},page_download:array{content_type:string,filename:string},page_endpoint:array{method:string,path:string,csv_path:string},page_auth_redirect:array{status:int,path:string},page_cli:array{command:string,export_command:string},page_links:array{current:string,export:string},page_parameters:array{pagination:array<int,string>,filters:array<int,string>,format:string},page_defaults:array{limit:int,offset:int,filters:array{actor:string,since:string,until:string},format:string},page_validation:array{limit:array{type:string,min:int,max:int},offset:array{type:string,min:int},filters:array<string,string>,format:array<int,string>},page_cache:array{store:bool,ttl_seconds:int,policy:string},page_response:array{items_key:string,pagination_key:string,filters_key:string},page_csv_columns:array<int,string>,page_csv_dialect:array{delimiter:string,enclosure:string,escape:string},page_csv_profile:array{has_header:bool,encoding:string,bom:bool},page_csv_schema:array<string,string>,page_csv_units:array<string,string>,page_csv_labels:array<string,string>,page_csv_empty_values:array{placeholder:string,columns:array<int,string>},page_csv_safety:array{formula_escape:bool,trusted_source:bool,recommended_preview_mode:string},page_csv_source:array<string,string>,page_csv_row_shape:array{fixed:bool,column_count:int,matches_header:bool},page_csv_integrity:array{hash:string,size:string,available_after_download:bool},page_csv_lifecycle:array{generated_on_request:bool,persisted:bool,retention:string},page_csv_delivery:array{mode:string,attachment:bool,streamed:bool},page_csv_scope:array{window:string,filters:string,pagination:string},page_csv_privacy:array{contains_actor_identifiers:bool,contains_payload_counts:bool,redaction:string},page_csv_handling:array{classification:string,share_scope:string,requires_secure_storage:bool},page_csv_compliance:array{purpose:string,policy:string,review_required:bool},page_csv_provenance:array{table:string,event_type:string,subject_key:string},page_csv_review:array{required:bool,recommended_actor:string,trigger:string,ticket_hint:string},page_csv_observability:array{audit_event:string,metric_key:string,alert_hint:string,dashboard_hint:string},page_csv_archive:array{recommended:bool,archive_key:string,include_hash:bool,retention_basis:string},page_csv_custody:array{custodian:string,transfer_mode:string,evidence_key:string,tamper_evident:bool},page_csv_accessibility:array{screen_reader_header:bool,plain_language_labels:bool,numeric_units_declared:bool,empty_values_declared:bool},page_csv_localization:array{locale:string,timezone:string,number_format:string,date_format:string},page_csv_reconciliation:array{row_count_source:string,total_matches_pagination:bool,checksum_scope:string,audit_linked:bool},page_csv_recovery:array{retry_supported:bool,idempotency_key:string,manual_fallback:string,rebuildable:bool},page_csv_notification:array{target:string,channel:string,message_key:string,ack_required:bool},page_csv_actions:array{primary:string,review:string,archive:string,retry:string},page_csv_permissions:array{download:string,review:string,archive:string,retry:string},page_csv_error_handling:array{empty:string,out_of_range:string,unauthorized:string,invalid_format:string},page_csv_availability:array{downloadable:bool,requires_rows:bool,empty_export_allowed:bool,degraded_mode:string},page_csv_audit_controls:array{preflight:string,record_export:bool,record_download:bool,review_queue:string},page_csv_governance:array{owner:string,policy_version:string,evidence_required:bool,escalation:string},page_csv_attestation:array{required:bool,actor:string,statement:string,expires:string},page_csv_disclosure:array{external_sharing:bool,audience:string,prohibited:string,watermark:string},page_csv_revocation:array{supported:bool,action:string,notify:string,evidence:string},page_csv_expiration:array{ttl_seconds:int,grace_seconds:int,expired_action:string,notify:string},page_csv_rotation:array{enabled:bool,interval_seconds:int,trigger:string,previous_token:string},page_csv_key_management:array{key_scope:string,storage:string,rotation_required:bool,exposure:string},page_csv_signature:array{algorithm:string,scope:string,required:bool,header:string},page_csv_verification:array{endpoint:string,failure_action:string,audit_event:string,client_required:bool},page_csv_monitoring:array{metric:string,threshold:int,window_seconds:int,alert_channel:string},page_csv_incident_response:array{severity:string,owner:string,playbook:string,notify:string},page_csv_postmortem:array{required:bool,due_hours:int,owner:string,artifact:string},page_csv_lessons:array{capture:bool,knowledge_base:string,tags:array<int,string>,owner:string},page_csv_training:array{required:bool,audience:string,frequency:string,material:string},page_csv_drill:array{required:bool,cadence:string,scenario:string,owner:string},page_csv_evaluation:array{metric:string,pass_threshold:int,reviewer:string,improvement_action:string},page_csv_remediation:array{required:bool,owner:string,due_days:int,tracking_key:string},page_csv_exception:array{allowed:bool,approver:string,approval_basis:string,expires_days:int},page_csv_waiver:array{supported:bool,approver:string,max_days:int,evidence_key:string},page_csv_waiver_review:array{required:bool,reviewer:string,cadence_days:int,escalation:string},page_csv_waiver_closure:array{required:bool,owner:string,evidence_key:string,status:string},page_csv_waiver_notification:array{target:string,channel:string,message_key:string,ack_required:bool},page_csv_waiver_acknowledgement:array{required:bool,actor:string,due_hours:int,evidence_key:string},page_csv_waiver_escalation:array{target:string,trigger:string,due_hours:int,evidence_key:string},page_csv_waiver_audit:array{event_type:string,subject_key:string,record_required:bool,retention:string},page_csv_waiver_audit_notification:array{target:string,channel:string,message_key:string,ack_required:bool},page_csv_waiver_audit_acknowledgement:array{required:bool,actor:string,due_hours:int,evidence_key:string},page_csv_waiver_audit_escalation:array{target:string,trigger:string,due_hours:int,evidence_key:string},page_csv_waiver_audit_resolution:array{owner:string,status:string,evidence_key:string,due_days:int},page_csv_waiver_audit_resolution_notification:array{target:string,channel:string,message_key:string,ack_required:bool},page_csv_waiver_audit_resolution_acknowledgement:array{required:bool,actor:string,due_hours:int,evidence_key:string},page_csv_waiver_audit_resolution_escalation:array{target:string,trigger:string,due_hours:int,evidence_key:string},page_csv_waiver_audit_resolution_closure:array{required:bool,owner:string,evidence_key:string,status:string},page_csv_waiver_audit_resolution_closure_notification:array{target:string,channel:string,message_key:string,ack_required:bool},page_csv_waiver_audit_resolution_closure_acknowledgement:array{required:bool,actor:string,due_hours:int,evidence_key:string},page_csv_waiver_audit_resolution_closure_escalation:array{target:string,trigger:string,due_hours:int,evidence_key:string},page_csv_waiver_audit_resolution_closure_disposition:array{status:string,owner:string,decision:string,evidence_key:string},page_csv_waiver_audit_resolution_closure_disposition_notification:array{target:string,channel:string,message_key:string,ack_required:bool},page_csv_waiver_audit_resolution_closure_disposition_acknowledgement:array{required:bool,actor:string,due_hours:int,evidence_key:string},page_csv_waiver_audit_resolution_closure_disposition_escalation:array{target:string,trigger:string,due_hours:int,evidence_key:string},page_csv_waiver_audit_resolution_closure_disposition_resolution:array{owner:string,status:string,evidence_key:string,due_days:int},page_csv_waiver_audit_resolution_closure_disposition_resolution_notification:array{target:string,channel:string,message_key:string,ack_required:bool},page_csv_waiver_audit_resolution_closure_disposition_resolution_acknowledgement:array{required:bool,actor:string,due_hours:int,evidence_key:string},page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation:array{target:string,trigger:string,due_hours:int,evidence_key:string},page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution:array{owner:string,status:string,evidence_key:string,due_days:int},page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_notification:array{target:string,channel:string,message_key:string,ack_required:bool},page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_acknowledgement:array{required:bool,actor:string,due_hours:int,evidence_key:string},page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation:array{target:string,trigger:string,due_hours:int,evidence_key:string},page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution:array{owner:string,status:string,evidence_key:string,due_days:int},page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution_notification:array{target:string,channel:string,message_key:string,ack_required:bool},page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution_acknowledgement:array{required:bool,actor:string,due_hours:int,evidence_key:string},page_contract:array{resource:string,version:string},page_request:array{limit:int,offset:int,filters:array<string,string>},page_capabilities:array{filters:array<int,string>,formats:array<int,string>,sorts:array<int,string>},page_security:array{requires_auth:bool,role:string,scope:string},page_empty_state:array{code:string,title:string,description:string},page_refresh:array{interval_seconds:int,query:string,enabled:bool},page_audit:array{event_type:string,subject_key:string},page_summary:array{page:string,range:string,filter:string,remaining:int},page_window:array{from:int,to:int,total:int,remaining:int},page_filter:array{applied:bool,label:string,keys:array<int,string>,values:array<string,string>,query:string,count:int},page_diagnostics:array{empty:bool,out_of_range:bool,first_page:bool,last_page:bool},page_status:string,page_status_label:string,page_state:array{status:string,label:string},active_filter_count:int,remaining:int,is_empty:bool,is_out_of_range:bool,has_more:bool,has_previous:bool,next_offset:int|null,prev_offset:int|null,first_offset:int,last_offset:int} */
    public function commercialAuditRiskNotificationIncidentExportActorSummaryExportPagination(int $limit = 10, string $actor = '', string $since = '', string $until = '', int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $total = $this->countCommercialAuditRiskNotificationIncidentExportActorSummaryExports($actor, $since, $until);
        $nextOffset = $offset + $limit;
        $hasMore = $nextOffset < $total;
        $prevOffset = $offset > 0 ? max(0, $offset - $limit) : null;
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($pages, (int) floor($offset / $limit) + 1);
        $from = ($total === 0 || $offset >= $total) ? 0 : $offset + 1;
        $to = $from === 0 ? 0 : min($total, $offset + $limit);
        $lastOffset = $total === 0 ? 0 : max(0, ($pages - 1) * $limit);
        $filterParts = [];
        $filterKeys = [];
        $filterValues = [];
        if ($actor !== '') {
            $filterParts[] = 'actor:' . $actor;
            $filterKeys[] = 'actor';
            $filterValues['actor'] = $actor;
        }
        if ($since !== '') {
            $filterParts[] = 'since:' . $since;
            $filterKeys[] = 'since';
            $filterValues['since'] = $since;
        }
        if ($until !== '') {
            $filterParts[] = 'until:' . $until;
            $filterKeys[] = 'until';
            $filterValues['until'] = $until;
        }
        $pageQueryValues = array_merge(['limit' => (string) $limit, 'offset' => (string) $offset], $filterValues);
        $nextPageQueryValues = array_merge(['limit' => (string) $limit, 'offset' => (string) $nextOffset], $filterValues);
        $prevPageQueryValues = $prevOffset === null ? [] : array_merge(['limit' => (string) $limit, 'offset' => (string) $prevOffset], $filterValues);
        $firstPageQueryValues = array_merge(['limit' => (string) $limit, 'offset' => '0'], $filterValues);
        $lastPageQueryValues = array_merge(['limit' => (string) $limit, 'offset' => (string) $lastOffset], $filterValues);
        $pageQuery = http_build_query($pageQueryValues, '', '&', PHP_QUERY_RFC3986);
        $csvPageQuery = http_build_query(array_merge(['format' => 'csv'], $pageQueryValues), '', '&', PHP_QUERY_RFC3986);
        $nextPageQuery = $hasMore ? http_build_query($nextPageQueryValues, '', '&', PHP_QUERY_RFC3986) : null;
        $prevPageQuery = $prevOffset === null ? null : http_build_query($prevPageQueryValues, '', '&', PHP_QUERY_RFC3986);
        $firstPageQuery = http_build_query($firstPageQueryValues, '', '&', PHP_QUERY_RFC3986);
        $lastPageQuery = http_build_query($lastPageQueryValues, '', '&', PHP_QUERY_RFC3986);
        $filterQuery = $filterValues === [] ? '' : http_build_query($filterValues, '', '&', PHP_QUERY_RFC3986);
        $pageLabel = $page . ' / ' . $pages;
        $rangeLabel = $from . '-' . $to . ' / ' . $total;
        $filterLabel = $filterParts === [] ? 'all' : implode(', ', $filterParts);
        $remaining = max(0, $total - $nextOffset);
        $isEmpty = $total === 0;
        $isOutOfRange = $total > 0 && $offset >= $total;
        $pageStatus = $isEmpty ? 'empty' : ($isOutOfRange ? 'out_of_range' : ($offset === 0 ? 'first' : ($hasMore ? 'middle' : 'last')));
        $pageStatusLabels = ['empty' => 'No exports', 'out_of_range' => 'Out of range', 'first' => 'First page', 'middle' => 'Middle page', 'last' => 'Last page'];

        return [
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'page_label' => $pageLabel,
            'from' => $from,
            'to' => $to,
            'range_label' => $rangeLabel,
            'filter_applied' => $filterParts !== [],
            'filter_label' => $filterLabel,
            'filter_keys' => $filterKeys,
            'filter_values' => $filterValues,
            'filter_query' => $filterQuery,
            'page_query' => $pageQuery,
            'next_page_query' => $nextPageQuery,
            'prev_page_query' => $prevPageQuery,
            'first_page_query' => $firstPageQuery,
            'last_page_query' => $lastPageQuery,
            'page_queries' => ['current' => $pageQuery, 'next' => $nextPageQuery, 'prev' => $prevPageQuery, 'first' => $firstPageQuery, 'last' => $lastPageQuery],
            'page_offsets' => ['current' => $offset, 'next' => $hasMore ? $nextOffset : null, 'prev' => $prevOffset, 'first' => 0, 'last' => $lastOffset],
            'page_availability' => ['next' => $hasMore, 'prev' => $prevOffset !== null, 'first' => $offset > 0, 'last' => $offset < $lastOffset],
            'page_navigation' => [
                'current' => ['query' => $pageQuery, 'offset' => $offset, 'available' => true],
                'next' => ['query' => $nextPageQuery, 'offset' => $hasMore ? $nextOffset : null, 'available' => $hasMore],
                'prev' => ['query' => $prevPageQuery, 'offset' => $prevOffset, 'available' => $prevOffset !== null],
                'first' => ['query' => $firstPageQuery, 'offset' => 0, 'available' => $offset > 0],
                'last' => ['query' => $lastPageQuery, 'offset' => $lastOffset, 'available' => $offset < $lastOffset],
            ],
            'page_counts' => ['total' => $total, 'pages' => $pages, 'current' => $page, 'active_filters' => count($filterParts), 'remaining' => $remaining],
            'page_labels' => ['page' => $pageLabel, 'range' => $rangeLabel, 'filter' => $filterLabel, 'status' => $pageStatusLabels[$pageStatus]],
            'page_flags' => ['empty' => $isEmpty, 'out_of_range' => $isOutOfRange, 'has_more' => $hasMore, 'has_previous' => $prevOffset !== null, 'filter_applied' => $filterParts !== []],
            'page_limits' => ['limit' => $limit, 'min' => 1, 'max' => 100, 'offset' => $offset],
            'page_sort' => ['field' => 'id', 'direction' => 'desc', 'label' => 'Newest exports first'],
            'page_export' => ['format' => 'csv', 'query' => $csvPageQuery, 'available' => true],
            'page_download' => ['content_type' => 'text/csv; charset=utf-8', 'filename' => 'commercial-audit-risk-notification-incident-export-actor-exports.csv'],
            'page_endpoint' => ['method' => 'GET', 'path' => '/admin/market-server/commercial-audits/risk-notification-incident-export-actor-exports', 'csv_path' => '/admin/market-server/commercial-audits/risk-notification-incident-export-actor-exports'],
            'page_auth_redirect' => ['status' => 302, 'path' => '/admin/login'],
            'page_cli' => ['command' => 'commercial-audit-risk-notification-incident-export-actor-exports', 'export_command' => 'export-commercial-audit-risk-notification-incident-export-actor-exports'],
            'page_links' => ['current' => '/admin/market-server/commercial-audits/risk-notification-incident-export-actor-exports?' . $pageQuery, 'export' => '/admin/market-server/commercial-audits/risk-notification-incident-export-actor-exports?' . $csvPageQuery],
            'page_parameters' => ['pagination' => ['limit', 'offset'], 'filters' => ['actor', 'since', 'until'], 'format' => 'format'],
            'page_defaults' => ['limit' => 10, 'offset' => 0, 'filters' => ['actor' => '', 'since' => '', 'until' => ''], 'format' => 'json'],
            'page_validation' => ['limit' => ['type' => 'integer', 'min' => 1, 'max' => 100], 'offset' => ['type' => 'integer', 'min' => 0], 'filters' => ['actor' => 'string', 'since' => 'string', 'until' => 'string'], 'format' => ['json', 'csv']],
            'page_cache' => ['store' => false, 'ttl_seconds' => 0, 'policy' => 'no-store'],
            'page_response' => ['items_key' => 'items', 'pagination_key' => 'pagination', 'filters_key' => 'filters'],
            'page_csv_columns' => ['id', 'actor', 'actors', 'bytes', 'created_at'],
            'page_csv_dialect' => ['delimiter' => ',', 'enclosure' => '"', 'escape' => '\\'],
            'page_csv_profile' => ['has_header' => true, 'encoding' => 'utf-8', 'bom' => false],
            'page_csv_schema' => ['id' => 'integer', 'actor' => 'string', 'actors' => 'integer', 'bytes' => 'integer', 'created_at' => 'datetime'],
            'page_csv_units' => ['actors' => 'count', 'bytes' => 'bytes'],
            'page_csv_labels' => ['id' => 'ID', 'actor' => 'Actor', 'actors' => 'Actors', 'bytes' => 'Bytes', 'created_at' => 'Created at'],
            'page_csv_empty_values' => ['placeholder' => '', 'columns' => ['actors', 'bytes']],
            'page_csv_safety' => ['formula_escape' => false, 'trusted_source' => true, 'recommended_preview_mode' => 'text'],
            'page_csv_source' => ['id' => 'audit.id', 'actor' => 'audit.actor', 'actors' => 'payload.actors', 'bytes' => 'payload.bytes', 'created_at' => 'audit.created_at'],
            'page_csv_row_shape' => ['fixed' => true, 'column_count' => 5, 'matches_header' => true],
            'page_csv_integrity' => ['hash' => 'sha256', 'size' => 'bytes', 'available_after_download' => true],
            'page_csv_lifecycle' => ['generated_on_request' => true, 'persisted' => false, 'retention' => 'none'],
            'page_csv_delivery' => ['mode' => 'download', 'attachment' => true, 'streamed' => true],
            'page_csv_scope' => ['window' => 'current_page', 'filters' => 'current_filters', 'pagination' => 'limit_offset'],
            'page_csv_privacy' => ['contains_actor_identifiers' => true, 'contains_payload_counts' => true, 'redaction' => 'none'],
            'page_csv_handling' => ['classification' => 'internal_audit', 'share_scope' => 'admin_only', 'requires_secure_storage' => true],
            'page_csv_compliance' => ['purpose' => 'commercial_audit_review', 'policy' => 'commercial_audit_policy', 'review_required' => true],
            'page_csv_provenance' => ['table' => 'cms_market_commercial_audits', 'event_type' => 'commercial_audit_risk_notification_incident_export_actor_summary_exported', 'subject_key' => 'commercial-audit-risk-notification-export-actors'],
            'page_csv_review' => ['required' => true, 'recommended_actor' => 'admin', 'trigger' => 'post_download', 'ticket_hint' => 'link_export_audit_id'],
            'page_csv_observability' => ['audit_event' => 'commercial_audit_risk_notification_incident_export_actor_summary_exported', 'metric_key' => 'commercial_audit.risk_notification.incident_export_actor_summary.exports', 'alert_hint' => 'unexpected_export_spike', 'dashboard_hint' => 'commercial_audit_exports'],
            'page_csv_archive' => ['recommended' => true, 'archive_key' => 'commercial_audit_export_actor_summary_exports', 'include_hash' => true, 'retention_basis' => 'commercial_audit_retention_policy'],
            'page_csv_custody' => ['custodian' => 'admin', 'transfer_mode' => 'secure_internal_channel', 'evidence_key' => 'export_audit_id', 'tamper_evident' => true],
            'page_csv_accessibility' => ['screen_reader_header' => true, 'plain_language_labels' => true, 'numeric_units_declared' => true, 'empty_values_declared' => true],
            'page_csv_localization' => ['locale' => 'en_US', 'timezone' => 'UTC', 'number_format' => 'plain_integer', 'date_format' => 'ISO-8601'],
            'page_csv_reconciliation' => ['row_count_source' => 'current_page_items', 'total_matches_pagination' => true, 'checksum_scope' => 'export_response', 'audit_linked' => true],
            'page_csv_recovery' => ['retry_supported' => true, 'idempotency_key' => 'filter_query', 'manual_fallback' => 'rerun_export_from_history', 'rebuildable' => true],
            'page_csv_notification' => ['target' => 'admin', 'channel' => 'admin_console', 'message_key' => 'commercial_audit_export_ready', 'ack_required' => false],
            'page_csv_actions' => ['primary' => 'download_csv', 'review' => 'open_audit_detail', 'archive' => 'store_with_hash', 'retry' => 'rerun_export'],
            'page_csv_permissions' => ['download' => 'market_server.commercial_audits', 'review' => 'market_server.commercial_audits', 'archive' => 'market_server.commercial_audits.archive', 'retry' => 'market_server.commercial_audits.retry'],
            'page_csv_error_handling' => ['empty' => 'return_header_only_csv', 'out_of_range' => 'return_empty_page_metadata', 'unauthorized' => 'redirect_login', 'invalid_format' => 'fallback_json'],
            'page_csv_availability' => ['downloadable' => true, 'requires_rows' => false, 'empty_export_allowed' => true, 'degraded_mode' => 'metadata_only'],
            'page_csv_audit_controls' => ['preflight' => 'validate_filters_and_scope', 'record_export' => true, 'record_download' => true, 'review_queue' => 'commercial_audit_exports'],
            'page_csv_governance' => ['owner' => 'commercial_audit', 'policy_version' => 'v1', 'evidence_required' => true, 'escalation' => 'security_review'],
            'page_csv_attestation' => ['required' => true, 'actor' => 'admin', 'statement' => 'export_reviewed', 'expires' => 'none'],
            'page_csv_disclosure' => ['external_sharing' => false, 'audience' => 'internal_audit', 'prohibited' => 'public_distribution', 'watermark' => 'confidential'],
            'page_csv_revocation' => ['supported' => true, 'action' => 'revoke_download', 'notify' => 'commercial_audit_owner', 'evidence' => 'revocation_audit_event'],
            'page_csv_expiration' => ['ttl_seconds' => 3600, 'grace_seconds' => 300, 'expired_action' => 'require_regeneration', 'notify' => 'commercial_audit_owner'],
            'page_csv_rotation' => ['enabled' => true, 'interval_seconds' => 1800, 'trigger' => 'download_token_refresh', 'previous_token' => 'invalidate'],
            'page_csv_key_management' => ['key_scope' => 'download_token', 'storage' => 'server_side_only', 'rotation_required' => true, 'exposure' => 'never_exported'],
            'page_csv_signature' => ['algorithm' => 'HMAC-SHA256', 'scope' => 'csv_body', 'required' => true, 'header' => 'X-CMS-CSV-Signature'],
            'page_csv_verification' => ['endpoint' => 'client_side', 'failure_action' => 'reject_download', 'audit_event' => 'csv_signature_verification_failed', 'client_required' => true],
            'page_csv_monitoring' => ['metric' => 'csv_signature_verification_failures', 'threshold' => 3, 'window_seconds' => 900, 'alert_channel' => 'security_ops'],
            'page_csv_incident_response' => ['severity' => 'high', 'owner' => 'security_ops', 'playbook' => 'csv_signature_verification_failure', 'notify' => 'commercial_audit_owner'],
            'page_csv_postmortem' => ['required' => true, 'due_hours' => 72, 'owner' => 'security_ops', 'artifact' => 'csv_verification_failure_postmortem'],
            'page_csv_lessons' => ['capture' => true, 'knowledge_base' => 'commercial_audit_runbooks', 'tags' => ['csv', 'signature', 'verification'], 'owner' => 'security_ops'],
            'page_csv_training' => ['required' => true, 'audience' => 'commercial_audit_admins', 'frequency' => 'quarterly', 'material' => 'csv_signature_verification_runbook'],
            'page_csv_drill' => ['required' => true, 'cadence' => 'semiannual', 'scenario' => 'csv_signature_failure_response', 'owner' => 'security_ops'],
            'page_csv_evaluation' => ['metric' => 'drill_success_rate', 'pass_threshold' => 95, 'reviewer' => 'commercial_audit_owner', 'improvement_action' => 'update_runbook'],
            'page_csv_remediation' => ['required' => true, 'owner' => 'commercial_audit_owner', 'due_days' => 30, 'tracking_key' => 'csv_drill_improvement'],
            'page_csv_exception' => ['allowed' => true, 'approver' => 'commercial_audit_owner', 'approval_basis' => 'risk_acceptance', 'expires_days' => 14],
            'page_csv_waiver' => ['supported' => true, 'approver' => 'security_ops', 'max_days' => 30, 'evidence_key' => 'csv_exception_waiver'],
            'page_csv_waiver_review' => ['required' => true, 'reviewer' => 'security_ops', 'cadence_days' => 7, 'escalation' => 'commercial_audit_owner'],
            'page_csv_waiver_closure' => ['required' => true, 'owner' => 'commercial_audit_owner', 'evidence_key' => 'csv_waiver_closure', 'status' => 'closed'],
            'page_csv_waiver_notification' => ['target' => 'commercial_audit_admins', 'channel' => 'admin_inbox', 'message_key' => 'csv_waiver_closed', 'ack_required' => true],
            'page_csv_waiver_acknowledgement' => ['required' => true, 'actor' => 'commercial_audit_admin', 'due_hours' => 24, 'evidence_key' => 'csv_waiver_ack'],
            'page_csv_waiver_escalation' => ['target' => 'commercial_audit_owner', 'trigger' => 'ack_overdue', 'due_hours' => 48, 'evidence_key' => 'csv_waiver_escalation'],
            'page_csv_waiver_audit' => ['event_type' => 'csv_waiver_escalated', 'subject_key' => 'export_actor_summary', 'record_required' => true, 'retention' => 'audit_policy'],
            'page_csv_waiver_audit_notification' => ['target' => 'security_ops', 'channel' => 'admin_inbox', 'message_key' => 'csv_waiver_escalation_recorded', 'ack_required' => true],
            'page_csv_waiver_audit_acknowledgement' => ['required' => true, 'actor' => 'security_ops', 'due_hours' => 24, 'evidence_key' => 'csv_waiver_audit_ack'],
            'page_csv_waiver_audit_escalation' => ['target' => 'commercial_audit_owner', 'trigger' => 'audit_ack_overdue', 'due_hours' => 48, 'evidence_key' => 'csv_waiver_audit_escalation'],
            'page_csv_waiver_audit_resolution' => ['owner' => 'commercial_audit_owner', 'status' => 'resolved', 'evidence_key' => 'csv_waiver_audit_resolution', 'due_days' => 7],
            'page_csv_waiver_audit_resolution_notification' => ['target' => 'security_ops', 'channel' => 'admin_inbox', 'message_key' => 'csv_waiver_audit_resolved', 'ack_required' => true],
            'page_csv_waiver_audit_resolution_acknowledgement' => ['required' => true, 'actor' => 'security_ops', 'due_hours' => 24, 'evidence_key' => 'csv_waiver_resolution_ack'],
            'page_csv_waiver_audit_resolution_escalation' => ['target' => 'commercial_audit_owner', 'trigger' => 'resolution_ack_overdue', 'due_hours' => 48, 'evidence_key' => 'csv_waiver_resolution_escalation'],
            'page_csv_waiver_audit_resolution_closure' => ['required' => true, 'owner' => 'commercial_audit_owner', 'evidence_key' => 'csv_waiver_resolution_closure', 'status' => 'closed'],
            'page_csv_waiver_audit_resolution_closure_notification' => ['target' => 'security_ops', 'channel' => 'admin_inbox', 'message_key' => 'csv_waiver_resolution_closed', 'ack_required' => true],
            'page_csv_waiver_audit_resolution_closure_acknowledgement' => ['required' => true, 'actor' => 'security_ops', 'due_hours' => 24, 'evidence_key' => 'csv_waiver_resolution_closure_ack'],
            'page_csv_waiver_audit_resolution_closure_escalation' => ['target' => 'commercial_audit_owner', 'trigger' => 'closure_ack_overdue', 'due_hours' => 48, 'evidence_key' => 'csv_waiver_resolution_closure_escalation'],
            'page_csv_waiver_audit_resolution_closure_disposition' => ['status' => 'finalized', 'owner' => 'commercial_audit_owner', 'decision' => 'accepted', 'evidence_key' => 'csv_waiver_resolution_closure_disposition'],
            'page_csv_waiver_audit_resolution_closure_disposition_notification' => ['target' => 'security_ops', 'channel' => 'admin_inbox', 'message_key' => 'csv_waiver_resolution_closure_disposition', 'ack_required' => true],
            'page_csv_waiver_audit_resolution_closure_disposition_acknowledgement' => ['required' => true, 'actor' => 'security_ops', 'due_hours' => 24, 'evidence_key' => 'csv_waiver_resolution_closure_disposition_ack'],
            'page_csv_waiver_audit_resolution_closure_disposition_escalation' => ['target' => 'commercial_audit_owner', 'trigger' => 'disposition_ack_overdue', 'due_hours' => 48, 'evidence_key' => 'csv_waiver_resolution_closure_disposition_escalation'],
            'page_csv_waiver_audit_resolution_closure_disposition_resolution' => ['owner' => 'commercial_audit_owner', 'status' => 'resolved', 'evidence_key' => 'csv_waiver_resolution_closure_disposition_resolution', 'due_days' => 7],
            'page_csv_waiver_audit_resolution_closure_disposition_resolution_notification' => ['target' => 'security_ops', 'channel' => 'admin_inbox', 'message_key' => 'csv_waiver_resolution_closure_disposition_resolved', 'ack_required' => true],
            'page_csv_waiver_audit_resolution_closure_disposition_resolution_acknowledgement' => ['required' => true, 'actor' => 'security_ops', 'due_hours' => 24, 'evidence_key' => 'csv_waiver_resolution_closure_disposition_resolution_ack'],
            'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation' => ['target' => 'commercial_audit_owner', 'trigger' => 'resolution_ack_overdue', 'due_hours' => 48, 'evidence_key' => 'csv_waiver_resolution_closure_disposition_resolution_escalation'],
            'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution' => ['owner' => 'commercial_audit_owner', 'status' => 'escalation_resolved', 'evidence_key' => 'csv_waiver_resolution_closure_disposition_resolution_escalation_resolution', 'due_days' => 3],
            'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_notification' => ['target' => 'security_ops', 'channel' => 'admin_inbox', 'message_key' => 'csv_waiver_resolution_closure_disposition_resolution_escalation_resolved', 'ack_required' => true],
            'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_acknowledgement' => ['required' => true, 'actor' => 'security_ops', 'due_hours' => 24, 'evidence_key' => 'csv_waiver_resolution_closure_disposition_resolution_escalation_resolution_ack'],
            'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation' => ['target' => 'commercial_audit_owner', 'trigger' => 'escalation_resolution_ack_overdue', 'due_hours' => 48, 'evidence_key' => 'csv_waiver_resolution_closure_disposition_resolution_escalation_resolution_escalation'],
            'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution' => ['owner' => 'commercial_audit_owner', 'status' => 'escalation_resolved', 'evidence_key' => 'csv_waiver_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution', 'due_days' => 3],
            'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution_notification' => ['target' => 'security_ops', 'channel' => 'admin_inbox', 'message_key' => 'csv_waiver_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolved', 'ack_required' => true],
            'page_csv_waiver_audit_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution_acknowledgement' => ['required' => true, 'actor' => 'security_ops', 'due_hours' => 24, 'evidence_key' => 'csv_waiver_resolution_closure_disposition_resolution_escalation_resolution_escalation_resolution_ack'],
            'page_contract' => ['resource' => 'commercial_audit_risk_notification_incident_export_actor_exports', 'version' => '1.0'],
            'page_request' => ['limit' => $limit, 'offset' => $offset, 'filters' => $filterValues],
            'page_capabilities' => ['filters' => ['actor', 'since', 'until'], 'formats' => ['json', 'csv'], 'sorts' => ['id_desc']],
            'page_security' => ['requires_auth' => true, 'role' => 'admin', 'scope' => 'market_server.commercial_audits'],
            'page_empty_state' => ['code' => 'no_exports', 'title' => 'No export audits', 'description' => 'No matching commercial audit risk notification incident export actor summaries were found.'],
            'page_refresh' => ['interval_seconds' => 30, 'query' => $pageQuery, 'enabled' => true],
            'page_audit' => ['event_type' => 'commercial_audit_risk_notification_incident_export_actor_summary_exported', 'subject_key' => 'commercial-audit-risk-notification-export-actors'],
            'page_summary' => ['page' => $pageLabel, 'range' => $rangeLabel, 'filter' => $filterLabel, 'remaining' => $remaining],
            'page_window' => ['from' => $from, 'to' => $to, 'total' => $total, 'remaining' => $remaining],
            'page_filter' => ['applied' => $filterParts !== [], 'label' => $filterLabel, 'keys' => $filterKeys, 'values' => $filterValues, 'query' => $filterQuery, 'count' => count($filterParts)],
            'page_diagnostics' => ['empty' => $isEmpty, 'out_of_range' => $isOutOfRange, 'first_page' => $offset === 0, 'last_page' => !$hasMore],
            'page_status' => $pageStatus,
            'page_status_label' => $pageStatusLabels[$pageStatus],
            'page_state' => ['status' => $pageStatus, 'label' => $pageStatusLabels[$pageStatus]],
            'active_filter_count' => count($filterParts),
            'remaining' => $remaining,
            'is_empty' => $isEmpty,
            'is_out_of_range' => $isOutOfRange,
            'has_more' => $hasMore,
            'has_previous' => $prevOffset !== null,
            'next_offset' => $hasMore ? $nextOffset : null,
            'prev_offset' => $prevOffset,
            'first_offset' => 0,
            'last_offset' => $lastOffset,
        ];
    }

    public function commercialAuditRiskNotificationIncidentExportActorSummaryExportHistoryCsv(int $limit = 100, string $actor = '', string $since = '', string $until = '', int $offset = 0): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new MarketServerException('Unable to open CSV buffer.');
        }
        fputcsv($handle, ['id', 'actor', 'actors', 'bytes', 'created_at'], ',', '"', '\\');
        foreach ($this->recentCommercialAuditRiskNotificationIncidentExportActorSummaryExports($limit, $actor, $since, $until, $offset) as $export) {
            fputcsv($handle, [
                (string) $export['id'],
                (string) $export['actor'],
                (string) ($export['payload']['actors'] ?? ''),
                (string) ($export['payload']['bytes'] ?? ''),
                (string) $export['created_at'],
            ], ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /** @return array{retention_days:int,audit_exports:int,purge_limit:int,updated_at:string} */
    public function saveCommercialAuditPolicy(int $retentionDays, bool $auditExports, int $purgeLimit): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO cms_market_commercial_audit_policies (retention_days, audit_exports, purge_limit, updated_at) VALUES (:retention_days, :audit_exports, :purge_limit, :updated_at)');
        $stmt->execute([
            ':retention_days' => max(1, $retentionDays),
            ':audit_exports' => $auditExports ? 1 : 0,
            ':purge_limit' => max(1, min(5000, $purgeLimit)),
            ':updated_at' => gmdate('c'),
        ]);

        return $this->commercialAuditPolicy();
    }

    /** @return array{retention_days:int,audit_exports:int,purge_limit:int,updated_at:string} */
    public function commercialAuditPolicy(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM cms_market_commercial_audit_policies ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return ['retention_days' => 365, 'audit_exports' => 1, 'purge_limit' => 500, 'updated_at' => ''];
        }

        return [
            'retention_days' => (int) $row['retention_days'],
            'audit_exports' => (int) $row['audit_exports'],
            'purge_limit' => (int) $row['purge_limit'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    public function purgeCommercialAudits(string $before = '', int $limit = 0): int
    {
        $policy = $this->commercialAuditPolicy();
        $before = $before === '' ? gmdate('c', strtotime('-' . (int) $policy['retention_days'] . ' days')) : $before;
        $limit = $limit > 0 ? $limit : (int) $policy['purge_limit'];
        $ids = $this->commercialAuditIdsBefore($before, $limit);
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare('DELETE FROM cms_market_commercial_audits WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        $count = $stmt->rowCount();
        $this->recordCommercialAudit('commercial_audits_purged', 'commercial-audits', 'system', [
            'before' => $before,
            'count' => $count,
        ]);

        return $count;
    }

    /** @return array{before:string,limit:int,candidate_count:int,oldest:string,newest:string} */
    public function commercialAuditPurgePreview(string $before = '', int $limit = 0): array
    {
        $policy = $this->commercialAuditPolicy();
        $before = $before === '' ? gmdate('c', strtotime('-' . (int) $policy['retention_days'] . ' days')) : $before;
        $limit = $limit > 0 ? $limit : (int) $policy['purge_limit'];
        $ids = $this->commercialAuditIdsBefore($before, $limit);
        if ($ids === []) {
            return ['before' => $before, 'limit' => max(1, min(5000, $limit)), 'candidate_count' => 0, 'oldest' => '', 'newest' => ''];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare('SELECT MIN(created_at) AS oldest, MAX(created_at) AS newest FROM cms_market_commercial_audits WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        $row = $stmt->fetch();

        return [
            'before' => $before,
            'limit' => max(1, min(5000, $limit)),
            'candidate_count' => count($ids),
            'oldest' => is_array($row) ? (string) ($row['oldest'] ?? '') : '',
            'newest' => is_array($row) ? (string) ($row['newest'] ?? '') : '',
        ];
    }

    /** @return array<string, mixed> */
    public function setTaxRuleStatus(int $ruleId, string $status, string $actor = 'system'): array
    {
        $status = $status === '' ? 'Active' : ucfirst(strtolower(trim($status)));
        if (!in_array($status, ['Active', 'Disabled'], true)) {
            throw new MarketServerException('Tax rule status is not supported.');
        }
        $stmt = $this->pdo->prepare('UPDATE cms_market_tax_rules SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $ruleId]);
        if ($stmt->rowCount() < 1) {
            throw new MarketServerException('Tax rule was not found.');
        }
        $rule = $this->taxRule($ruleId);
        $this->recordCommercialAudit('tax_rule_status_changed', (string) $rule['country'], $actor, [
            'tax_rule_id' => $ruleId,
            'status' => $status,
        ]);

        return $rule;
    }

    public function taxAmountForCountry(int $amountCents, string $country): int
    {
        $stmt = $this->pdo->prepare('SELECT tax_rate_basis_points FROM cms_market_tax_rules WHERE country = :country AND status = :status ORDER BY id DESC LIMIT 1');
        $stmt->execute([':country' => strtoupper($country), ':status' => 'Active']);

        return (int) floor($amountCents * ((int) $stmt->fetchColumn()) / 10000);
    }

    /** @return list<array<string, mixed>> */
    public function publishedCatalog(string $extensionType = ''): array
    {
        $sql = 'SELECT p.market_id, p.extension_type, p.name, v.version, v.package_sha256, v.status FROM cms_market_projects p INNER JOIN cms_market_versions v ON v.project_id = p.id WHERE v.status IN (:published, :deprecated)';
        $params = [':published' => ReviewState::PUBLISHED, ':deprecated' => ReviewState::DEPRECATED];
        if ($extensionType !== '') {
            $sql .= ' AND p.extension_type = :extension_type';
            $params[':extension_type'] = $extensionType;
        }
        $sql .= ' ORDER BY p.name';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed> */
    public function publishedVersionDetail(string $marketId, string $version = ''): array
    {
        $sql = 'SELECT p.market_id, p.extension_type, p.name, p.status AS project_status, v.id AS version_id, v.version, v.package_path, v.package_sha256, v.changelog, v.status, v.submitted_at FROM cms_market_projects p INNER JOIN cms_market_versions v ON v.project_id = p.id WHERE p.market_id = :market_id AND v.status IN (:published, :deprecated)';
        $params = [':market_id' => $marketId, ':published' => ReviewState::PUBLISHED, ':deprecated' => ReviewState::DEPRECATED];
        if ($version !== '') {
            $sql .= ' AND v.version = :version';
            $params[':version'] = $version;
        }
        $sql .= ' ORDER BY v.id DESC LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MarketServerException('Published market version was not found.');
        }
        $signatures = $this->signaturesForVersion((int) $row['version_id']);

        return $row + [
            'deprecated' => (string) ($row['status'] ?? '') === ReviewState::DEPRECATED,
            'download_url' => '/api/market/download?market_id=' . rawurlencode((string) $row['market_id']) . '&version=' . rawurlencode((string) $row['version']),
            'signatures' => $signatures,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function signaturesForVersion(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT algorithm, payload_json, signature, created_at FROM cms_market_package_signatures WHERE version_id = :version_id ORDER BY id DESC');
        $stmt->execute([':version_id' => $versionId]);

        return $stmt->fetchAll();
    }

    public function versionStatus(int $versionId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM cms_market_versions WHERE id = :id');
        $stmt->execute([':id' => $versionId]);
        $status = $stmt->fetchColumn();
        if (!is_string($status) || $status === '') {
            throw new MarketServerException('Market version was not found.');
        }

        return $status;
    }

    private function normalizeDeveloperRole(string $role): string
    {
        $role = ucfirst(strtolower(trim($role)));
        if (!in_array($role, ['Owner', 'Maintainer', 'Developer', 'Viewer'], true)) {
            throw new MarketServerException('Developer role is not supported.');
        }

        return $role;
    }

    private function countTable(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function countWhere(string $table, string $column, string $value): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = :value');
        $stmt->execute([':value' => $value]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, int> */
    private function countGroupedStatuses(string $table, string $column): array
    {
        $stmt = $this->pdo->query('SELECT ' . $column . ', COUNT(*) AS total FROM ' . $table . ' GROUP BY ' . $column);
        $rows = $stmt->fetchAll();
        $summary = [];
        foreach ($rows as $row) {
            $summary[(string) $row[$column]] = (int) $row['total'];
        }

        return $summary;
    }

    /** @return list<int> */
    private function commercialAuditIdsBefore(string $before, int $limit): array
    {
        $limit = max(1, min(5000, $limit));
        $stmt = $this->pdo->prepare('SELECT id FROM cms_market_commercial_audits WHERE created_at < :before ORDER BY id ASC LIMIT ' . $limit);
        $stmt->execute([':before' => $before]);
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    private function countCommercialAuditEventsSince(string $eventType, string $since): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cms_market_commercial_audits WHERE event_type = :event_type AND created_at >= :since');
        $stmt->execute([':event_type' => $eventType, ':since' => $since]);

        return (int) $stmt->fetchColumn();
    }

    private function countNotificationsByTypeAndStatus(string $type, string $status): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cms_market_developer_notifications WHERE type = :type AND dispatch_status = :status');
        $stmt->execute([':type' => $type, ':status' => $status]);

        return (int) $stmt->fetchColumn();
    }

    private function riskLevelMeets(string $actual, string $minimum): bool
    {
        $rank = ['Low' => 0, 'Medium' => 1, 'High' => 2];
        $minimum = ucfirst(strtolower(trim($minimum)));
        if (!isset($rank[$minimum])) {
            $minimum = 'Medium';
        }

        return ($rank[$actual] ?? 0) >= $rank[$minimum];
    }

    private function commercialAuditRiskNotificationInCooldown(string $riskLevel, int $cooldownMinutes): bool
    {
        $since = gmdate('c', time() - (max(1, $cooldownMinutes) * 60));
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cms_market_commercial_audits WHERE event_type = :event_type AND subject_key = :subject_key AND created_at >= :since');
        $stmt->execute([
            ':event_type' => 'commercial_audit_risk_notified',
            ':subject_key' => $riskLevel,
            ':since' => $since,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<array<string, mixed>> */
    private function commercialRiskNotificationRecipients(): array
    {
        $owners = array_values(array_filter($this->developers(), static fn (array $developer): bool => (string) $developer['status'] === 'Active' && (string) $developer['role'] === 'Owner'));
        if ($owners !== []) {
            return $owners;
        }

        return array_values(array_filter($this->developers(), static fn (array $developer): bool => (string) $developer['status'] === 'Active'));
    }

    private function countDatePrefix(string $table, string $column, string $prefix): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' LIKE :prefix');
        $stmt->execute([':prefix' => $prefix . '%']);

        return (int) $stmt->fetchColumn();
    }

    private function normalizePaymentStatus(string $status): string
    {
        $status = ucfirst(strtolower(trim($status)));
        if (!in_array($status, ['Pending', 'Paid', 'Failed', 'Refunded'], true)) {
            throw new MarketServerException('Payment status is not supported.');
        }

        return $status;
    }

    private function paymentStatusFromWebhook(string $status): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, ['paid', 'succeeded', 'completed'], true)) {
            return 'Paid';
        }
        if (in_array($status, ['failed', 'canceled', 'cancelled'], true)) {
            return 'Failed';
        }
        if ($status === 'refunded') {
            return 'Refunded';
        }

        return 'Pending';
    }

    private function subscriptionStatusFromWebhook(string $status): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, ['active', 'paid', 'succeeded', 'completed'], true)) {
            return 'Active';
        }
        if (in_array($status, ['trialing', 'trial'], true)) {
            return 'Trialing';
        }
        if (in_array($status, ['past_due', 'past due'], true)) {
            return 'Past Due';
        }
        if (in_array($status, ['unpaid', 'failed'], true)) {
            return 'Unpaid';
        }
        if (in_array($status, ['canceled', 'cancelled'], true)) {
            return 'Canceled';
        }

        return 'Pending';
    }

    /** @param list<string> $lines */
    private function simplePdf(array $lines): string
    {
        $content = "BT\n/F1 14 Tf\n72 760 Td\n";
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $content .= "0 -24 Td\n";
            }
            $content .= '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line) . ") Tj\n";
        }
        $content .= "ET\n";
        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

        return $pdf;
    }

    private function normalizeSettlementStatus(string $status): string
    {
        $status = ucfirst(strtolower(trim($status)));
        if (!in_array($status, ['Pending', 'Processing', 'Paid', 'Failed'], true)) {
            throw new MarketServerException('Settlement status is not supported.');
        }

        return $status;
    }

    /** @param array<string, mixed> $row */
    private function developerFromRow(array $row): DeveloperIdentity
    {
        return new DeveloperIdentity(
            (int) $row['id'],
            (string) $row['developer_key'],
            (string) $row['display_name'],
            (string) $row['email'],
            (string) $row['status'],
            (string) ($row['role'] ?? 'Developer')
        );
    }
}
