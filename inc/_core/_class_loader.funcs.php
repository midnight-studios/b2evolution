<?php
/**
 * Functions for autoloading Classes in PHP 5 and above.
 *
 * This file is part of the evoCore framework - {@link http://evocore.net/}
 * See also {@link https://github.com/b2evolution/b2evolution}.
 *
 * @license GNU GPL v2 - {@link http://b2evolution.net/about/gnu-gpl-license}
 *
 * @copyright (c)2009-2016 by Francois Planque - {@link http://fplanque.com/}
 * Parts of this file are copyright (c)2009 by Daniel HAHLER - {@link http://daniel.hahler.de/}.
 *
 * @package evocore
 */
if( !defined('EVO_MAIN_INIT') ) die( 'Please, do not access this page directly.' );

/**
 * Dynamic list of class mapping.
 *
 * Controllers should call load_class() to register classes they may need to have autoloaded when they use them.
 *
 * @var marray
 */
$map_class_path = array();

/**
 * Autoload the required .class.php file when a class is accessed but not defined yet.
 * This gets hooked into spl_autoload_register (preferred) or called through __autoload.
 * Requires PHP5.
 */
function evocms_autoload_class( $classname )
{
	global $map_class_path;

	$classname = strtolower($classname);
	if( isset($map_class_path[$classname]) )
	{
		require_once $map_class_path[$classname];
	}
}


/*
 * Use spl_autoload_register mechanism, if available (PHP>=5.1.2).
 * This way a stacked set of autoload functions can be used.
 */
if( function_exists('spl_autoload_register') )
{
	// spl_autoload_register( 'var_dump' );
	spl_autoload_register( 'evocms_autoload_class' );
}
else
{
	// PHP<5.1.2: Use the fallback method. Function in a separate file as by simply adding it here would generate deprecated warning
	load_funcs( '_core/fallback/_autoload.funcs.php' );
}


/**
 * In PHP4, this immediately loaded the class. In PHP5, it's smarter than that:
 * It only registers the class & file name so that PHP can later load the class
 * IF and ONLY IF the class is actually needed during execution.
 */
function load_class( $class_path, $classname, $relative_to = 'inc' )
{
	global $map_class_path;
	
	switch( $relative_to )
	{
		case 'plugins':// detect which plugin requested this, then load the class
			
			global $plugins_path;
			
				//PHP 4 >= 4.3.0, PHP 5, PHP 7
				$backtrace = debug_backtrace();

				foreach( $backtrace as $l_trace )
				{
					if( isset( $l_trace['file'] ) && isset( $l_trace['function'] ) && $l_trace['function'] == 'load_class' )
					{
						$sub_path = preg_replace( ':^'.preg_quote(  $plugins_path, ':' ).':', '', $l_trace['file'] );
						$path_parts = explode( '/', $sub_path );
						$plugin_folder = isset($path_parts[0]) ? $path_parts[0] : NULL;
						$source = trailing_slash( $plugins_path.$plugin_folder );
						break;
					}
				}
					global $Debuglog;
					// This is a class loader, make sure $Debuglog exists already
					if( ! empty( $Debuglog ) )
					{
						if( ! file_exists( $source ) )
						{
							$Debuglog->add( sprintf( T_('file [%s] does not exist'), $source.$class_path ), 'Load class' );

							// Last try
							$source = $plugins_path;

							if( ! file_exists( $source ) )
							{
								$Debuglog->add( sprintf( T_('file [%s] does not exist'), $source.$class_path ), 'Load class' );
								break;
							}

						}
						else
						{
							$Debuglog->add( sprintf( T_('Class [%s] load is requested from plugin [%s]'), $classname, $plugin_folder ), 'Load class' );
							$Debuglog->add( sprintf( T_('Class [%s]: %s'), $classname, $source.$class_path ), 'Load class' );
						}
					}

			break;
			
		default: // The normal way that is backwards compatible
			
			global $inc_path;
				
			$source = $inc_path;
			
			break;	
	}
	
	if( !is_null($classname) )
	{
		$map_class_path[strtolower($classname)] = $source.$class_path;
	}
	return true;
}

?>
