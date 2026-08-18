<?php
declare(strict_types=1);

function business_entry_smart_trim(mixed $value): string
{
    return trim((string)$value);
}

function business_entry_smart_key(mixed $value): string
{
    $text = preg_replace('/\s+/u', ' ', business_entry_smart_trim($value));
    $text = $text === null ? '' : $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function business_entry_smart_mobile_digits(mixed $value): string
{
    return preg_replace('/\D+/', '', business_entry_smart_trim($value)) ?? '';
}

function business_entry_smart_week_label(string $date): string
{
    try {
        $day = (int)(new DateTimeImmutable($date))->format('j');
    } catch (Throwable) {
        return '';
    }
    $week = min(4, max(1, (int)ceil($day / 7)));
    return 'Week-' . $week;
}

function business_entry_smart_number(mixed $value, ?float $default = null): ?float
{
    $raw = str_replace([',', '₹', ' '], '', business_entry_smart_trim($value));
    if ($raw === '') {
        return $default;
    }
    return is_numeric($raw) ? (float)$raw : $default;
}

function business_entry_smart_duplicate(PDO $pdo, int $organizationId, string $module, array $data): array
{
    $result = ['duplicate'=>false, 'count'=>0, 'message'=>'No exact duplicate candidate found.', 'matches'=>[]];

    if ($module === 'new_ums') {
        $nameKey = business_entry_smart_key($data['full_name'] ?? '');
        $mobileDigits = business_entry_smart_mobile_digits($data['mobile'] ?? '');
        $umsDate = business_entry_smart_trim($data['ums_date'] ?? '');
        if ($nameKey === '' && $mobileDigits === '') {
            return $result;
        }

        $stmt = $pdo->prepare('SELECT id, full_name, mobile, join_date FROM members WHERE organization_id=? ORDER BY id DESC');
        $stmt->execute([$organizationId]);
        foreach ($stmt->fetchAll() as $row) {
            $sameName = $nameKey !== '' && business_entry_smart_key($row['full_name'] ?? '') === $nameKey;
            $sameMobile = $mobileDigits !== '' && business_entry_smart_mobile_digits($row['mobile'] ?? '') === $mobileDigits;
            $sameDate = $umsDate !== '' && (string)($row['join_date'] ?? '') === $umsDate;
            if (($sameName && $sameDate) || ($sameName && $sameMobile) || ($sameMobile && $sameDate)) {
                $result['matches'][] = [
                    'id'=>(int)$row['id'],
                    'label'=>(string)$row['full_name'],
                    'detail'=>'Existing member #' . (int)$row['id'] . (($row['join_date'] ?? null) ? ' • ' . (string)$row['join_date'] : ''),
                ];
            }
        }
    } elseif ($module === 'vp') {
        $memberId = (int)($data['member_id'] ?? 0);
        $date = business_entry_smart_trim($data['entry_date'] ?? '');
        $vp = business_entry_smart_number($data['volume_points'] ?? null);
        $type = business_entry_smart_trim($data['order_type'] ?? '');
        if ($memberId > 0 && $date !== '' && $vp !== null) {
            $stmt = $pdo->prepare("SELECT id FROM volume_point_entries WHERE organization_id=? AND member_id=? AND entry_date=? AND volume_points=? AND LOWER(COALESCE(order_type,''))=LOWER(?) ORDER BY id DESC LIMIT 6");
            $stmt->execute([$organizationId,$memberId,$date,$vp,$type]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $result['matches'][] = ['id'=>(int)$id,'label'=>'VP #' . (int)$id,'detail'=>$date . ' • ' . $vp . ' VP'];
            }
        }
    } elseif ($module === 'order') {
        $memberId = (int)($data['member_id'] ?? 0);
        $date = business_entry_smart_trim($data['order_date'] ?? '');
        $gross = business_entry_smart_number($data['gross_amount'] ?? null);
        $discount = business_entry_smart_number($data['discount_amount'] ?? 0, 0.0) ?? 0.0;
        $net = business_entry_smart_number($data['net_amount'] ?? null);
        if ($net === null && $gross !== null) $net = $gross - $discount;
        $type = business_entry_smart_trim($data['order_type'] ?? 'regular');
        if ($memberId > 0 && $date !== '' && $net !== null) {
            $stmt = $pdo->prepare("SELECT id FROM orders WHERE organization_id=? AND member_id=? AND order_date=? AND net_amount=? AND LOWER(COALESCE(order_type,''))=LOWER(?) ORDER BY id DESC LIMIT 6");
            $stmt->execute([$organizationId,$memberId,$date,$net,$type]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $result['matches'][] = ['id'=>(int)$id,'label'=>'Order #' . (int)$id,'detail'=>$date . ' • ₹' . number_format($net,2)];
            }
        }
    } elseif ($module === 'renewal') {
        $memberId = (int)($data['member_id'] ?? 0);
        $date = business_entry_smart_trim($data['renewal_date'] ?? '');
        $amount = business_entry_smart_number($data['amount'] ?? 0, 0.0) ?? 0.0;
        $vp = business_entry_smart_number($data['volume_points'] ?? 0, 0.0) ?? 0.0;
        if ($memberId > 0 && $date !== '') {
            $stmt = $pdo->prepare('SELECT id FROM renewals WHERE organization_id=? AND member_id=? AND renewal_date=? AND amount=? AND volume_points=? ORDER BY id DESC LIMIT 6');
            $stmt->execute([$organizationId,$memberId,$date,$amount,$vp]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $result['matches'][] = ['id'=>(int)$id,'label'=>'Renewal #' . (int)$id,'detail'=>$date . ' • ₹' . number_format($amount,2)];
            }
        }
    } elseif ($module === 'income') {
        $date = business_entry_smart_trim($data['income_date'] ?? '');
        $type = business_entry_smart_trim($data['income_type'] ?? '');
        $amount = business_entry_smart_number($data['amount'] ?? null);
        if ($date !== '' && $type !== '' && $amount !== null) {
            $stmt = $pdo->prepare("SELECT id FROM income_entries WHERE organization_id=? AND income_date=? AND amount=? AND LOWER(income_type)=LOWER(?) ORDER BY id DESC LIMIT 6");
            $stmt->execute([$organizationId,$date,$amount,$type]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $result['matches'][] = ['id'=>(int)$id,'label'=>'Income #' . (int)$id,'detail'=>$date . ' • ' . $type . ' • ₹' . number_format($amount,2)];
            }
        }
    } elseif ($module === 'royalty') {
        $date = business_entry_smart_trim($data['royalty_date'] ?? '');
        $label = business_entry_smart_trim($data['period_label'] ?? '');
        if ($label === '' && $date !== '') $label = business_entry_smart_week_label($date);
        $amount = business_entry_smart_number($data['amount'] ?? null);
        $vp = business_entry_smart_number($data['volume_points'] ?? 0, 0.0) ?? 0.0;
        if ($date !== '' && $amount !== null) {
            $stmt = $pdo->prepare("SELECT id FROM royalty_entries WHERE organization_id=? AND royalty_date=? AND amount=? AND volume_points=? AND LOWER(COALESCE(period_label,''))=LOWER(?) ORDER BY id DESC LIMIT 6");
            $stmt->execute([$organizationId,$date,$amount,$vp,$label]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $result['matches'][] = ['id'=>(int)$id,'label'=>'Royalty #' . (int)$id,'detail'=>$date . ' • ₹' . number_format($amount,2)];
            }
        }
    }

    $result['count'] = count($result['matches']);
    $result['duplicate'] = $result['count'] > 0;
    if ($result['duplicate']) {
        $result['message'] = $module === 'new_ums'
            ? 'A similar existing member/UMS identity was found. This is a warning only; no automatic merge will occur.'
            : 'An exact-looking business fact already exists. Review it before saving another copy.';
    }
    return $result;
}
