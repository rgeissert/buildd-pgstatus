<?

/**
 * Copyright 2010-2011 © Mehdi Dogguy <mehdi@debian.org>
 *
 */

require_once("library.php");
db_connect();

list($packages, $suite, $archs, $compact) =
  sanitize_params("packages", "suite", "archs", "compact");

html_header(page_title($packages), count($packages) > 1);

echo "<div id=\"body\">\n";

if (!empty($packages)) pkg_links($packages, $suite);
select_suite($packages, $suite, $archs);
if (!empty($packages)) buildd_status($packages, $suite, $archs);

echo "</div>";
html_footer();

?>
