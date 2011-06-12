<?

/**
 * Copyright 2010-2011 © Mehdi Dogguy <mehdi@debian.org>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation; either version 2 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
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
