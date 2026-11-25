<?php
declare(strict_types=1);

use Cms\Core\Scheduler\ScheduledTask;
use Cms\Core\Scheduler\SchedulerService;
use Cms\Core\Scheduler\SchedulerTaskRegistry;

$root = dirname(__DIR__);
require $root.'/system/core/Bootstrap/autoload.php';
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
(require $root.'/system/migrations/2026_09_08_000005_core_scheduler_foundation.php')->up($pdo);
$service = new SchedulerService($pdo);
$seen = [];
$check = static function (bool $result, string $name): void {
    if (!$result) {
        throw new RuntimeException($name);
    }
    echo '[PASS] ', $name, PHP_EOL;
};
foreach (['fixture.publish', 'fixture.writer', 'fixture.disabled'] as $id) {
    $service->register(new ScheduledTask($id, 'fixture', 60, ['task' => $id], $id !== 'fixture.disabled'));
    SchedulerTaskRegistry::register($id, static function (array $payload) use (&$seen): void {
        $seen[] = $payload['task'];
    });
}
$due = $pdo->prepare('UPDATE cms_core_scheduled_tasks SET next_run_at = ? WHERE task_id = ?');
$due->execute(['2000-01-01T00:00:00+00:00', 'fixture.writer']);
$due->execute(['2000-01-01T00:01:00+00:00', 'fixture.publish']);
$due->execute(['2000-01-01T00:01:00+00:00', 'fixture.disabled']);
$writerBefore = $service->find('fixture.writer');
$check($service->runDueTask('fixture.publish', true)['succeeded'] === 1 && $seen === ['fixture.publish'], 'Selected publication dispatches despite an earlier unrelated due task');
$check($service->find('fixture.writer') === $writerBefore, 'Writer state stays unchanged during publication');
$published = $service->find('fixture.publish');
$check(strtotime($published['next_run_at']) % 60 === 0 && $published['next_run_at'] > $published['last_run_at'], 'Selected publication retains opt-in minute alignment');
$check($service->runDueTask('fixture.publish', true)['processed'] === 0, 'Same-minute retry cannot re-run the finished task');
$check($service->runDueTask('fixture.disabled')['processed'] === 0 && $service->runDueTask('fixture.unknown')['processed'] === 0, 'Disabled and unknown task selectors dispatch nothing');
$lease = $pdo->prepare('UPDATE cms_core_scheduled_tasks SET lock_until = ? WHERE task_id = ?');
$lease->execute([gmdate('c', time()+60), 'fixture.writer']);
$check($service->runDueTask('fixture.writer')['processed'] === 0, 'Selected writer respects the existing active lease');
$lease->execute([null, 'fixture.writer']);
$check($service->runDue(10)['succeeded'] === 1 && $seen === ['fixture.publish','fixture.writer'], 'Generic runner still dispatches all eligible tasks');
$writerAfter = $service->find('fixture.writer');
$check(strtotime($writerAfter['next_run_at'])-strtotime($writerAfter['last_run_at']) === 60, 'Default completion-based cadence remains in use');
