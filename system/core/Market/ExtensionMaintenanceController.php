<?php

declare(strict_types=1);

namespace Cms\Core\Market;

use Cms\Core\Auth\AdminAuthenticator;
use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Cms\Core\Security\CsrfToken;
use Cms\Core\Support\View;
use Throwable;

final class ExtensionMaintenanceController
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function updates(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $pdo = ConnectionFactory::make($this->settings);
            $repo = new MarketInstallRepository($pdo);
            $updates = (new ExtensionUpdateChecker($repo, new OfflineMarketClient()))->availableUpdates();
            $rows = '';
            foreach ($updates as $update) {
                $rows .= '<tr><td>' . View::escape((string) $update['extension_id']) . '</td><td>' . View::escape((string) $update['type']) .
                    '</td><td>' . View::escape((string) $update['current_version']) . '</td><td>' . View::escape((string) $update['available_version']) .
                    '</td><td><a class="button" href="/admin/extensions/update-detail?extension_id=' . rawurlencode((string) $update['extension_id']) .
                    '&type=' . rawurlencode((string) $update['type']) . '&version=' . rawurlencode((string) $update['available_version']) . '">详情</a></td></tr>';
            }
            $rows = $rows !== '' ? $rows : '<tr><td colspan="5" class="muted">暂无可用更新</td></tr>';
            $body = '<h1>扩展更新</h1><table><thead><tr><th>ID</th><th>类型</th><th>当前</th><th>可用</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>';

            return Response::html(View::page('扩展更新', $body));
        } catch (Throwable $exception) {
            return $this->errorPage('扩展更新', $exception);
        }
    }

    public function dependencyGraph(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        try {
            $repo = new MarketInstallRepository(ConnectionFactory::make($this->settings));
            $rows = '';
            foreach ($repo->latestInstalledByExtension() as $source) {
                $metadata = json_decode((string) ($source['metadata_json'] ?? ''), true);
                $deps = [];
                foreach (($metadata['dependencies'] ?? []) as $dependency) {
                    if (is_array($dependency)) {
                        $deps[] = View::escape((string) ($dependency['type'] ?? 'plugin') . ':' . (string) ($dependency['extension_id'] ?? ''));
                    }
                }
                $rows .= '<tr><td>' . View::escape((string) $source['extension_type'] . ':' . (string) $source['extension_id']) .
                    '</td><td>' . ($deps === [] ? '<span class="muted">无</span>' : implode(', ', $deps)) . '</td></tr>';
            }
            $rows = $rows !== '' ? $rows : '<tr><td colspan="2" class="muted">暂无已安装扩展来源记录</td></tr>';
            $body = '<h1>依赖图</h1><table><thead><tr><th>扩展</th><th>依赖</th></tr></thead><tbody>' . $rows . '</tbody></table>';

            return Response::html(View::page('依赖图', $body));
        } catch (Throwable $exception) {
            return $this->errorPage('依赖图', $exception);
        }
    }

    public function updateDetail(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $extensionId = (string) $request->input('extension_id', '');
        $type = (string) $request->input('type', 'plugin');
        $version = (string) $request->input('version', '');
        $body = '<h1>更新详情</h1><p class="muted">一键更新会先下载远程包，再进入安装器校验授权、hash、依赖、兼容性和并发锁。</p>' .
            '<form method="post" action="/admin/extensions/update-install">' . CsrfToken::field() .
            '<label>扩展 ID<input name="extension_id" value="' . View::escape($extensionId) . '" required></label>' .
            '<label>类型<select name="type"><option value="plugin"' . ($type === 'plugin' ? ' selected' : '') . '>Plugin</option><option value="theme"' . ($type === 'theme' ? ' selected' : '') . '>Theme</option></select></label>' .
            '<label>目标版本<input name="version" value="' . View::escape($version) . '" required></label>' .
            '<label>Market ID<input name="market_id" value="official:' . View::escape($extensionId) . '" required></label>' .
            '<label>包 URL<input name="package_url" placeholder="https://market.example.com/package.zip 或 file:///tmp/package.zip" required></label>' .
            '<label>包 SHA-256<input name="package_sha256" required></label>' .
            '<button type="submit">一键更新</button></form>';

        return Response::html(View::page('更新详情', $body));
    }

    public function updateInstall(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }

        try {
            $root = dirname(__DIR__, 3);
            $jobs = $this->jobRepository($root);
            $jobId = $jobs->enqueue('extension_update', [
                'extension_id' => (string) $request->input('extension_id', ''),
                'type' => (string) $request->input('type', 'plugin'),
                'version' => (string) $request->input('version', ''),
                'market_id' => (string) $request->input('market_id', ''),
                'package_url' => (string) $request->input('package_url', ''),
                'package_sha256' => (string) $request->input('package_sha256', ''),
            ]);
            $body = '<h1>更新任务已入队</h1><p class="muted">任务 ID：' . View::escape($jobId) . '</p>' .
                '<p><a class="button" href="/admin/extensions/jobs">查看任务</a></p>';

            return Response::html(View::page('一键更新', $body));
        } catch (Throwable $exception) {
            return $this->errorPage('一键更新', $exception);
        }
    }

    public function downloadProgress(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $root = dirname(__DIR__, 3);
        $items = (new DownloadProgressRepository($root . '/storage/market/download-progress.json'))->all();

        return Response::json(['downloads' => array_values($items)]);
    }

    public function presignObjectUpload(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        try {
            $root = dirname(__DIR__, 3);
            $key = $this->objectKey((string) $request->input('key', 'uploads/package-' . date('YmdHis') . '.zip'));
            $ttl = (int) $request->input('ttl', 300);
            $maxBytes = (int) $request->input('max_bytes', 0);
            $contentType = (string) $request->input('content_type', 'application/zip');
            $baseUri = (string) $this->settings->get('market.object_storage_url', '');
            $secret = (string) $this->settings->get('market.object_storage_secret', '');
            $adapter = $baseUri === ''
                ? new LocalObjectStorageAdapter($root . '/storage/market/remote-objects')
                : new RemoteObjectStorageAdapter($baseUri, $secret === '' ? [] : ['X-Signature-Secret' => $secret]);

            return Response::json(['upload' => $adapter->presignUpload($key, $ttl, ['content_type' => $contentType, 'max_bytes' => $maxBytes])]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 500);
        }
    }

    public function jobs(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $root = dirname(__DIR__, 3);
        $repo = $this->jobRepository($root);
        $filters = [
            'status' => (string) $request->input('status', ''),
            'type' => (string) $request->input('type', ''),
            'q' => trim((string) $request->input('q', '')),
        ];
        $pagination = $repo->paginate($filters, (int) $request->input('page', 1), (int) $request->input('per_page', 10));
        $jobs = $pagination['items'];
        $rows = '';
        foreach ($jobs as $job) {
            $jobId = (string) $job['id'];
            $rows .= '<tr><td><a href="/admin/extensions/jobs/' . rawurlencode($jobId) . '">' . View::escape($jobId) . '</a></td><td>' . View::escape((string) $job['type']) .
                '</td><td data-job-status="' . View::escape((string) $job['id']) . '">' . View::escape((string) $job['status']) . '</td><td>' . (int) ($job['attempts'] ?? 0) . '</td><td>' .
                View::escape((string) ($job['updated_at'] ?? '')) . '</td><td>' . View::escape((string) ($job['error'] ?? '')) . '</td><td>' . $this->jobActions($jobId) . '</td></tr>';
        }
        $rows = $rows !== '' ? $rows : '<tr><td colspan="7" class="muted">暂无任务</td></tr>';

        $rollbackRows = '';
        foreach ((new ExtensionRollbackRepository($root . '/storage/market/rollback', $root . '/storage/market/rollback-restores'))->all() as $artifact) {
            $id = (string) ($artifact['id'] ?? '');
            $extensionId = (string) ($artifact['result']['extension_id'] ?? '');
            $version = (string) ($artifact['result']['version'] ?? '');
            $rollbackRows .= '<tr><td>' . View::escape($id) . '</td><td>' . View::escape($extensionId) . '</td><td>' . View::escape($version) .
                '</td><td>' . View::escape((string) ($artifact['created_at'] ?? '')) . '</td><td><form method="post" action="/admin/extensions/rollback-restore">' .
                CsrfToken::field() . '<input type="hidden" name="id" value="' . View::escape($id) . '"><button type="submit">请求回滚</button></form></td></tr>';
        }
        $rollbackRows = $rollbackRows !== '' ? $rollbackRows : '<tr><td colspan="5" class="muted">暂无回滚归档</td></tr>';

        $csrf = View::escape(CsrfToken::get());
        $status = View::escape($filters['status']);
        $type = View::escape($filters['type']);
        $q = View::escape($filters['q']);
        $body = '<h1>扩展更新任务</h1><form method="get" action="/admin/extensions/jobs">' .
            '<label>搜索<input name="q" value="' . $q . '" placeholder="任务 ID / 扩展 ID / 错误"></label>' .
            '<label>状态<select name="status"><option value="">全部</option>' . $this->option('Queued', $status) . $this->option('Running', $status) . $this->option('Retry', $status) . $this->option('Completed', $status) . $this->option('Failed', $status) . $this->option('Cancelled', $status) . '</select></label>' .
            '<label>类型<select name="type"><option value="">全部</option>' . $this->option('extension_update', $type) . $this->option('extension_rollback', $type) . '</select></label>' .
            '<label>每页<input name="per_page" value="' . (int) $pagination['per_page'] . '"></label><button type="submit">筛选</button></form>' .
            '<p class="muted" id="job-summary">Total: ' . (int) $repo->summary()['total'] . '</p>' .
            '<p class="muted">筛选结果：' . (int) $pagination['total'] . ' 条，第 ' . (int) $pagination['page'] . ' / ' . (int) $pagination['pages'] . ' 页</p>' .
            '<button type="button" id="run-next-job">执行下一任务</button>' .
            '<table><thead><tr><th>ID</th><th>类型</th><th>状态</th><th>尝试</th><th>更新时间</th><th>错误</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<p><a class="button" href="/admin/extensions/jobs?q=' . rawurlencode($filters['q']) . '&status=' . rawurlencode($filters['status']) . '&type=' . rawurlencode($filters['type']) . '&per_page=' . (int) $pagination['per_page'] . '&page=' . max(1, (int) $pagination['page'] - 1) . '">上一页</a> ' .
            '<a class="button" href="/admin/extensions/jobs?q=' . rawurlencode($filters['q']) . '&status=' . rawurlencode($filters['status']) . '&type=' . rawurlencode($filters['type']) . '&per_page=' . (int) $pagination['per_page'] . '&page=' . min((int) $pagination['pages'], (int) $pagination['page'] + 1) . '">下一页</a></p>' .
            '<h2>回滚归档</h2><table><thead><tr><th>ID</th><th>扩展</th><th>版本</th><th>创建时间</th><th>操作</th></tr></thead><tbody>' . $rollbackRows . '</tbody></table>' .
            '<script>
const csrf = "' . $csrf . '";
async function refreshJobs(){
  const res = await fetch("/admin/extensions/job-status", {headers: {"Accept": "application/json"}});
  if(!res.ok){ return; }
  const data = await res.json();
  document.getElementById("job-summary").textContent = "Total: " + data.summary.total + " Queued: " + data.summary.queued + " Running: " + data.summary.running + " Retry: " + data.summary.retry + " Failed: " + data.summary.failed + " Cancelled: " + data.summary.cancelled;
  for (const job of data.jobs) {
    const cell = document.querySelector("[data-job-status=\"" + job.id + "\"]");
    if (cell) { cell.textContent = job.status; }
  }
}
document.getElementById("run-next-job").addEventListener("click", async () => {
  await fetch("/admin/extensions/run-job", {method: "POST", headers: {"Content-Type": "application/x-www-form-urlencoded"}, body: "_csrf=" + encodeURIComponent(csrf)});
  await refreshJobs();
});
setInterval(refreshJobs, 2500);
</script>';

        return Response::html(View::page('扩展更新任务', $body));
    }

    public function jobStatus(): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $root = dirname(__DIR__, 3);
        $repo = $this->jobRepository($root);
        $worker = new MarketWorkerLock($root . '/storage/market/worker.lock');

        return Response::json(['summary' => $repo->summary(), 'jobs' => array_values($repo->all()), 'worker' => $worker->status()]);
    }

    public function jobDetail(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }

        $id = basename($request->path);
        $root = dirname(__DIR__, 3);
        $job = $this->jobRepository($root)->find($id);
        if ($job === null) {
            return Response::text('Not Found', 404);
        }
        $auditFilters = [
            'job_id' => $id,
            'action' => (string) $request->input('audit_action', ''),
            'actor' => trim((string) $request->input('audit_actor', '')),
            'q' => trim((string) $request->input('audit_q', '')),
        ];
        $auditRows = '';
        foreach ($this->jobAuditRepository($root)->search($auditFilters) as $audit) {
            $auditRows .= '<tr><td>' . View::escape((string) ($audit['action'] ?? '')) . '</td><td>' . View::escape((string) ($audit['actor'] ?? '')) . '</td><td>' . View::escape((string) ($audit['created_at'] ?? '')) . '</td></tr>';
        }
        $auditRows = $auditRows !== '' ? $auditRows : '<tr><td colspan="3" class="muted">暂无审计记录</td></tr>';
        $body = '<h1>任务详情</h1><p><a href="/admin/extensions/jobs">返回任务列表</a></p>' .
            '<table><tbody>' .
            '<tr><th>ID</th><td>' . View::escape((string) $job['id']) . '</td></tr>' .
            '<tr><th>类型</th><td>' . View::escape((string) $job['type']) . '</td></tr>' .
            '<tr><th>状态</th><td>' . View::escape((string) $job['status']) . '</td></tr>' .
            '<tr><th>尝试</th><td>' . (int) ($job['attempts'] ?? 0) . '</td></tr>' .
            '<tr><th>创建时间</th><td>' . View::escape((string) ($job['created_at'] ?? '')) . '</td></tr>' .
            '<tr><th>更新时间</th><td>' . View::escape((string) ($job['updated_at'] ?? '')) . '</td></tr>' .
            '</tbody></table>' .
            '<h2>Payload</h2><pre>' . View::escape(json_encode($job['payload'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') . '</pre>' .
            '<h2>Result</h2><pre>' . View::escape(json_encode($job['result'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') . '</pre>' .
            '<h2>Error</h2><pre>' . View::escape((string) ($job['error'] ?? '')) . '</pre>' .
            '<h2>操作</h2>' . $this->jobActions((string) $job['id']) .
            '<h2>审计</h2><form method="get" action="/admin/extensions/jobs/' . rawurlencode($id) . '">' .
            '<label>动作<input name="audit_action" value="' . View::escape($auditFilters['action']) . '"></label>' .
            '<label>操作者<input name="audit_actor" value="' . View::escape($auditFilters['actor']) . '"></label>' .
            '<label>搜索<input name="audit_q" value="' . View::escape($auditFilters['q']) . '"></label><button type="submit">筛选审计</button></form>' .
            '<table><thead><tr><th>动作</th><th>操作者</th><th>时间</th></tr></thead><tbody>' . $auditRows . '</tbody></table>';

        return Response::html(View::page('任务详情', $body));
    }

    public function jobAction(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }

        try {
            $root = dirname(__DIR__, 3);
            $repo = $this->jobRepository($root);
            $id = (string) $request->input('id', '');
            $action = (string) $request->input('action', '');
            match ($action) {
                'cancel' => $repo->cancel($id, 'Cancelled by administrator.'),
                'retry' => $repo->retry($id),
                'front' => $repo->moveToFront($id),
                default => throw new MarketException('Unknown job action.'),
            };
            $this->jobAuditRepository($root)->record($id, $action, (string) ($guard['email'] ?? 'admin'), ['source' => 'admin']);

            return Response::redirect('/admin/extensions/jobs/' . rawurlencode($id));
        } catch (Throwable $exception) {
            return $this->errorPage('任务操作', $exception);
        }
    }

    public function runJob(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        try {
            $root = dirname(__DIR__, 3);
            $result = (new ExtensionUpdateJobRunner(
                $root,
                ConnectionFactory::make($this->settings),
                $this->jobRepository($root),
            ))->runNext(new MarketWorkerLock($root . '/storage/market/worker.lock'));

            return Response::json(['result' => $result]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 500);
        }
    }

    public function reclaimWorker(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::json(['error' => 'Forbidden'], 403);
        }
        $root = dirname(__DIR__, 3);
        $lock = new MarketWorkerLock($root . '/storage/market/worker.lock');

        return Response::json(['reclaimed' => $lock->reclaimIfStale((int) $request->input('timeout', 300)), 'worker' => $lock->status()]);
    }

    public function rollbackRestore(Request $request): Response
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof Response) {
            return $guard;
        }
        if (!CsrfToken::verify($request->input('_csrf'))) {
            return Response::text('Forbidden', 403);
        }

        try {
            $root = dirname(__DIR__, 3);
            $rollbackId = (string) $request->input('id', '');
            (new ExtensionRollbackRepository($root . '/storage/market/rollback', $root . '/storage/market/rollback-restores'))->requestRestore($rollbackId);
            $jobId = $this->jobRepository($root)->enqueue('extension_rollback', ['rollback_id' => $rollbackId]);

            return Response::html(View::page('回滚恢复', '<h1>回滚恢复任务已入队</h1><p class="muted">任务 ID：' . View::escape($jobId) . '</p><p><a class="button" href="/admin/extensions/jobs">返回任务列表</a></p>'));
        } catch (Throwable $exception) {
            return $this->errorPage('回滚恢复', $exception);
        }
    }

    /** @return array{id:int,email:string,display_name:string}|Response */
    private function requireAdmin(): array|Response
    {
        try {
            $user = (new AdminAuthenticator(ConnectionFactory::make($this->settings)))->user();
        } catch (Throwable) {
            return Response::redirect('/admin/login');
        }

        return $user === null || $user['id'] <= 0 ? Response::redirect('/admin/login') : $user;
    }

    private function errorPage(string $title, Throwable $exception): Response
    {
        return Response::html(View::page($title, '<h1>' . View::escape($title) . '</h1><p class="error">' . View::escape($exception->getMessage()) . '</p>'), 500);
    }

    private function jobActions(string $id): string
    {
        $escaped = View::escape($id);
        $csrf = CsrfToken::field();
        $form = static function (string $action, string $label) use ($escaped, $csrf): string {
            return '<form method="post" action="/admin/extensions/job-action" style="display:inline-block;margin-right:6px">' . $csrf .
                '<input type="hidden" name="id" value="' . $escaped . '">' .
                '<input type="hidden" name="action" value="' . View::escape($action) . '">' .
                '<button type="submit">' . View::escape($label) . '</button></form>';
        };

        return $form('cancel', '取消') . $form('retry', '重试') . $form('front', '队首');
    }

    private function option(string $value, string $selected): string
    {
        return '<option value="' . View::escape($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . View::escape($value) . '</option>';
    }

    private function objectKey(string $key): string
    {
        $key = ltrim(trim($key), '/');
        if ($key === '' || str_contains($key, '..') || !preg_match('/^[A-Za-z0-9._\/-]+$/', $key)) {
            throw new MarketException('Invalid object key.');
        }

        return $key;
    }

    private function jobAuditRepository(string $root): DatabaseMarketJobAuditRepository|MarketJobAuditRepository
    {
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $pdo->query('SELECT id FROM cms_market_job_audits LIMIT 1');

            return new DatabaseMarketJobAuditRepository($pdo);
        } catch (Throwable) {
            return new MarketJobAuditRepository($root . '/storage/market/job-audit.json');
        }
    }

    private function jobRepository(string $root): MarketJobRepositoryInterface
    {
        try {
            $pdo = ConnectionFactory::make($this->settings);
            $pdo->query('SELECT id FROM cms_market_jobs LIMIT 1');

            return new DatabaseMarketJobRepository($pdo);
        } catch (Throwable) {
            return new MarketJobRepository($root . '/storage/market/jobs.json');
        }
    }
}
