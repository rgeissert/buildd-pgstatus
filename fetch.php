<?

/**
 * Copyright 2010-2011 © Mehdi Dogguy <mehdi@debian.org>
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
