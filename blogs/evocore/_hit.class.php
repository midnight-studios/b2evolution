<?php
/**
 * This file implements the Hit class.
 *
 * This file is part of the b2evolution/evocms project - {@link http://b2evolution.net/}.
 * See also {@link http://sourceforge.net/projects/evocms/}.
 *
 * @copyright (c)2003-2005 by Francois PLANQUE - {@link http://fplanque.net/}.
 * Parts of this file are copyright (c)2004-2005 by Daniel HAHLER - {@link http://thequod.de/contact}.
 *
 * @license http://b2evolution.net/about/license.html GNU General Public License (GPL)
 * {@internal
 * b2evolution is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * b2evolution is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with b2evolution; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 * }}
 *
 * {@internal
 * Daniel HAHLER grants Francois PLANQUE the right to license
 * Daniel HAHLER's contributions to this file and the b2evolution project
 * under any OSI approved OSS license (http://www.opensource.org/licenses/).
 * }}
 *
 * @package evocore
 *
 * {@internal Below is a list of authors who have contributed to design/coding of this file: }}
 * @author blueyed: Daniel HAHLER.
 * @author fplanque: Francois PLANQUE.
 *
 * @version $Id$
 *
 */
if( !defined('EVO_MAIN_INIT') ) die( 'Please, do not access this page directly.' );


/**
 * A hit to a blog.
 *
 * NOTE: The internal function double_check_referer() uses the class Net_IDNA_php4 from /blogs/lib/_idna_convert.class.php.
 *       It's required() only, when needed.
 */
class Hit
{
	/**
	 * Is the hit already logged?
	 * @var boolean
	 */
	var $logged = false;

	/**
	 * The type of referer.
	 *
	 * @var string 'search'|'blacklist'|'referer'|'direct'|'spam'
	 */
	var $referer_type;

	/**
	 * The ID of the referer's base domain in T_basedomains
	 *
	 * @var integer
	 */
	var $referer_domain_ID = 0;

	/**
	 * Is this a reload?
	 * This gets lazy-filled by {@link is_new_view()}.
	 * @var boolean
	 * @access protected
	 */
	var $_is_new_view;

	/**
	 * Ignore this hit?
	 * @var boolean
	 */
	var $ignore = false;

	/**
	 * Remote address (IP).
	 * @var string
	 */
	var $IP;

	/**
	 * The user agent.
	 * @var string
	 */
	var $user_agent;

	/**
	 * The user's remote host.
	 * Use {@link get_remote_host()} to access it (lazy filled).
	 * @var string
	 * @access protected
	 */
	var $_remoteHost;

	/**
	 * The user agent type.
	 *
	 * The default setting ('unknown') is taken for new entries (into T_useragents),
	 * that are not detected as 'rss', 'robot' or 'browser'.
	 *
	 * @var string 'rss'|'robot'|'browser'|'unknown'
	 */
	var $agent_type = 'unknown';

	/**
	 * The ID of the user agent entry in T_useragents.
	 * @var integer
	 */
	var $agent_ID;

	/**#@+
	 * @var integer|NULL Detected browser.
	 */
	var $is_lynx;
	var $is_gecko;
	var $is_winIE;
	var $is_macIE;
	var $is_opera;
	var $is_NS4;
	/**#@-*/


	/**
	 * Constructor
	 */
	function Hit()
	{
		global $Debuglog, $DB;
		global $comments_allowed_uri_scheme;

		// Get the first IP in the list of REMOTE_ADDR and HTTP_X_FORWARDED_FOR
		$this->IP = get_ip_list( true );

		// Check the referer:
		$this->detect_referer();
		$this->referer_basedomain = getBaseDomain($this->referer);

		if( $this->referer_basedomain )
		{
			$basedomain = $DB->get_row( '
				SELECT dom_ID, dom_status FROM T_basedomains
				 WHERE dom_name = "'.$DB->escape($this->referer_basedomain).'"' );
			if( $basedomain )
			{
				$this->referer_domain_ID = $basedomain->dom_ID;
				if( $basedomain->dom_status == 'blacklist' )
				{
					$this->referer_type = 'blacklist';
				}
			}
			else
			{
				$DB->query( '
					INSERT INTO T_basedomains (dom_name, dom_status)
						VALUES( "'.$DB->escape($this->referer_basedomain).'",
						"'.( $this->referer_type == 'blacklist' ? 'blacklist' : 'unknown' ).'" )' );
				$this->referer_domain_ID = $DB->insert_id;
			}
		}

		$this->detect_useragent();


		$Debuglog->add( 'IP: '.$this->IP, 'hit' );
		$Debuglog->add( 'UserAgent: '.$this->user_agent, 'hit' );
		$Debuglog->add( 'Referer: '.var_export($this->referer, true).'; type='.$this->referer_type, 'hit' );
		$Debuglog->add( 'Remote Host: '.$this->get_remote_host(), 'hit' );
	}


	/**
	 * Detect Referer (sic!).
	 * Due to potential non-thread safety with getenv() (fallback), we'd better do this early.
	 *
	 * referer_type: enum('search', 'blacklist', 'referer', 'direct', 'spam')
	 */
	function detect_referer()
	{
		global $HTTP_REFERER; // might be set by PHP (give highest priority)
		global $Debuglog;
		global $comments_allowed_uri_scheme; // used to validate the Referer
		global $blackList, $search_engines;  // used to detect $referer_type

		if( isset( $HTTP_REFERER ) )
		{ // Referer provided by PHP:
			$this->referer = $HTTP_REFERER;
		}
		else
		{
			if( isset($_SERVER['HTTP_REFERER']) )
			{
				$this->referer = $_SERVER['HTTP_REFERER'];
			}
			else
			{ // Fallback method (not thread safe :[[ ) - this function does not work in ISAPI mode.
				$this->referer = getenv('HTTP_REFERER');
			}
		}


		// Check if the referer is valid and is not blacklisted:
		if( $error = validate_url( $this->referer, $comments_allowed_uri_scheme ) )
		{
			$Debuglog->add( 'detect_referer(): '.$error, 'hit');
			$this->referer_type = 'spam'; // Hazardous
			$this->referer = false;
			// QUESTION: add domain to T_basedomains, type 'blacklist' ?

			// This is most probably referer spam,
			// In order to preserve server resources, we're going to stop processing immediatly!!
			require dirname(__FILE__).'/_referer_spam.page.php';	// error & exit
			// THIS IS THE END!!
		}


		// Check blacklist, see {@link $blackList}
		// NOTE: This is NOT the antispam!!
		// fplanque: we log these again, because if we didn't we woudln't detect
		// reloads on these... and that would be a problem!
		foreach( $blackList as $lBlacklist )
		{
			if( strpos( $this->referer, $lBlacklist ) !== false )
			{
				$Debuglog->add( 'detect_referer(): blacklist ('.$lBlacklist.')', 'hit' );
				$this->referer_type = 'blacklist';
				return;
			}
		}


		// Is the referer a search engine?
		foreach( $search_engines as $lSearchEngine )
		{
			if( stristr($this->referer, $lSearchEngine) )
			{
				$Debuglog->add( 'detect_referer(): search engine ('.$lSearchEngine.')', 'hit' );
				$this->referer_type = 'search';
				return;
			}
		}

		if( !empty($this->referer) )
		{
			$this->referer_type = 'referer';
		}
		else
		{
			$this->referer_type = 'direct';
		}
	}


	/**
	 * Set {@link $user_agent} and detect the browser.
	 * This function also handles the relations with T_useragents and sets {@link $agent_type}.
	 */
	function detect_useragent()
	{
		global $HTTP_USER_AGENT; // might be set by PHP, give highest priority
		global $DB, $Debuglog;
		global $user_agents;
		global $skin; // to detect agent_type (gets set in /xmlsrv/atom.php for example)


		if( isset($HTTP_USER_AGENT) )
		{
			$this->user_agent = $HTTP_USER_AGENT;
		}
		elseif( isset($_SERVER['HTTP_USER_AGENT']) )
		{
			$this->user_agent = $_SERVER['HTTP_USER_AGENT'];
		}

		if( !empty($this->user_agent) )
		{ // detect browser
			if(strpos($this->user_agent, 'Lynx') !== false)
			{
				$this->is_lynx = 1;
				$this->agent_type = 'browser';
			}
			elseif(strpos($this->user_agent, 'Gecko') !== false)
			{
				$this->is_gecko = 1;
				$this->agent_type = 'browser';
			}
			elseif(strpos($this->user_agent, 'MSIE') !== false && strpos($this->user_agent, 'Win') !== false)
			{
				$this->is_winIE = 1;
				$this->agent_type = 'browser';
			}
			elseif(strpos($this->user_agent, 'MSIE') !== false && strpos($this->user_agent, 'Mac') !== false)
			{
				$this->is_macIE = 1;
				$this->agent_type = 'browser';
			}
			elseif(strpos($this->user_agent, 'Opera') !== false)
			{
				$this->is_opera = 1;
				$this->agent_type = 'browser';
			}
			elseif(strpos($this->user_agent, 'Nav') !== false || preg_match('/Mozilla\/4\./', $this->user_agent))
			{
				$this->is_NS4 = 1;
				$this->agent_type = 'browser';
			}

			if( $this->user_agent != strip_tags($this->user_agent) )
			{ // then they have tried something funky, putting HTML or PHP into the user agent
				$Debuglog->add( 'detect_useragent(): '.T_('bad char in User Agent'), 'hit');
				$this->user_agent = '';
			}
		}
		$this->is_IE = (($this->is_macIE) || ($this->is_winIE));


		/*
		 * Detect requests for XML feeds by $skin / $tempskin param.
		 * Use $skin, if not empty (may be set in /xmlsrv/atom.php for example), otherwise $tempskin.
		 */
		$used_skin = empty( $skin ) ? param( 'tempskin', 'string', '', true ) : $skin;
		if( in_array( $used_skin, array( '_atom', '_rdf', '_rss', '_rss2' ) ) )
		{
			$Debuglog->add( 'detect_useragent(): RSS', 'hit' );
			$this->agent_type = 'rss';
		}
		else
		{ // Lookup robots
			foreach( $user_agents as $lUserAgent )
			{
				if( ($lUserAgent[0] == 'robot') && (strstr($this->user_agent, $lUserAgent[1])) )
				{
					$Debuglog->add( 'detect_useragent(): robot', 'hit' );
					$this->agent_type = 'robot';
				}
			}
		}


		if( $agnt_data = $DB->get_row( '
			SELECT agnt_ID FROM T_useragents
			 WHERE agnt_signature = "'.$DB->escape( $this->user_agent ).'"
			   AND agnt_type = "'.$this->agent_type.'"' ) )
		{ // this agent (with that type) hit us once before, re-use ID
			$this->agent_ID = $agnt_data->agnt_ID;
		}
		else
		{ // create new user agent entry
			$DB->query( '
				INSERT INTO T_useragents ( agnt_signature, agnt_type )
				VALUES ( "'.$DB->escape( $this->user_agent ).'", "'.$this->agent_type.'" )' );

			$this->agent_ID = $DB->insert_id;
		}
	}


	/**
	 * Log a hit on a blog page / rss feed.
	 *
	 * This function should be called at the end of the page, otherwise if the page
	 * is displaying previous hits, it may display the current one too.
	 *
	 * The hit will not be logged in special occasions, see {@link $ignore} and {@link is_good_hit()}.
	 *
	 * @return boolean true if the hit gets logged; false if not
	 */
	function log()
	{
		global $Debuglog, $DB, $blog, $debug_no_register_shutdown;
		global $Settings;

		if( $this->logged )
		{
			return false;
		}

		if( $this->ignore || ! $this->is_good_hit() )
		{ // We don't want to log this hit!
			$hit_info = 'referer_type: '.var_export($this->referer_type, true)
				.', agent_type: '.var_export($this->agent_type, true)
				#.', is'.( $this->is_new_view() ? '' : ' NOT' ).' a new view'
				.', is'.( $this->ignore ? '' : ' NOT' ).' ignored'
				.', is'.( $this->is_good_hit() ? '' : ' NOT' ).' a good hit';
			$Debuglog->add( 'log(): Hit NOT logged, ('.$hit_info.')', 'hit' );
			return false;
		}

		if( $this->referer_type == 'referer' && $Settings->get('hit_doublecheck_referer') )
		{
			if( !$debug_no_register_shutdown && function_exists( 'register_shutdown_function' ) )
			{ // register it as a shutdown function, because it will be slow!
				$Debuglog->add( 'log(): double-check: loading referering page.. (register_shutdown_function())', 'hit' );
				register_shutdown_function( array( &$this, 'double_check_referer' ) ); // this will also call _record_the_hit()
			}
			else
			{
				// flush now, so that the meat of the page will get shown before it tries to check
				// back against the refering URL.
				flush();

				$Debuglog->add( 'log(): double-check: loading referering page..', 'hit' );

				$this->double_check_referer(); // this will also call _record_the_hit()
			}
		}
		else
		{
			$this->_record_the_hit();
		}

		// Remember we have logged already:
		$this->logged = true;

		return true;
	}


	/**
	 * This records the hit. You should not call this directly, but {@link log()}!
	 *
	 * It gets called either by {@link log()} or by {@link double_check_referer()} when this is used.
	 *
	 * It will call Hitlist::dbprune() to do the automatic pruning of old hits.
	 *
	 * @access protected
	 */
	function _record_the_hit()
	{
		global $DB, $Session, $ReqURI, $Blog, $localtimenow, $Debuglog;

		$referer_basedomain = getBaseDomain( $this->referer );

		$Debuglog->add( 'log(): Recording the hit.', 'hit' );

		// insert hit into DB table:
		$sql = '
			INSERT INTO T_hitlog( hit_sess_ID, hit_datetime, hit_uri, hit_referer_type,
				hit_referer, hit_referer_dom_ID, hit_blog_ID, hit_remote_addr )
			VALUES( "'.$Session->ID.'", FROM_UNIXTIME('.$localtimenow.'), "'.$DB->escape($ReqURI).'",
				"'.$this->referer_type.'", "'.$DB->escape($this->referer).'",
				"'.$this->referer_domain_ID.'", "'.$Blog->ID.'", "'.$DB->escape( $this->IP ).'"
			)';

		$DB->query( $sql, 'Record the hit' );

		require_once( dirname(__FILE__).'/_hitlist.class.php' );
		Hitlist::dbprune(); // will prune once per day, according to Settings
	}


	/**
	 * This function gets called (as a {@link register_shutdown_function() shutdown function}, if possible) and checks
	 * if the referering URL's content includes the current URL - if not it is probably spam!
	 *
	 * On success, this methods records the hit.
	 *
	 * TODO: use DB cache to avoid checking the same page again and again!
	 * TODO: transform into plugin (blueyed)
	 *
	 * @uses _record_the_hit()
	 */
	function double_check_referer()
	{
		global $ReqURI, $Debuglog;
		global $core_dirout, $lib_subdir;

		if( !empty($this->referer) )
		{
			if( ($fp = @fopen( $this->referer, 'r' )) )
			{
				socket_set_timeout($fp, 5); // timeout after 5 seconds
				// Get the refering page's content
				$content_ref_page = '';
				$bytes_read = 0;
				while( ($l_byte = fgetc($fp)) !== false )
				{
					$content_ref_page .= $l_byte;
					if( ++$bytes_read > 512000 )
					{ // do not pull more than 500kb of data!
						break;
					}
				}

				/**
				 * IDNA converter class
				 */
				require_once dirname(__FILE__).'/'.$core_dirout.$lib_subdir.'_idna_convert.class.php';
				$IDNA = new Net_IDNA_php4();

				// Build the search pattern.
				// We match for basically for 'href="[SERVER]|[REQ_URI]', where [SERVER]
				$search_pattern = '~\shref=["\']?https?://(';
				$possible_hosts = array( $_SERVER['HTTP_HOST'] );
				if( $_SERVER['SERVER_NAME'] != $_SERVER['HTTP_HOST'] )
				{
					$possible_hosts[] = $_SERVER['SERVER_NAME'];
				}
				$search_pattern_hosts = array();
				foreach( $possible_hosts as $l_host )
				{
					if( preg_match( '~^([^.]+\.)(.*?)([^.]+\.[^.]+)$~', $l_host, $match ) )
					{ // we have subdomains in this hostname
						if( stristr( $match[1], 'www' ) )
						{ // search also for hostname without 'www.'
							$search_pattern_hosts[] = $match[2].$match[3];
						}
					}
					$search_pattern_hosts[] = $l_host;
				}
				$search_pattern_hosts = array_unique($search_pattern_hosts);
				foreach( $search_pattern_hosts as $l_host )
				{ // add IDN, because this is probably linked
					$l_idn_host = $IDNA->decode( $l_host ); // the decoded puny-code ("xn--..") name (utf8)

					if( $l_idn_host != $l_host )
					{
						$search_pattern_hosts[] = $l_idn_host;
					}
				}

				// add hosts to pattern, preg_quoted
				for( $i = 0, $n = count($search_pattern_hosts); $i < $n; $i++ )
				{
					$search_pattern_hosts[$i] = preg_quote( $search_pattern_hosts[$i], '~' );
				}
				$search_pattern .= implode( '|', $search_pattern_hosts ).')'.$ReqURI.'~i';


				// TODO: handle encoding of the refering page (iconv/recode), if we have decoded base name, $content_ref_page must be utf8
				if( preg_match( $search_pattern, $content_ref_page ) )
				{
					$Debuglog->add( 'double_check_referer(): found current url in page ('.bytesreadable($bytes_read).' read)', 'hit' );
				}
				else
				{
					$Debuglog->add( 'double_check_referer(): '.sprintf('did not find &laquo;%s&raquo; in &laquo;%s&raquo; (%s bytes read). -> referer_type=spam!', $search_pattern, $this->referer, bytesreadable($bytes_read) ), 'hit' );
					$this->referer_type = 'spam';
				}
				unset( $content_ref_page );
			}
			else
			{ // This was probably spam!
				$Debuglog->add( 'double_check_referer(): could not access &laquo;'.$this->referer.'&raquo;', 'hit' );
				$this->referer_type = 'spam';
			}
		}

		$this->_record_the_hit();

		return true;
	}


	/**
	 * Get the User agent's signature.
	 *
	 * @return string
	 */
	function get_user_agent()
	{
		return $this->user_agent;
	}


	/**
	 * Get the remote hostname.
	 *
	 * @return string
	 */
	function get_remote_host()
	{
		if( is_null($this->_remoteHost) )
		{
			if( isset( $_SERVER['REMOTE_HOST'] ) )
			{
				$this->_remoteHost = $_SERVER['REMOTE_HOST'];
			}
			else
			{
				$this->_remoteHost = @gethostbyaddr($this->IP);
			}
		}

		return $this->_remoteHost;
	}


	/**
	 * Determine if a hit is a new view (not reloaded, (internally) ignored or from a robot).
	 *
	 * 'Reloaded' means: visited before from the same user (in a session) or from same IP/user_agent in the
	 * last {@link $Settings reloadpage_timeout} seconds.
	 *
	 * This gets queried by the Item objects before incrementing its view count (if the Item gets viewed
	 * in total ({@link $dispmore})).
	 *
	 * @todo fplanque>> if this is only useful to display who's online or view counts, provide option to disable all those resource consuming gadgets. (Those gadgets should be plugins actually, and they should enable this query only if needed)
	 *        blueyed>> Move functionality to Plugin (with a hook in Item::content())?!
	 * @return boolean
	 */
	function is_new_view()
	{
		if( $this->ignore || $this->agent_type == 'robot' )
		{
			return false;
		}

		if( ! isset($this->_is_new_view) )
		{
			global $current_User;
			global $DB, $Debuglog, $Settings, $ReqURI, $localtimenow;

			// Restrict to current user if logged in:
			if( ! empty($current_User->ID) )
			{ // select by user ID: one user counts really just once. May be even faster than the anonymous query below..!?
				$sql = '
					SELECT hit_ID FROM T_hitlog INNER JOIN T_sessions ON hit_sess_ID = sess_ID
					 WHERE sess_user_ID = '.$current_User->ID.'
						 AND hit_uri = "'.$DB->escape( $ReqURI ).'"
					 LIMIT 1';
			}
			else
			{ // select by remote_addr/agnt_signature:
				$sql = 'SELECT hit_ID FROM T_hitlog INNER JOIN T_sessions ON hit_sess_ID = sess_ID
							 INNER JOIN T_useragents ON sess_agnt_ID = agnt_ID
				 WHERE hit_datetime > "'.date( 'Y-m-d H:i:s', $localtimenow - $Settings->get('reloadpage_timeout') ).'"
					 AND hit_remote_addr = '.$DB->quote( $this->IP ).'
					 AND hit_uri = "'.$DB->escape( $ReqURI ).'"
					 AND agnt_signature = '.$DB->quote($this->user_agent).'
				 LIMIT 1';
			}
			if( $DB->get_var( $sql, 0, 0, 'Hit: Check for reload' ) )
			{
				$Debuglog->add( 'No new view!', 'hit' );
				$this->_is_new_view = true;  // We don't want to log this hit again
			}
			else
			{
				$this->_is_new_view = false;
			}
		}

		return $this->_is_new_view;
	}


	/**
	 * Is this a good hit? This means "no spam".
	 *
	 * @return boolean
	 */
	function is_good_hit()
	{
		return ( $this->referer_type != 'spam' );
	}


	/**
	 * Is this a browser reload (F5)?
	 *
	 * @return boolean true on reload, false if not.
	 */
	function is_browser_reload()
	{
		if( ( isset( $_SERVER['HTTP_CACHE_CONTROL'] ) && strpos( $_SERVER['HTTP_CACHE_CONTROL'], 'max-age=0' ) !== false )
			|| ( isset( $_SERVER['HTTP_PRAGMA'] ) && $_SERVER['HTTP_PRAGMA'] == 'no-cache' ) )
		{ // Reload
			return true;
		}

		return false;
	}

}
?>