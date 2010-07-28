<?

require_once("library.php");

$suite = $_GET["suite"];
$package = $_GET["p"];
$archs = $_GET["a"];
$compact = !empty($_GET["compact"]);
$packages = preg_split('/[ ,]+/', $package);

html_header(count($packages) > 1);

page_header($packages);

echo "<div id=\"body\">\n";

if (!empty($package)) pkg_links($packages, $suite);
select_suite($packages, $suite, $archs);
if (!empty($package)) buildd_status($packages, $suite, $archs);

echo "</div>";
html_footer();

?>
