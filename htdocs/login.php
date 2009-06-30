<?php
// $Header: /cvsroot/phpldapadmin/phpldapadmin/htdocs/login.php,v 1.56 2007/12/15 07:50:30 wurley Exp $

/**
 * For servers whose auth_type is set to 'cookie' or 'session'. Pass me the
 * login info and I'll write two cookies, pla_login_dn_X and pla_pass_X where X
 * is the server_id. The cookie_time comes from config.php
 *
 * @package phpLDAPadmin
 */
/**
 */

require './common.php';

$dn = get_request('login_dn');
$pass = get_request('login_pass');
$uid = get_request('uid');

if ($ldapserver->isAnonBindAllowed())
	$anon_bind = get_request('anonymous_bind') == 'on' ? true : false;
else
	$anon_bind = false;

if (! $anon_bind && ! strlen($pass))
	system_message(array(
		'title'=>_('Authenticate to server'),
		'body'=>_('You left the password blank.'),
		'type'=>'warn'),
		'cmd.php?cmd=login_form');

$save_auth_type = $ldapserver->auth_type;

if ($anon_bind) {
	if (DEBUG_ENABLED)
		debug_log('Anonymous Login was posted [%s].',64,$anon_bind);

	$dn = null;
	$pass = null;

/* Checks if the login_attr option is enabled for this host,
   which allows users to login with a simple username like 'jdoe' rather
   than the fully qualified DN, 'uid=jdoe,ou=people,,dc=example,dc=com'. */
} elseif ($ldapserver->isLoginAttrEnabled()) {

	# Is this a login string (printf-style)
	if ($ldapserver->isLoginStringEnabled()) {
		$dn = str_replace('<username>',$uid,$ldapserver->getLoginString());

		if (DEBUG_ENABLED)
			debug_log('LoginStringDN: [%s]',64,$dn);

	} else {
		# This is a standard login_attr

		/* Fake the auth_type of config to do searching. This way, the admin can specify
		   the DN to use when searching for the login_attr user. */
		$ldapserver->auth_type = 'config';

		if ($ldapserver->login_dn)
			$ldapserver->connect();
		else
			$ldapserver->connect(true,'anonymous');

		if (! empty($ldapserver->login_class))
			$filter = sprintf('(&(objectClass=%s)(%s=%s))',$ldapserver->login_class,$ldapserver->login_attr,$uid);
		else
			$filter = sprintf('%s=%s',$ldapserver->login_attr,$uid);

		# Got through each of the BASE DNs and test the login.
		foreach ($ldapserver->getBaseDN() as $base_dn) {
			if (DEBUG_ENABLED)
				debug_log('Searching LDAP with base [%s]',64,$base_dn);

			$result = $ldapserver->search(null,$base_dn,$filter,array('dn'));
			$result = array_pop($result);
			$dn = $result['dn'];

			if ($dn) {
				if (DEBUG_ENABLED)
					debug_log('Got DN [%s] for user ID [%s]',64,$dn,$uid);
				break;
			}
		}

		# If we got here then we werent able to find a DN for the login filter.
		if (! $dn)
			if ($ldapserver->login_fallback_dn)
				$dn = $uid;
			else
				system_message(array(
					'title'=>_('Authenticate to server'),
					'body'=>_('Bad username or password. Please try again.'),
					'type'=>'error'),
					'cmd.php?cmd=login_form');

		# Restore the original auth_type
		$ldapserver->auth_type = $save_auth_type;
	}
}

# We fake a 'config' server auth_type to omit duplicated code
if (DEBUG_ENABLED)
	debug_log('Setting login type to CONFIG with DN [%s]',64,$dn);

$save_auth_type = $ldapserver->auth_type;
$ldapserver->auth_type = 'config';
$ldapserver->login_dn = $dn;
$ldapserver->login_pass = $pass;

# Verify that dn is allowed to login
if (! $ldapserver->userIsAllowedLogin($dn))
	system_message(array(
		'title'=>_('Authenticate to server'),
		'body'=>_('Sorry, you are not allowed to use phpLDAPadmin with this LDAP server.'),
		'type'=>'error'),
		'cmd.php?cmd=login_form');

if (DEBUG_ENABLED)
	debug_log('User is not prohibited from logging in - now bind with DN [%s]',64,$dn);

# Verify that the login is good
if (is_null($dn) && is_null($pass))
	$ds = $ldapserver->connect(false,'anonymous',true);
else
	$ds = $ldapserver->connect(false,'user',true);

if (DEBUG_ENABLED)
	debug_log('Connection returned [%s]',64,$ds);

if (! is_resource($ds)) {
	if ($anon_bind)
		system_message(array(
			'title'=>_('Authenticate to server'),
			'body'=>_('Could not bind anonymously to server.'),
			'type'=>'error'),
			'cmd.php?cmd=login_form');

	else
		system_message(array(
			'title'=>_('Authenticate to server'),
			'body'=>_('Bad username or password. Please try again.'),
			'type'=>'error'),
			'cmd.php?cmd=login_form');

	syslog_notice("Authentification FAILED for $dn");
}

$ldapserver->auth_type = $save_auth_type;
$ldapserver->setLoginDN($dn,$pass,$anon_bind) or pla_error(_('Could not set cookie.'));
set_lastactivity($ldapserver);

if (! $anon_bind) {
	syslog_notice("Authentification successful for $dn");
}

# Since we were successful, clear the cache so that it will be refreshed with the new creditentials.
del_cached_item($ldapserver->server_id,'tree','null');

system_message(array(
	'title'=>_('Authenticate to server'),
	'body'=>_('Successfully logged into server.').($anon_bind ? sprintf(' (%s)',_('Anonymous Bind')) : ''),
	'type'=>'info'),
	'index.php');
?>