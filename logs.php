<?

require_once("library.php");

html_header(false);

$pkg = preg_replace ('/[^-a-z0-9\+\., ]/', '', $_GET["pkg"]);
$suite = check_suite($_GET["suite"]);
$arch  = $_GET["arch"];
$ver   = $_GET["ver"];
$stamp = $_GET["stamp"];
if (!valid_arch($arch)) { $arch = array(); } else { $arch = array($arch); }
if (!preg_match('/^[[:alnum:].+-:~]+$/', $ver)) $ver = "";
if (!preg_match('/^[0-9]+$/', $stamp)) $stamp = "";

function logslink($pkg, $ver, $arch, $text) {
  return sprintf("<a href=\"logs.php?pkg=%s&ver=%s&arch=%s\">%s</a>",
		 urlencode($pkg),
		 urlencode($ver),
		 urlencode($arch),
		 $text
		 );
}

echo "<h1 id=\"title\">Debian Package Auto-Building</h1>\n";
printf("<h2 id=\"subtitle\">Buildd logs for %s</h2>\n", $pkg);

echo "<div id=\"body\">\n";
printf("<h3>Buildd logs for <a href=\"package.php?p=%s\">%s</a>", $pkg, $pkg);
if (!empty($ver)) {
  echo "_$ver";
  printf(" <small>[%s]</small>", logslink($pkg, "", $arch[0], "X"));
 }
if (!empty($arch)) {
  printf(" on <a href=\"architecture.php?a=%s\">%s</a>", $arch[0], $arch[0]);
  printf(" <small>[%s]</small>", logslink($pkg, $ver, "", "X"));
 }
echo "</h3>\n";

$query = log_query($pkg, $arch, $ver);
$query_result = pg_query($dbconn, $query);
$found = false;
$lastver = "";
echo '<table class=data><tr>
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
    if ($r["version"] != $lastver) echo "<tr><td colspan=\"6\">&nbsp;</td></tr>";
  }

  $result = color_text("Maybe-".ucwords($r["result"]), $r["result"] == "failed");
  $link = build_log_link($pkg, $r["arch"], $r["version"], strtotime($r["timestamp"]),
			 $result);
  $duration = date_diff_details($r["build_time"], 0);
  $disk_space = logsize($r["disk_space"]);

  printf("<tr>
            <td>%s</td>
            <td>%s</td>
            <td>%s</td>
            <td>%s</td>
            <td>%s</td>
            <td>%s</td>
         </tr>\n",
	 (!$found || $r["version"] != $lastver ?
            logslink($pkg, $r["version"], $arch[0], $r["version"])
	  :
	    "—"
	 ),
	 $link,
	 (count($arch) > 0 ? $r["arch"] : logslink($pkg, $ver, $r["arch"], $r["arch"])),
	 $r["timestamp"],
	 no_empty_text($duration[1]),
	 no_empty_text($disk_space));

  $found = true;
  $lastver = $r["version"];
}
pg_free_result($query_result);
if (!$found) {
  printf("<tr><td colspan=\"6\"><i>No build logs found for %s (%s) in the database</i></td></tr>",
	 $pkg,
	 $ver);

}
echo "</table>";

echo "</div>";
html_footer();

?>
