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

define("BUILDD_DIR", "/srv/buildd.debian.org");
define("DEBIAN", "Debian");
define("BUILDD_HOST", "buildd.debian.org");
define("BUILDD_TEXT", "buildd.d.o");
define("ALT_BUILDD_HOST", "buildd.debian-ports.org");
define("ALT_BUILDD_TEXT", "b.d-ports.o");

$ARCHS = array("amd64"); // Will be fixed later (when pg connection is established)
$SUITES = array("sid"); // Will be fixed later (when pg connection is established)
$ALIASES = array();

$statehelp = array(
 "BD-Uninstallable" => "Package should be built, but its build dependencies cannot be fulfilled",
 "Build-Attempted"  => "A build was attempted, but it failed",
 "Building"         => "Package is assigned to a buildd which should build it shortly/is building it",
 "Maybe-Failed"     => "A build was attempted, but it failed",
 "Maybe-Successful" => "Package looks like it built successfully and is pending confirmation and upload",
 "Built"            => "Package looks like it built successfully and is pending confirmation and upload",
 "Dep-Wait"         => "Package cannot be built (yet) because build-dependencies cannot be satisfied",
 "Failed"           => "Package failed to build; the buildd admin has confirmed the failure is not transitional",
 "Installed"        => "Package is up-to-date in the archive",
 "Needs-Build"      => "Package is queued for building, waiting for a buildd to become available",
 "Uploaded"         => "Package was built successfully and uploaded, but didn't appear yet in the archive",
 "Not-For-Us"       => "Package is marked as not-to-be-built on this architecture",
 );

$compactstate = array(
 "BD-Uninstallable" => "∉",
 "Build-Attempted"  => "∿",
 "Building"         => "⚒",
 "Maybe-Failed"     => "(✘)",
 "Maybe-Successful" => "(✔)",
 "Built"            => "☺",
 "Failed"           => "✘",
 "Dep-Wait"         => "⌚",
 "Installed"        => "✔",
 "Needs-Build"      => "⌂",
 "Uploaded"         => "♐",
 "Not-For-Us"       => "⎇",
 );

$compactarch = array(
 "hurd-i386" => "hurd",
 "kfreebsd-amd64" => "kbsd64",
 "kfreebsd-i386" => "kbsd32",
 "powerpc" => "ppc"
 );

$valid_archs = array(); // Will be filled in later.

$goodstate = array("Maybe-Successful", "Built", "Installed", "Uploaded");
$badstate = array("Failed", "Maybe-Failed", "Build-Attempted");
$okstate = array("Built", "Installed", "Uploaded");
$donestate = array("Installed", "Uploaded");
$pendingstate = array("Building", "Dep-Wait", "Needs-Build");
$skipstates = array("overwritten-by-arch-all", "arch-all-only");
$passtates = array("absent", "packages-arch-specific");

$dbconn = FALSE;
$compact = FALSE;
$time = time("now");

$idcounter = 0; // Counter to generate unique id attributes

function db_connect() {
  global $dbconn, $ARCHS, $SUITES, $ALIASES, $valid_archs;
  $dbconn = pg_pconnect("service=wanna-build") or status_fail();

  $result = pg_query($dbconn, "select architecture from architectures order by architecture asc");
  $ARCHS = pg_fetch_all_columns($result, 0);
  pg_free_result($result);

  $result = pg_query($dbconn, "select * from distributions where public order by sort_order DESC");
  $SUITES = pg_fetch_all_columns($result, 0);
  pg_free_result($result);

  $result = pg_query($dbconn, "select * from distribution_aliases");
  while($row = pg_fetch_row($result))
    $ALIASES[$row[0]] = $row[1];
  pg_free_result($result);

  $result = pg_query($dbconn, "select distribution, architecture from distribution_architectures");
  while ($row = pg_fetch_assoc($result)) {
    $valid_archs[$row["distribution"]][] = $row["architecture"];
  }
  pg_free_result($result);
}

function db_disconnect() {
  global $dbconn;
  pg_close($dbconn);
}

function string_query($package, $suite, $fields="", $extra="") {
  global $dbconn;
  $package = pg_escape_string($dbconn, $package);
  if (empty($fields)) {
    $fields = "architecture,
               package,
               distribution,
               version::character varying,
               state,
               section,
               priority,
               installed_version,
               previous_state,
               state_change,
               notes,
               builder,
               failed,
               old_failed,
               binary_nmu_version,
               binary_nmu_changelog,
               failed_category,
               permbuildpri,
               buildpri,
               depends,
               rel,
               bd_problem,
               extra_depends,
               extra_conflicts,
               build_arch_all";
  }
  $format = "SELECT %s
             FROM packages_public
             WHERE distribution = '%s'
               AND package = '%s'
             ORDER BY architecture ASC
             %s";
  return sprintf($format, $fields, $suite, $package, $extra);
}

function log_query_arch($pkg, $arch, $ver="") {
  return sprintf("SELECT '%s'::character varying AS arch, CAST (version AS debversion) AS debversion, *
                  FROM \"%s_public\".pkg_history
                  WHERE package LIKE '%s'
                  %s",
		 $arch, $arch, $pkg, $ver);
}

function log_query($pkg, $archs, $ver) {
  global $ARCHS;
  if (empty($archs)) $archs = $ARCHS;
  if (!empty($ver)) $ver = sprintf(" AND version LIKE '%s'", $ver);
  $query = log_query_arch($pkg, array_shift($archs), $ver);
  foreach($archs as $arch) {
    $query .= sprintf(" UNION %s", log_query_arch($pkg, $arch, $ver));
  }
  return sprintf("%s ORDER BY debversion DESC, arch ASC, timestamp DESC", $query);
}

function ignored_arch($arch, $suite) {
  global $valid_archs;
  if (!empty($suite) && isset($valid_archs[$suite]))
    return !in_array($arch, array_values($valid_archs[$suite]));
  else
    return false;
}

function print_legend() {
  global $compact , $compactstate;
  if ($compact) {
    echo "<table class=\"data\">\n";
    echo "<tr><th>Symbol</th><th>State</th></tr>\n";
    foreach ($compactstate as $state => $symbol) {
      echo "<tr><td class=\"compact\">$symbol</td><td class=\"".pkg_state_class($state)."\">$state</td></tr>";
    }
    echo "</table>\n";
  }
}

function check_suite($suite, $default="sid") {
  global $SUITES, $ALIASES;
  if (in_array($suite, $SUITES)) {
    return $suite;
  } else if (in_array($suite, array_keys($ALIASES))) {
    return $ALIASES[$suite];
  } else {
    return $default;
  }
}

function valid_arch($arch) {
  global $ARCHS;
  return in_array($arch, $ARCHS);
}

function check_arch($arch, $suite="") {
  global $ARCHS, $valid_archs;
  if (valid_arch($arch)) {
    return $arch;
  } elseif (!empty($suite) && array_key_exists($suite, $valid_archs) && !empty($valid_archs[$suite])) {
    sort($valid_archs[$suite]);
    return $valid_archs[$suite][0];
  } else {
    return $ARCHS[0];
  }
}

function good_arch($arch) {
  return ($arch == check_arch($arch));
}

function sanitize_pkgname($package) {
  $package = preg_replace("/([^_\/]+)([_\/].*)?/", "$1", $package);
  return preg_replace ('/[^-[[:alnum:]%@\+\.,]/', '', $package);
}

function safe_get($array, $key, $default="") {
  if (array_key_exists($key, $array))
    return $array[$key];
  else
    return $default;
}

function safe_char($string, $pos, $default='') {
  if (empty($string) || strlen($string) < $pos) {
    return $default;
  } else {
    return substr($string, $pos, $pos+1);
  }
}

function sanitize_params() {
  global $dbconn, $ARCHS;
  $result = array();
  foreach(func_get_args() as $key => $param) {
    switch($param) {
    case "p":
    case "pkg":
    case "maint":
    case "package":
      array_push($result, sanitize_pkgname(safe_get($_GET, $param)));
      break;
    case "packages":
      $packages = array();
      if (array_key_exists("p", $_GET) && !empty($_GET["p"]))
        $packages = preg_split('/[ ,]+/', $_GET["p"]);
      else
        $packages = preg_split('/[ ,]+/', safe_get($_GET, "pkg"));
      foreach($packages as $key => $package) {
        $packages[$key] = sanitize_pkgname($package);
	if (empty($package)) unset($packages[$key]);
      }
      array_push($result, $packages);
      break;
    case "a":
      array_push($result, check_arch(safe_get($_GET, "a")));
      break;
    case "arch":
      $tmpa = safe_get($_GET, "arch");
      if (!valid_arch($tmpa))
	array_push($result, "");
      else
	array_push($result, $tmpa);
      break;
    case "archs":
      $tmpas = safe_get($_GET, "a");
      if (empty($tmpas))
	array_push($result, $ARCHS);
      else
	array_push($result, check_archs($tmpas));
      break;
    case "ver":
      $tmpv = safe_get($_GET, "ver");
      if (!preg_match('/^[[:alnum:].+-:~]+$/', $tmpv))
	array_push($result, "");
      else
	array_push($result, $tmpv);
      break;
    case "stamp":
      $tmpst = safe_get($_GET, "stamp");
      if (!preg_match('/^[[:digit:]]+$/', $tmpst))
	array_push($result, "");
      else
	array_push($result, $tmpst);
      break;
    case "suite":
      $key = "suite";
      if (!array_key_exists($key, $_GET) || empty($_GET[$key])) $key = "dist";
      if (array_key_exists($key, $_GET)) array_push($result, check_suite($_GET[$key]));
      if (!array_key_exists($key, $_GET)) array_push($result, check_suite(""));
      break;
    case "compact":
      array_push($result, array_key_exists("compact", $_GET) && !empty($_GET["compact"]));
      break;
    case "buildd":
    case "notes":
      $temp = pg_escape_string($dbconn, safe_get($_GET, $param));
      if ($param == "buildd" && preg_match('/[^[[:alnum:]_-]/', $temp)) $temp = "";
      array_push($result, $temp);
      break;
    case "mail":
      array_push($result, preg_match('/@/', safe_get($_GET, "p")) || preg_match('/@/', safe_get($_GET, "maint")));
      break;
    case "comaint":
      $tmp = safe_get($_GET, $param);
      switch ($tmp) {
      case "yes":
      case "no":
      case "only":
	array_push($result, $tmp);
	break;
      default:
	array_push($result, "no");
	break;
      }
      break;
    case "raw":
      array_push($result, array_key_exists("raw", $_GET));
      break;
    }
  }
  return $result;
}

function remove_values($values, $array) {
  if (!is_array($array) || empty($array)) return $array;
  foreach ($array as $key => $value) {
    if (!in_array($value, $values)) unset($array[$key]);
  }
  return $array;
}

function check_archs($archs) {
  if (!is_array($archs)) $archs = explode(",", $archs);
  $archs = array_filter($archs, "good_arch");
  return array_unique($archs);
}

function filter_archs($myarchs, $suite) {
  global $valid_archs, $ARCHS;
  $myarchs = check_archs($myarchs);
  if (count($myarchs) == 0 || $myarchs[0] == "") $myarchs = $ARCHS;
  if (!empty($suite) && isset($valid_archs[$suite]))
    $myarchs = remove_values($valid_archs[$suite], $myarchs);
  return array_unique($myarchs);
}

function arch_name($arch) {
  global $compact , $compactarch;
  if ($compact && in_array($arch, array_keys($compactarch)))
    return $compactarch[$arch];
  else return $arch;
}

function select_logs($package="") {
  echo "<form action=\"logs.php\" method=\"get\">\n<p>\n";
  echo "Package: <input id=\"log_field\" type=\"text\" name=\"pkg\" value=\"$package\" />";
  printf("<input type=\"submit\" value=\"Go\" />\n");
  echo "</p>\n</form>\n";
}

function array_eq($array1, $array2) {
  $a12 = array_diff($array1, $array2);
  $a21 = array_diff($array2, $array1);
  return (empty($a12) && empty($a21));
}

function select_suite($packages, $selected_suite, $archs="", $comaint="no") {
  global $compact, $SUITES, $ARCHS;
  $package = implode(",", $packages);
  $archs = implode(",", check_archs($archs));
  $selected_suite = check_suite($selected_suite);
  printf("<form action=\"package.php\" method=\"get\">\n<p>\nPackage(s): <input id=\"pkg_field\" type=\"text\" name=\"p\" value=\"%s\" /> Suite: ",
         $package
         );
  printf("<select name=\"suite\" id=\"suite\">\n");
  foreach($SUITES as $suite) {
    $selected = "";
    if ($suite == $selected_suite) $selected = ' selected="selected"';
    printf("\t<option value=\"%s\"%s>%s</option>\n", $suite, $selected, $suite);
  }
  printf("</select>\n");
  if (!empty($archs) && !array_eq($ARCHS, preg_split('/[ ,]+/', $archs)))
    printf("<input type=\"hidden\" name=\"a\" value=\"%s\" />\n", $archs);
  printf("<input type=\"submit\" value=\"Go\" />\n");
  echo "<br />\n<span class=\"buttons tiny\">\n";
  printf("<input type=\"checkbox\" name=\"compact\" value=\"compact\" %s />Compact mode\n",
         $compact ? "checked=\"checked\"" : ""
         );
  printf("<input type=\"checkbox\" name=\"comaint\" value=\"yes\" %s />Co-maintainers\n",
         $comaint != "no" ? "checked=\"checked\"" : ""
         );
  echo "</span>\n";
  printf("</p>\n</form>\n");
}

function date_diff_details($time, $lastchange) {
  if (empty($lastchange)) return array(0, "");
  $diff = abs($time - $lastchange);
  $days = floor($diff / (3600 * 24));
  $rest = $diff - ($days * 3600 * 24);
  $hours = floor($rest / 3600);
  $mins = floor(($rest % 3600) / 60);
  $secs = ($rest % 3600) % 60;
  $date = array();
  if ($days > 0) array_push($date, "${days}d");
  if ($hours > 0) array_push($date, "${hours}h");
  if ($mins > 0) array_push($date, "${mins}m");
  if (empty($date)) array_push($date, "${secs}s");
  $date = implode(" ", $date);
  return array($days, $date);
}

function date_diff_short($lastchange) {
  global $time;
  $result = date_diff_details($time, $lastchange);
  return $result[1];
}

function fdate($secs) {
  return strftime("%Y-%m-%d %H:%M:%S", $secs);
}

function pkg_history($pkg, $ver, $arch, $suite) {
  global $dbconn;
  $package = pg_escape_string($dbconn, $pkg);
  $format = "select * from \"%s_public\".pkg_history
      where package like '%s' and distribution like '%s' and version like '%s'
      order by timestamp desc";
  $query = sprintf($format, $arch, $package, $suite, $ver);
  $result = pg_query($dbconn, $query);
  $results = array();
  while ($history = pg_fetch_assoc($result)) {
    $results[] =
      array("timestamp" => strtotime($history["timestamp"]),
            "date" => $history["timestamp"],
            "result" => $history["result"]
            );
  }
  pg_free_result($result);
  return array(count($results), $results);
}

function buildd_list($arch, $suite) {
  global $dbconn;
  $format = "select username from \"%s_public\".users
      where distribution like '%s'
      order by username asc";
  $query = sprintf($format, $arch, $suite);
  $result = pg_query($dbconn, $query);
  return pg_fetch_all($result);
}

function color_text($text, $failed, $raw=false) {
  if ($raw) return $text;
  if ($failed)
    return "<span class=\"red\">$text</span>";
  else
    return "<span class=\"green\">$text</span>";
}

function no_empty_text($text, $suffix="") {
  if (empty($text))
    return "—";
  else
    return $text.$suffix;
}

function logsize($size) {
  if (empty($size)) return $size;
  $sep = ' ';
  $unit = null;
  $units = array('B', 'KB', 'MB', 'GB', 'TB');
  for($i = 0, $c = count($units); $i < $c; $i++) {
    if ($size > 1024) {
      $size = $size / 1024;
    } else {
      $unit = $units[$i];
      break;
    }
  }
  return round($size, 2).$sep.$unit;
}

function logs_link($pkg, $arch, $ver="", $text="old") {
  if (!empty($ver)) $ver = sprintf("&amp;ver=%s", urlencode($ver));
  if (!empty($arch)) $arch = sprintf("&amp;arch=%s", urlencode($arch));
  return sprintf("<a href=\"logs.php?pkg=%s%s%s\">%s</a>",
		 urlencode($pkg),
		 $ver,
		 $arch,
		 $text
		 );
}

function loglink($package, $version, $arch, $timestamp, $count, $failed) {
  global $pendingstate;
  $log = "";
  $old = logs_link($package, $arch);
  $all = sprintf("<a href=\"logs.php?pkg=%s&amp;arch=%s&amp;ver=%s\">all (%d)</a>",
                 urlencode($package),
                 urlencode($arch),
                 urlencode($version),
                 htmlentities($count)
                 );
  if (empty($timestamp) || $count == 0)
    $log = "no log";
  else {
    $text = "last log";
    if ($failed) $text = "<span class=\"red\">$text</span>";
    $log = build_log_link($package, $arch, $version, $timestamp, $text);
  }
  return sprintf("%s | %s | %s", $old, $all, $log);
}

function build_log_link($package, $arch, $version, $timestamp, $text) {
    return sprintf("<a href=\"fetch.php?pkg=%s&amp;arch=%s&amp;ver=%s&amp;stamp=%s\">%s</a>",
                   urlencode($package),
                   urlencode($arch),
                   urlencode($version),
                   urlencode($timestamp),
                   $text);
}

function logpath($pkg, $ver, $arch, $stamp) {
  return sprintf("%s/db/%s/%s/%s/%s_%s_log.bz2",
		 BUILDD_DIR,
		 safe_char($pkg, 0),
		 $pkg,
		 $ver,
		 $arch,
		 $stamp);
}

function paspath($suite) {
  return sprintf("%s/etc/packages-arch-specific/checkout/%s/Packages-arch-specific",
                 BUILDD_DIR,
                 $suite);
}

function paslink($suite) {
  return sprintf("<a href=\"https://%s/quinn-diff/%s/Packages-arch-specific\">Packages-arch-specific</a>",
                 BUILDD_HOST,
                 $suite);
}

function tailoflog($pkg, $ver, $arch, $stamp) {
  return shell_exec(sprintf("bzegrep -B17 \"^Build finished at\" %s | head -n15",
			    escapeshellarg(logpath($pkg, $ver, $arch, $stamp))));
}

function pkg_state_class($state) {
  global $compact;
  $state = strtolower(implode("", explode("-", $state)));
  $class = ($compact ? "compact " : "");
  if (!empty($state))
    return $class . "status-$state";
  else
    return $class;
}

function is_buildd($name) {
  return preg_match("@buildd_.*-.*@", $name);
}

function buildd_name($name) {
  return preg_replace('/buildd_(kfreebsd-|hurd-)?[^-]*-/', '', $name);
}

function buildd_realname($name, $arch) {
  $name = preg_replace('/\..*/', '', $name);
  return sprintf("buildd_%s-%s", $arch, $name);
}

function pkg_buildd($buildd, $suite, $arch) {
  if ($buildd == "none") return $buildd;
  $name = buildd_name($buildd);
  return sprintf("<a href=\"architecture.php?a=%s&amp;suite=%s&amp;buildd=%s\">%s</a>",
                 urlencode($arch),
                 urlencode($suite),
                 urlencode($buildd),
                 htmlentities($name));
}

function pkg_state($status, $state) {
  global $goodstate;
  if (in_array($status, $goodstate))
    return "";
  else
    return $state;
}

function pkg_status($status) {
  global $compact , $compactstate;
  if ($compact == FALSE)
    return $status;
  else {
    $status = preg_replace("/ .*$/", "", $status);
    return $compactstate[$status];
  }
}

function section_area($section) {
  if (preg_match("/^non-free\/.*$/", $section)) return "non-free";
  else if (preg_match("/^contrib\/.*$/", $section)) return "contrib";
  else return "main";
}

function pkg_area($package) {
  global $dbconn;
  $query = string_query($package, "unstable", "section", "LIMIT 1");
  $result = pg_query($dbconn, $query);
  $section = @pg_fetch_result($result, 0, 0);
  pg_free_result($result);
  return section_area($section);
}

function default_area($section) {
  $area = section_area($section);
  if (empty($area) || $area == "main") return "";
  else return ", <i>$area</i>";
}

function pkg_version($version, $binnmu) {
  if (!empty($binnmu))
    return sprintf("%s+b%s", $version, $binnmu);
  else
    return $version;
}

function strip_suite ($suite) {
  $pos = strpos($suite, "-");
  if ($pos === false)
    return $suite;
  else
    return substr($suite, 0, $pos);
}

function grep_file($maintainer, $file) {
  $packages = array();
  if (is_readable($file)) {
    $f = fopen($file, 'r');
    while(!feof($f)) {
      $line = fgets($f, 4096);
      preg_match("/^(?P<package>[^[:space:]]+).*<(?P<mail>.*)>$/", $line, $r);
      if (array_key_exists("mail", $r) && $r["mail"] == $maintainer)
        array_push($packages, $r["package"]);
    }
    fclose($f);
  }
  return $packages;
}

function grep_maintainers($mail, $comaint) {
  $packages = array();
  if ($comaint == "yes" || $comaint == "no")
    $packages = array_merge($packages, grep_file($mail, sprintf("%s/etc/Maintainers", BUILDD_DIR)));
  if ($comaint == "yes" || $comaint == "only")
    $packages = array_merge($packages, grep_file($mail, sprintf("%s/etc/Uploaders", BUILDD_DIR)));
  sort($packages);
  return array_unique($packages);
}

function pkg_links($packages, $suite, $p=true, $mail="") {
  $suite = strip_suite($suite);
  $links = array();
  if ($p) echo "<p>";
  if (count($packages) == 1) {
    $package = $packages[0];
    if (empty($package)) return;
    preg_match("/^(?P<all>(?P<prefix>(?:(?:lib)?[[:alnum:]])).*)$/", $package, $pkg);
    $links =
      array(
            sprintf("<a href=\"http://packages.qa.debian.org/%s\">PTS</a>", urlencode($package)),
            sprintf("<a href=\"http://packages.debian.org/changelogs/pool/%s/%s/%s/current/changelog\">Changelog</a>",
                    pkg_area($package), urlencode($pkg["prefix"]), urlencode($pkg["all"])),
            sprintf("<a href=\"http://bugs.debian.org/src:%s\">Bugs</a>", urlencode($package)),
            sprintf("<a href=\"http://packages.debian.org/source/%s/%s\">packages.d.o</a>",
                    urlencode($suite), urlencode($package)),
	    sprintf("<a href=\"http://%s/status/package.php?p=%s&amp;suite=%s\">%s</a>",
		    ALT_BUILDD_HOST,
		    urlencode($package),
		    urlencode($suite),
		    ALT_BUILDD_TEXT
		    )
            );
  } else {
    $packages = array_map("urlencode", $packages);
    $srcs = implode(";src=", $packages);
    if (!empty($mail))
      array_push($links,
		 sprintf("<a href=\"http://qa.debian.org/developer.php?login=%s\">DDPO</a> (%s)",
			 urlencode($mail),
			 htmlentities($mail)
			 )
		 );
    array_push($links,
	       sprintf("<a href=\"http://bugs.debian.org/cgi-bin/pkgreport.cgi?src=%s;dist=%s\">Bugs</a>",
		       $srcs,
		       urlencode($suite))
	       );
  }
  echo implode(" &ndash; ", $links);
  if ($p) echo "</p>\n";
}

function pkg_state_help($state, $notes) {
  $notes = pkg_state($state, $notes);
  if (!empty($notes)) $state .= " ($notes)";
  return $state;
}

function some_link($arch, $suite, $text, $sep) {
  $bsep = "";
  $esep = "";
  if ($sep) {
    $bsep = "[";
    $esep = "]";
  }
  return sprintf(" <a href=\"architecture.php?a=%s&amp;suite=%s\">%s%s%s</a> ",
                 urlencode($arch), $suite, $bsep, htmlentities($text), $esep);
}

function suite_link($arch, $suite, $sep=false) {
  return some_link($arch, $suite, $suite, $sep);
}

function arch_link($arch, $suite, $sep=false) {
  return some_link($arch, $suite, arch_name($arch), $sep);
}

function single($info, $version, $log, $arch, $suite, $problemid) {
  global $statehelp;
  $state = $info["state"];
  if ($state == "Dep-Wait" && !empty($info["depends"]))
    $state .= " (" . $info["depends"] . ")";
  if (is_array($info)) {
    $misc = sprintf("%s:%s", $info["section"], $info["priority"]);
    printf("<tr><td>%s</td><td>%s</td><td %s class=\"status %s\" title=\"%s\">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>\n",
           arch_link($info["architecture"], $suite),
           $version,
           ($problemid ? "id=\"status-" . $problemid. "\"" : ""),
           pkg_state_class($info["state"]),
           $statehelp[$info["state"]],
           pkg_status($state),
           date_diff_short($info["timestamp"]),
           pkg_buildd($info["builder"], $suite, $arch),
           pkg_state($info["state"], $info["notes"]),
	   ($misc == ":" ? "—" : $misc),
           $log
           );
  } else {
    echo "<tr><td colspan=\"8\"><i>";
    switch ($info) {
    case "overwritten-by-arch-all":
      echo "This package has been overwritten and became architecture \"all\"";
      break;
    case "arch-all-only":
      echo "This is an architecture \"all\" package. Not present in wanna-build";
      break;
    case "arch-not-in-arch-list":
      printf("%s is not present in the architecture list set by the maintainer",
             $arch);
      break;
    case "packages-arch-specific":
      printf("Present in %s database, but marked as Auto-Not-For-Us (%s)",
             urlencode($arch),
             paslink($suite));
      break;
    case "failed-removed":
      printf("Package does not need to build on this architecture");
      break;
    case "absent":
    default:
      printf("No entry in %s database, check %s",
             urlencode($arch),
             paslink($suite));
      break;
    }
    echo "</i></td></tr>\n";
  }
}

function pkg_status_symbol($good) {
  if ($good)
    return "✔";
  else
    return "✘";
}

function multi($info, $version, $log, $arch, $suite, $problemid) {
  global $compact;
  if (is_array($info)) {
    printf("<td %s class=\"status %s\" title=\"%s%s\">%s</td>",
           ($problemid ? "id=\"status-" . $problemid. "\"" : ""),
           pkg_state_class($info["state"]),
           pkg_state_help($info["state"], $info["notes"]),
           ($info["state"] == "Dep-Wait" && !empty($info["depends"]) ?
             "\n".$info["depends"] : ""),
           pkg_status($info["state"]));
  } else {
    printf("<td><i>%s</i></td>\n", ($compact ? "" : "not in w-b"));
  }
}

function buildd_status_header($mode, $archs, $package, $suite) {
  if ($mode == "single") {
    echo '<table class="data">
<tr><th>Architecture</th><th>Version</th><th>Status</th><th>For</th><th>Buildd</th><th>State</th><th>Misc</th><th><a href="logs.php?pkg='.urlencode($package).'">Logs</a></th></tr>
';
    echo "\n";
  } else {
    echo "<table class=\"data\"><tr><th>Package</th>";
    foreach ($archs as $arch) {
      printf("<th>%s</th>", arch_link($arch, $suite));
    }
    echo "</tr>\n";
  }
}

function buildd_status_footer($mode) {
  echo "</table>";
}

function detect_links($message) {
  $message = preg_replace('/([a-zA-Z]{3,}:\/\/[^ \n]+)/',
                          '<a href="\1">\1</a>',
                          $message);
  $message = preg_replace('/(#([0-9]{3,6}))/',
                          '<a href="http://bugs.debian.org/cgi-bin/bugreport.cgi?bug=\2">\1</a>',
                          $message);
  return $message;
}

function make_list($list, $sep=", ", $last_sep=" and ") {
  $last = array_pop($list);
  if (empty($list)) {
    return $last;
  } else {
    return sprintf("%s%s%s", implode($sep, $list), $last_sep, $last);
  }
}

function touch_array(&$array) {
  if (!isset($array) || !is_array($array))
    $array = array();
}

// This function is ugly. Any idea to improve the handling of failures
// is welcome. See also next function.
function factorize_issues(&$problems) {
  $equiv = array();
  // factorize "failing reason" issues
  foreach($problems as $package => $issues) {
    if (isset($issues["failing reason"])) {
      $list = $issues["failing reason"];
      foreach($list as $message => $issues) {
        $first = array_shift($issues);
        $arch = $first[0];
        touch_array($equiv[$package][$arch]);
        foreach($issues as $key => $issue) {
          array_push($equiv[$package][$arch], $issue);
        }
      }
    }
  }
  // factorize "tail of log" issues
  foreach($problems as $package => $issues) {
    if (isset($issues["tail of log"])) {
      $list = $issues["tail of log"];
      foreach($list as $message => $issues) {
        foreach($issues as $key => $issue) {
          list($arch, $version, $timestamp, $problemid) = $issue;
          if (isset($equiv[$package][$arch])) {
            $problems[$package]["tail of log"][$message] =
              array_merge(
                          $problems[$package]["tail of log"][$message],
                          $equiv[$package][$arch]
                          );
          } else {
            unset($problems[$package]["tail of log"][$message]);
          }
        }
      }
    }
  }
}

function buildd_failures($problems, $pas, $suite) {
  if (!empty($pas)) {
    $message = shell_exec(sprintf("egrep \"^%%?(%s):\" %s",
                                  implode("|", $pas),
                                  paspath($suite)));
    if (!empty($message))
      printf("<p><b>Occurrences found in %s file:</b></p>\n<pre class=\"failure\">%s</pre>\n",
             paslink($suite),
             detect_links(htmlentities($message)));
  }
  factorize_issues($problems);
  foreach($problems as $package => $issues) {
    foreach($issues as $reason => $list) {
      foreach ($list as $message => $issue) {
        $archs_data = array();
        foreach ($issue as $data) {
          list($arch, $version, $timestamp, $problemid) = $data;
          if (empty($version) || empty($timestamp) || $reason == "failing reason") {
            array_push($archs_data, sprintf("<span id=\"problem-%d\">%s</span>", $problemid, $arch));
          } else {
            array_push($archs_data, sprintf("<span id=\"problem-%d\">%s</span>", $problemid, build_log_link($package, $arch, $version, $timestamp, $arch)));
          }
        }
        $extra = "";
        if ($reason == "tail of log") {
          list($arch, $version, $timestamp, $problemid) = $issue[0];
          $message = tailoflog($package, $version, $arch, $timestamp);
          $extra = build_log_link($package, $arch, $version, $timestamp, "(more)");
        }
	$message = detect_links(htmlentities($message));
	printf("<p><b>%s for <a href=\"package.php?p=%s&amp;suite=%s\">%s</a> on %s:</b></p>\n<pre id=\"problem-%d\" class=\"failure\">%s%s</pre>\n",
               ucfirst($reason),
               urlencode($package),
               $suite,
	       $package,
	       make_list($archs_data),
               $problemid,
	       $message,
               $extra);
      }
    }
  }
}

function report_problem(&$problems, $package, $arch, $category, $message, $version="", $timestamp="") {
  if (strlen($message) <= 1) return;
  global $idcounter;
  $idcounter++;
  touch_array($problems[$package][$category][$message]);
  array_push($problems[$package][$category][$message],
	     array($arch, $version, $timestamp, $idcounter));
  return $idcounter;
}

function print_jsdiv($mode) {
  if ($mode == "multi") echo "<div id=\"jsmode\"></div>\n";
}

function wb_relevant_packages($packages, $suite) {
  global $dbconn, $skipstates;

  $relevant = array();
  foreach ($packages as $package) {
    $package = pg_escape_string($dbconn, $package);
    $result = pg_query($dbconn, string_query($package, $suite));
    $in_wb = false;
    while($info = pg_fetch_assoc($result)) {
      if (in_array($info["notes"], $skipstates)) {
        $in_wb = false;
        break;
      }
      $in_wb = true;
    }
    if ($in_wb) array_push($relevant, $package);
    pg_free_result($result);
  }

  return $relevant;
}

function buildd_status($packages, $suite, $archis=array()) {
  global $dbconn , $pendingstate , $goodstate, $badstate, $donestate , $skipstates, $passtates, $time , $compact , $okstate;

  $print = "single";
  if (count($packages) > 1) {
    $print = "multi";
  }

  $suite = check_suite($suite);
  $archs = filter_archs($archis, $suite);

  $problems = array();
  $pas = array();

  print_jsdiv($print);

  sort($archs);
  buildd_status_header($print, $archs, $packages[0], $suite);

  foreach ($packages as $package) {
    if (empty($package)) continue;

    $package = pg_escape_string($dbconn, $package);
    $result = pg_query($dbconn, string_query($package, $suite));

    $infos = array();
    $overall_status = TRUE;

    while($info = pg_fetch_assoc($result)) {
      $arch = $info["architecture"];
      if (!empty($arch)) {
        if ($arch == "freebsd-i386") $arch = "k".$arch;
        if (!in_array($arch, $archs)) continue;
        $info["architecture"] = $arch;
        $info["timestamp"] = strtotime($info["state_change"]);
        if ($info["state"] == "Auto-Not-For-Us") {
          $infos[$arch] = $info["notes"];
        } elseif ($info["state"] == "Failed-Removed") {
          $infos[$arch] = "failed-removed";
        } else {
          $infos[$arch] = $info;
        }
        $overall_status = $overall_status
          && (   !is_array($info)
              || $info["notes"] == "uncompiled"
              || ignored_arch($arch, $suite)
              || $info["state"] == "Not-For-Us"
              || $info["state"] == "Auto-Not-For-Us"
              || in_array($info["state"], $okstate)
             );
      }
    }
    pg_free_result($result);

    foreach($archs as $arch) {
      if (!isset($infos[$arch])) $infos[$arch] = "absent";
    }

    $overall_status_class = $overall_status ? "good" : "bad";

    if ($print == "multi")
      printf("<tr class=\"%s\"><td><a href=\"package.php?p=%s&amp;suite=%s\">%s&nbsp;%s</a></td>",
             $overall_status_class,
             urlencode($package),
             $suite,
             pkg_status_symbol($overall_status),
             htmlentities($package));

    ksort($infos);
    foreach($infos as $arch => $info) {
      $key = sprintf("%s/%s", $package, $arch);
      $problemid = 0;

      if (in_array($info, $passtates) && !in_array($package, $pas))
        array_push($pas, $package);

      $version = pkg_version($info["version"], $info["binary_nmu_version"]);

      $log = "no log";
      list($count, $logs) = pkg_history($package, $version, $arch, $suite);
      if (is_array($info) && $count >= 1 && $info["state"] != "Auto-Not-For-Us") {
        $timestamp = $logs[0]["timestamp"];
        $lastchange = $info["timestamp"];

        if (in_array($info["state"], $badstate)) {
          $reason = "failing reason";
          if (is_array($info) && strlen($info["failed"]) > 1)
            $problemid = report_problem($problems, $package, $arch, $reason, $info["failed"], $version, $timestamp);
        }

        if (in_array($info["state"], array("Dep-Wait", "BD-Uninstallable"))) {
          $reason = "dependency installability problem";
          if (is_array($info) && !empty($info["bd_problem"]))
            $problemid = report_problem($problems, $package, $arch, $reason, $info["bd_problem"]);
        }

        if (in_array($info["state"], $pendingstate) && $timestamp > $lastchange) {
          if (isset($logs[0]["result"])) $info["state"] = "Maybe-".ucfirst($logs[0]["result"]);
          $info["state_change"] = $logs[0]["date"];
        }
        $last_failed = in_array($info["state"], $pendingstate);

	if (in_array($info["state"], $badstate)) {
	  $reason = "tail of log";
	  $problemid = report_problem($problems, $package, $arch, $reason, $timestamp, $version, $timestamp);
	}
        $log = loglink($package, $version, $arch, $timestamp, $count, $last_failed);
      }

      // Maintainer/Porter upload
      if ($log == "no log" && in_array($info["state"], $donestate)) $info["builder"] = "none";

      // Do not display buildds that are user logins
      if (!is_buildd($info["builder"])) $info["builder"] = "";

      if ($info["state"] == "Installed" && $log == "no log") $info["timestamp"] = "";
      pkg_history($package, $version, $arch, $suite);

      if ($log == "no log") $log = sprintf("%s | %s", logs_link($package, $arch), $log);
      $print($info, $version, $log, $arch, $suite, $problemid);

      // There is no need to repeat the same message for all selected architectures.
      // So, we decide to skip to rest.
      if ($print == "single" && (in_array($info, $skipstates)))
        break;
    }

    if ($print == "multi") echo "</tr>\n";
  }

  buildd_status_footer($print);

  buildd_failures($problems, $pas, $suite);

  print_legend();
}

function archs_overview_links($current_suite, $current_arch="", $show_dists=true) {
  global $ARCHS, $SUITES;
  if ($show_dists) {
    echo "Distributions: ";
    foreach($SUITES as $suite) {
      if ($suite == $current_suite)
	echo " <strong>[$suite]</strong> ";
      else
	echo suite_link($current_arch, $suite, true);
    }
    echo "<br />";
  }
  echo "Architectures: ";
  $archs = $ARCHS;
  foreach(filter_archs($archs, $current_suite) as $arch) {
    if ($arch == $current_arch)
      echo " <strong>[$arch]</strong> ";
    else
      echo arch_link($arch, $current_suite, true);
  }
  echo "<br />";
}

function buildds_overview_link($arch, $suite, $current_buildd="") {
  $list = buildd_list($arch, $suite);
  echo "Restrict on buildd: ";
  if (empty($current_buildd))
    echo " [<strong>all</strong>] ";
  else
    printf(" [<a href=\"architecture.php?a=%s&amp;suite=%s\">all</a>] ", urlencode($arch), urlencode($suite));
  if (is_array($list))
    foreach($list as $buildd) {
      $name = $buildd["username"];
      if ($name == "buildd_${arch}" || !is_buildd($name)) continue;
      if ($name != $current_buildd)
        $name = pkg_buildd($buildd["username"], $suite, $arch);
      else
        $name = "<strong>" . buildd_name($name) . "</strong>";
      printf(" [%s] ", $name);
    }
  echo "<br />";
}

function notes_overview_link($arch, $suite, $current_notes="") {
  echo "Restrict on notes: ";
  $current_notes = empty($current_notes) ? "all" : $current_notes;
  foreach(array('all', 'out-of-date', 'uncompiled', 'related') as $note) {
    $wrap = ($current_notes == $note);
    $link = sprintf('<a href="architecture.php?a=%s&amp;suite=%s%s">%s</a>', $arch, $suite, ($note != 'all') ? "&amp;notes=$note" : '', $note);
    printf('[%s%s%s] ', $wrap ? '<strong>' : '', $link, $wrap ? '</strong>' : '');
  }
  echo '<br />';
}

function page_title($packages, $text="Buildd status for") {
  $pkgs = "";
  $count = count($packages);
  if ($count >= 1 && $count < 10) $pkgs = implode(", ", $packages);
  if ($count >= 10 || $count == 0)
    return sprintf("%s selected packages", $text);
  else
    return sprintf("%s %s", $text, $pkgs);
}

function html_header($subtitle="Buildd information pages", $js=false, $raw=false) {
  if ($raw) {
    header('Content-type: text/plain; charset=UTF-8');
    return;
  }

  echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\" \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">
<html xmlns=\"http://www.w3.org/1999/xhtml\">
<head>
<title>$subtitle</title>

<meta http-equiv=\"content-type\" content=\"text/html; charset=utf-8\" />
<link type=\"text/css\" rel=\"stylesheet\" href=\"media/revamp.css\" />
<link rel=\"StyleSheet\" type=\"text/css\" href=\"media/pkg.css\" />
<link rel=\"StyleSheet\" type=\"text/css\" href=\"media/status.css\" />
<script type=\"text/javascript\" src=\"media/jquery.js\"></script>
";

  if ($js) echo "
<script type=\"text/javascript\" src=\"media/status.js\"></script>
";

  echo "
<script type=\"text/javascript\">
$(document).ready(function () {
  $(\"#pkg_field\").focus();

  $(\".status\").each(function (){
    var id = $(this).attr('id');
    if (id && id.substr(0,7) == 'status-') {
      otherid = id.replace('status-','problem-');
      problem = $('#'+otherid);
      title = $(this).attr('title');
      if (title) { title += ':\\n';}
      title += $(problem).text();
      $(this).attr('title',title);
    }
  });
});
</script>
";

  echo "\n</head>\n<body>\n";
  printf ("<h1 id=\"title\"><a href=\"http://%s\">%s Package Auto-Building</a></h1>\n", BUILDD_HOST, DEBIAN);
  echo "<h2 id=\"subtitle\">$subtitle</h2>\n";
}

function request_uri() {
  return urlencode(sprintf("https://%s%s", $_SERVER["HTTP_HOST"], $_SERVER["REQUEST_URI"]));
}

function html_footer_text($raw=false) {
  global $time;

  if ($raw) return;

  $date = fdate($time);
  echo "<div id=\"footer\">
<small>Page generated on $date UTC<br />
Pages written by <a href=\"http://wiki.debian.org/MehdiDogguy\">Mehdi Dogguy</a><br />
Service maintained by the wanna-build team &lt;<a href=\"http://lists.debian.org/debian-wb-team/\">debian-wb-team@lists.debian.org</a>&gt;<br />
Download code with git: <tt>git clone http://buildd.debian.org/git/pgstatus.git</tt></small><br />
<span class=\"tiny\">
<a href=\"http://validator.w3.org/check?uri=".request_uri()."\">Valid XHTML</a>&nbsp;
<a href=\"http://jigsaw.w3.org/css-validator/validator?uri=".request_uri()."\">Valid CSS</a>
</span>
</div>
</body>
</html>";
}

function html_footer($raw=false) {
  db_disconnect();
  html_footer_text($raw);
}

function alert_if_neq($kind, $good, $bad) {
  if ($good != $bad)
    printf("<div class=\"alert\">Using <i>%s</i> as %s because \"<i>%s</i>\" is unknown!</div>",
       $good, $kind, $bad);
}

function status_fail() {
  echo "Connection to the PGdb failed!";
  html_footer_text();
  die();
}

?>
