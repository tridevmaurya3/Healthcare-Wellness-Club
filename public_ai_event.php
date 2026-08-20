<?php
declare(strict_types=1);
require_once __DIR__.'/business/config/ai_analytics.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}
try{
 $data=json_decode(file_get_contents('php://input')?:'{}',true,32,JSON_THROW_ON_ERROR);$type=trim((string)($data['event_type']??''));
 if(!in_array($type,['panel_open','panel_close','topic_click','human_handoff','answer_helpful','answer_unanswered','risk_report'],true))throw new RuntimeException('Invalid event.');
 $token=strtolower(trim((string)($data['session_token']??'')));if(!preg_match('/^[a-f0-9-]{36}$/',$token))throw new RuntimeException('Invalid session.');
 $question=trim(preg_replace('/\s+/u',' ',(string)($data['question']??''))??'');if(in_array($type,['answer_unanswered','risk_report'],true)&&mb_strlen($question)<3)throw new RuntimeException('Report details required.');$question=mb_substr($question,0,1000);
 $pdo=role_portal_db();aia_ensure($pdo);$orgId=pscms_org_id($pdo);$topic=substr(trim((string)($data['topic']??'')),0,80);$page=aia_path((string)($data['page_path']??''));
 $limit=$pdo->prepare("SELECT COUNT(*) FROM public_ai_events WHERE organization_id=? AND session_token=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 MINUTE)");$limit->execute([$orgId,$token]);if((int)$limit->fetchColumn()>=20)throw new RuntimeException('Please wait before sending more feedback.');
 if($type==='risk_report'){$limit=$pdo->prepare("SELECT COUNT(*) FROM public_ai_safety_incidents WHERE organization_id=? AND session_token=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");$limit->execute([$orgId,$token]);if((int)$limit->fetchColumn()>=3)throw new RuntimeException('Report limit reached.');}
 $s=$pdo->prepare("INSERT INTO public_ai_events(organization_id,session_token,event_type,topic,page_path) VALUES(?,?,?,?,?)");$s->execute([$orgId,$token,$type,$topic?:null,$page?:null]);
 if($type==='answer_unanswered'){$s=$pdo->prepare("INSERT INTO public_ai_unanswered(organization_id,session_token,question_text,page_path) VALUES(?,?,?,?)");$s->execute([$orgId,$token,$question,$page?:null]);}
 if($type==='risk_report'){$s=$pdo->prepare("INSERT INTO public_ai_safety_incidents(organization_id,session_token,report_text,page_path) VALUES(?,?,?,?)");$s->execute([$orgId,$token,$question,$page?:null]);}
 echo json_encode(['ok'=>true]);
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'message'=>'Feedback could not be saved.']);}
