<?php
require_once __DIR__ . "/config.php";

require_once __DIR__ . "/session.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: " . BASE_URL . "/auth/login.php");
    exit();
}
