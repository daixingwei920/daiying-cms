<?php
declare(strict_types=1);
namespace Cms\Core\Homepage;
use PDO;
use RuntimeException;
/** Core-owned selection. Revisions and leases prevent restoring over later administrator choices. */
final class HomepageSelection {
 public function __construct(private readonly PDO $db){}
 public function snapshot():array {
  $r=$this->db->query('SELECT revision,state_json FROM cms_homepage_selection WHERE id=1')->fetch(PDO::FETCH_ASSOC);
  $s=is_array($r)?json_decode($r['state_json'],true):null;
  if(!is_array($s)||!isset($s['provider'],$s['owner'],$s['token']))throw new RuntimeException('Invalid homepage selection');
  return ['revision'=>(int)$r['revision']]+$s;
 }
 public function lease(string $owner):?array {$q=$this->db->prepare('SELECT lease_json FROM cms_homepage_leases WHERE owner_id=?');$q->execute([$owner]);$v=$q->fetchColumn();return $v===false?null:json_decode($v,true,512,JSON_THROW_ON_ERROR);}
 private function transaction(callable $fn):mixed {
  $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
  try{$this->db->exec('UPDATE cms_homepage_selection SET revision=revision WHERE id=1');$r=$fn();if($own)$this->db->commit();return $r;}
  catch(\Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 private function write(array $s,int $revision):void {$q=$this->db->prepare('UPDATE cms_homepage_selection SET state_json=?,revision=revision+1 WHERE id=1 AND revision=?');unset($s['revision']);$q->execute([json_encode($s,JSON_THROW_ON_ERROR),$revision]);if($q->rowCount()!==1)throw new RuntimeException('Homepage revision conflict');}
 private function saveLease(string $owner,array $s):void {$q=$this->db->prepare('DELETE FROM cms_homepage_leases WHERE owner_id=?');$q->execute([$owner]);$q=$this->db->prepare('INSERT INTO cms_homepage_leases VALUES (?,?)');$q->execute([$owner,json_encode($s,JSON_THROW_ON_ERROR)]);}
 /** Caller is the Core administrator endpoint after authentication, CSRF and provider validation. */
 public function select(string $provider,string $owner,int $expectedRevision):void {
  $this->transaction(function()use($provider,$owner,$expectedRevision){$old=$this->snapshot();if($old['revision']!==$expectedRevision)throw new RuntimeException('Homepage revision conflict');
   if($provider!=='core.theme'&&!str_starts_with($provider,$owner.':'))throw new RuntimeException('Invalid homepage owner');
   $token=bin2hex(random_bytes(16));
   if($old['provider']===$provider&&$old['owner']===$owner){$lease=$this->lease($owner);$previous=$lease['previous']??['provider'=>'core.theme','owner'=>'core','token'=>''];}
   else $previous=$old;
   $this->write(['provider'=>$provider,'owner'=>$owner,'token'=>$token],$expectedRevision);
   if($owner!=='core')$this->saveLease($owner,['provider'=>$provider,'token'=>$token,'previous'=>$previous,'state'=>'active']);
  });
 }
 /** Explicit restore only succeeds while this exact lease still owns the current selection. */
 public function restore(string $owner,int $expectedRevision):bool {
  return $this->transaction(function()use($owner,$expectedRevision){$s=$this->snapshot();if($s['revision']!==$expectedRevision)throw new RuntimeException('Homepage revision conflict');return $this->release($owner,false);});
 }
 private function release(string $owner,bool $pause):bool {
  $s=$this->snapshot();$l=$this->lease($owner);if(!$l||$s['owner']!==$owner||$s['token']!==$l['token'])return false;
  $this->write($l['previous'],$s['revision']);$l['state']=$pause?'paused':'restored';$l['resume_revision']=$s['revision']+1;$this->saveLease($owner,$l);return true;
 }
 public function lifecycle(string $owner,bool $enabled,bool $uninstall=false):void {
  $this->transaction(function()use($owner,$enabled,$uninstall){$l=$this->lease($owner);if(!$l)return;
   if(!$enabled){$this->release($owner,!$uninstall);if($uninstall){$q=$this->db->prepare('DELETE FROM cms_homepage_leases WHERE owner_id=?');$q->execute([$owner]);}return;}
   $s=$this->snapshot();if(($l['state']??'')!=='paused')return;
   if($s['revision']!==($l['resume_revision']??-1)){$l['state']='superseded';$this->saveLease($owner,$l);return;}
   $this->write(['provider'=>$l['provider'],'owner'=>$owner,'token'=>$l['token']],$s['revision']);$l['state']='active';$this->saveLease($owner,$l);
  });
 }
}
