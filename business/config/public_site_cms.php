<?php
declare(strict_types=1);

require_once __DIR__ . '/role_portal_auth.php';

const PUBLIC_SITE_CMS_VERSION = '1.0-dynamic-customer-site';

function pscms_org_id(PDO $pdo): int
{
    $id=(int)$pdo->query("SELECT id FROM organizations ORDER BY id LIMIT 1")->fetchColumn();
    if($id<=0) throw new RuntimeException('Organization is not configured.');
    return $id;
}

function pscms_ensure(PDO $pdo): void
{
    static $done=false;if($done)return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS public_site_content (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        content_key VARCHAR(120) NOT NULL,
        content_value TEXT NOT NULL,
        updated_by BIGINT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_public_site_content (organization_id,content_key),
        KEY idx_public_site_content_org (organization_id),
        CONSTRAINT fk_public_site_content_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS public_site_stories (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        member_name VARCHAR(190) NOT NULL,
        headline VARCHAR(220) NULL,
        story_text TEXT NOT NULL,
        image_path VARCHAR(500) NOT NULL,
        sort_order INT NOT NULL DEFAULT 100,
        is_featured TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        created_by BIGINT UNSIGNED NULL,
        updated_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_public_site_stories (organization_id,status,sort_order,id),
        CONSTRAINT fk_public_site_stories_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS public_site_services (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        service_name VARCHAR(190) NOT NULL,
        service_text TEXT NOT NULL,
        image_path VARCHAR(500) NOT NULL,
        sort_order INT NOT NULL DEFAULT 100,
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        created_by BIGINT UNSIGNED NULL,
        updated_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_public_site_services (organization_id,status,sort_order,id),
        CONSTRAINT fk_public_site_services_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $orgId=pscms_org_id($pdo);
    $defaults=[
        'global_brand_name'=>'Healthcare Wellness Club',
        'global_whatsapp'=>'918858302744',
        'global_phone'=>'+91-5483561586',
        'global_email'=>'healthcare.wellnessclub@gmail.com',
        'global_location'=>'Wellness Center, Ghazipur, Uttar Pradesh, India',
        'home_eyebrow'=>'Personal wellness. Professional guidance.',
        'home_title'=>'Build a healthier routine with Healthcare Wellness Club.',
        'home_lead'=>'A modern wellness space for personalised guidance, healthy routine support, community motivation and easy access to services — designed to keep your wellness journey simple, organised and consistent.',
        'home_primary_cta'=>'Explore Wellness Services',
        'home_secondary_cta'=>'Contact the Club',
        'about_kicker'=>'About the coach',
        'about_title'=>'Guidance built around consistency, support and healthier everyday habits.',
        'about_intro'=>'Healthcare Wellness Club is designed as a welcoming space where people can receive practical wellness guidance, stay motivated and build routines that fit their everyday life.',
        'about_coach_name'=>'Kusum Maurya',
        'about_coach_role'=>'Wellness Coach • Healthcare Wellness Club',
        'about_mission_title'=>'Helping people make wellness feel simpler and more achievable.',
        'about_mission_copy'=>'As an independent wellness coach working with Herbalife products, my role is to support people with general wellness education, motivation, routine building and community connection.',
        'about_mission_quote'=>'My mission is to empower individuals to build healthier, happier everyday routines through personalised support, practical education and a positive community environment.',
        'services_kicker'=>'Wellness services',
        'services_title'=>'Practical support for healthier everyday routines.',
        'services_intro'=>'Choose from wellness education, guided activities, community support and follow-up options designed to make healthy routines easier to understand and maintain.',
        'services_chip'=>'Wellness support areas',
        'services_note'=>'These services provide general wellness education and support. They are not a substitute for diagnosis, treatment or personalised medical advice from a qualified healthcare professional.',
        'services_cta_title'=>'Talk to the club before you begin.',
        'services_cta_copy'=>'Share what kind of wellness support you are looking for and the club can explain the available services and how they work.',
        'stories_kicker'=>'Member experiences',
        'stories_title'=>'Real people. Personal journeys. Everyday consistency.',
        'stories_intro'=>'Explore individual member experiences one story at a time. Use the story selector or let the spotlight rotate automatically.',
        'stories_chip'=>'Personal experiences • Results vary',
        'stories_disclaimer'=>'Individual experiences differ. Wellness outcomes can vary based on many factors. These stories are personal accounts, not medical claims or guaranteed outcomes.',
        'stories_cta_title'=>'Your wellness journey can be personal too.',
        'stories_cta_copy'=>'Explore the available services or contact the club to understand the support, routines and community activities available.',
        'contact_kicker'=>'Contact the club',
        'contact_title'=>'Have a question? Start with a simple conversation.',
        'contact_intro'=>'Send a wellness, joining, appointment or product enquiry. Your request goes directly into the club’s secure follow-up workspace.',
        'contact_chip'=>'Ghazipur • Uttar Pradesh • India',
        'contact_info_copy'=>'Use whichever contact method is most convenient for your enquiry.',
        'contact_disclaimer_title'=>'General wellness support, not medical treatment.',
        'contact_disclaimer_copy'=>'The club provides general wellness education, motivation and community support. For symptoms, diagnosis, treatment or medical concerns, please consult an appropriately qualified healthcare professional.'
    ];
    $ins=$pdo->prepare("INSERT IGNORE INTO public_site_content(organization_id,content_key,content_value) VALUES(?,?,?)");
    foreach($defaults as $k=>$v)$ins->execute([$orgId,$k,$v]);

    $s=$pdo->prepare("SELECT COUNT(*) FROM public_site_stories WHERE organization_id=?");$s->execute([$orgId]);
    if((int)$s->fetchColumn()===0){
        $stories=[
            ['Kusum Maurya','A routine built with consistency','Shared that regular wellness routines and consistent support helped her feel more active and better supported in everyday life.','img/kusum-me.jpg',10,1],
            ['Pinki Devi','Confidence through consistency','Shared a positive experience with greater activity, confidence and consistency during her wellness journey.','success/pinki.jpg',20,0],
            ['Poonam Maurya','A more organised wellness routine','Shared that following a more organised routine helped her feel healthier, happier and more consistent.','success/poonam.jpg',30,0],
            ['Ramanand Pandey','Long-term routine changes','Shared a long-term wellness journey involving sustained routine changes and improvements in personally reported well-being.','success/Rama.jpg',40,0],
            ['Shilpa Singh','More energy and confidence','Shared feeling more energetic and confident after maintaining a consistent wellness routine.','success/shilpa.jpg',50,0],
            ['Kusum Pandey','Positive long-term consistency','Shared a positive long-term experience focused on greater energy, confidence and everyday consistency.','success/Kusum.jpg',60,0]
        ];
        $i=$pdo->prepare("INSERT INTO public_site_stories(organization_id,member_name,headline,story_text,image_path,sort_order,is_featured,status) VALUES(?,?,?,?,?,?,?,'active')");
        foreach($stories as $r)$i->execute([$orgId,...$r]);
    }

    $s=$pdo->prepare("SELECT COUNT(*) FROM public_site_services WHERE organization_id=?");$s->execute([$orgId]);
    if((int)$s->fetchColumn()===0){
        $services=[
            ['Nutrition Consulting','Balanced meals, nutrition habits and general product information.','img/service1.jpg'],
            ['Fitness Sessions','Group movement, yoga and guided activity for different ability levels.','img/service2.jpg'],
            ['Wellness Goal Support','Routine planning, progress review and regular check-ins around chosen wellness goals.','img/service3.jpg'],
            ['Wellness Workshops','Hydration, sleep, mindfulness, stress management and everyday healthy habits.','img/service4.jpg'],
            ['Community Activities','Walks and community events that encourage connection, consistency and activity.','img/service5.jpg'],
            ['Online Support','Virtual coaching, webinars and online groups for useful tips and encouragement.','img/service6.jpg'],
            ['Healthy Habit Challenges','Positive challenges around hydration, movement and consistency.','img/service7.jpg'],
            ['Everyday Motivation','Practical encouragement and follow-up for maintaining useful routines.','img/service8.jpg'],
            ['Mental Well-being Resources','General relaxation, mindfulness and stress-management practices.','img/service9.jpg']
        ];
        $i=$pdo->prepare("INSERT INTO public_site_services(organization_id,service_name,service_text,image_path,sort_order,status) VALUES(?,?,?,?,?,'active')");
        $n=10;foreach($services as $r){$i->execute([$orgId,$r[0],$r[1],$r[2],$n]);$n+=10;}
    }
    $done=true;
}

function pscms_payload(PDO $pdo): array
{
    pscms_ensure($pdo);$orgId=pscms_org_id($pdo);
    $s=$pdo->prepare("SELECT content_key,content_value FROM public_site_content WHERE organization_id=?");$s->execute([$orgId]);$content=[];
    foreach($s->fetchAll() as $r)$content[(string)$r['content_key']]=(string)$r['content_value'];
    $s=$pdo->prepare("SELECT id,member_name,headline,story_text,image_path,sort_order,is_featured FROM public_site_stories WHERE organization_id=? AND status='active' ORDER BY is_featured DESC,sort_order,id");$s->execute([$orgId]);$stories=$s->fetchAll();
    $s=$pdo->prepare("SELECT id,service_name,service_text,image_path,sort_order FROM public_site_services WHERE organization_id=? AND status='active' ORDER BY sort_order,id");$s->execute([$orgId]);$services=$s->fetchAll();
    return ['version'=>PUBLIC_SITE_CMS_VERSION,'content'=>$content,'stories'=>$stories,'services'=>$services,'updated_at'=>gmdate('c')];
}
