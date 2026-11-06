<?php
function fw_lab_records(mysqli $conn, int $uid, int $category, string $start, string $end, string $label): array
{
    $rows = fw_query($conn, "SELECT t.transaction_id,t.transaction_date,t.description,t.amount,c.category_name,COALESCE(sl.label,'') AS spending_label,(?='' OR sl.label=?) AS is_matching FROM transactions t JOIN categories c ON c.category_id=t.category_id AND c.user_id=t.user_id LEFT JOIN fw_spending_labels sl ON sl.transaction_id=t.transaction_id AND sl.user_id=t.user_id WHERE t.user_id=? AND t.category_id=? AND t.transaction_type='expense' AND t.transaction_date BETWEEN ? AND ? AND (?='' OR sl.label=? OR COALESCE(sl.label,'')='') ORDER BY t.transaction_date,t.transaction_id LIMIT 100", 'ssiissss', [$label, $label, $uid, $category, $start, $end, $label, $label]);
    foreach ($rows as &$row) {
        $row['is_matching'] = (bool) $row['is_matching'];
        $row['amount'] = (float) $row['amount'];
    }unset($row);
    return $rows;
}
