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
    if(!business_column_exists($pdo,'dynamic_form_definitions','is_custom'))$pdo->exec("ALTER TABLE dynamic_form_definitions ADD COLUMN is_custom TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dynamic_form_submissions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, organization_id BIGINT UNSIGNED NOT NULL, form_id BIGINT UNSIGNED NOT NULL,
        submission_code VARCHAR(60) NOT NULL, submitted_by BIGINT UNSIGNED NOT NULL, submitted_role VARCHAR(30) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'submitted', submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dynamic_submission_code (organization_id,submission_code), KEY idx_dynamic_submissions (organization_id,form_id,submitted_at)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dynamic_form_submission_values (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, organization_id BIGINT UNSIGNED NOT NULL, submission_id BIGINT UNSIGNED NOT NULL,
        field_id BIGINT UNSIGNED NOT NULL, field_key VARCHAR(120) NOT NULL, field_label VARCHAR(190) NOT NULL, field_value TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_dynamic_submission_value (submission_id,field_id),
        KEY idx_dynamic_submission_values (organization_id,submission_id)
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
      'customers'=>[
        ['customer_name','Customer Name','text',1],['mobile','Mobile','text',0],['email','Email','text',0],
        ['customer_type','Customer Type','select',0,['retail'=>'Retail','preferred'=>'Preferred Customer','associate'=>'Associate','member'=>'Member Customer','other'=>'Other']],
        ['member_id','Verified Member Link','select',0],['status','Status','select',0,['active'=>'Active','inactive'=>'Inactive']],['notes','Notes','text',0],
      ],
      'memberships'=>[
        ['user_id','Customer Login Account','select',1],['member_code','Club Member ID','text',0],['discount_label_id','Discount Label','select',0],
        ['coach_user_id','Assigned Coach','select',0],['crm_customer_id','CRM Customer Link','select',0],
        ['membership_status','Membership Status','select',0,['pending'=>'Pending','active'=>'Active','inactive'=>'Inactive','expired'=>'Expired']],
        ['joined_at','Joined Date','text',0],['notes','Internal Membership Note','text',0],
        ['label_name','Label Name','text',0],['label_code','Label Code','text',0],['pricing_tier_code','Pricing Tier','select',0],
        ['discount_type','Discount Type','select',0,['percentage'=>'Percentage','fixed'=>'Fixed Amount','tier_price'=>'Exact Tier Price']],['discount_value','Discount Value','text',0],
        ['title','Offer Title','text',0],['subtitle','Offer Subtitle','text',0],['promotion_type','Promotion Type','select',0],['product_id','Product','select',0],['starts_at','Starts At','text',0],['ends_at','Ends At','text',0],
      ],
      'leads'=>[
        ['q','Search','text',0],['stage','Stage','select',0,['all'=>'All Stages','new'=>'New','contacted'=>'Contacted','qualified'=>'Qualified','appointment'=>'Appointment','converted'=>'Converted','closed'=>'Closed']],
        ['full_name','Lead Name','text',1],['mobile','Mobile','text',0],['email','Email','text',0],['lead_type','Lead Type','select',0,['contact'=>'Contact','wellness'=>'Wellness','join'=>'Join','appointment'=>'Appointment','product'=>'Product']],
        ['priority','Priority','select',0,['normal'=>'Normal','high'=>'High','urgent'=>'Urgent']],['assigned_user_id','Assigned Coach / Administrator','select',0],['message','Message','text',0],
      ],
      'appointments'=>[
        ['appointment_id','Appointment','select',1],['status','Status','select',0,['scheduled'=>'Scheduled','completed'=>'Completed','cancelled'=>'Cancelled','no_show'=>'No Show','rescheduled'=>'Rescheduled']],['notes','Notes','text',0],
        ['start_at','Appointment Date & Time','text',0],['mode','Mode','select',0,['club'=>'Club Visit','phone'=>'Phone','video'=>'Video','home'=>'Home Visit']],['purpose','Purpose','text',0],['assigned_user_id','Assigned Coach / Administrator','select',0],
      ],
      'products'=>[
        ['sku','Stock / SKU','text',1],['product_name','Product Name','text',1],['short_name','Short Name','text',0],['brand_name','Brand','text',0],
        ['category_id','Existing Category','select',1],['new_category','Create New Category','text',0],['pack_size','Pack Size','text',0],['pack_unit','Pack Unit','text',0],
        ['description','Description / Product Detail','text',0],['status','Status','select',0,['active'=>'Active','inactive'=>'Inactive']],['product_image','Product Image','text',0],
        ['effective_from','Price Effective Date','text',1],['mrp','MRP','text',1],['volume_points','Volume Points','text',1],['source_reference','Authoritative Source / Reference','text',1],
      ],
      'inventory'=>[
        ['movement_type','Movement Type','select',0,['purchase'=>'Purchase Receipt','opening'=>'Opening Stock','customer_return'=>'Customer Return to Stock','adjustment_plus'=>'Positive Adjustment']],
        ['product_id','Product','select',1],['quantity','Quantity','text',1],['movement_date','Movement Date','text',1],['batch_code','Batch / Lot No.','text',0],
        ['manufacture_date','Manufacture Date','text',0],['expiry_date','Expiry Date','text',0],['unit_cost','Actual Unit Cost','text',0],['supplier_name','Supplier','text',0],['purchase_reference','Bill / Purchase Reference','text',0],['notes','Notes','text',0],['use_as_profit_cost','Use as Profit Cost','checkbox',0,['1'=>'Yes']],
      ],
      'purchases'=>[
        ['supplier_id','Supplier','select',1],['order_date','Order Date','text',1],['expected_date','Expected Date','text',0],['notes','Notes','text',0],
        ['product_id','Product','select',0],['quantity','Quantity','text',0],['estimated_unit_cost','Estimated Unit Cost','text',0],
        ['status','PO Status','select',0,['draft'=>'Draft','ordered'=>'Ordered','closed'=>'Closed','cancelled'=>'Cancelled']],
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

function dynamic_forms_custom_catalog(PDO $pdo,int $orgId): array
{
    dynamic_forms_ensure($pdo);$s=$pdo->prepare("SELECT d.*,(SELECT COUNT(*) FROM dynamic_form_fields f WHERE f.form_id=d.id AND f.organization_id=d.organization_id AND f.status='active') field_count,(SELECT COUNT(*) FROM dynamic_form_submissions x WHERE x.form_id=d.id AND x.organization_id=d.organization_id) submission_count FROM dynamic_form_definitions d WHERE d.organization_id=? AND d.is_custom=1 AND d.status='active' ORDER BY d.sort_order,d.form_name");$s->execute([$orgId]);return $s->fetchAll();
}

function dynamic_forms_schema(PDO $pdo,int $orgId,string $page): array
{
    dynamic_forms_ensure($pdo);$page=basename($page);$s=$pdo->prepare("SELECT * FROM dynamic_form_definitions WHERE organization_id=? AND target_page=? AND status='active' ORDER BY sort_order,id");$s->execute([$orgId,$page]);$forms=$s->fetchAll();
    foreach($forms as &$form){$q=$pdo->prepare("SELECT * FROM dynamic_form_fields WHERE organization_id=? AND form_id=? AND status='active' ORDER BY sort_order,id");$q->execute([$orgId,(int)$form['id']]);$form['fields']=$q->fetchAll();foreach($form['fields'] as &$field){$o=$pdo->prepare("SELECT id,option_value,option_label,parent_option_value,sort_order FROM dynamic_form_options WHERE organization_id=? AND field_id=? AND status='active' ORDER BY sort_order,id");$o->execute([$orgId,(int)$field['id']]);$field['options']=$o->fetchAll();}unset($field);}unset($form);return $forms;
}
