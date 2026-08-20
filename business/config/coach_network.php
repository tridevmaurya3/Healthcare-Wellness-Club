<?php
declare(strict_types=1);

require_once __DIR__ . '/role_portal_auth.php';

const COACH_NETWORK_VERSION = '1.0-coach-hierarchy-levels';

function coach_network_ensure(PDO $pdo): void
{
    role_portal_ensure($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS coach_level_labels (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        level_code VARCHAR(60) NOT NULL,
        level_name VARCHAR(150) NOT NULL,
        level_group VARCHAR(40) NOT NULL DEFAULT 'associate',
        sort_order INT NOT NULL DEFAULT 10,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_coach_level (organization_id,level_code),
        KEY idx_coach_level_sort (organization_id,status,sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS coach_network_profiles (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        organization_id BIGINT UNSIGNED NOT NULL,
        coach_user_id BIGINT UNSIGNED NOT NULL,
        parent_coach_user_id BIGINT UNSIGNED NULL,
        level_label_id BIGINT UNSIGNED NULL,
        herbalife_member_id VARCHAR(80) NULL,
        joined_at DATE NULL,
        network_status VARCHAR(20) NOT NULL DEFAULT 'active',
        notes TEXT NULL,
        assigned_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_coach_profile (organization_id,coach_user_id),
        KEY idx_coach_parent (organization_id,parent_coach_user_id),
        KEY idx_coach_level (organization_id,level_label_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ctx=security_step17_context($pdo);$orgId=(int)$ctx['organization_id'];
    $levels=[
        ['INDEPENDENT_ASSOCIATE','Independent Associate','associate',10],
        ['SENIOR_CONSULTANT','Senior Consultant','associate',20],
        ['SUCCESS_BUILDER','Success Builder','associate',30],
        ['QUALIFIED_PRODUCER','Qualified Producer','associate',40],
        ['SUPERVISOR','Supervisor','sales_leader',50],
        ['WORLD_TEAM','World Team','recognition',60],
        ['GLOBAL_EXPANSION_TEAM','Global Expansion Team (GET)','recognition',70],
        ['MILLIONAIRE_TEAM','Millionaire Team','recognition',80],
        ['PRESIDENTS_TEAM',"President's Team",'recognition',90],
        ['CHAIRMANS_CLUB',"Chairman's Club",'recognition',100],
        ['FOUNDERS_CIRCLE',"Founder's Circle",'recognition',110],
    ];
    $s=$pdo->prepare("INSERT INTO coach_level_labels(organization_id,level_code,level_name,level_group,sort_order,status) VALUES(?,?,?,?,?,'active') ON DUPLICATE KEY UPDATE level_name=VALUES(level_name),level_group=VALUES(level_group),sort_order=VALUES(sort_order),status='active'");
    foreach($levels as $level)$s->execute([$orgId,...$level]);
}

function coach_network_would_cycle(PDO $pdo,int $orgId,int $coachId,int $parentId): bool
{
    if($parentId<=0)return false;if($coachId===$parentId)return true;
    $seen=[];$current=$parentId;$q=$pdo->prepare("SELECT parent_coach_user_id FROM coach_network_profiles WHERE organization_id=? AND coach_user_id=? LIMIT 1");
    while($current>0&&!isset($seen[$current])){$seen[$current]=true;if($current===$coachId)return true;$q->execute([$orgId,$current]);$current=(int)($q->fetchColumn()?:0);}
    return false;
}
