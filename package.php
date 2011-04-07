<?

/**
 * Copyright 2010-2011 © Mehdi Dogguy <mehdi@debian.org>
 *
 */

require_once("library.php");
db_connect();

list($entry, $packages, $suite, $archs, $compact, $mail, $comaint) =
  sanitize_params("p", "packages", "suite", "archs", "compact", "mail", "comaint");

if ($mail) $packages = grep_maintainers($entry, $comaint);

html_header(page_title($packages), count($packages) > 1);

echo "<div id=\"body\">\n";

if (!empty($packages)) pkg_links($packages, $suite);
select_suite($packages, $suite, $archs);
if (!empty($packages)) buildd_status($packages, $suite, $archs);

echo "</div>";
html_footer();

?>
