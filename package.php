<?

require_once("library.php");

html_header();

$suite = check_suite($_GET["suite"]);
$package = $_GET["p"];
$archs = $_GET["a"];
$packages = preg_split('/[ ,]+/', $package);

page_header($packages);
if (!empty($package)) pkg_links($packages, $suite);
select_suite($packages, $suite, $archs);
if (!empty($package)) buildd_status($packages, $suite, $archs);

html_footer();

?>
