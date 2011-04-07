<?

/**
 * Copyright 2010-2011 © Mehdi Dogguy <mehdi@debian.org>
 *
 */

require_once("library.php");
db_connect();

list($packages, $suite) = sanitize_params("packages", "suite");

html_header();

echo "<div id=\"body\">\n";

echo "<h3>Overview of specific pending items on the various autobuilt architectures</h3>";
archs_overview_links($suite, "", false);

echo "<h3>Information about a specific package/multiple packages</h3>";
select_suite($packages, $suite);

echo "<h3>Build logs for a specific package</h3>";
select_logs($packages[0]);

echo "</div>";
html_footer();

?>
