<?

/**
 * Copyright 2010 © Mehdi Dogguy <mehdi@debian.org>
 *
 */

define(ARCHS, "alpha amd64 arm armel hppa hurd-i386 i386 ia64 kfreebsd-amd64 kfreebsd-i386 mips mipsel powerpc s390 sparc");
define(SUITES, "oldstable stable testing unstable experimental etch-volatile etch-backports etch-edu lenny-volatile lenny-backports lenny-edu");

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

$goodstate = array("Maybe-Successful", "Built", "Installed", "Uploaded");
$pendingstate = array("Building", "Dep-Wait", "Needs-Build");

$dbconn = FALSE;
$time = time("now");

function db_connect() {
  global $dbconn;
  $dbconn = pg_pconnect("service=wanna-build");
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
                           rel character varying,             bd_problem text)
      order by arch asc";
  return sprintf($format, $suite, $package);
}

function check_suite($suite) {
  $suites = explode(" ", SUITES);
  if (in_array($suite, $suites)) {
    return $suite;
  } else {
    return "unstable";
  }
}

function check_arch($arch) {
  $archs = explode(" ", ARCHS);
  if (in_array($arch, $archs)) {
    return $arch;
  } else {
    return $archs[0];
  }
}

function select_suite($packages, $selected_suite) {
  $suites = explode(" ", SUITES);
  $package = implode(",", $packages);
  printf("<form action=\"package.php\" method=\"get\">\n<p>\nPackage(s): <input type=text length=30 name=p value=\"%s\"> Suite: ",
         $package
         );
  printf('<select name="suite" id="suite">');
  foreach($suites as $suite) {
    $selected = "";
    if ($suite == $selected_suite) $selected = ' selected="selected"';
    printf('<option value="%s"%s>%s</option>', $suite, $selected, $suite);
  }
  printf("</select>\n<input type=submit value=Go>\n</form>\n");
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
                 $arch,
                 urlencode($version),
                 $count
                 );
  if (empty($timestamp) || $count == 0)
    $log = "no log";
  else {
    $text = "last log";
    if ($failed) $text = "<font color=red>$text</font>";
    $log = sprintf("<a href=\"/fetch.cgi?pkg=%s&arch=%s&ver=%s&stamp=%s&file=log&as=raw\">%s</a>",
                   urlencode($package),
                   $arch,
                   urlencode($version),
                   $timestamp,
                   $text);
  }
  return sprintf("%s | %s", $all, $log);
}

function pkg_state_class($state) {
  $state = strtolower(implode("", explode("-", $state)));
  if (!empty($state))
    return "status-$state";
  else
    return "";
}

function buildd_name($name) {
  $name = explode("-", $name);
  return $name[count($name)-1];
}

function pkg_buildd($buildd, $suite, $arch) {
  $name = explode("-", $buildd);
  $name = $name[count($name)-1];
  return sprintf("<a href=\"architecture.php?a=%s&suite=%s&buildd=%s\">%s</a>", $arch, $suite, $buildd, $name);
}

function pkg_state($status, $state) {
  global $goodstate;
  if (in_array($status, $goodstate))
    return "";
  else
    return $state;
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
            sprintf("<a href=\"http://packages.qa.debian.org/%s\">PTS</a>", $package),
            sprintf("<a href=\"http://packages.debian.org/changelogs/pool/main/%s/%s/current/changelog\">Changelog</a>", $package{0}, $package),
            sprintf("<a href=\"http://bugs.debian.org/src:%s\">Bugs</a>", $package),
            sprintf("<a href=\"http://packages.debian.org/source/%s/%s\">packages.d.o</a>", $suite, $package),
            );
    echo implode(" &ndash; ", $links);
    echo "</p>\n";
  } else {
    $srcs = implode(";src=", $packages);
    $url = sprintf("http://bugs.debian.org/cgi-bin/pkgreport.cgi?src=%s;dist=%s", $srcs, $suite);
    printf("<p><a href=\"%s\">Bugs</a></p>", $url);
  }
}

function arch_link($arch, $sep=false) {
  $bsep = "";
  $esep = "";
  if ($sep) {
    $bsep = "[";
    $esep = "]";
  }
  return sprintf(" <a href=\"architecture.php?a=%s\">%s%s%s</a> ", $arch, $bsep, $arch, $esep);
}

function single($info, $version, $log, $arch, $suite) {
  global $statehelp;
  $state = $info["state"];
  if ($state == "Dep-Wait" && !empty($info["depends"]))
    $state .= " (" . $info["depends"] . ")";
  if (is_array($info)) {
    printf("<tr><td>%s</td><td>%s</td><td class=\"status %s\" title=\"%s\">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>\n",
           arch_link($info["arch"]),
           $version,
           pkg_state_class($info["state"]),
           $statehelp[$info["state"]],
           $state,
           date_diff($info["timestamp"]),
           pkg_buildd($info["builder"], $suite, $arch),
           pkg_state($info["state"], $info["notes"]),
           sprintf("%s:%s", $info["section"], $info["priority"]),
           $log
           );
  } else {
    printf("<tr><td colspan=\"8\"><i>No entry in %s database, check <a href=\"https://buildd.debian.org/quinn-diff/%s/Packages-arch-specific\">Packages-arch-specific</a></i></td></tr>\n", $arch, $suite);
  }
}

function multi($info, $version, $log, $arch, $suite) {
  if (is_array($info)) {
    printf("<td class=\"%s\">%s</td>", pkg_state_class($info["state"]), $info["state"]);
  } else {
    printf("<td><i>not in w-b</i></td>\n");
  }
}

function buildd_status_header($mode, $archs) {
  if ($mode == "single") {
    echo '<table class=data>
<tr><th>Architecture</th><th>Version</th><th>Status</th><th>For</th><th>Buildd</th><th>State</th><th>Misc</th><th>Logs</th></tr>
';
    echo "\n";
  } else {
    echo "<table class=data><tr><th>Package</th>";
    foreach ($archs as $arch) {
      printf("<th>%s</th>", arch_link($arch));
    }
    echo "</tr>\n";
  }
}

function buildd_status_footer($mode) {
  echo "</table>";
}

function buildd_failures($reason, $failures, $subst=false) {
  foreach($failures as $key => $message) {
    if ($subst)
      $message = preg_replace('/(#([0-9]{3,6}))/',
                              '<a href="http://bugs.debian.org/cgi-bin/bugreport.cgi?bug=\2">\1</a>',
                              $message);
    printf("<p><b>%s $reason:</b><br />\n<pre>%s</pre>\n</p>\n", $key, $message);
  }
}

function buildd_status($packages, $suite, $archis="") {
  global $dbconn , $pendingstate;

  $print = "single";
  if (count($packages) > 1) {
    $print = "multi";
  }

  $suites = explode(" ", SUITES);
  if (!in_array($suite, $suites)) {
      $suite = "unstable";
  }

  $archs = explode(" ", ARCHS);
  if (!empty($archis)) {
    $archs = preg_split('/[ ,]+/', $archis);
  }

  $failures = array();
  $bdproblems = array();

  buildd_status_header($print, $archs);

  foreach ($packages as $package) {
    if (empty($package)) continue;
    if ($print == "multi") echo "<tr><td><a href=\"package.php?p=$package\">$package</a></td>";

    $package = pg_escape_string($dbconn, $package);
    $result = pg_query($dbconn, string_query($package, $suite));

    $infos = array();

    while($info = pg_fetch_assoc($result)) {
      $arch = $info["arch"];
      if (!empty($arch)) {
        if ($arch == "freebsd-i386") $arch = "k".$arch;
        if (!in_array($arch, $archs)) continue;
        $info["arch"] = $arch;
        $info["timestamp"] = strtotime($info["state_change"]);
        $infos[$arch] = $info;
      }
    }
    foreach($archs as $arch) {
      if (!isset($infos[$arch])) $infos[$arch] = "absent";
    }

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

      pkg_history($package, $version, $arch, $suite);
      $print($info, $version, $log, $arch, $suite);
    }

    if ($print == "multi") echo "</tr>";
  }

  buildd_status_footer($print);

  buildd_failures("failing reason", $failures, true);
  buildd_failures("dependency installability problem", $bdproblems);
}

function archs_overview_links($current_arch="") {
  $archs = explode(" ", ARCHS);
  foreach($archs as $arch) {
    if ($arch == $current_arch)
      echo " <strong>[$arch]</strong> ";
    else
      echo arch_link($arch, true);
  }
}

function buildds_overview_link($arch, $suite, $current_buildd="") {
  $list = buildd_list($arch, $suite);
  echo "Restrict on buildd: ";
  if (empty($current_buildd))
    echo " [<strong>all</strong>] ";
  else
    printf(" [<a href=\"architecture.php?a=%s&suite=%s\">all</a>] ", $arch, $suite);
  if (is_array($list))
    foreach($list as $buildd) {
      $name = $buildd["username"];
      if ($name != $current_buildd)
        $name = pkg_buildd($buildd[username], $suite, $arch);
      else
        $name = "<strong>" . buildd_name($name) . "</strong>";
      printf(" [%s] ", $name);
    }
}

function page_header($packages, $text="for package(s):") {
  $title = "";
  if (count($packages) >= 1) $title = " " . implode(", ", $packages);
  printf("<h1>Buildd status %s%s</h1>\n", $text, $title);
}

function html_header($title="Buildd information pages") {
  echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\" \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">
<html>
<head>
<title>$title</title>

<link rel=\"StyleSheet\" type=\"text/css\" href=\"status.css\" />
<link rel=\"StyleSheet\" type=\"text/css\" href=\"http://buildd.debian.org/pkg.css\" />

</head>
<body>
";
  db_connect();
}

function html_footer() {
  global $time;
  $date = fdate($time);
  db_disconnect();
  echo "<hr />
<!-- Include here a timestamp, git clone url and the author's name -->
<small>
Page generated on $date UTC<br />
Pages written by <a href=\"http://wiki.debian.org/MehdiDogguy\">Mehdi Dogguy</a>
(based on old status pages written by Jeroen van Wolffelaar)<br />
Pages maintained by the wanna-build team &lt;debian-wb-team@lists.debian.org&gt;<br />
Download code with git: <tt>git clone http://buildd.debian.org/~mehdi/pgstatus/.git</tt>
</small>
</body>
</html>";
}

?>
