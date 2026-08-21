<?php
declare(strict_types=1);
require_once __DIR__.'/config/dynamic_forms.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
 $pdo=role_portal_db();dynamic_forms_ensure($pdo);security_step17_session_start();$user=security_step17_session_user($pdo,true);
 if(!$user||(string)$user['role_code']!=='admin'){http_response_code(403);throw new RuntimeException('Administrator access required.');}
 $input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))throw new RuntimeException('Invalid request.');security_step17_verify_csrf((string)($input['csrf']??''));
 $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];$key=trim((string)($input['form_key']??''));$s=$pdo->prepare("SELECT id FROM dynamic_form_definitions WHERE organization_id=? AND form_key=? AND status='active' LIMIT 1");$s->execute([$orgId,$key]);$formId=(int)$s->fetchColumn();if($formId<=0)throw new RuntimeException('Form definition not found.');
 $fields=is_array($input['fields']??null)?array_slice($input['fields'],0,100):[];$addedFields=0;$addedOptions=0;$pdo->beginTransaction();
 foreach($fields as $index=>$field){if(!is_array($field))continue;$fieldKey=trim((string)($field['name']??''));$label=trim((string)($field['label']??$fieldKey));if($fieldKey===''||strlen($fieldKey)>120||preg_match('/(?:^|_)(?:id|token|csrf|password)$/i',$fieldKey))continue;$options=is_array($field['options']??null)?array_slice($field['options'],0,100):[];if(!$options)continue;
  $insert=$pdo->prepare("INSERT IGNORE INTO dynamic_form_fields(organization_id,form_id,field_key,field_label,field_type,help_text,sort_order,status) VALUES(?,?,?,?,'select','Auto-discovered from the connected Business OS form.',?,'active')");$insert->execute([$orgId,$formId,$fieldKey,substr($label?:$fieldKey,0,190),($index+1)*10]);$addedFields+=$insert->rowCount();
  $find=$pdo->prepare("SELECT id FROM dynamic_form_fields WHERE organization_id=? AND form_id=? AND field_key=? LIMIT 1");$find->execute([$orgId,$formId,$fieldKey]);$fieldId=(int)$find->fetchColumn();if($fieldId<=0)continue;
  $optionInsert=$pdo->prepare("INSERT IGNORE INTO dynamic_form_options(organization_id,field_id,option_value,option_label,sort_order,status) VALUES(?,?,?,?,?,'active')");$order=10;foreach($options as $option){if(!is_array($option))continue;$value=trim((string)($option['value']??''));$optionLabel=trim((string)($option['label']??''));if($value===''||$optionLabel===''||strlen($value)>190||strlen($optionLabel)>190)continue;$optionInsert->execute([$orgId,$fieldId,$value,$optionLabel,$order]);$addedOptions+=$optionInsert->rowCount();$order+=10;}
 }
 $pdo->commit();security_step17_audit($pdo,(int)$user['id'],'dynamic_form_auto_discovered','dynamic_form',$formId,['form_key'=>$key,'fields_added'=>$addedFields,'options_added'=>$addedOptions]);echo json_encode(['ok'=>true,'fields_added'=>$addedFields,'options_added'=>$addedOptions],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();http_response_code(http_response_code()>=400?http_response_code():422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
