<?php
declare(strict_types=1);

require_once __DIR__ . '/sales_step15.php';
require_once __DIR__ . '/purchase_step14.php';

const FINANCE_STEP16_SOURCE_CODE = 'FINANCE';

function finance_step16_tables(): array
{
    return [
        'finance_ledger_accounts','finance_cash_accounts','finance_journals','finance_journal_lines',
        'finance_source_links','finance_manual_transactions','finance_statement_lines','finance_reconciliations',
    ];
}

function finance_step16_run_migration(PDO $pdo): void
{
    $file = dirname(__DIR__, 2) . '/database/migrations/012_step16_finance_accounting.sql';
    if (!is_file($file)) throw new RuntimeException('STEP 16 finance migration is missing.');
    $sql = (string)file_get_contents($file);
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement === '') continue;
        $statement = preg_replace('/^(?:\s*--[^\r\n]*(?:\r?\n|$))+/', '', $statement) ?? $statement;
        $statement = trim($statement);
        if ($statement === '' || preg_match('/^USE\s+/i', $statement)) continue;
        $pdo->exec($statement);
    }
}

function finance_step16_seed(PDO $pdo): void
{
    $ctx = product_step12_context($pdo); $orgId = (int)$ctx['organization_id'];
    $accounts = [
        ['1000','Cash Clearing','asset','debit','cash_clearing'],
        ['1010','Bank Clearing','asset','debit','bank_clearing'],
        ['1020','UPI Clearing','asset','debit','upi_clearing'],
        ['1030','Card Settlement Clearing','asset','debit','card_clearing'],
        ['1040','Cheque Clearing','asset','debit','cheque_clearing'],
        ['1050','Other Payment Clearing','asset','debit','other_clearing'],
        ['1100','Accounts Receivable','asset','debit','accounts_receivable'],
        ['1150','Customer Advances / Unapplied Receipts','liability','credit','customer_advances'],
        ['1200','Inventory Asset','asset','debit','inventory_asset'],
        ['1210','Purchases / Goods in Transit Clearing','asset','debit','purchases_clearing'],
        ['2000','Accounts Payable','liability','credit','accounts_payable'],
        ['3000','Opening Balance Equity','equity','credit','opening_equity'],
        ['4000','Product Sales Revenue','income','credit','product_sales'],
        ['4100','Sales Returns & Allowances','income','credit','sales_returns'],
        ['4200','Business Income','income','credit','business_income'],
        ['4210','Royalty Income','income','credit','royalty_income'],
        ['4290','Purchase Return Gain / Adjustment','income','credit','purchase_return_gain'],
        ['5000','Cost of Goods Sold','expense','debit','cogs'],
        ['5100','General Business Expense','expense','debit','general_expense'],
        ['5190','Purchase Return Loss / Adjustment','expense','debit','purchase_return_loss'],
    ];
    $stmt=$pdo->prepare("INSERT INTO finance_ledger_accounts(organization_id,account_code,account_name,account_class,normal_balance,system_role,is_system,status) VALUES(?,?,?,?,?,?,1,'active') ON DUPLICATE KEY UPDATE account_name=VALUES(account_name),account_class=VALUES(account_class),normal_balance=VALUES(normal_balance),is_system=1,status='active'");
    foreach($accounts as $a)$stmt->execute([$orgId,$a[0],$a[1],$a[2],$a[3],$a[4]]);

    $clearings = [
        ['SYS-CASH','Cash Clearing','cash','cash_clearing'],
        ['SYS-BANK','Bank Clearing','bank','bank_clearing'],
        ['SYS-UPI','UPI Clearing','upi','upi_clearing'],
        ['SYS-CARD','Card Settlement Clearing','card','card_clearing'],
        ['SYS-CHEQUE','Cheque Clearing','cheque','cheque_clearing'],
        ['SYS-OTHER','Other Payment Clearing','other','other_clearing'],
    ];
    $find=$pdo->prepare("SELECT id FROM finance_ledger_accounts WHERE organization_id=? AND system_role=? LIMIT 1");
    $ins=$pdo->prepare("INSERT INTO finance_cash_accounts(organization_id,ledger_account_id,account_code,account_name,account_type,opening_balance,is_system_clearing,status,notes) VALUES(?,?,?,?,?,0,1,'active','System clearing account. Reconcile/reclassify to the real account when known.') ON DUPLICATE KEY UPDATE account_name=VALUES(account_name),account_type=VALUES(account_type),is_system_clearing=1,status='active'");
    foreach($clearings as $c){$find->execute([$orgId,$c[3]]);$lid=(int)$find->fetchColumn();if($lid>0)$ins->execute([$orgId,$lid,$c[0],$c[1],$c[2]]);}
}

function finance_step16_ensure(PDO $pdo): void
{
    sales_step15_ensure($pdo); purchase_step14_ensure($pdo);
    foreach(finance_step16_tables() as $table){if(!business_table_exists($pdo,$table)){finance_step16_run_migration($pdo);break;}}
    foreach(finance_step16_tables() as $table){if(!business_table_exists($pdo,$table))throw new RuntimeException('STEP 16 table missing: '.$table);}
    finance_step16_seed($pdo);
    if(business_table_exists($pdo,'schema_meta')){
        $pdo->exec("INSERT INTO schema_meta(meta_key,meta_value) VALUES('finance_step16_version','1.0-complete') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $pdo->exec("INSERT IGNORE INTO schema_meta(meta_key,meta_value) VALUES('finance_step16_activated_at',DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s'))");
    }
}

function finance_step16_context(PDO $pdo): array { finance_step16_ensure($pdo); return product_step12_context($pdo); }

function finance_step16_source(PDO $pdo,int $orgId): int
{
    $stmt=$pdo->prepare("INSERT INTO data_sources(organization_id,source_code,source_name,source_type,is_active) VALUES(?,?,'Finance & Accounting','manual',1) ON DUPLICATE KEY UPDATE source_name=VALUES(source_name),source_type=VALUES(source_type),is_active=1");$stmt->execute([$orgId,FINANCE_STEP16_SOURCE_CODE]);
    $stmt=$pdo->prepare("SELECT id FROM data_sources WHERE organization_id=? AND source_code=? LIMIT 1");$stmt->execute([$orgId,FINANCE_STEP16_SOURCE_CODE]);$id=(int)$stmt->fetchColumn();if($id<=0)throw new RuntimeException('Finance source could not be prepared.');return $id;
}
function finance_step16_json(array $data): string {$j=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);if($j===false)throw new RuntimeException('Finance JSON could not be encoded.');return $j;}
function finance_step16_raw_event(PDO $pdo,int $orgId,int $clubId,string $dataset,string $externalId,array $payload,string $entityType,?int $entityId=null): int
{
    $sourceId=finance_step16_source($pdo,$orgId);$raw=finance_step16_json($payload);$stmt=$pdo->prepare("INSERT INTO raw_source_records(organization_id,club_id,data_source_id,source_dataset,external_record_id,captured_at,record_hash,raw_json,mapping_status,mapped_entity_type,mapped_entity_id) VALUES(?,?,?,?,?,NOW(),?,?,'mapped',?,?)");$stmt->execute([$orgId,$clubId,$sourceId,$dataset,$externalId,hash('sha256',$raw),$raw,$entityType,$entityId]);return(int)$pdo->lastInsertId();
}
function finance_step16_audit(PDO $pdo,int $orgId,int $clubId,string $event,string $entityType,?int $entityId,array $details): void
{
    if(!business_table_exists($pdo,'audit_logs'))return;$stmt=$pdo->prepare("INSERT INTO audit_logs(organization_id,club_id,event_type,entity_type,entity_id,details_json,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?)");$stmt->execute([$orgId,$clubId,$event,$entityType,$entityId,finance_step16_json($details),substr((string)($_SERVER['REMOTE_ADDR']??''),0,64),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);
}
function finance_step16_date(string $value,string $label): string {$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value)throw new RuntimeException('Choose a valid '.$label.'.');return $value;}
function finance_step16_code(string $prefix): string {return $prefix.'-'.date('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(2)),0,4));}

function finance_step16_role(PDO $pdo,int $orgId,string $role): array
{
    $stmt=$pdo->prepare("SELECT * FROM finance_ledger_accounts WHERE organization_id=? AND system_role=? AND status='active' LIMIT 1");$stmt->execute([$orgId,$role]);$r=$stmt->fetch();if(!$r)throw new RuntimeException('Finance ledger role missing: '.$role);return $r;
}
function finance_step16_cash(PDO $pdo,int $orgId,int $cashId): array
{
    $stmt=$pdo->prepare("SELECT c.*,l.account_name ledger_name,l.status ledger_status FROM finance_cash_accounts c JOIN finance_ledger_accounts l ON l.id=c.ledger_account_id AND l.organization_id=c.organization_id WHERE c.organization_id=? AND c.id=? LIMIT 1");$stmt->execute([$orgId,$cashId]);$r=$stmt->fetch();if(!$r)throw new RuntimeException('Cash/Bank account was not found.');return $r;
}
function finance_step16_cash_for_method(PDO $pdo,int $orgId,string $method): array
{
    $map=['cash'=>'cash_clearing','bank'=>'bank_clearing','upi'=>'upi_clearing','card'=>'card_clearing','cheque'=>'cheque_clearing','other'=>'other_clearing'];$role=$map[$method]??'other_clearing';$ledger=finance_step16_role($pdo,$orgId,$role);$stmt=$pdo->prepare("SELECT * FROM finance_cash_accounts WHERE organization_id=? AND ledger_account_id=? AND status='active' LIMIT 1");$stmt->execute([$orgId,(int)$ledger['id']]);$c=$stmt->fetch();if(!$c)throw new RuntimeException('System clearing account missing for '.$method.'.');return $c;
}

function finance_step16_create_ledger_category(PDO $pdo,string $name,string $class): int
{
    finance_step16_ensure($pdo);$ctx=finance_step16_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$name=trim($name);if($name==='')throw new RuntimeException('Category name is required.');if(!in_array($class,['income','expense'],true))throw new RuntimeException('Custom category must be Income or Expense.');$code='USR-'.strtoupper(substr(hash('sha256',$class.'|'.$name.'|'.microtime(true)),0,10));$normal=$class==='income'?'credit':'debit';$stmt=$pdo->prepare("INSERT INTO finance_ledger_accounts(organization_id,account_code,account_name,account_class,normal_balance,is_system,status) VALUES(?,?,?,?,?,0,'active')");$stmt->execute([$orgId,$code,$name,$class,$normal]);$id=(int)$pdo->lastInsertId();$raw=finance_step16_raw_event($pdo,$orgId,$clubId,'Finance Category','finance-category-'.$id,['ledger_account_id'=>$id,'name'=>$name,'class'=>$class],'finance_ledger_account',$id);finance_step16_audit($pdo,$orgId,$clubId,'finance_category_created','finance_ledger_account',$id,['raw_source_id'=>$raw]);return $id;
}

function finance_step16_post_journal(PDO $pdo,string $date,string $type,string $memo,string $sourceCode,?string $sourceType,?int $sourceId,?string $sourceHash,array $lines,?int $rawId=null,?int $reversalOf=null): int
{
    $ctx=product_step12_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$date=finance_step16_date($date,'journal date');if(!$lines)throw new RuntimeException('Journal needs at least two lines.');$resolved=[];$debit=0.0;$credit=0.0;$n=0;
    foreach($lines as $line){$n++;$lid=(int)($line['ledger_id']??0);if($lid<=0){$role=trim((string)($line['role']??''));if($role==='')throw new RuntimeException('Journal line account is missing.');$lid=(int)finance_step16_role($pdo,$orgId,$role)['id'];}$d=round((float)($line['debit']??0),2);$c=round((float)($line['credit']??0),2);if($d<0||$c<0||($d>0&&$c>0)||($d<=0&&$c<=0))throw new RuntimeException('Each journal line must contain one positive debit or credit.');$cashId=isset($line['cash_id'])&&$line['cash_id']!==null?(int)$line['cash_id']:null;if($cashId!==null&&$cashId>0){$cash=finance_step16_cash($pdo,$orgId,$cashId);if((int)$cash['ledger_account_id']!==$lid)throw new RuntimeException('Cash account does not match its ledger account.');}$resolved[]=['ledger_id'=>$lid,'cash_id'=>$cashId,'debit'=>$d,'credit'=>$c,'memo'=>(string)($line['memo']??'')];$debit+=$d;$credit+=$c;}
    $debit=round($debit,2);$credit=round($credit,2);if(abs($debit-$credit)>0.01||$debit<=0)throw new RuntimeException('Journal is not balanced: debit '.number_format($debit,2).' vs credit '.number_format($credit,2).'.');
    $number=finance_step16_code($reversalOf?'REV':'JV');$pdo->beginTransaction();try{$stmt=$pdo->prepare("INSERT INTO finance_journals(organization_id,club_id,journal_number,journal_date,journal_type,memo,source_code,source_type,source_id,source_hash,status,reversal_of_id,raw_source_id) VALUES(?,?,?,?,?,?,?,?,?,?,'posted',?,?)");$stmt->execute([$orgId,$clubId,$number,$date,$type,trim($memo)?:null,$sourceCode?:null,$sourceType,$sourceId,$sourceHash,$reversalOf,$rawId]);$jid=(int)$pdo->lastInsertId();$ins=$pdo->prepare("INSERT INTO finance_journal_lines(organization_id,journal_id,ledger_account_id,cash_account_id,line_no,debit_amount,credit_amount,line_memo) VALUES(?,?,?,?,?,?,?,?)");$i=0;foreach($resolved as $r){$i++;$ins->execute([$orgId,$jid,$r['ledger_id'],$r['cash_id'],$i,$r['debit'],$r['credit'],$r['memo']?:null]);}$pdo->commit();return $jid;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function finance_step16_reverse_journal(PDO $pdo,int $journalId,string $reason): int
{
    $ctx=finance_step16_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$stmt=$pdo->prepare("SELECT * FROM finance_journals WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$journalId]);$j=$stmt->fetch();if(!$j)throw new RuntimeException('Finance journal was not found.');$stmt=$pdo->prepare("SELECT id FROM finance_journals WHERE organization_id=? AND reversal_of_id=? LIMIT 1");$stmt->execute([$orgId,$journalId]);$existing=(int)$stmt->fetchColumn();if($existing>0)return $existing;$stmt=$pdo->prepare("SELECT * FROM finance_journal_lines WHERE organization_id=? AND journal_id=? ORDER BY line_no");$stmt->execute([$orgId,$journalId]);$lines=[];foreach($stmt->fetchAll() as $l)$lines[]=['ledger_id'=>(int)$l['ledger_account_id'],'cash_id'=>$l['cash_account_id']!==null?(int)$l['cash_account_id']:null,'debit'=>(float)$l['credit_amount'],'credit'=>(float)$l['debit_amount'],'memo'=>'Reversal: '.trim($reason)];$rid=finance_step16_post_journal($pdo,(string)$j['journal_date'],'reversal','Reversal of '.$j['journal_number'].' • '.trim($reason),'FINANCE',$j['source_type']?:'journal',$journalId,hash('sha256','reverse|'.$journalId.'|'.$reason),$lines,null,$journalId);$pdo->prepare("UPDATE finance_journals SET status='reversed' WHERE organization_id=? AND id=?")->execute([$orgId,$journalId]);finance_step16_audit($pdo,$orgId,$clubId,'finance_journal_reversed','finance_journal',$journalId,['reversal_journal_id'=>$rid,'reason'=>trim($reason)]);return $rid;
}

function finance_step16_sync_source(PDO $pdo,string $sourceType,int $sourceId,string $eventKey,string $date,string $journalType,string $memo,string $sourceCode,array $payload,array $lines,bool $active=true): ?int
{
    $ctx=finance_step16_context($pdo);$orgId=(int)$ctx['organization_id'];$hash=hash('sha256',finance_step16_json($payload));$stmt=$pdo->prepare("SELECT * FROM finance_source_links WHERE organization_id=? AND source_type=? AND source_id=? AND event_key=? LIMIT 1");$stmt->execute([$orgId,$sourceType,$sourceId,$eventKey]);$link=$stmt->fetch();
    if(!$active){if($link&&$link['status']==='active'){finance_step16_reverse_journal($pdo,(int)$link['journal_id'],'Source event is no longer active.');$pdo->prepare("UPDATE finance_source_links SET status='reversed',source_hash=? WHERE id=?")->execute([$hash,(int)$link['id']]);}return null;}
    if($link&&$link['status']==='active'&&hash_equals((string)$link['source_hash'],$hash))return(int)$link['journal_id'];
    if($link&&$link['status']==='active')finance_step16_reverse_journal($pdo,(int)$link['journal_id'],'Source event changed; replacement journal posted.');
    $jid=finance_step16_post_journal($pdo,$date,$journalType,$memo,$sourceCode,$sourceType,$sourceId,$hash,$lines);
    if($link)$pdo->prepare("UPDATE finance_source_links SET source_hash=?,journal_id=?,status='active' WHERE id=?")->execute([$hash,$jid,(int)$link['id']]);
    else{$stmt=$pdo->prepare("INSERT INTO finance_source_links(organization_id,source_type,source_id,event_key,source_hash,journal_id,status) VALUES(?,?,?,?,?,?,'active')");$stmt->execute([$orgId,$sourceType,$sourceId,$eventKey,$hash,$jid]);}
    return $jid;
}

function finance_step16_sync_all(PDO $pdo): array
{
    finance_step16_ensure($pdo);sales_step15_backfill($pdo);$ctx=finance_step16_context($pdo);$orgId=(int)$ctx['organization_id'];$counts=['invoice'=>0,'customer_payment'=>0,'customer_return'=>0,'refund'=>0,'cogs'=>0,'supplier_bill'=>0,'receipt'=>0,'supplier_payment'=>0,'supplier_return'=>0];
    $ar=finance_step16_role($pdo,$orgId,'accounts_receivable');$adv=finance_step16_role($pdo,$orgId,'customer_advances');

    $stmt=$pdo->prepare("SELECT i.*,sl.sale_status FROM sales_invoices i JOIN product_sale_ledger sl ON sl.organization_id=i.organization_id AND sl.order_id=i.order_id WHERE i.organization_id=?");$stmt->execute([$orgId]);foreach($stmt->fetchAll() as $r){$active=$r['status']==='active'&&$r['sale_status']==='active';$amount=round((float)$r['net_amount'],2);$lines=[['role'=>'accounts_receivable','debit'=>$amount],['role'=>'product_sales','credit'=>$amount]];finance_step16_sync_source($pdo,'sales_invoice',(int)$r['id'],'invoice',(string)$r['invoice_date'],'sales_invoice','Sales Invoice '.$r['invoice_number'],'CUSTOMER-SALES',['status'=>$r['status'],'sale_status'=>$r['sale_status'],'net'=>$amount],$lines,$active&&$amount>0);$counts['invoice']++;}

    $stmt=$pdo->prepare("SELECT p.*,EXISTS(SELECT 1 FROM sales_invoices i WHERE i.organization_id=p.organization_id AND i.order_id=p.order_id AND i.status='active') has_invoice FROM product_sale_payments p WHERE p.organization_id=?");$stmt->execute([$orgId]);foreach($stmt->fetchAll() as $r){$cash=finance_step16_cash_for_method($pdo,$orgId,(string)$r['payment_method']);$target=(int)$r['has_invoice']>0?'accounts_receivable':'customer_advances';$amount=round((float)$r['amount'],2);$lines=[['ledger_id'=>(int)$cash['ledger_account_id'],'cash_id'=>(int)$cash['id'],'debit'=>$amount],['role'=>$target,'credit'=>$amount]];finance_step16_sync_source($pdo,'product_sale_payment',(int)$r['id'],'receipt',(string)$r['payment_date'],'customer_receipt','Customer payment for Sale #'.$r['order_id'],'PRODUCT-SALES',['status'=>$r['status'],'amount'=>$amount,'method'=>$r['payment_method'],'target'=>$target],$lines,$r['status']==='active'&&$amount>0);$counts['customer_payment']++;}

    $stmt=$pdo->prepare("SELECT r.*,EXISTS(SELECT 1 FROM sales_invoices i WHERE i.organization_id=r.organization_id AND i.order_id=r.order_id AND i.status='active') has_invoice,COALESCE(x.cost_total,0) cost_total,COALESCE(x.missing_cost,0) missing_cost FROM sales_returns r LEFT JOIN (SELECT sri.sales_return_id,SUM(CASE WHEN poi.unit_cost IS NOT NULL THEN sri.quantity_returned*poi.unit_cost ELSE 0 END) cost_total,SUM(poi.unit_cost IS NULL) missing_cost FROM sales_return_items sri JOIN product_order_items poi ON poi.id=sri.order_item_id AND poi.organization_id=sri.organization_id WHERE sri.organization_id=? GROUP BY sri.sales_return_id) x ON x.sales_return_id=r.id WHERE r.organization_id=?");$stmt->execute([$orgId,$orgId]);foreach($stmt->fetchAll() as $r){$credit=round((float)$r['total_credit'],2);$cost=round((float)$r['cost_total'],2);$active=$r['status']==='posted'&&(int)$r['has_invoice']>0;$lines=[];if($credit>0){$lines[]=['role'=>'sales_returns','debit'=>$credit];$lines[]=['role'=>'accounts_receivable','credit'=>$credit];}if((int)$r['missing_cost']===0&&$cost>0){$lines[]=['role'=>'inventory_asset','debit'=>$cost];$lines[]=['role'=>'cogs','credit'=>$cost];}$hasEffect=$credit>0||((int)$r['missing_cost']===0&&$cost>0);finance_step16_sync_source($pdo,'sales_return',(int)$r['id'],'credit_note',(string)$r['return_date'],'customer_return','Customer Return '.$r['return_number'],'CUSTOMER-SALES',['status'=>$r['status'],'credit'=>$credit,'cost'=>$cost,'missing_cost'=>(int)$r['missing_cost'],'has_invoice'=>(int)$r['has_invoice']],$lines,$active&&$hasEffect);$counts['customer_return']++;}

    $stmt=$pdo->prepare("SELECT r.*,EXISTS(SELECT 1 FROM sales_invoices i WHERE i.organization_id=r.organization_id AND i.order_id=r.order_id AND i.status='active') has_invoice FROM sales_refunds r WHERE r.organization_id=?");$stmt->execute([$orgId]);foreach($stmt->fetchAll() as $r){$cash=finance_step16_cash_for_method($pdo,$orgId,(string)$r['refund_method']);$target=(int)$r['has_invoice']>0?'accounts_receivable':'customer_advances';$amount=round((float)$r['amount'],2);$lines=[['role'=>$target,'debit'=>$amount],['ledger_id'=>(int)$cash['ledger_account_id'],'cash_id'=>(int)$cash['id'],'credit'=>$amount]];finance_step16_sync_source($pdo,'sales_refund',(int)$r['id'],'refund',(string)$r['refund_date'],'customer_refund','Customer Refund #'.$r['id'],'CUSTOMER-SALES',['status'=>$r['status'],'amount'=>$amount,'method'=>$r['refund_method'],'target'=>$target],$lines,$r['status']==='active'&&$amount>0);$counts['refund']++;}

    $stmt=$pdo->prepare("SELECT sl.order_id,sl.sale_status,sl.cost_status,sl.cost_total,o.order_date FROM product_sale_ledger sl JOIN orders o ON o.id=sl.order_id AND o.organization_id=sl.organization_id WHERE sl.organization_id=?");$stmt->execute([$orgId]);foreach($stmt->fetchAll() as $r){$cost=round((float)($r['cost_total']??0),2);$active=$r['sale_status']==='active'&&$r['cost_status']==='complete'&&$cost>0;$lines=[['role'=>'cogs','debit'=>$cost],['role'=>'inventory_asset','credit'=>$cost]];finance_step16_sync_source($pdo,'product_sale',(int)$r['order_id'],'cogs',(string)$r['order_date'],'cogs','COGS for Product Sale #'.$r['order_id'],'PRODUCT-SALES',['sale_status'=>$r['sale_status'],'cost_status'=>$r['cost_status'],'cost'=>$cost],$lines,$active);$counts['cogs']++;}

    $stmt=$pdo->prepare("SELECT * FROM purchase_bills WHERE organization_id=?");$stmt->execute([$orgId]);foreach($stmt->fetchAll() as $r){$amount=round((float)$r['total_amount'],2);$lines=[['role'=>'purchases_clearing','debit'=>$amount],['role'=>'accounts_payable','credit'=>$amount]];finance_step16_sync_source($pdo,'purchase_bill',(int)$r['id'],'bill',(string)$r['invoice_date'],'supplier_bill','Supplier Bill '.$r['invoice_number'],'PURCHASES',['status'=>$r['status'],'total'=>$amount],$lines,$r['status']==='active'&&$amount>0);$counts['supplier_bill']++;}

    $stmt=$pdo->prepare("SELECT r.id,r.receipt_date,r.receipt_number,r.status,COALESCE(SUM(ri.quantity_received*ri.unit_cost),0) amount FROM purchase_receipts r LEFT JOIN purchase_receipt_items ri ON ri.purchase_receipt_id=r.id AND ri.organization_id=r.organization_id WHERE r.organization_id=? GROUP BY r.id");$stmt->execute([$orgId]);foreach($stmt->fetchAll() as $r){$amount=round((float)$r['amount'],2);$lines=[['role'=>'inventory_asset','debit'=>$amount],['role'=>'purchases_clearing','credit'=>$amount]];finance_step16_sync_source($pdo,'purchase_receipt',(int)$r['id'],'inventory_receipt',(string)$r['receipt_date'],'inventory_receipt','Goods Receipt '.$r['receipt_number'],'PURCHASES',['status'=>$r['status'],'amount'=>$amount],$lines,$r['status']==='posted'&&$amount>0);$counts['receipt']++;}

    $stmt=$pdo->prepare("SELECT * FROM purchase_payments WHERE organization_id=?");$stmt->execute([$orgId]);foreach($stmt->fetchAll() as $r){$cash=finance_step16_cash_for_method($pdo,$orgId,(string)$r['payment_method']);$amount=round((float)$r['amount'],2);$lines=[['role'=>'accounts_payable','debit'=>$amount],['ledger_id'=>(int)$cash['ledger_account_id'],'cash_id'=>(int)$cash['id'],'credit'=>$amount]];finance_step16_sync_source($pdo,'purchase_payment',(int)$r['id'],'supplier_payment',(string)$r['payment_date'],'supplier_payment','Supplier Payment #'.$r['id'],'PURCHASES',['status'=>$r['status'],'amount'=>$amount,'method'=>$r['payment_method']],$lines,$r['status']==='active'&&$amount>0);$counts['supplier_payment']++;}

    $stmt=$pdo->prepare("SELECT r.*,COALESCE(x.cost_total,0) cost_total FROM purchase_returns r LEFT JOIN (SELECT purchase_return_id,SUM(quantity_returned*unit_cost) cost_total FROM purchase_return_items WHERE organization_id=? GROUP BY purchase_return_id) x ON x.purchase_return_id=r.id WHERE r.organization_id=?");$stmt->execute([$orgId,$orgId]);foreach($stmt->fetchAll() as $r){$credit=round((float)$r['total_credit'],2);$cost=round((float)$r['cost_total'],2);$lines=[];if($credit>0)$lines[]=['role'=>'accounts_payable','debit'=>$credit];if($cost>0)$lines[]=['role'=>'inventory_asset','credit'=>$cost];if($credit>$cost+0.01)$lines[]=['role'=>'purchase_return_gain','credit'=>round($credit-$cost,2)];elseif($cost>$credit+0.01)$lines[]=['role'=>'purchase_return_loss','debit'=>round($cost-$credit,2)];finance_step16_sync_source($pdo,'purchase_return',(int)$r['id'],'supplier_credit',(string)$r['return_date'],'supplier_return','Supplier Return '.$r['return_number'],'PURCHASES',['status'=>$r['status'],'credit'=>$credit,'inventory_cost'=>$cost],$lines,$r['status']==='posted'&&($credit>0||$cost>0));$counts['supplier_return']++;}
    return $counts;
}

function finance_step16_create_cash_account(PDO $pdo,array $input): int
{
    finance_step16_ensure($pdo);$ctx=finance_step16_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$name=trim((string)($input['account_name']??''));if($name==='')throw new RuntimeException('Account name is required.');$type=(string)($input['account_type']??'bank');if(!in_array($type,['cash','bank','upi','wallet','other'],true))$type='other';$opening=round((float)($input['opening_balance']??0),2);$openingDate=trim((string)($input['opening_date']??''));if(abs($opening)>0.009)$openingDate=finance_step16_date($openingDate,'opening balance date');else$openingDate=$openingDate!==''?finance_step16_date($openingDate,'opening balance date'):null;$code='ACC-'.strtoupper(substr(hash('sha256',$name.'|'.microtime(true)),0,10));$ledgerCode='L-'.$code;
    $pdo->beginTransaction();try{$stmt=$pdo->prepare("INSERT INTO finance_ledger_accounts(organization_id,account_code,account_name,account_class,normal_balance,is_system,status,notes) VALUES(?,?,?,'asset','debit',0,'active','User-created liquid account.')");$stmt->execute([$orgId,$ledgerCode,$name]);$lid=(int)$pdo->lastInsertId();$stmt=$pdo->prepare("INSERT INTO finance_cash_accounts(organization_id,ledger_account_id,account_code,account_name,account_type,institution_name,account_last4,opening_balance,opening_date,is_system_clearing,status,notes) VALUES(?,?,?,?,?,?,?,?,?,0,'active',?)");$stmt->execute([$orgId,$lid,$code,$name,$type,trim((string)($input['institution_name']??''))?:null,trim((string)($input['account_last4']??''))?:null,$opening,$openingDate,trim((string)($input['notes']??''))?:null]);$id=(int)$pdo->lastInsertId();$rawId=finance_step16_raw_event($pdo,$orgId,$clubId,'Finance Account','finance-account-'.$id,['cash_account_id'=>$id,'account_name'=>$name,'account_type'=>$type,'opening_balance'=>$opening,'opening_date'=>$openingDate],'finance_cash_account',$id);$pdo->commit();if(abs($opening)>0.009){$lines=$opening>0?[['ledger_id'=>$lid,'cash_id'=>$id,'debit'=>abs($opening)],['role'=>'opening_equity','credit'=>abs($opening)]]:[['role'=>'opening_equity','debit'=>abs($opening)],['ledger_id'=>$lid,'cash_id'=>$id,'credit'=>abs($opening)]];finance_step16_post_journal($pdo,(string)$openingDate,'opening_balance','Opening balance • '.$name,'FINANCE','finance_cash_account',$id,hash('sha256','opening|'.$id.'|'.$opening.'|'.$openingDate),$lines,$rawId);}finance_step16_audit($pdo,$orgId,$clubId,'finance_cash_account_created','finance_cash_account',$id,['opening_balance'=>$opening,'raw_source_id'=>$rawId]);return $id;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function finance_step16_manual_transaction(PDO $pdo,array $input): int
{
    finance_step16_ensure($pdo);$ctx=finance_step16_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$date=finance_step16_date((string)($input['transaction_date']??''),'transaction date');$type=(string)($input['transaction_type']??'');if(!in_array($type,['income','expense','transfer'],true))throw new RuntimeException('Choose Income, Expense or Transfer.');$amount=round((float)($input['amount']??0),2);if($amount<=0)throw new RuntimeException('Amount must be greater than zero.');$memo=trim((string)($input['memo']??''));if($memo==='')throw new RuntimeException('Memo is required.');$category=(int)($input['category_ledger_account_id']??0);$from=(int)($input['from_cash_account_id']??0);$to=(int)($input['to_cash_account_id']??0);$lines=[];
    if($type==='income'){$cash=finance_step16_cash($pdo,$orgId,$to);$stmt=$pdo->prepare("SELECT * FROM finance_ledger_accounts WHERE organization_id=? AND id=? AND account_class='income' AND status='active' LIMIT 1");$stmt->execute([$orgId,$category]);$cat=$stmt->fetch();if(!$cat)throw new RuntimeException('Choose an Income category.');$lines=[['ledger_id'=>(int)$cash['ledger_account_id'],'cash_id'=>$to,'debit'=>$amount],['ledger_id'=>$category,'credit'=>$amount]];$from=null;}
    elseif($type==='expense'){$cash=finance_step16_cash($pdo,$orgId,$from);$stmt=$pdo->prepare("SELECT * FROM finance_ledger_accounts WHERE organization_id=? AND id=? AND account_class='expense' AND status='active' LIMIT 1");$stmt->execute([$orgId,$category]);$cat=$stmt->fetch();if(!$cat)throw new RuntimeException('Choose an Expense category.');$lines=[['ledger_id'=>$category,'debit'=>$amount],['ledger_id'=>(int)$cash['ledger_account_id'],'cash_id'=>$from,'credit'=>$amount]];$to=null;}
    else{if($from<=0||$to<=0||$from===$to)throw new RuntimeException('Choose different From and To accounts.');$fc=finance_step16_cash($pdo,$orgId,$from);$tc=finance_step16_cash($pdo,$orgId,$to);$lines=[['ledger_id'=>(int)$tc['ledger_account_id'],'cash_id'=>$to,'debit'=>$amount],['ledger_id'=>(int)$fc['ledger_account_id'],'cash_id'=>$from,'credit'=>$amount]];$category=null;}
    $rawId=finance_step16_raw_event($pdo,$orgId,$clubId,'Manual Finance Transaction','manual-finance-'.time().'-'.bin2hex(random_bytes(2)),['date'=>$date,'type'=>$type,'amount'=>$amount,'memo'=>$memo,'category'=>$category,'from'=>$from,'to'=>$to],'finance_manual_transaction',null);$jid=finance_step16_post_journal($pdo,$date,'manual_'.$type,$memo,'FINANCE','finance_manual_transaction',null,hash('sha256',finance_step16_json([$date,$type,$amount,$memo,$category,$from,$to,$rawId])),$lines,$rawId);$stmt=$pdo->prepare("INSERT INTO finance_manual_transactions(organization_id,club_id,transaction_date,transaction_type,amount,category_ledger_account_id,from_cash_account_id,to_cash_account_id,memo,journal_id,status,raw_source_id) VALUES(?,?,?,?,?,?,?,?,?,?,'active',?)");$stmt->execute([$orgId,$clubId,$date,$type,$amount,$category,$from,$to,$memo,$jid,$rawId]);$id=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE raw_source_records SET mapped_entity_id=? WHERE organization_id=? AND id=?")->execute([$id,$orgId,$rawId]);finance_step16_audit($pdo,$orgId,$clubId,'finance_manual_transaction_added','finance_manual_transaction',$id,['journal_id'=>$jid,'raw_source_id'=>$rawId]);return $id;
}

function finance_step16_reverse_manual(PDO $pdo,int $id,string $reason): void
{
    finance_step16_ensure($pdo);$ctx=finance_step16_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];if(strlen(trim($reason))<5)throw new RuntimeException('Enter a clear reversal reason.');$stmt=$pdo->prepare("SELECT * FROM finance_manual_transactions WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$id]);$r=$stmt->fetch();if(!$r)throw new RuntimeException('Manual finance transaction was not found.');if($r['status']!=='active')return;finance_step16_reverse_journal($pdo,(int)$r['journal_id'],trim($reason));$pdo->prepare("UPDATE finance_manual_transactions SET status='reversed',reversal_reason=? WHERE organization_id=? AND id=?")->execute([trim($reason),$orgId,$id]);$raw=finance_step16_raw_event($pdo,$orgId,$clubId,'Manual Finance Reversal','manual-finance-reversal-'.$id.'-'.time(),['transaction_id'=>$id,'reason'=>trim($reason)],'finance_manual_transaction',$id);finance_step16_audit($pdo,$orgId,$clubId,'finance_manual_transaction_reversed','finance_manual_transaction',$id,['reason'=>trim($reason),'raw_source_id'=>$raw]);
}

function finance_step16_post_legacy_receipt(PDO $pdo,string $kind,int $sourceId,int $cashId): int
{
    finance_step16_ensure($pdo);$ctx=finance_step16_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$cash=finance_step16_cash($pdo,$orgId,$cashId);$rev=defined('BUSINESS_REVERSED_SOURCE_SHEET')?BUSINESS_REVERSED_SOURCE_SHEET:'Manual Entry • Reversed';
    if($kind==='income'){$stmt=$pdo->prepare("SELECT id,income_date txn_date,amount,income_type label,source_sheet FROM income_entries WHERE organization_id=? AND id=? LIMIT 1");$role='business_income';$sourceType='legacy_income';}
    elseif($kind==='royalty'){$stmt=$pdo->prepare("SELECT id,COALESCE(royalty_date,DATE(created_at)) txn_date,amount,COALESCE(period_label,'Royalty') label,source_sheet FROM royalty_entries WHERE organization_id=? AND id=? LIMIT 1");$role='royalty_income';$sourceType='legacy_royalty';}
    else throw new RuntimeException('Legacy finance source type is invalid.');$stmt->execute([$orgId,$sourceId]);$r=$stmt->fetch();if(!$r||$r['source_sheet']===$rev)throw new RuntimeException('Active legacy source record was not found.');$amount=round((float)$r['amount'],2);if($amount<=0)throw new RuntimeException('Only a positive legacy receipt can be posted as received cash/bank.');$lines=[['ledger_id'=>(int)$cash['ledger_account_id'],'cash_id'=>$cashId,'debit'=>$amount],['role'=>$role,'credit'=>$amount]];$jid=finance_step16_sync_source($pdo,$sourceType,$sourceId,'confirmed_receipt',(string)$r['txn_date'],'legacy_receipt','Confirmed legacy receipt • '.$r['label'],'LEGACY-XLSX',['source_sheet'=>$r['source_sheet'],'amount'=>$amount,'cash_account_id'=>$cashId],$lines,true);$raw=finance_step16_raw_event($pdo,$orgId,$clubId,'Legacy Finance Bridge','legacy-finance-'.$kind.'-'.$sourceId.'-'.time(),['kind'=>$kind,'source_id'=>$sourceId,'cash_account_id'=>$cashId,'amount'=>$amount,'journal_id'=>$jid,'confirmation'=>'User explicitly confirmed this operational income as a received cash/bank amount.'],'finance_journal',$jid);finance_step16_audit($pdo,$orgId,$clubId,'legacy_finance_receipt_confirmed','finance_journal',$jid,['kind'=>$kind,'source_id'=>$sourceId,'raw_source_id'=>$raw]);return(int)$jid;
}

function finance_step16_cash_balance(PDO $pdo,int $orgId,int $cashId,?string $toDate=null): float
{
    $cash=finance_step16_cash($pdo,$orgId,$cashId);$sql="SELECT COALESCE(SUM(l.debit_amount-l.credit_amount),0) FROM finance_journal_lines l JOIN finance_journals j ON j.id=l.journal_id AND j.organization_id=l.organization_id WHERE l.organization_id=? AND l.cash_account_id=?";$args=[$orgId,$cashId];if($toDate!==null){$sql.=" AND j.journal_date<=?";$args[]=$toDate;}$stmt=$pdo->prepare($sql);$stmt->execute($args);return round((float)$stmt->fetchColumn(),2);
}
function finance_step16_accounts(PDO $pdo,int $orgId): array
{
    $stmt=$pdo->prepare("SELECT c.*,l.account_code ledger_code,l.account_name ledger_name FROM finance_cash_accounts c JOIN finance_ledger_accounts l ON l.id=c.ledger_account_id AND l.organization_id=c.organization_id WHERE c.organization_id=? ORDER BY c.is_system_clearing,c.account_name,c.id");$stmt->execute([$orgId]);$rows=$stmt->fetchAll();foreach($rows as &$r)$r['current_balance']=finance_step16_cash_balance($pdo,$orgId,(int)$r['id']);unset($r);return $rows;
}
function finance_step16_pnl(PDO $pdo,int $orgId,string $from,string $to): array
{
    $stmt=$pdo->prepare("SELECT a.account_class,a.account_code,a.account_name,SUM(l.debit_amount) debits,SUM(l.credit_amount) credits FROM finance_journal_lines l JOIN finance_journals j ON j.id=l.journal_id AND j.organization_id=l.organization_id JOIN finance_ledger_accounts a ON a.id=l.ledger_account_id AND a.organization_id=l.organization_id WHERE l.organization_id=? AND j.journal_date BETWEEN ? AND ? AND a.account_class IN ('income','expense') GROUP BY a.id ORDER BY a.account_class,a.account_code");$stmt->execute([$orgId,$from,$to]);$rows=$stmt->fetchAll();$income=0.0;$expense=0.0;foreach($rows as &$r){$r['net']=$r['account_class']==='income'?round((float)$r['credits']-(float)$r['debits'],2):round((float)$r['debits']-(float)$r['credits'],2);if($r['account_class']==='income')$income+=(float)$r['net'];else$expense+=(float)$r['net'];}unset($r);return ['rows'=>$rows,'income'=>round($income,2),'expense'=>round($expense,2),'profit'=>round($income-$expense,2)];
}
function finance_step16_control_balances(PDO $pdo,int $orgId): array
{
    $out=[];foreach(['accounts_receivable','accounts_payable','purchases_clearing','inventory_asset','customer_advances'] as $role){$a=finance_step16_role($pdo,$orgId,$role);$stmt=$pdo->prepare("SELECT COALESCE(SUM(l.debit_amount),0) d,COALESCE(SUM(l.credit_amount),0) c FROM finance_journal_lines l WHERE l.organization_id=? AND l.ledger_account_id=?");$stmt->execute([$orgId,(int)$a['id']]);$r=$stmt->fetch()?:['d'=>0,'c'=>0];$out[$role]=$a['normal_balance']==='debit'?round((float)$r['d']-(float)$r['c'],2):round((float)$r['c']-(float)$r['d'],2);}return $out;
}

function finance_step16_add_statement_line(PDO $pdo,int $cashId,string $date,float $signed,string $description,string $reference=''): int
{
    finance_step16_ensure($pdo);$ctx=finance_step16_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];finance_step16_cash($pdo,$orgId,$cashId);$date=finance_step16_date($date,'statement date');if(abs($signed)<0.01)throw new RuntimeException('Statement amount cannot be zero.');$raw=finance_step16_raw_event($pdo,$orgId,$clubId,'Bank Statement Line','statement-line-'.time().'-'.bin2hex(random_bytes(2)),['cash_account_id'=>$cashId,'date'=>$date,'amount_signed'=>$signed,'description'=>$description,'reference'=>$reference],'finance_statement_line',null);$stmt=$pdo->prepare("INSERT INTO finance_statement_lines(organization_id,cash_account_id,statement_date,amount_signed,description,reference_no,status,raw_source_id) VALUES(?,?,?,?,?,?,'pending',?)");$stmt->execute([$orgId,$cashId,$date,$signed,trim($description)?:null,trim($reference)?:null,$raw]);$id=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE raw_source_records SET mapped_entity_id=? WHERE organization_id=? AND id=?")->execute([$id,$orgId,$raw]);return $id;
}
function finance_step16_match_statement(PDO $pdo,int $statementId,int $journalLineId): void
{
    finance_step16_ensure($pdo);$ctx=finance_step16_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];$stmt=$pdo->prepare("SELECT * FROM finance_statement_lines WHERE organization_id=? AND id=? LIMIT 1");$stmt->execute([$orgId,$statementId]);$s=$stmt->fetch();if(!$s)throw new RuntimeException('Statement line was not found.');$stmt=$pdo->prepare("SELECT l.*,j.journal_date,j.journal_number FROM finance_journal_lines l JOIN finance_journals j ON j.id=l.journal_id AND j.organization_id=l.organization_id WHERE l.organization_id=? AND l.id=? AND l.cash_account_id=? LIMIT 1");$stmt->execute([$orgId,$journalLineId,(int)$s['cash_account_id']]);$l=$stmt->fetch();if(!$l)throw new RuntimeException('Matching cashbook journal line was not found.');$movement=round((float)$l['debit_amount']-(float)$l['credit_amount'],2);if(abs($movement-(float)$s['amount_signed'])>0.01)throw new RuntimeException('Statement amount does not match the selected cashbook movement.');$pdo->prepare("UPDATE finance_statement_lines SET matched_journal_line_id=?,status='matched' WHERE organization_id=? AND id=?")->execute([$journalLineId,$orgId,$statementId]);finance_step16_audit($pdo,$orgId,$clubId,'finance_statement_matched','finance_statement_line',$statementId,['journal_line_id'=>$journalLineId,'journal_number'=>$l['journal_number']]);
}
function finance_step16_create_reconciliation(PDO $pdo,int $cashId,string $from,string $to,float $statementEnding,string $notes=''): int
{
    finance_step16_ensure($pdo);$ctx=finance_step16_context($pdo);$orgId=(int)$ctx['organization_id'];$clubId=(int)$ctx['club_id'];finance_step16_cash($pdo,$orgId,$cashId);$from=finance_step16_date($from,'reconciliation start date');$to=finance_step16_date($to,'reconciliation end date');if($to<$from)throw new RuntimeException('Reconciliation end date cannot be before start date.');$calc=finance_step16_cash_balance($pdo,$orgId,$cashId,$to);$diff=round($statementEnding-$calc,2);$status=abs($diff)<=0.01?'reconciled':'draft';$stmt=$pdo->prepare("INSERT INTO finance_reconciliations(organization_id,cash_account_id,period_start,period_end,statement_ending_balance,calculated_ending_balance,difference_amount,status,notes,completed_at) VALUES(?,?,?,?,?,?,?,?,?,?)");$stmt->execute([$orgId,$cashId,$from,$to,$statementEnding,$calc,$diff,$status,trim($notes)?:null,$status==='reconciled'?date('Y-m-d H:i:s'):null]);$id=(int)$pdo->lastInsertId();$raw=finance_step16_raw_event($pdo,$orgId,$clubId,'Finance Reconciliation','finance-reconciliation-'.$id,['reconciliation_id'=>$id,'cash_account_id'=>$cashId,'period_start'=>$from,'period_end'=>$to,'statement_ending'=>$statementEnding,'calculated_ending'=>$calc,'difference'=>$diff,'status'=>$status],'finance_reconciliation',$id);finance_step16_audit($pdo,$orgId,$clubId,'finance_reconciliation_created','finance_reconciliation',$id,['difference'=>$diff,'status'=>$status,'raw_source_id'=>$raw]);return $id;
}
