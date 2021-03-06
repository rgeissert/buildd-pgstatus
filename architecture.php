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

function pkg_name($pkg) {
  return $pkg["name"];
}

list($packages, $suite, $buildd, $notes) =
  sanitize_params("packages", "suite", "buildd", "notes");

$default_suite = "sid";
if ($suite != "all") $default_suite = $suite;

$arch = check_arch(safe_get($_GET, "a"), $default_suite);

html_header("Buildd status of $arch ($suite)");

echo "<div id=\"body\">\n";

echo "<div style=\"text-align: right\">";
select_suite($packages, $default_suite);
echo "</div><br />";

alert_if_neq("architecture", $arch, htmlspecialchars(safe_get($_GET, "a")));

archs_overview_links($suite, $arch);
if ($suite != "all") {
  buildds_overview_link($arch, $suite, $buildd);
  buildds_machine_info($arch, $suite, $buildd);
  notes_overview_link($arch, $suite, $notes);
}

echo "<p>The time indicates for how long a package is in the given state.</p>";

$query =
  "select package, version, state, state_change, section, builder, binary_nmu_version from \""
  .$arch."_public\".packages";
$query_ext = array();
if ($suite != "all") $query_ext[] = "distribution = '$suite'";
if (!empty($buildd)) $query_ext[] = "builder = '$buildd'";
if (!empty($notes)) $query_ext[] = "notes = '$notes'";
if (!empty($query_ext)) $query .= " WHERE " . implode(" AND ", $query_ext);

$query .= " ORDER BY state_change ASC";

$final = array();
$finalp = array();
$counts = array();
$limit = 500;

$results = pg_query($dbconn, $query);

$nocolor_states = array("Installed", "Auto-Not-For-Us", "Not-For-Us");

while ($info = pg_fetch_assoc($results)) {
  $state = $info["state"];
  if (!isset ($counts[$state])) $counts[$state] = 0;
  $counts[$state] += 1;
  if ($counts[$state] < $limit) {
    $text = "";
    if (($counts[$state] - 1) % 10 == 0) $text .= "<span class=\"green\">${counts[$state]}</span>: ";
    list($count, $logs) = pkg_history($info["package"], $info["version"], $arch, $default_suite);
    if ($count >= 1) {
      $timestamp = $logs[0]["timestamp"];
      $lastchange = strtotime($info["state_change"]);
      if (in_array($info["state"], $pendingstate) && $timestamp > $lastchange) {
        if (isset($logs[0]["result"])) {
          $counts[$state]--;
          $state = "Maybe-".ucfirst($logs[0]["result"]);
          if (!isset ($counts[$state])) $counts[$state] = 0;
          $counts[$state]++;
        }
        $info["state_change"] = $logs[0]["date"];
      }
    }
    if (!in_array($state, array("Failed-Removed", "Not-For-Us", "Auto-Not-For-Us"))) {
      $binnmu = "";
      if ($info["binary_nmu_version"] > 0) {
        $binnmu = sprintf("+b%d, ", $info["binary_nmu_version"]);
      }
      list($days, $duration) = date_diff_details($time, strtotime($info["state_change"]));
      if (!in_array($state, $nocolor_states)) {
	  if ($days > 21)
	    $duration = "<span class=\"red\">$duration</span>";
	  elseif ($days > 7)
	    $duration = "<span class=\"orange\">$duration</span>";
      }
      $link = sprintf("<a href=\"package.php?p=%s&amp;suite=%s\">%s</a>", urlencode($info["package"]), $default_suite, htmlentities($info["package"]));
      $text .= sprintf("%s (%s%s", $link, $binnmu, $duration);
      if ($count > 1 && $state != "BD-Uninstallable") $text .= ", <strong>tried $count times</strong>";
      $text .= default_area($info["section"]);
      if (!empty($info["builder"]))
        $text .= ", " . buildd_name($info["builder"]) . ")";
      else
        $text .= ")";
    } else {
      $text .= $info["package"];
    }
    $final[$state][] = $text;
    $finalp[$state][] = $info["package"];
  } else {
    $final[$state] = array();
    $finalp[$state] = array();
  }
}

if (!empty($final)) {
  echo "<table class=\"data\">\n";
  ksort($final);
  foreach($final as $state => $list) {
    $count = safe_get($counts, $state, 0);
    echo "<tr>";
    $link = $state;
    if (count($finalp[$state]) > 0) {
      $packages = array_map("urlencode", $finalp[$state]);
      $packages = implode(",", $packages);
      $link = sprintf("<a href=\"package.php?p=%s&amp;suite=%s\">%s</a>", $packages, $default_suite, htmlentities($state));
    }
    echo "<td valign=\"top\">$link</td>";
    echo "<td valign=\"top\" align=\"center\">$count</td>";
    echo "<td>";
    if ($count < $limit) {
      echo implode(", ", $list);
    } else {
      echo "<i>Too many results, cannot display</i>";
    }
    echo "</td>";
    echo "</tr>";
  }
  echo "</table>\n";
}

echo "</div>";

html_footer();

?>
