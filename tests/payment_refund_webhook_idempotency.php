<?php
/** Offline ledger/callback regressions. No provider refund API is registered or invoked. */
declare(strict_types=1);
define('CMS_ROOT', getenv('CMS_PAYMENT_TEST_ROOT') ?: dirname(__DIR__));
require CMS_ROOT . '/system/core/Bootstrap/autoload.php';

use Cms\Core\Payment\{PaymentException, PaymentRepository, PaymentService, PaymentTransaction};

function refund_db(string $path = ':memory:'): PDO
{
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA busy_timeout=15000');
    return $pdo;
}
function refund_payment(PaymentRepository $repo, string $name): int
{
    return $repo->insertPayment(['subject_type'=>'callback_test','subject_id'=>$name,'provider_id'=>'test.callback',
        'remote_id'=>'cs_'.$name,'reference'=>$name,'status'=>'paid','amount_minor'=>100,'currency'=>'USD',
        'idempotency_key'=>'pay_'.$name,'request_hash'=>hash('sha256',$name)]);
}
function refund_callback(PaymentService $svc, string $event, string $payment, string $refund, int $amount, string $status): array
{
    $raw = json_encode(['event_id'=>$event,'event_type'=>'refund.updated','provider_payment_id'=>'cs_'.$payment,
        'refund'=>['provider_refund_id'=>$refund,'amount_minor'=>$amount,'status'=>$status,'reason'=>'webhook reason']], JSON_THROW_ON_ERROR);
    $receipt = $svc->recordWebhookReceipt('test.callback',$event,$raw);
    return $svc->applyWebhookPaymentStatus('test.callback',(int)$receipt['id'],$raw) ?? [];
}
if (($argv[1] ?? '') === '--worker') {
    $pdo=refund_db($argv[2]);$svc=new PaymentService($pdo,new PaymentRepository($pdo));
    refund_callback($svc,$argv[3],'concurrent','re_concurrent',100,'completed');
    exit(0);
}
$checks=0;
function refund_check(bool $ok,string $message): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;echo "PASS $message\n";
}
function refund_reject(callable $fn,string $message): void
{
    try {$fn();} catch (PaymentException) {refund_check(true,$message);return;}
    throw new RuntimeException($message);
}
$path=tempnam(sys_get_temp_dir(),'cms-refund-callback-');$pdo=refund_db($path);
$migration=require CMS_ROOT.'/system/migrations/2026_08_20_000001_core_payment_schema.php';$migration->up($pdo);
$pdo->exec('CREATE TABLE cms_audit_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_type TEXT,actor_id INTEGER,action TEXT,context_json TEXT,created_at TEXT)');
$repo=new PaymentRepository($pdo);$svc=new PaymentService($pdo,$repo);

// A historical API response has already created this row. This test never requests a refund.
$paid=refund_payment($repo,'api');
$rid=$repo->insertRefund(['payment_id'=>$paid,'provider_id'=>'test.callback','remote_id'=>'re_api','status'=>'completed',
    'amount_minor'=>100,'currency'=>'USD','reason'=>'original API reason','idempotency_key'=>'historical_api',
    'request_hash'=>hash('sha256','original API request')]);
$repo->updatePaymentStatus($paid,'refunded');$before=$repo->refund($rid);
$result=refund_callback($svc,'evt_created','api','re_api',100,'completed');
refund_check($result['status']==='refunded'&&$repo->refund($rid)===$before,'Already-accounted API refund callback succeeds without changing the original row');
refund_callback($svc,'evt_updated','api','re_api',100,'completed');
refund_check(count($repo->refundsForPayment($paid))===1&&$repo->refundedMinorForPayment($paid)===100,'Different event IDs for one remote refund never double count');
refund_check($pdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE status='processed'")->fetchColumn()==2,'Both distinct duplicate notifications are acknowledged');
refund_callback($svc,'evt_updated','api','re_api',100,'completed');
refund_check($pdo->query('SELECT COUNT(*) FROM cms_payment_webhook_receipts')->fetchColumn()==2,'Identical event replay reuses its receipt');
refund_reject(fn()=>refund_callback($svc,'evt_bad_amount','api','re_api',99,'completed'),'Same refund ID with a different amount is rejected');
refund_reject(fn()=>refund_callback($svc,'evt_updated','api','re_api',99,'completed'),'Same event ID with a different payload is rejected');
$other=refund_payment($repo,'other');
refund_reject(fn()=>refund_callback($svc,'evt_wrong_payment','other','re_api',100,'completed'),'Same refund ID cannot be rebound to another payment');
refund_check($repo->payment($other)['status']==='paid'&&$repo->refundsForPayment($other)===[],'Rejected callback leaves the other payment unchanged');

$pending=refund_payment($repo,'pending');
$authorization=$repo->insertAuthorization(['payment_id'=>$pending,'subject_type'=>'callback_test','subject_id'=>'pending','token_hash'=>hash('sha256','auth'),
    'status'=>'active','max_uses'=>1,'used_count'=>0,'expires_at'=>gmdate('c',time()+3600)]);
refund_callback($svc,'evt_pending','pending','re_pending',100,'pending');
$pendingRow=$repo->refundByRemote('test.callback','re_pending');
refund_check($pendingRow['status']==='pending'&&$repo->refundedMinorForPayment($pending)===0,'Pending callback is persisted without reducing net paid amount');
refund_check($repo->authorization($authorization)['status']==='active','Pending callback does not revoke access');
refund_callback($svc,'evt_complete','pending','re_pending',100,'completed');
refund_check($repo->refund((int)$pendingRow['id'])['status']==='completed'&&count($repo->refundsForPayment($pending))===1,'Pending-to-completed transition updates the same refund row');
refund_check($repo->payment($pending)['status']==='refunded'&&$repo->authorization($authorization)['status']==='revoked','Completed full refund synchronizes payment and revokes access');
$completed=$repo->refund((int)$pendingRow['id']);
refund_callback($svc,'evt_delayed_pending','pending','re_pending',100,'pending');
refund_callback($svc,'evt_repeat_complete','pending','re_pending',100,'completed');
refund_check($repo->refund((int)$pendingRow['id'])===$completed,'Delayed pending or repeated success does not regress a completed refund');
refund_reject(fn()=>refund_callback($svc,'evt_conflict_terminal','pending','re_pending',100,'failed'),'Contradictory terminal refund status is rejected');

foreach (['failed','cancelled'] as $status) {
    $pid=refund_payment($repo,$status);
    refund_callback($svc,'evt_'.$status.'_pending',$status,'re_'.$status,100,'pending');
    refund_callback($svc,'evt_'.$status,$status,'re_'.$status,100,$status);
    refund_check($repo->payment($pid)['status']==='paid'&&$repo->refundedMinorForPayment($pid)===0,'Pending-to-'.$status.' does not deduct funds');
}
$over=refund_payment($repo,'over');
refund_callback($svc,'evt_over_pending','over','re_over',20,'pending');
refund_callback($svc,'evt_other_completed','over','re_other_completed',90,'completed');
refund_reject(fn()=>refund_callback($svc,'evt_over_complete','over','re_over',20,'completed'),'Pending completion cannot exceed remaining captured amount');
refund_check($repo->refundByRemote('test.callback','re_over')['status']==='pending'&&$repo->refundedMinorForPayment($over)===90,'Over-refund rejection rolls back its status transition');

$rollback=refund_payment($repo,'rollback');
refund_callback($svc,'evt_rollback_pending','rollback','re_rollback',100,'pending');
$pdo->beginTransaction();refund_callback($svc,'evt_rollback_complete','rollback','re_rollback',100,'completed');
refund_check($pdo->inTransaction(),'Callback retains ownership of a caller transaction');$pdo->rollBack();
refund_check($repo->refundByRemote('test.callback','re_rollback')['status']==='pending'&&$repo->payment($rollback)['status']==='paid','Caller rollback restores pending refund and payment atomically');

$concurrent=refund_payment($repo,'concurrent');
refund_callback($svc,'evt_concurrent_pending','concurrent','re_concurrent',100,'pending');
$children=[];
for($i=0;$i<6;$i++){
    $proc=proc_open([PHP_BINARY,__FILE__,'--worker',$path,'evt_concurrent_'.$i],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $children[]=[$proc,$pipes];
}
foreach($children as[$proc,$pipes]){
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
    if(proc_close($proc)!==0)throw new RuntimeException($output.$error);
}
refund_check(count($repo->refundsForPayment($concurrent))===1&&$repo->refundedMinorForPayment($concurrent)===100,'Six concurrent callback events complete one economic refund exactly once');
refund_check((int)$pdo->query("SELECT COUNT(*) FROM cms_payment_webhook_receipts WHERE external_event_id LIKE 'evt_concurrent_%' AND status='processed'")->fetchColumn()===7,'All concurrent notifications and original pending event are acknowledged');
unset($svc,$repo,$pdo);unlink($path);
echo "Checks: $checks; provider calls: 0; real refunds: 0\n";
