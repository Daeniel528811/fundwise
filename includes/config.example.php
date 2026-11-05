<?php
// Copy to includes/config.php and replace placeholders locally.
// Never commit config.php. No environment-variable loader is used by this app.
define('BASE_URL', ''); // Root htdocs deployment; use '/fundwise' for a subdirectory.
define('DB_HOST', 'YOUR_MYSQL_HOST');
define('DB_USER', 'YOUR_MYSQL_USERNAME');
define('DB_PASS', 'YOUR_MYSQL_PASSWORD');
define('DB_NAME', 'fundwise_db'); // Use the actual database name provided by your host.
