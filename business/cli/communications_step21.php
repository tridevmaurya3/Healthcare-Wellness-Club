<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&PHP_SAPI!=='phpdbg'){http_response_code(403);exit("CLI only\n");}
require_once dirname(__DIR__).'/config/communications_step21.php';
try{$pdo=business_db();comm_step21_ensure($pdo);$ctx=comm_step21_context($pdo);$orgId=(int)$ctx['organization_id'];$result=comm_step21_scheduler_run($pdo,$orgId,true);echo json_encode(['ok'=>true,'step'=>21,'result'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit(0);}catch(Throwable $e){fwrite(STDERR,json_encode(['ok'=>false,'step'=>21,'error'=>$e->getMessage()],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);exit(1);}
