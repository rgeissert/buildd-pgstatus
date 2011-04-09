<?

/**
 * Copyright 2010-2011 © Mehdi Dogguy <mehdi@debian.org>
 *
 */

require_once("library.php");
db_connect();

list($pkg, $ver, $arch, $suite, $stamp) =
  sanitize_params("pkg", "ver", "arch", "suite", "stamp");
if (empty($arch))
  $arch = array();
else
  $arch = array($arch);

function next_version($version, $lastver, $found) {
  global $pkg, $ver, $arch;
  if (!$found || $version != $lastver)
    if ($version != $ver)
      return logs_link($pkg, $arch[0], $version, $version);
    else
      return $version;
  else
    return "";
}

function next_arch($archi, $lastarch, $found) {
  global $pkg, $ver, $arch;
  if ($found && $lastarch == $archi) return "";
  if (count($arch) > 0)
    return $archi;
  else
    return logs_link($pkg, $archi, $ver, $archi);
}

html_header(sprintf("Build logs for %s%s%s",
		    ( empty($pkg) ? "nothing" : $pkg ),
		    ( empty($ver) ? "" : "_$ver" ),
		    ( empty($arch) ? "" : " on ".$arch[0] )
		    )
	    );

echo "<div id=\"body\">\n";

echo "<div style=\"float:right\">";
select_logs($package);
echo "</div>";

pkg_links(array($pkg), "sid");

if (empty($pkg)) {
  echo "<h3>Please enter a package name there --></h3>\n";
} else {
  printf("<h3>Build logs for <a href=\"package.php?p=%s\">%s</a>", urlencode($pkg), $pkg);
  if (!empty($ver)) {
    echo "_$ver";
    printf(" <small>[%s]</small>", logs_link($pkg, $arch[0], "", "X"));
  }
  if (!empty($arch)) {
    printf(" on <a href=\"architecture.php?a=%s\">%s</a>", $arch[0], $arch[0]);
    printf(" <small>[%s]</small>", logs_link($pkg, "", $ver, "X"));
  }
  echo "</h3>\n";

  $query = log_query($pkg, $arch, $ver);
  $query_result = pg_query($dbconn, $query);
  $found = false;
  $lastver = "";
  $lastarch = "";
  echo '<table class="data logs"><tr>
        <th>Version</th>
        <th>Result</th>
        <th>Architecture</th>
        <th>Build date</th>
        <th>Build time</th>
        <th>Disk space</th>
    </tr>';
  while($r = pg_fetch_assoc($query_result)) {
    if (count($arch) == 0) {
      if (!$found) $lastver = $r["version"];
      if (!$found) $lastarch = $r["arch"];
      if ($r["version"] != $lastver) echo "<tr><td colspan=\"6\">&nbsp;</td></tr>";
    }

    $result = color_text("Maybe-".ucwords($r["result"]), $r["result"] == "failed");
    $link = build_log_link($pkg, $r["arch"], $r["version"], strtotime($r["timestamp"]),
			   $result);
    $duration = date_diff_details($r["build_time"], 0);
    $disk_space = logsize($r["disk_space"]);

    $version = next_version($r["version"], $lastver, $found);
    $architecture = next_arch($r["arch"], $lastarch, $found);
    printf("<tr>
            <td%s>%s</td>
            <td%s>%s</td>
            <td>%s</td>
            <td>%s</td>
            <td>%s</td>
            <td>%s</td>
           </tr>\n",
	   (empty($version) ? " class=\"empty\" " : ""),
	   $version,
	   (empty($architecture) ? " class=\"empty\" " : ""),
	   $architecture,
	   $link,
	   $r["timestamp"],
	   no_empty_text($duration[1]),
	   no_empty_text($disk_space));

    $found = true;
    $lastver = $r["version"];
    $lastarch = $r["arch"];
  }
  pg_free_result($query_result);
  if (!$found) {
    printf("<tr><td colspan=\"6\"><i>No build logs found for %s (%s) in the database</i></td></tr>",
	   $pkg,
	   $ver);

  }
  echo "</table>";
}

echo "</div>";
html_footer();

?>
