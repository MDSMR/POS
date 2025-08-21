<?php
// /controllers/admin/rewards/cashback/ledger_export_csv.php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
require_method('GET');

$q      = trim((string)($_GET['q'] ?? ''));
$branch = (int)($_GET['branch'] ?? 0);
$type   = (string)($_GET['type'] ?? 'all');
$d      = (string)($_GET['d'] ?? 'month');
$from   = (string)($_GET['from'] ?? '');
$to     = (string)($_GET['to'] ?? '');

$today=new DateTimeImmutable('today');
switch($d){
  case 'day':   $start=$today->format('Y-m-d 00:00:00'); $end=$today->format('Y-m-d 23:59:59'); break;
  case 'week':  $ws=$today->modify('monday this week'); $we=$ws->modify('+6 days'); $start=$ws->format('Y-m-d 00:00:00'); $end=$we->format('Y-m-d 23:59:59'); break;
  case 'year':  $ys=new DateTimeImmutable(date('Y').'-01-01'); $ye=new DateTimeImmutable(date('Y').'-12-31'); $start=$ys->format('Y-m-d 00:00:00'); $end=$ye->format('Y-m-d 23:59:59'); break;
  case 'custom':
    $fd=$from!==''?DateTimeImmutable::createFromFormat('Y-m-d',$from):$today->modify('first day of this month');
    $td=$to  !==''?DateTimeImmutable::createFromFormat('Y-m-d',$to)  :$today->modify('last day of this month');
    if(!$fd) $fd=$today; if(!$td) $td=$today; if($fd>$td){ [$fd,$td]=[$td,$fd]; }
    $start=$fd->format('Y-m-d 00:00:00'); $end=$td->format('Y-m-d 23:59:59'); break;
  case 'month':
  default:      $ms=$today->modify('first day of this month'); $me=$today->modify('last day of this month'); $start=$ms->format('Y-m-d 00:00:00'); $end=$me->format('Y-m-d 23:59:59'); $d='month';
}

$filename='cashback_ledger_'.date('Ymd_His').'.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');

$fp = fopen('php://output', 'w');
fputcsv($fp, ['ID','Date','Customer','Phone','Branch','Order','Type','Amount','Note']);

try{
  $out = db_tx(function(PDO $pdo) use($TENANT_ID,$q,$branch,$type,$start,$end,$fp){
    $where=["l.tenant_id=:t","l.created_at BETWEEN :ds AND :de","p.program_type='cashback'"];
    $args=[':t'=>$TENANT_ID, ':ds'=>$start, ':de'=>$end];

    if($q!==''){
      $where[]="(c.phone LIKE :q OR c.name LIKE :q OR o.customer_name LIKE :q OR l.order_id=:qid)";
      $args[':q']='%'.$q.'%';
      $args[':qid']=ctype_digit($q)?(int)$q:-1;
    }
    if($branch>0){ $where[]="o.branch_id=:b"; $args[':b']=$branch; }
    if(in_array($type,['earn','redeem','expire','stamp_reward','adjustment'],true)){
      if($type==='earn')             $where[]="l.entry_type='cashback_earn'";
      elseif($type==='redeem')       $where[]="l.entry_type='cashback_redeem'";
      elseif($type==='expire')       $where[]="l.entry_type='expire'";
      elseif($type==='stamp_reward') $where[]="l.entry_type='stamp_reward_cash'";
      else                           $where[]="l.entry_type LIKE 'cashback_adjust%'";
    }

    $whereSql='WHERE '.implode(' AND ',$where);
    $sql="
      SELECT l.id,l.created_at,l.entry_type,l.cash_delta,l.meta_json,
             l.order_id,
             b.name AS branch_name,
             COALESCE(c.name, o.customer_name) AS customer_name,
             c.phone AS customer_phone
      FROM loyalty_ledger l
      JOIN loyalty_programs p ON p.id=l.program_id AND p.tenant_id=:t
      LEFT JOIN orders o    ON o.id=l.order_id
      LEFT JOIN branches b  ON b.id=o.branch_id
      LEFT JOIN customers c ON c.id=l.customer_id
      $whereSql
      ORDER BY l.id DESC
      LIMIT 5000
    ";
    $st=$pdo->prepare($sql); $st->execute($args);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
      $et=(string)($r['entry_type']??'');
      $label = $et==='cashback_earn'?'Earn':($et==='cashback_redeem'?'Redeem':($et==='expire'?'Expire':($et==='stamp_reward_cash'?'Stamp Reward':ucfirst($et))));
      $meta = $r['meta_json']?json_decode((string)$r['meta_json'],true):[];
      $bits=[];
      if(isset($meta['percent'])) $bits[]='Earn '.$meta['percent'].'%';
      if(isset($meta['visit']))   $bits[]='Visit '.$meta['visit'];
      if(isset($meta['redeem_of'])) $bits[]='Redeem of #'.$meta['redeem_of'];
      fputcsv($fp, [
        (int)$r['id'],
        $r['created_at'],
        (string)($r['customer_name']??''),
        (string)($r['customer_phone']??''),
        (string)($r['branch_name']??''),
        $r['order_id']?('#'.$r['order_id']):'',
        $label,
        number_format((float)($r['cash_delta']??0), 3, '.', ''),
        $bits?implode(' · ',$bits):'',
      ]);
    }
    return true;
  });
} catch(Throwable $e){
  fputcsv($fp, ['ERROR', $e->getMessage()]);
}
fclose($fp);