<?php
require_once 'config.php';
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

$status_param = isset($_GET['status']) ? $_GET['status'] : '0';
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 1;

$data = array();

// Adattato per la tabella delle materie (fc_images) e il tracking relativo (user_learnt_fc)
if ($status_param === 'all') {
    $where = "1=1";
} elseif ($status_param === '1') {
    $where = "id IN (SELECT fc_image_id FROM user_learnt_fc WHERE user_id = $user_id)";
} else {
    $where = "id NOT IN (SELECT fc_image_id FROM user_learnt_fc WHERE user_id = $user_id)";
}

// Conta solo i padri e le carte singole, ignorando i "figli"
$res_all = mysqli_query($conn, "SELECT COUNT(*) as total FROM fc_images WHERE $where");
$data['all'] = mysqli_fetch_assoc($res_all)['total'];

$query = "SELECT category_id, COUNT(*) as c FROM fc_images WHERE $where GROUP BY category_id";
$res = mysqli_query($conn, $query);
while($row = mysqli_fetch_assoc($res)) {
    if ($row['category_id'] != null) {
        $data['cat_' . $row['category_id']] = $row['c'];
    }
}

echo json_encode($data);
mysqli_close($conn);
?>