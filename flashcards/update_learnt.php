<?php
require_once 'config.php';
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$status = isset($_POST['status']) ? intval($_POST['status']) : 0;
$user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 1;
$type = isset($_POST['type']) ? $_POST['type'] : 'en';

if ($id > 0) {
    if ($type === 'en') {
        if ($status == 1) {
            mysqli_query($conn, "INSERT IGNORE INTO user_learnt_english (user_id, image_id, learnt_date) VALUES ($user_id, $id, CURDATE())");
        } else {
            mysqli_query($conn, "DELETE FROM user_learnt_english WHERE user_id = $user_id AND image_id = $id");
        }
    } else {
        if ($status == 1) {
            mysqli_query($conn, "INSERT IGNORE INTO user_learnt_fc (user_id, fc_image_id, learnt_date) VALUES ($user_id, $id, CURDATE())");
        } else {
            mysqli_query($conn, "DELETE FROM user_learnt_fc WHERE user_id = $user_id AND fc_image_id = $id");
        }
    }
}
mysqli_close($conn);
?>