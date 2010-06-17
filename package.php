<?

require_once("library.php");

html_header();

$suite = $_GET["suite"];
$package = $_GET["p"];
$archs = $_GET["a"];
$compact = isset($_GET["compact"]);
$packages = preg_split('/[ ,]+/', $package);

page_header($packages);
if (!empty($package)) pkg_links($packages, $suite);
select_suite($packages, $suite, $archs, $compact);
if (!empty($package)) buildd_status($packages, $suite, $archs, $compact);

html_footer();

?>
