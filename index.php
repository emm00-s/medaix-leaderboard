<?php
/******************************************************************
 * LimeSurvey — Live Leaderboard (debug build)
 * --------------------------------------------------------------
 * Version : 2.4‑d (debug env‑vars, full file)
 * Updated : 2025‑05‑11
 ******************************************************************/

// 1. INCLUDE CONFIGURATION FILE ------------------------------------------------
if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';

// DEBUG BLOCK (remove when stable)
error_log('[DEBUG] LIMESURVEY_USERNAME = ' . (defined('LIMESURVEY_USERNAME') ? LIMESURVEY_USERNAME : '(undef)'));

// 2. VERIFY CONSTANTS ---------------------------------------------------------
$need = ['LIMESURVEY_API_URL','LIMESURVEY_USERNAME','LIMESURVEY_PASSWORD','LIMESURVEY_SURVEY_ID','COLUMN_NICKNAME','COLUMN_SCORE'];
foreach($need as $c) if(!defined($c)) die("Missing constant $c");
if(!defined('LIMESURVEY_AUTH_SOURCE')) define('LIMESURVEY_AUTH_SOURCE','');

$err = null; $entries = [];
function api($url,$payload){$payload+=['jsonrpc'=>'2.0','id'=>1];$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);$raw=curl_exec($ch);$code=curl_getinfo($ch, CURLINFO_HTTP_CODE);curl_close($ch);if($code!=200) throw new Exception("HTTP $code");$resp=json_decode($raw,1);if(isset($resp['error'])) throw new Exception('API '.json_encode($resp['error']));return $resp;}
try{
  $params=[LIMESURVEY_USERNAME,LIMESURVEY_PASSWORD];if(LIMESURVEY_AUTH_SOURCE) $params[] = LIMESURVEY_AUTH_SOURCE;
  $key=api(LIMESURVEY_API_URL,['method'=>'get_session_key','params'=>$params])['result']??null;
  if(!$key) throw new Exception('No session key');
  $exp=api(LIMESURVEY_API_URL,['method'=>'export_responses','params'=>[$key,LIMESURVEY_SURVEY_ID,'json']]);
  api(LIMESURVEY_API_URL,['method'=>'release_session_key','params'=>[$key]]);
  if(!is_string($exp['result'])) throw new Exception('Unexpected export');
  $data=json_decode(base64_decode($exp['result']),1);
  foreach(($data['responses']??$data) as $set) foreach($set as $row){$n=$row[COLUMN_NICKNAME]??'';$s=$row[COLUMN_SCORE]??null;if($n!==''&&is_numeric($s)) $entries[]=['nickname'=>htmlspecialchars($n),'score'=>(int)$s];}
  usort($entries,fn($a,$b)=>$b['score']<=>$a['score']);
}catch(Exception $e){$err=$e->getMessage();}
$ttl=defined('LEADERBOARD_TITLE')?LEADERBOARD_TITLE:'Live Leaderboard';
$pts=defined('POINTS_SUFFIX')?POINTS_SUFFIX:'points';
$searchPH=defined('SEARCH_PLACEHOLDER')?SEARCH_PLACEHOLDER:'Search...';
$noMsg=defined('NO_RESULTS_MESSAGE')?NO_RESULTS_MESSAGE:'No nickname found.';
$noScores=defined('NO_SCORES_MESSAGE')?NO_SCORES_MESSAGE:'No scores yet.';
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($ttl)?></title>
<style>body{font-family:Arial,Helvetica,sans-serif;background:#f0f4f8;margin:0;padding:20px}.box{max-width:720px;margin:0 auto;background:#fff;padding:24px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.1)}h1{text-align:center;margin:0 0 24px}#search{width:100%;padding:12px;border:1px solid #ccc;border-radius:8px;margin-bottom:20px}ol{list-style:none;padding:0}li{display:flex;justify-content:space-between;padding:12px 16px;margin-bottom:8px;border:1px solid #e0e0e0;border-radius:8px;background:#fafafa}li:nth-child(1){background:#fff8e1}li:nth-child(2){background:#f5f5f5}li:nth-child(3){background:#fff0e6}.msg{padding:16px;text-align:center;border:1px solid #e0e0e0;border-radius:8px;background:#fff}</style></head><body><div class="box">
<h1><?=htmlspecialchars($ttl)?></h1>
<?php if($err):?><div class="msg"><?=htmlspecialchars($err)?></div><?php elseif(!$entries):?><div class="msg"><?=htmlspecialchars($noScores)?></div><?php else:?>
<input id="search" placeholder="<?=$searchPH?>"><ol id="list">
<?php foreach($entries as $i=>$e):$rank=$i+1;$badge=$rank<=3?['🥇','🥈','🥉'][$rank-1]:'#'.$rank;?><li><span><?=$badge?></span><span><?=$e['nickname']?></span><span><?=$e['score'].' '.$pts?></span></li><?php endforeach;?>
</ol><div id="nores" class="msg" style="display:none;"><?=$noMsg?></div><?php endif;?></div>
<?php if($entries):?><script>const q=document.getElementById('search'),items=[...document.querySelectorAll('#list li')],no=document.getElementById('nores');q.addEventListener('input',()=>{const t=q.value.toLowerCase().trim();let v=0;items.forEach(li=>{const m=li.children[1].textContent.toLowerCase().includes(t);li.style.display=m?'':'none';if(m)v++;});no.style.display=t&&!v?'block':'none';});</script><?php endif; ?></body></html>
