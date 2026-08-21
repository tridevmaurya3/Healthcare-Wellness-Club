<?php
declare(strict_types=1);

require_once __DIR__.'/role_portal_auth.php';

function dynamic_forms_ensure(PDO $pdo): void
{
    static $done=false;if($done)return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS dynamic_form_definitions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, organization_id BIGINT UNSIGNED NOT NULL,
        form_key VARCHAR(100) NOT NULL, form_name VARCHAR(190) NOT NULL, target_page VARCHAR(190) NOT NULL,
        description VARCHAR(500) NULL, sort_order INT NOT NULL DEFAULT 100, status VARCHAR(30) NOT NULL DEFAULT 'active',
        created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dynamic_form (organization_id,form_key), KEY idx_dynamic_form_page (organization_id,target_page,status)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dynamic_form_fields (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, organization_id BIGINT UNSIGNED NOT NULL, form_id BIGINT UNSIGNED NOT NULL,
        field_key VARCHAR(120) NOT NULL, field_label VARCHAR(190) NOT NULL, field_type VARCHAR(30) NOT NULL DEFAULT 'select',
        help_text VARCHAR(500) NULL, parent_field_id BIGINT UNSIGNED NULL, is_required TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 100, status VARCHAR(30) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dynamic_field (form_id,field_key), KEY idx_dynamic_fields (organization_id,form_id,status,sort_order)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dynamic_form_options (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, organization_id BIGINT UNSIGNED NOT NULL, field_id BIGINT UNSIGNED NOT NULL,
        option_value VARCHAR(190) NOT NULL, option_label VARCHAR(190) NOT NULL, parent_option_value VARCHAR(190) NULL,
        sort_order INT NOT NULL DEFAULT 100, status VARCHAR(30) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dynamic_option (field_id,option_value), KEY idx_dynamic_options (organization_id,field_id,status,sort_order)
    ) ENGINE=InnoDB");
    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];
    $seed=[
      ['new_ums','New UMS','data_entry_center.php','New member/UMS entry options'],['volume_points','Volume Points','data_entry_center.php','Personal and team VP entry options'],
      ['orders','Orders','data_entry_center.php','Order type and related customer options'],['renewal','Renewal','data_entry_center.php','Membership renewal options'],
      ['income','Income','data_entry_center.php','Retail, check and club income options'],['royalty','Royalty','data_entry_center.php','Royalty and royalty VP options'],
      ['customers','Customer Management','customer_center.php','Customer profile form options'],['memberships','Club Membership','customer_membership_manager.php','Membership, label and Coach options'],
      ['leads','Leads & Enquiries','lead_center.php','Lead source, stage and assignment options'],['appointments','Appointments','lead_appointments.php','Appointment and Coach options'],
      ['products','Product Master','product_master_manager.php','Product category and catalogue options'],['inventory','Inventory','inventory_center.php','Stock and batch options'],
      ['purchases','Purchases & Suppliers','purchase_center.php','Supplier and purchase workflow options']
    ];
    $s=$pdo->prepare("INSERT IGNORE INTO dynamic_form_definitions(organization_id,form_key,form_name,target_page,description,sort_order,status) VALUES(?,?,?,?,?,?,'active')");
    $n=10;foreach($seed as $row){$s->execute([$orgId,$row[0],$row[1],$row[2],$row[3],$n]);$n+=10;}
    dynamic_forms_seed_entry_fields($pdo,$orgId);
    $done=true;
}

/**
 * Register the fields that already exist in the six Master Tracking entry
 * forms. INSERT IGNORE preserves every Administrator edit while ensuring a
 * newly-added Designer tab is never empty before it has been opened once.
 */
function dynamic_forms_seed_entry_fields(PDO $pdo,int $orgId): void
{
    $catalog=[
      'new_ums'=>[
        ['full_name','Member Name','text',1],['mobile','Mobile','text',0],['ums_date','UMS Date','text',1],
        ['ums_type','UMS Type','select',0,['New UMS'=>'New UMS','First Set'=>'First Set','Second Set'=>'Second Set','Active UMS'=>'Active UMS']],
        ['status','Status','select',0,['active'=>'Active','inactive'=>'Inactive']],['team','Team / Managed By','select',0],['sponsor_member_id','Verified Sponsor','select',0],
      ],
      'volume_points'=>[
        ['member_id','Member','select',1],['entry_date','Date','text',1],['volume_points','Volume Points','text',1],
        ['order_type','Order Type','select',0,['Personal Order'=>'Personal Order','New UMS'=>'New UMS','Renewal UMS'=>'Renewal UMS','Extra Customer Order'=>'Extra Customer Order','Customer Order'=>'Customer Order','Team Order'=>'Team Order']],
        ['vp_from','VP From','select',0,['UMS'=>'UMS','Personal Order'=>'Personal Order','Customer Order'=>'Customer Order','1st Line'=>'1st Line','Downline'=>'Downline','Renewal'=>'Renewal']],
        ['ordered_by','Ordered / Managed By','select',0],
        ['vp_type','VP Type','select',0,['Personal VP'=>'Personal VP','Team VP'=>'Team VP','Customer VP'=>'Customer VP','Renewal VP'=>'Renewal VP','Order VP'=>'Order VP']],
        ['level_label','Coach Level','select',0],['week_label','Week','select',0,['Week-1'=>'Week 1','Week-2'=>'Week 2','Week-3'=>'Week 3','Week-4'=>'Week 4']],
      ],
      'orders'=>[
        ['member_id','Member','select',1],['order_date','Order Date','text',1],
        ['order_type','Order Type','select',1,['regular'=>'Regular Order','extra_customer'=>'Extra Customer Order','personal'=>'Personal Order','new_ums'=>'New UMS Order','renewal'=>'Renewal Order','team'=>'Team Order']],
        ['description','Description','text',0],['gross_amount','Gross Amount','text',1],['discount_amount','Discount','text',1],['net_amount','Net Amount','text',0],['profit_amount','Profit','text',1],['volume_points','Volume Points','text',1],
      ],
      'renewal'=>[
        ['member_id','Member','select',1],['renewal_date','Renewal Date','text',1],
        ['period_months','Period','select',0,['1'=>'1 Month','3'=>'3 Months','6'=>'6 Months','12'=>'12 Months','18'=>'18 Months','24'=>'24 Months','36'=>'36 Months']],
        ['amount','Amount','text',1],['volume_points','Volume Points','text',1],
      ],
      'income'=>[
        ['income_date','Income Date','text',1],['income_type','Income Type','select',1,['retail'=>'Retail','check'=>'Check','club'=>'Club','other'=>'Other']],['amount','Amount','text',1],['notes','Notes','text',0],
      ],
      'royalty'=>[
        ['royalty_date','Royalty Date','text',1],['period_label','Period','select',0,['Week-1'=>'Week 1','Week-2'=>'Week 2','Week-3'=>'Week 3','Week-4'=>'Week 4']],['amount','Amount','text',1],['volume_points','Volume Points','text',1],['notes','Notes','text',0],
      ],
    ];
    $findForm=$pdo->prepare("SELECT id FROM dynamic_form_definitions WHERE organization_id=? AND form_key=? LIMIT 1");
    $addField=$pdo->prepare("INSERT IGNORE INTO dynamic_form_fields(organization_id,form_id,field_key,field_label,field_type,help_text,is_required,sort_order,status) VALUES(?,?,?,?,?,'Connected to the existing Business OS entry form.',?,?,'active')");
    $findField=$pdo->prepare("SELECT id FROM dynamic_form_fields WHERE organization_id=? AND form_id=? AND field_key=? LIMIT 1");
    $addOption=$pdo->prepare("INSERT IGNORE INTO dynamic_form_options(organization_id,field_id,option_value,option_label,sort_order,status) VALUES(?,?,?,?,?,'active')");
    foreach($catalog as $formKey=>$fields){
        $findForm->execute([$orgId,$formKey]);$formId=(int)$findForm->fetchColumn();if($formId<=0)continue;
        $order=10;
        foreach($fields as $field){
            [$key,$label,$type,$required]=$field;$addField->execute([$orgId,$formId,$key,$label,$type,$required,$order]);
            $findField->execute([$orgId,$formId,$key]);$fieldId=(int)$findField->fetchColumn();
            $optionOrder=10;foreach(($field[4]??[]) as $value=>$optionLabel){$addOption->execute([$orgId,$fieldId,(string)$value,(string)$optionLabel,$optionOrder]);$optionOrder+=10;}
            $order+=10;
        }
    }
}

function dynamic_forms_catalog(PDO $pdo,int $orgId): array
{
    dynamic_forms_ensure($pdo);$s=$pdo->prepare("SELECT id,form_key,form_name,target_page,description,sort_order FROM dynamic_form_definitions WHERE organization_id=? AND status='active' ORDER BY sort_order,form_name");$s->execute([$orgId]);return $s->fetchAll();
}

function dynamic_forms_schema(PDO $pdo,int $orgId,string $page): array
{
    dynamic_forms_ensure($pdo);$page=basename($page);$s=$pdo->prepare("SELECT * FROM dynamic_form_definitions WHERE organization_id=? AND target_page=? AND status='active' ORDER BY sort_order,id");$s->execute([$orgId,$page]);$forms=$s->fetchAll();
    foreach($forms as &$form){$q=$pdo->prepare("SELECT * FROM dynamic_form_fields WHERE organization_id=? AND form_id=? AND status='active' ORDER BY sort_order,id");$q->execute([$orgId,(int)$form['id']]);$form['fields']=$q->fetchAll();foreach($form['fields'] as &$field){$o=$pdo->prepare("SELECT id,option_value,option_label,parent_option_value,sort_order FROM dynamic_form_options WHERE organization_id=? AND field_id=? AND status='active' ORDER BY sort_order,id");$o->execute([$orgId,(int)$field['id']]);$field['options']=$o->fetchAll();}unset($field);}unset($form);return $forms;
}
