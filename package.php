<?

/**
 * Copyright 2010-2011 © Mehdi Dogguy <mehdi@debian.org>
 *
 */

require_once("library.php");
db_connect();

list($entry, $packages, $suite, $archs, $compact, $mail, $comaint) =
  sanitize_params("p", "packages", "suite", "archs", "compact", "mail", "comaint");

if ($mail)
  $packages = grep_maintainers($entry, $comaint);
else
  $entry = "";

if (count($packages) > 1) $packages = wb_relevant_packages($packages, $suite);

$title = page_title($packages);
if ($mail) $title = sprintf("Buildd status for packages maintained by %s",
			    htmlentities($entry));
html_header($title, count($packages) > 1);

echo "<div id=\"body\">\n";

if (!empty($packages)) pkg_links($packages, $suite, true, $entry);

if ($mail && empty($packages)) {
  select_suite(array($entry), $suite, $archs, $comaint);
  echo "<br /><i>No packages found for maintainer \"$entry\"!</i>";
} else {
  select_suite($packages, $suite, $archs, $comaint);
}

if (!empty($packages)) buildd_status($packages, $suite, $archs);

echo "</div>";
html_footer();

?>
