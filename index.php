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
