<?

require_once("library.php");

function pkg_name($pkg) {
  return $pkg["name"];
}

html_header();

$suite = check_suite($_GET["suite"]);
$package = preg_replace ('/[^-a-z0-9\+\., ]/', '', $_GET["p"]);
$arch = check_arch($_GET["a"]);
$buildd = pg_escape_string($dbconn, $_GET["buildd"]);
$notes = pg_escape_string($dbconn, $_GET["notes"]);
if (ereg('[^a-z0-9_-]', $buildd)) $buildd="";
$packages = preg_split('/[ ,]+/', $package);

page_header(array(), "of $arch ($suite)");

echo "<div id=\"body\">\n";

echo "<div style=\"text-align: right\">";
select_suite($packages, $suite);
echo "</div><br />";

alert_if_neq("suite", $suite, $_GET["suite"]);
alert_if_neq("architecture", $arch, $_GET["a"]);

archs_overview_links($suite, $arch);

buildds_overview_link($arch, $suite, $buildd);

echo "<p>The time indicates for how long a package is in the given state.</p>";

$query =
  "select package, version, state, state_change, section, builder from \""
  .$arch."_public\".packages where distribution like '$suite'";
if (!empty($buildd)) $query .= " and builder like '$buildd'";
if (!empty($notes)) $notes .= " and notes like '$notes'";

$query .= " order by state_change asc";

$final = array();
$finalp = array();
$counts = array();
$limit = 500;

$results = pg_query($dbconn, $query);

while ($info = pg_fetch_assoc($results)) {
  $state = $info["state"];
  if (!isset ($counts[$state])) $counts[$state] = 0;
  $counts[$state] += 1;
  if ($counts[$state] < $limit) {
    $text = "";
    if (($counts[$state] - 1) % 10 == 0) $text .= "<font color=green>${counts[$state]}</font>: ";
    list($count, $logs) = pkg_history($text, $info["version"], $arch, $suite);
    if ($count >= 1) {
      $timestamp = $logs[0]["timestamp"];
      $lastchange = strtotime($info["state_change"]);
      if (in_array($info["state"], $pendingstate) && $timestamp > $lastchange) {
        if (isset($logs[0]["result"])) $state = "Maybe-".ucfirst($logs[0]["result"]);
        $info["state_change"] = $logs[0]["date"];
      }
    }
    if (!in_array($state, array("Failed-Removed", "Not-For-Us"))) {
      list($days, $duration) = date_diff_details(strtotime($info["state_change"]));
      if ($days > 21)
        $duration = "<font color=red>$duration</font>";
      elseif ($days > 7)
        $duration = "<font color=orange>$duration</font>";
      $link = sprintf("<a href=\"package.php?p=%s&suite=%s\">%s</a>", urlencode($info["package"]), $suite, htmlentities($info["package"]));
      $text .= sprintf("%s (%s", $link, $duration);
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

echo "<table class=\"data\">\n";
ksort($final);
foreach($final as $state => $list) {
  $count = $counts[$state];
  echo "<tr>";
  $link = $state;
  if (count($finalp[$state]) > 0) {
    $packages = array_map("urlencode", $finalp[$state]);
    $packages = implode(",", $packages);
    $link = sprintf("<a href=\"package.php?p=%s&suite=%s\">%s</a>", $packages, $suite, htmlentities($state));
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

echo "</div>";

html_footer();

?>
