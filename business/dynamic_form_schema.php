<?php
declare(strict_types=1);
require_once __DIR__.'/config/dynamic_forms.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{$pdo=role_portal_db();dynamic_forms_ensure($pdo);security_step17_session_start();$user=security_step17_session_user($pdo,true);if(!$user||!in_array((string)$user['role_code'],['admin','coach'],true)){http_response_code(403);throw new RuntimeException('Access denied.');}$ctx=security_step17_context($pdo);echo json_encode(['ok'=>true,'forms'=>dynamic_forms_schema($pdo,(int)$ctx['organization_id'],(string)($_GET['page']??''))],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}catch(Throwable $e){echo json_encode(['ok'=>false,'message'=>'Dynamic form schema unavailable.']);}
