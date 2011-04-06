<?

require_once("library.php");
db_connect();

$package = preg_replace ('/[^-a-z0-9\+\., ]/', '', $_GET["p"]);
$packages = preg_split('/[ ,]+/', $package);
$suite = check_suite($_GET["suite"]);
$archs = $_GET["a"];
$compact = !empty($_GET["compact"]);

html_header(page_title($packages), count($packages) > 1);

echo "<div id=\"body\">\n";

alert_if_neq("architecure", $archs, $_GET["a"]);

if (!empty($package)) pkg_links($packages, $suite);
select_suite($packages, $suite, $archs);
if (!empty($package)) buildd_status($packages, $suite, $archs);

echo "</div>";
html_footer();

?>
