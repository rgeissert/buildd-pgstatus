<?

/**
 * Copyright 2010 © Mehdi Dogguy <mehdi@debian.org>
 *
 */

$ARCHS = array("alpha", "amd64", "arm", "armel", "hppa", "hurd-i386", "i386", "ia64", "kfreebsd-amd64", "kfreebsd-i386", "mips", "mipsel", "powerpc", "s390", "sparc");
$SUITES = array("oldstable", "stable", "testing", "unstable", "experimental"); // Will be fixed later (when pg connection is established)
$ALIASES = array();

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

$valid_archs = array(); // Will be filled in later.

$goodstate = array("Maybe-Successful", "Built", "Installed", "Uploaded");
$okstate = array("Built", "Installed", "Uploaded");
$donestate = array("Installed", "Uploaded");
$pendingstate = array("Building", "Dep-Wait", "Needs-Build");

$dbconn = FALSE;
$compact = FALSE;
$time = time("now");

function db_connect() {
  global $dbconn, $SUITES, $ALIASES, $valid_archs;
  $dbconn = pg_pconnect("service=wanna-build") or status_fail();

  $result = pg_query($dbconn, "select * from distributions where public order by sort_order DESC");
  $SUITES = pg_fetch_all_columns($result, 0);
  pg_free_result($result);

  $result = pg_query($dbconn, "select * from distribution_aliases");
  while($row = pg_fetch_row($result))
    $ALIASES[$row[0]] = $row[1];
  pg_free_result($result);

  $result = pg_query($dbconn, "select * from distribution_architectures");
  while ($row = pg_fetch_assoc($result)) {
    $valid_archs[$row["distribution"]][] = $row["architecture"];
  }
  pg_free_result($result);
}

function db_disconnect() {
  global $dbconn;
  pg_close($dbconn);
}

function string_query($package, $suite, $fields="*", $extra="") {
  global $dbconn;
  $package = pg_escape_string($dbconn, $package);
  $format = "select %s from query_source_package('%s', '%s') as
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
                           rel character varying,             bd_problem text,
                           extra_depends character varying,   extra_conflicts character varying,
                           build_arch_all boolean)
      order by arch asc %s";
  return sprintf($format, $fields, $suite, $package, $extra);
}

function log_query_arch($pkg, $arch, $ver="") {
  return sprintf("SELECT '%s'::character varying AS arch, *
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
  return sprintf("%s ORDER BY version DESC, arch ASC, timestamp DESC", $query);
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

function check_suite($suite) {
  global $SUITES, $ALIASES;
  if (in_array($suite, $SUITES)) {
    return $suite;
  } else if (in_array($suite, array_keys($ALIASES))) {
    return $ALIASES[$suite];
  } else {
    return "sid";
  }
}

function valid_arch($arch) {
  global $ARCHS;
  return in_array($arch, $ARCHS);
}

function check_arch($arch) {
  global $ARCHS;
  if (valid_arch($arch)) {
    return $arch;
  } else {
    return $ARCHS[0];
  }
}

function good_arch($arch) {
  return ($arch == check_arch($arch));
}

function remove_values($values, $array) {
  if (!is_array($array) || empty($array)) return $array;
  foreach ($array as $key => $value) {
    if (!in_array($value, $values)) unset($array[$key]);
  }
  return $array;
}

function check_archs($archs) {
  $archs = explode(",", $archs);
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

function select_logs($package) {
  echo "<form action=\"logs.php\" method=\"get\">\n<p>\n";
  echo "Package: <input id=pkg_field type=text length=30 name=pkg value=\"$package\">";
  printf("<input type=submit value=Go>\n");
  echo "</p>\n</form>\n";
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

function date_diff_details($time, $lastchange) {
  $diff = $time - $lastchange;
  $days = floor($diff / (3600 * 24));
  $rest = $diff - ($days * 3600 * 24);
  $hours = floor($rest / 3600);
  $mins = floor(($rest % 3600) / 60);
  $date = array();
  if ($days > 0) array_push($date, "${days}d");
  if ($hours > 0) array_push($date, "${hours}h");
  if ($mins > 0) array_push($date, "${mins}m");
  $date = implode(" ", $date);
  return array($days, $date);
}

function date_diff($lastchange) {
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

function color_text($text, $failed) {
  if ($failed)
    return "<font color=red>$text</font>";
  else
    return "<font color=green>$text</font>";
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
  if (!empty($ver)) $ver = sprintf("&ver=%s", urlencode($ver));
  if (!empty($arch)) $arch = sprintf("&arch=%s", urlencode($arch));
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
  $all = sprintf("<a href=\"logs.php?pkg=%s&arch=%s&ver=%s\">all (%d)</a>",
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
    $log = build_log_link($package, $arch, $version, $timestamp, $text);
  }
  return sprintf("%s | %s | %s", $old, $all, $log);
}

function build_log_link($package, $arch, $version, $timestamp, $text) {
    return sprintf("<a href=\"fetch.php?pkg=%s&arch=%s&ver=%s&stamp=%s\">%s</a>",
                   urlencode($package),
                   urlencode($arch),
                   urlencode($version),
                   urlencode($timestamp),
                   $text);
}

function logpath($pkg, $ver, $arch, $stamp) {
  return sprintf("/srv/buildd.debian.org/db/%s/%s/%s/%s_%s_log.bz2",
		 $pkg[0],
		 $pkg,
		 $ver,
		 $arch,
		 $stamp);
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
  if ($buildd == "none") return $buildd;
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

function pkg_links($packages, $suite, $p=true) {
  $suite = strip_suite($suite);
  if (count($packages) == 1) {
    $package = $packages[0];
    if ($p) echo "<p>";
    $links =
      array(
            sprintf("<a href=\"http://packages.qa.debian.org/%s\">PTS</a>", urlencode($package)),
            sprintf("<a href=\"http://packages.debian.org/changelogs/pool/%s/%s/%s/current/changelog\">Changelog</a>",
                    pkg_area($package), urlencode($package{0}), urlencode($package)),
            sprintf("<a href=\"http://bugs.debian.org/src:%s\">Bugs</a>", urlencode($package)),
            sprintf("<a href=\"http://packages.debian.org/source/%s/%s\">packages.d.o</a>",
                    urlencode($suite), urlencode($package)),
            );
    echo implode(" &ndash; ", $links);
    if ($p) echo "</p>\n";
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

function some_link($arch, $suite, $text, $sep) {
  $bsep = "";
  $esep = "";
  if ($sep) {
    $bsep = "[";
    $esep = "]";
  }
  return sprintf(" <a href=\"architecture.php?a=%s&suite=%s\">%s%s%s</a> ",
                 urlencode($arch), $suite, $bsep, htmlentities($text), $esep);
}

function suite_link($arch, $suite, $sep=false) {
  return some_link($arch, $suite, $suite, $sep);
}

function arch_link($arch, $suite, $sep=false) {
  return some_link($arch, $suite, arch_name($arch), $sep);
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

function buildd_status_header($mode, $archs, $package, $suite) {
  if ($mode == "single") {
    echo '<table class=data>
<tr><th>Architecture</th><th>Version</th><th>Status</th><th>For</th><th>Buildd</th><th>State</th><th>Misc</th><th><a href="logs.php?pkg='.$package.'">Logs</a></th></tr>
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
    if ($subst) {
      $message = preg_replace('/([a-zA-Z]{3,}:\/\/[^ ]+)/',
                              '<a href="\1">\1</a>',
                              $message);
      $message = preg_replace('/(#([0-9]{3,6}))/',
                              '<a href="http://bugs.debian.org/cgi-bin/bugreport.cgi?bug=\2">\1</a>',
                              $message);
    }
    printf("<p><b>%s $reason:</b><br />\n<pre>%s</pre>\n</p>\n", $key, $message);
  }
}

function print_jsdiv($mode) {
  if ($mode != "multi") return;
  echo "<div id=\"jsmode\"></div>\n";
}

function buildd_status($packages, $suite, $archis="") {
  global $dbconn , $pendingstate , $donestate , $time , $compact , $okstate;

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
  buildd_status_header($print, $archs, $packages[0], $suite);

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

      // Maintainer/Porter upload
      if ($log == "no log" && in_array($info["state"], $donestate)) $info["builder"] = "none";

      if ($info["state"] == "Installed" && $log == "no log") $info["timestamp"] = $time;
      pkg_history($package, $version, $arch, $suite);
      if ($log == "no log") $log = sprintf("%s | %s", logs_link($package, $arch), $log);
      $print($info, $version, $log, $arch, $suite);
    }

    if ($print == "multi") echo "</tr>\n";
  }

  buildd_status_footer($print);

  buildd_failures("failing reason", $failures, true);
  buildd_failures("dependency installability problem", $bdproblems);

  print_legend();
}

function archs_overview_links($current_suite, $current_arch="") {
  global $ARCHS, $SUITES;
  echo "Distributions: ";
  foreach($SUITES as $suite) {
    if ($suite == $current_suite)
      echo " <strong>[$suite]</strong> ";
   else
      echo suite_link($current_arch, $suite, true);
  }
  echo "<br />";
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
  echo "<br />";
}

function notes_overview_link($arch, $suite, $current_notes="") {
  echo "Restrict on notes: ";
  $current_notes = empty($current_notes) ? "all" : $current_notes;
  foreach(array('all', 'out-of-date', 'uncompiled', 'related') as $note) {
    $wrap = ($current_notes == $note);
    $link = sprintf('<a href="architecture.php?a=%s&suite=%s%s">%s</a>', $arch, $suite, ($note != 'all') ? "&notes=$note" : '', $note);
    printf('[%s%s%s] ', $wrap ? '<strong>' : '', $link, $wrap ? '</strong>' : '');
  }
  echo '<br />';
}

function page_header($packages, $text="Buildd status for package(s):") {
  $pkgs = "";
  $count = count($packages);
  if ($count >= 1 && $count < 10) $pkgs = " " . implode(", ", $packages);
  if ($count >= 10) $text = "for selected packages:";
  echo "<h1 id=\"title\">Debian Package Auto-Building</h1>\n";
  printf("<h2 id=\"subtitle\">%s%s</h2>\n", $text, $pkgs);
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
  echo "<div id=\"footer\">
<small>Page generated on $date UTC<br />
Pages written by <a href=\"http://wiki.debian.org/MehdiDogguy\">Mehdi Dogguy</a><br />
Service maintained by the wanna-build team &lt;<a href=\"http://lists.debian.org/debian-wb-team/\">debian-wb-team@lists.debian.org</a>&gt;<br />
Download code with git: <tt>git clone http://buildd.debian.org/git/pgstatus.git</tt></small>
</div>
</body>
</html>";
}

function html_footer() {
  db_disconnect();
  html_footer_text();
}

function alert_if_neq($kind, $good, $bad) {
  if ($good != $bad)
    printf("<div class=\"alert\">Using <i>%s</i> as %s because <i>%s</i> is unknown!</div>",
       $good, $kind, $bad);
}

function status_fail() {
  echo "Connection to the PGdb failed!";
  html_footer_text();
  die();
}

?>
