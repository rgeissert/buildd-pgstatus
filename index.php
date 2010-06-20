<?

require_once("library.php");

html_header();

$suite = check_suite($_GET["suite"]);
$package = $_GET["p"];
$packages = preg_split('/[ ,]+/', $package);

echo "<h1>Buildd information page</h1>";

echo "<h2>Overview of specific pending items on the various autobuilt architectures</h2>";
archs_overview_links($suite);

echo "<h2>Information about a specific package/multiple packages</h2>";
select_suite($packages, $suite);

html_footer();

?>
