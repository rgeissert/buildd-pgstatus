<?

/**
 * Copyright 2010-2012 © Mehdi Dogguy <mehdi@debian.org>
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

list($pkg, $ver, $arch, $suite, $stamp, $raw) =
  sanitize_params("pkg", "ver", "arch", "suite", "stamp", "raw");

function ifecho($text) {
  global $raw;
  if (!$raw) echo $text;
}

function ifescape($text) {
  global $raw;
  if ($raw)
    return $text;
  else
    return htmlspecialchars($text);
}

html_header(sprintf("Build log for %s (%s) on %s", $pkg, $ver, $arch), false, $raw);

ifecho("<div id=\"body\">\n");
$path = logpath($pkg, $ver, $arch, $stamp);
if (!file_exists($path)) {
  header("Status: 404 Not Found");
  echo color_text("log file not found!", true, $raw);
} else {
  $bz = bzopen($path, 'r');
  if (!$bz) {
    echo color_text("log file cannot be opened!", true, $raw);
  } else {

    $links = sprintf("%s → %s → %s → %s\n",
		     sprintf("<a href=\"package.php?p=%s\">%s</a>", urlencode($pkg), $pkg),
		     logs_link($pkg, "", $ver, $ver),
		     logs_link($pkg, $arch, $ver, $arch),
		     fdate($stamp)
		     );
    ifecho($links);

    ifecho("\n<pre>\n");

    $skip = true;
    while (!feof($bz)) {
      $line = fgets($bz, 4096);
      if ($skip && preg_match("/^$/", $line)) $skip = false;
      if (!$skip) echo ifescape($line);
    }

    ifecho("\n</pre>\n");
  }
  bzclose($bz);
}

ifecho("</div>");

html_footer($raw);

?>
