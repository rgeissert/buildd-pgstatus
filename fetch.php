<?

/**
 * Copyright 2010-2011 © Mehdi Dogguy <mehdi@debian.org>
 *
 */

require_once("library.php");
db_connect();

list($pkg, $ver, $arch, $suite, $stamp) =
  sanitize_params("pkg", "ver", "arch", "suite", "stamp");

html_header(sprintf("Build log for %s (%s) on %s", $pkg, $ver, $arch));

echo "<div id=\"body\">\n";
$path = logpath($pkg, $ver, $arch, $stamp);
if (!file_exists($path)) {
  echo color_text("log file not found!", true);
} else {
  $bz = bzopen($path, 'r');
  if (!$bz) {
    echo color_text("log file cannot be opened!", true);
  } else {

    printf("%s → %s → %s → %s\n",
	   sprintf("<a href=\"package.php?p=%s\">%s</a>", urlencode($pkg), $pkg),
	   logs_link($pkg, "", $ver, $ver),
	   logs_link($pkg, $arch, $ver, $arch),
	   fdate($stamp)
	   );

    echo "\n<pre>\n";
    $skip = true;
    while (!feof($bz)) {
      $line = fgets($bz, 4096);
      if ($skip && preg_match("/^$/", $line)) $skip = false;
      if (!$skip) echo htmlspecialchars($line);
    }
    echo "\n</pre>\n";
  }
  bzclose($bz);
}
echo "</div>";

html_footer();

?>
