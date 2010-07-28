<?

/**
 * Copyright 2010 © Mehdi Dogguy <mehdi@debian.org>
 *
 */

$ARCHS = array("alpha", "amd64", "arm", "armel", "hppa", "hurd-i386", "i386", "ia64", "kfreebsd-amd64", "kfreebsd-i386", "mips", "mipsel", "powerpc", "s390", "sparc");
$SUITES = array("oldstable", "stable", "testing", "unstable", "experimental", "etch-volatile", "etch-backports", "etch-edu", "lenny-volatile", "lenny-backports", "lenny-edu");

$statehelp = array(
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

$ignorearchs = array(
 "experimental" => array("arm"),
 "unstable" => array("arm"),
 "testing" => array("alpha", "arm", "hurd-i386"),
 "stable" => array("kfreebsd-amd64", "kfreebsd-i386", "hurd-i386"),
 "oldstable" => array("kfreebsd-amd64", "kfreebsd-i386", "hurd-i386"),
 "etch-volatile" => array("kfreebsd-amd64", "kfreebsd-i386", "hurd-i386"),
 "etch-backports" => array("kfreebsd-amd64", "kfreebsd-i386", "hurd-i386"),
 "etch-edu" => array("kfreebsd-amd64", "kfreebsd-i386", "hurd-i386"),
 "lenny-volatile" => array("kfreebsd-amd64", "kfreebsd-i386", "hurd-i386"),
 "lenny-backports" => array("kfreebsd-amd64", "kfreebsd-i386", "hurd-i386"),
 "lenny-edu" => array("kfreebsd-amd64", "kfreebsd-i386", "hurd-i386")
 );

$goodstate = array("Maybe-Successful", "Built", "Installed", "Uploaded");
$okstate = array("Built", "Installed", "Uploaded");
$pendingstate = array("Building", "Dep-Wait", "Needs-Build");

$dbconn = FALSE;
$compact = FALSE;
$time = time("now");

function db_connect() {
  global $dbconn;
  $dbconn = pg_pconnect("service=wanna-build") or status_fail();
}

function db_disconnect() {
  global $dbconn;
  pg_close($dbconn);
}

function string_query($package, $suite) {
  global $dbconn;
  $package = pg_escape_string($dbconn, $package);
  $format = "select * from query_source_package('%s', '%s') as
      query_source_package(arch character varying,            package character varying,
                           distribution character varying,    version character varying,
                           state character varying,           section character varying,
                           priority character varying,        installed_version character varying,
                           previous_state character varying,  state_change timestamp without time zone,
                           notes character varying,           builder character varying,
                           failed text,                       old_failed text,
                           binary_nmu_version integer,        binary_nmu_changelog character varying,
                           failed_category character varying, permbuildpri integer,
                           buildpri integer,                  depends character varying,
                           rel character varying,             bd_problem text, field1 character varying, filed2 character varying)
      order by arch asc";
  return sprintf($format, $suite, $package);
}

function ignored_arch($arch, $suite) {
  global $ignorearchs;
  if (!empty($suite) && isset($ignorearchs[$suite]))
    return in_array($arch, $ignorearchs[$suite]);
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

function check_suite($suite) {
  global $SUITES;
  if (in_array($suite, $SUITES)) {
    return $suite;
  } else {
    return "unstable";
  }
}

function check_arch($arch) {
  global $ARCHS;
  if (in_array($arch, $ARCHS)) {
    return $arch;
  } else {
    return $archs[0];
  }
}

function good_arch($arch) {
  return ($arch == check_arch($arch));
}

function remove_values($values, $array) {
  if (!is_array($array) || empty($array)) return $array;
  foreach ($array as $key => $value) {
    if (in_array($value, $values)) unset($array[$key]);
  }
  return $array;
}

function check_archs($archs) {
  $archs = explode(",", $archs);
  $archs = array_filter($archs, "good_arch");
  return array_unique($archs);
}

function filter_archs($myarchs, $suite) {
  global $ignorearchs, $ARCHS;
  $myarchs = check_archs($myarchs);
  if (count($myarchs) == 0 || $myarchs[0] == "") $myarchs = $ARCHS;
  if (!empty($suite) && isset($ignorearchs[$suite]))
    $myarchs = remove_values($ignorearchs[$suite], $myarchs);
  return array_unique($myarchs);
}

function arch_name($arch) {
  global $compact , $compactarch;
  if ($compact && in_array($arch, array_keys($compactarch)))
    return $compactarch[$arch];
  else return $arch;
}

function select_suite($packages, $selected_suite, $archs="") {
  global $compact, $SUITES;
  $package = implode(",", $packages);
  $archs = implode(",", check_archs($archs));
  $selected_suite = check_suite($selected_suite);
  printf("<form action=\"package.php\" method=\"get\">\n<p>\nPackage(s): <input id=pkg_field type=text length=30 name=p value=\"%s\"> Suite: ",
         $package
         );
  printf("<select name=\"suite\" id=\"suite\">\n");
  foreach($SUITES as $suite) {
    $selected = "";
    if ($suite == $selected_suite) $selected = ' selected="selected"';
    printf("\t<option value=\"%s\"%s>%s</option>\n", $suite, $selected, $suite);
  }
  printf("</select>\n");
  if (!empty($archs)) printf("<input type=hidden name=a value=\"%s\">\n", $archs);
  printf("<input type=submit value=Go>\n");
  printf("<br /><input type=\"checkbox\" name=\"compact\" value=\"compact\" %s /><span class=\"tiny\">Compact mode</span>\n",
         $compact ? " checked=\"\"" : ""
         );
  printf("</form>\n");
}

function date_diff_details($lastchange) {
  global $time;
  $diff = $time - $lastchange;
  $days = floor($diff / (3600 * 24));
  $rest = $diff - ($days * 3600 * 24);
  $hours = floor($rest / 3600);
  $mins = floor(($rest % 3600) / 60);
  $date = "";
  if ($days > 0) $date = sprintf("%sd", $days);
  if ($hours != 0 || $mins != 0) {
    if (!empty($date)) $date .= " ";
    $date .= "${hours}h ${mins}m";
  }
  return array($days, $date);
}

function date_diff($lastchange) {
  $result = date_diff_details($lastchange);
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

function loglink($package, $version, $arch, $timestamp, $count, $failed) {
  global $pendingstate;
  $log = "";
  $all = sprintf("<a href=\"/build.php?pkg=%s&arch=%s&ver=%s\">all (%d)</a>",
                 urlencode($package),
                 urlencode($arch),
                 urlencode($version),
                 htmlentities($count)
                 );
  if (empty($timestamp) || $count == 0)
    $log = "no log";
  else {
    $text = "last log";
    if ($failed) $text = "<font color=red>$text</font>";
    $log = sprintf("<a href=\"/fetch.cgi?pkg=%s&arch=%s&ver=%s&stamp=%s&file=log&as=raw\">%s</a>",
                   urlencode($package),
                   urlencode($arch),
                   urlencode($version),
                   urlencode($timestamp),
                   $text);
  }
  return sprintf("%s | %s", $all, $log);
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

function buildd_name($name) {
  return ereg_replace('.*-', '', $name);
}

function pkg_buildd($buildd, $suite, $arch) {
  $name = buildd_name($buildd);
  return sprintf("<a href=\"architecture.php?a=%s&suite=%s&buildd=%s\">%s</a>",
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

function pkg_version($version, $binnmu) {
  if (!empty($binnmu))
    return sprintf("%s+b%s", $version, $binnmu);
  else
    return $version;
}

function pkg_links($packages, $suite) {
  if (count($packages) == 1) {
    $package = $packages[0];
    echo "<p>";
    $links =
      array(
            sprintf("<a href=\"http://packages.qa.debian.org/%s\">PTS</a>", urlencode($package)),
            sprintf("<a href=\"http://packages.debian.org/changelogs/pool/main/%s/%s/current/changelog\">Changelog</a>",
                    urlencode($package{0}), urlencode($package)),
            sprintf("<a href=\"http://bugs.debian.org/src:%s\">Bugs</a>", urlencode($package)),
            sprintf("<a href=\"http://packages.debian.org/source/%s/%s\">packages.d.o</a>",
                    urlencode($suite), urlencode($package)),
            );
    echo implode(" &ndash; ", $links);
    echo "</p>\n";
  } else {
    $packages = array_map("urlencode", $packages);
    $srcs = implode(";src=", $packages);
    $url = sprintf("http://bugs.debian.org/cgi-bin/pkgreport.cgi?src=%s;dist=%s", $srcs, urlencode($suite));
    printf("<p><a href=\"%s\">Bugs</a></p>", $url);
  }
}

function pkg_state_help($state, $notes) {
  $notes = pkg_state($state, $notes);
  if (!empty($notes)) $state .= " ($notes)";
  return $state;
}

function arch_link($arch, $suite, $sep=false) {
  $bsep = "";
  $esep = "";
  if ($sep) {
    $bsep = "[";
    $esep = "]";
  }
  return sprintf(" <a href=\"architecture.php?a=%s&suite=%s\">%s%s%s</a> ",
                 urlencode($arch), $suite, $bsep, htmlentities(arch_name($arch)), $esep);
}

function single($info, $version, $log, $arch, $suite) {
  global $statehelp;
  $state = $info["state"];
  if ($state == "Dep-Wait" && !empty($info["depends"]))
    $state .= " (" . $info["depends"] . ")";
  if (is_array($info)) {
    printf("<tr><td>%s</td><td>%s</td><td class=\"status %s\" title=\"%s\">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>\n",
           arch_link($info["arch"], $suite),
           $version,
           pkg_state_class($info["state"]),
           $statehelp[$info["state"]],
           pkg_status($state),
           date_diff($info["timestamp"]),
           pkg_buildd($info["builder"], $suite, $arch),
           pkg_state($info["state"], $info["notes"]),
           sprintf("%s:%s", $info["section"], $info["priority"]),
           $log
           );
  } else {
    printf("<tr><td colspan=\"8\"><i>No entry in %s database, check <a href=\"https://buildd.debian.org/quinn-diff/%s/Packages-arch-specific\">Packages-arch-specific</a></i></td></tr>\n", urlencode($arch), urlencode($suite));
  }
}

function pkg_status_symbol($good) {
  if ($good)
    return "✔";
  else
    return "✘";
}

function multi($info, $version, $log, $arch, $suite) {
  global $compact;
  if (is_array($info)) {
    printf("<td class=\"%s\" title=\"%s\">%s</td>",
           pkg_state_class($info["state"]),
           pkg_state_help($info["state"], $info["notes"]),
           pkg_status($info["state"]));
  } else {
    printf("<td><i>%s</i></td>\n", ($compact ? "" : "not in w-b"));
  }
}

function buildd_status_header($mode, $archs, $suite) {
  if ($mode == "single") {
    echo '<table class=data>
<tr><th>Architecture</th><th>Version</th><th>Status</th><th>For</th><th>Buildd</th><th>State</th><th>Misc</th><th>Logs</th></tr>
';
    echo "\n";
  } else {
    echo "<table class=data><tr><th>Package</th>";
    foreach ($archs as $arch) {
      printf("<th>%s</th>", arch_link($arch, $suite));
    }
    echo "</tr>\n";
  }
}

function buildd_status_footer($mode) {
  echo "</table>";
}

function buildd_failures($reason, $failures, $subst=false) {
  foreach($failures as $key => $message) {
    $message = htmlentities($message);
    if ($subst)
      $message = preg_replace('/(#([0-9]{3,6}))/',
                              '<a href="http://bugs.debian.org/cgi-bin/bugreport.cgi?bug=\2">\1</a>',
                              $message);
    printf("<p><b>%s $reason:</b><br />\n<pre>%s</pre>\n</p>\n", $key, $message);
  }
}

function print_jsdiv($mode) {
  if ($mode != "multi") return;
  echo "<div id=\"jsmode\"></div>\n";
}

function buildd_status($packages, $suite, $archis="") {
  global $dbconn , $pendingstate , $time , $compact , $okstate;

  $print = "single";
  if (count($packages) > 1) {
    $print = "multi";
  }

  $suite = check_suite($suite);
  $archs = filter_archs($archis, $suite);

  $failures = array();
  $bdproblems = array();

  print_jsdiv($print);

  sort($archs);
  buildd_status_header($print, $archs, $suite);

  //sort($packages);
  foreach ($packages as $package) {
    if (empty($package)) continue;

    $package = pg_escape_string($dbconn, $package);
    $result = pg_query($dbconn, string_query($package, $suite));

    $infos = array();
    $overall_status = TRUE;

    while($info = pg_fetch_assoc($result)) {
      $arch = $info["arch"];
      if (!empty($arch)) {
        if ($arch == "freebsd-i386") $arch = "k".$arch;
        if (!in_array($arch, $archs)) continue;
        $info["arch"] = $arch;
        $info["timestamp"] = strtotime($info["state_change"]);
        $infos[$arch] = $info;
        $overall_status = $overall_status
          && (   !is_array($info)
              || $info["notes"] == "uncompiled"
              || ignored_arch($arch, $suite)
              || $info["state"] == "Not-For-Us"
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
      printf("<tr class=\"%s\"><td><a href=\"package.php?p=%s&suite=%s\">%s&nbsp;%s</a></td>",
             $overall_status_class,
             urlencode($package),
             $suite,
             pkg_status_symbol($overall_status),
             htmlentities($package));

    ksort($infos);
    foreach($infos as $arch => $info) {
      $key = sprintf("%s/%s", $package, $arch);
      if (is_array($info) && !empty($info["failed"])) $failures[$key] = $info["failed"];
      if (is_array($info) && !empty($info["bd_problem"])) $bdproblems[$key] = $info["bd_problem"];
      $version = pkg_version($info["version"], $info["binary_nmu_version"]);

      $log = "no log";
      list($count, $logs) = pkg_history($package, $version, $arch, $suite);
      if (is_array($info) && $count >= 1) {
        $timestamp = $logs[0]["timestamp"];
        $lastchange = $info["timestamp"];
        if (in_array($info["state"], $pendingstate) && $timestamp > $lastchange) {
          if (isset($logs[0]["result"])) $info["state"] = "Maybe-".ucfirst($logs[0]["result"]);
          $info["state_change"] = $logs[0]["date"];
        }
        $last_failed = in_array($info["state"], $pendingstate);
        $log = loglink($package, $version, $arch, $timestamp, $count, $last_failed);
      }

      if ($info["state"] == "Installed" && $log == "no log") $info["timestamp"] = $time;
      pkg_history($package, $version, $arch, $suite);
      $print($info, $version, $log, $arch, $suite);
    }

    if ($print == "multi") echo "</tr>\n";
  }

  buildd_status_footer($print);

  buildd_failures("failing reason", $failures, true);
  buildd_failures("dependency installability problem", $bdproblems);

  print_legend();
}

function archs_overview_links($suite, $current_arch="") {
  global $ARCHS;
  foreach($ARCHS as $arch) {
    if ($arch == $current_arch)
      echo " <strong>[$arch]</strong> ";
    else
      echo arch_link($arch, $suite, true);
  }
}

function buildds_overview_link($arch, $suite, $current_buildd="") {
  $list = buildd_list($arch, $suite);
  echo "Restrict on buildd: ";
  if (empty($current_buildd))
    echo " [<strong>all</strong>] ";
  else
    printf(" [<a href=\"architecture.php?a=%s&suite=%s\">all</a>] ", urlencode($arch), urlencode($suite));
  if (is_array($list))
    foreach($list as $buildd) {
      $name = $buildd["username"];
      if ($name == "buildd_${arch}") continue;
      if ($name != $current_buildd)
        $name = pkg_buildd($buildd[username], $suite, $arch);
      else
        $name = "<strong>" . buildd_name($name) . "</strong>";
      printf(" [%s] ", $name);
    }
}

function page_header($packages, $text="for package(s):") {
  $title = "";
  $count = count($packages);
  if ($count >= 1 && $count < 10) $title = " " . implode(", ", $packages);
  if ($count >= 10) $text = "for selected packages:";
  echo "<h1 id=\"title\">Debian Package Auto-Building</h1>\n";
  printf("<h2 id=\"subtitle\">Buildd status %s%s</h2>\n", $text, $title);
}

function html_header($js=FALSE, $title="Buildd information pages") {
  echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\" \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">
<html>
<head>
<title>$title</title>

<meta http-equiv=\"content-type\" content=\"text/html; charset=utf-8\" />
<link type=\"text/css\" rel=\"stylesheet\" href=\"/gfx/revamp.css\" />
<link rel=\"StyleSheet\" type=\"text/css\" href=\"pkg.css\" />
<link rel=\"StyleSheet\" type=\"text/css\" href=\"status.css\" />
<script type=\"text/javascript\" src=\"jquery.js\"></script>
";

  if ($js) echo "
<script type=\"text/javascript\" src=\"status.js\"></script>
";

  echo "
<script type=\"text/javascript\">
$(document).ready(function () { $(\"#pkg_field\").focus() });
</script>
";

  echo "\n</head>\n<body>\n";
  db_connect();
}

function html_footer_text() {
  global $time;
  $date = fdate($time);
  echo "<hr />
<div id=\"footer\">
Page generated on $date UTC<br />
Pages written by <a href=\"http://wiki.debian.org/MehdiDogguy\">Mehdi Dogguy</a>
(based on old status pages written by Jeroen van Wolffelaar)<br />
Pages maintained by the wanna-build team &lt;debian-wb-team@lists.debian.org&gt;<br />
Download code with git: <tt>git clone http://buildd.debian.org/git/pgstatus.git</tt>
</div>
</body>
</html>";
}

function html_footer() {
  db_disconnect();
  html_footer_text();
}

function status_fail() {
  echo "Connection to the PGdb failed!";
  html_footer_text();
  die();
}

?>
