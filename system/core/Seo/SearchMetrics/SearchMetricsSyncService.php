<?php

declare(strict_types=1);

namespace Cms\Core\Seo\SearchMetrics;

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Plugin\PluginSecretStore;
use Cms\Core\Scheduler\ScheduledTask;
use Cms\Core\Scheduler\SchedulerService;
use Cms\Core\Scheduler\SchedulerTaskRegistry;
use Cms\Core\Seo\SearchEngine\SearchEngineDataRepository;
use PDO;

final class SearchMetricsSyncService
{
    public const TASK_ID = 'core.seo.search_metrics.sync';

    public function __construct(
        private readonly PDO $pdo,
        private readonly GoogleSearchConsoleProvider $google,
        private readonly GoogleSearchConsoleConnectionRepository $googleConnections,
    ) {
    }

    /** @return array{ok:bool,status:string,message:string,inserted:int,updated:int,skipped:int,rows:int} */
    public function syncGoogle(?string $periodStart = null, ?string $periodEnd = null): array
    {
        $config = $this->googleConnections->config();
        $end = $periodEnd ?? gmdate('Y-m-d', time() - 3 * 86400);
        $start = $periodStart ?? gmdate('Y-m-d', strtotime($end . ' -6 days'));
        try {
            $result = $this->google->sync(new SearchMetricsSyncRequest((string) ($config['property_url'] ?? ''), $start, $end));
            if (!$result->ok) {
                $this->googleConnections->recordSync(false, $result->message);
                return ['ok' => false, 'status' => $result->status, 'message' => $result->message, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'rows' => 0];
            }
            $import = (new SearchEngineDataRepository($this->pdo))->importMetrics($result->rows, 'google_search_console');
            $message = 'rows=' . count($result->rows) . ', inserted=' . (int) $import['inserted'] . ', updated=' . (int) $import['updated'] . ', skipped=' . (int) $import['skipped'];
            $httpStatuses = is_array($result->raw['http_statuses'] ?? null) ? $result->raw['http_statuses'] : [];
            $tokenStatus = isset($httpStatuses['token_refresh']) ? (int) $httpStatuses['token_refresh'] : 0;
            $searchStatuses = array_filter(array_map('intval', is_array($httpStatuses['search_analytics'] ?? null) ? $httpStatuses['search_analytics'] : []));
            if ($tokenStatus > 0 || $searchStatuses !== []) {
                $message .= ', token_http=' . ($tokenStatus > 0 ? (string) $tokenStatus : 'n/a') . ', search_http=' . ($searchStatuses !== [] ? implode('|', $searchStatuses) : 'n/a');
            }
            $this->googleConnections->recordSync(true, $message);
            return ['ok' => true, 'status' => 'synced', 'message' => $message, 'inserted' => (int) $import['inserted'], 'updated' => (int) $import['updated'], 'skipped' => (int) $import['skipped'], 'rows' => count($result->rows)];
        } catch (\Throwable $exception) {
            $this->googleConnections->recordSync(false, $exception->getMessage());
            return ['ok' => false, 'status' => 'auth_error', 'message' => $exception->getMessage(), 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'rows' => 0];
        }
    }

    public static function register(Settings $settings): void
    {
        try {
            $pdo = ConnectionFactory::make($settings);
            $service = new SchedulerService($pdo);
            SchedulerTaskRegistry::register(self::TASK_ID, static function () use ($settings): void {
                $pdo = ConnectionFactory::make($settings);
                $connections = new GoogleSearchConsoleConnectionRepository($pdo, new PluginSecretStore($pdo, (string) $settings->get('security.encryption_key', '')));
                $sync = new self($pdo, new GoogleSearchConsoleProvider($connections), $connections);
                $result = $sync->syncGoogle();
                if (!$result['ok'] && !in_array($result['status'], ['not_connected', 'not_configured'], true)) {
                    throw new SearchMetricsException($result['message']);
                }
            });
            $service->register(new ScheduledTask(self::TASK_ID, 'core.seo', 86400));
        } catch (\Throwable) {
            // Scheduler setup must not block normal requests; health/UI expose connection state.
        }
    }
}
