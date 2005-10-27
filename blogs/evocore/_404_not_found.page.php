<?php
/**
 * This page displays an error message when we cannot resolve the extra path.
 *
 * This happens when you request an url of the form http://.../some_stub_file.php/some_malformed_extra_path/...
 *
 * b2evolution - {@link http://b2evolution.net/}
 * Released under GNU GPL License - {@link http://b2evolution.net/about/license.html}
 * @copyright (c)2003-2005 by Francois PLANQUE - {@link http://fplanque.net/}
 *
 * @package evocore
 */

header('HTTP/1.0 404 Not Found');
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
		<title>404 Not Found</title>
	</head>
	<body>
		<h1>404 Not Found</h1>
		<p><?php echo $app_name ?> cannot resolve the requested URL.</p>
		<?php
			debug_info();
		?>
	</body>
</html>
<?php
 	exit;
?>