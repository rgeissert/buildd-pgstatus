<?

require_once("library.php");

function pkg_name($pkg) {
  return $pkg["name"];
}

html_header();

$suite = check_suite($_GET["suite"]);
$package = $_GET["p"];
$arch = $_GET["a"];
if (empty($arch)) $arch="alpha";
$buildd = $_GET["buildd"];
$packages = preg_split('/[ ,]+/', $package);

echo "<div style=\"text-align: right\">";
select_suite($packages, $suite);
echo "</div>";

archs_overview_links($arch);

page_header(array(), "of $arch");

buildds_overview_link($arch, $suite, $buildd);

echo "<p>The time indicates for how long a package is in the given state. A/B
means that on A out of B other architectures where the package had a build
attempt, the build succeeded. the name indicates the build deamon used for
last build.</p>";

$query =
  "select package, version, state, state_change, builder from \""
  .$arch."_public\".packages where distribution like '$suite' ";
if (!empty($buildd)) $query .= " and builder like '$buildd'";

$final = array();
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
      $link = sprintf("<a href=\"package.php?p=%s&suite=%s\">%s</a>", $info["package"], $suite, $info["package"]);
      $text .= sprintf("%s (%s", $link, $duration);
      if ($count > 1 && $state != "BD-Uninstallable") $text .= ", <strong>tried $count times</strong>";
      if (!empty($info["builder"]))
        $text .= ", " . buildd_name($info["builder"]) . ")";
      else
        $text .= ")";
    } else {
      $text .= $info["package"];
    }
    $final[$state][] = $text;
  } else {
    $final[$state] = array(); 
  }
}

echo "<table border=1 cellpadding=1 cellspacing=1>\n";
ksort($final);
foreach($final as $state => $list) {
  $count = $counts[$state];
  echo "<tr>";
  echo "<td>$state</td>";
  echo "<td>$count</td>";
  echo "<td>";
  if ($count < $limit) {
    echo implode(" ", $list);
  } else {
    echo "<i>Too many results, cannot display</i>";
  }
  echo "</td>";
  echo "</tr>";
}
echo "</table>\n";

html_footer();

?>