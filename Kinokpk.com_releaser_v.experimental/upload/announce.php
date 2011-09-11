<?php
/**
 * Annouce of tracker
 * @license GNU GPLv3 http://opensource.org/licenses/gpl-3.0.html
 * @package Kinokpk.com releaser
 * @author ZonD80 <admin@kinokpk.com>
 * @copyright (C) 2008-now, ZonD80, Germany, TorrentsBook.com
 * @link http://dev.kinokpk.com
 */

define ('IN_ANNOUNCE', true);
define("ROOT_PATH",dirname(__FILE__).'/');
@error_reporting(E_ALL & ~E_NOTICE);
@ini_set('error_reporting', E_ALL & ~E_NOTICE);
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@ini_set('ignore_repeated_errors', '0');
@ignore_user_abort(1);
@set_time_limit(0);
date_default_timezone_set('UTC');
require_once(ROOT_PATH . 'include/classes.php');
require_once(ROOT_PATH . 'include/benc.php');
require_once(ROOT_PATH . 'include/functions_announce.php');
gzip();
$time=time();
foreach (array('passkey','info_hash','peer_id','event','port','left') as $x) {
	if(isset($_GET[$x]))
	$GLOBALS[$x] = (string)$_GET[$x];
}

$port = intval($port);
$left = intval($left);

foreach (array('passkey','info_hash','peer_id','port') as $x)
	if (!isset($GLOBALS[$x])) err('Missing key: '.$x);
foreach (array('info_hash','peer_id') as $x)
	if (mb_strlen($GLOBALS[$x]) != 20) //20
		err('Invalid '.$x.' (' . mb_strlen($GLOBALS[$x]) . ' - ' . urlencode($GLOBALS[$x]) . ')');
if (mb_strlen($passkey) != 32)
	err('Invalid passkey (' . mb_strlen($passkey) . " - $passkey)");
$ip = getip();
//$announce_wait = 30;
foreach(array('num want', 'numwant', 'num_want') as $k) {
	$rsize = (int) $_GET[$k];
	break;
}

if ($rsize>50) $rsize = 50;
elseif ($rsize<=0) $rsize = 50;

if (!$port || $port > 0xffff || portblacklisted($port))
	err("Invalid or blacklisted port");
if (!isset($event))
	$event = '';
$seeder = ($left == 0) ? 1: 0;

checkclient($peer_id);

INIT();

//$ucls = mysql_query("SELECT id,enabled,passkey_ip FROM users WHERE passkey = " . sqlesc($passkey)) or sqlerr(__FILE__,__LINE__);
$ucls = mysql_query("SELECT id,enabled,torrent_pass FROM users INNER JOIN xbt_users ON users.id = xbt_users.uid WHERE torrent_pass = " . sqlesc($passkey)) or sqlerr(__FILE__,__LINE__);
if (mysql_affected_rows() == 0)
	err('Invalid passkey! Re-download the .torrent from '.$REL_CONFIG['defaultbaseurl']);
//list($userid,$enabled,$passkey_ip,$last_announced) = mysql_fetch_array($ucls);
list($userid,$enabled,$passkey_ip) = mysql_fetch_array($ucls);
if (!$userid) err('Unknown passkey');
//Uncomment// if ($passkey_ip && ($ip != $passkey_ip))
	//Uncomment// err('Unauthorized IP for this passkey!');
//if ($last_announced>$time-$announce_wait) err("You requesting announce to quick, please wait for $announce_wait seconds");

//$hash = bin2hex($info_hash);
$hash = $info_hash;

$res = mysql_query('SELECT torrents.id, banned, snatched.completedat FROM torrents LEFT JOIN snatched ON torrents.id=snatched.torrent WHERE info_hash LIKE "'.$hash.'%"') or sqlerr(__FILE__,__LINE__);
$torrent = mysql_fetch_assoc($res);
if (!$torrent)
	//Uncomment/ err('Torrent not registered with this tracker.');
if ($torrent['banned'])
	err("This torrent banned");	

$torrentid = $torrent['id'];
//$fields = 'seeder, ip, port, userid, last_action';
$fields = 'ipa AS ip, port, xbt_announce_log.mtime AS last_action';
//$limit = '';
$SNcompleted = $torrent['completedat'];
//$selfwhere = "torrent = $torrentid AND userid=$userid";
$selfwhere = "fid = ".sqlesc($torrentid)." AND uid=".sqlesc($userid)."";
$selfwhere_ann = "info_hash=('SELECT info_hash FROM xbt_files WHERE fid=$torrentid') AND uid=".sqlesc($userid)."";

	$cronrow = mysql_query("SELECT cron_value FROM cron WHERE cron_name = 'announce_interval'") or sqlerr(__FILE__,__LINE__);
	$announce_interval = mysql_result($cronrow,0)*4;

$limit = ' ORDER BY RAND() LIMIT '.$rsize;

	//$res = mysql_query('SELECT '.$fields.' FROM peers WHERE torrent = '.$torrentid.' AND userid<>'.$userid.$limit)  or sqlerr(__FILE__,__LINE__);
	$res = mysql_query("SELECT ipa AS ip, port, xbt_announce_log.mtime AS last_action FROM xbt_announce_log  INNER JOIN xbt_files_users ON xbt_announce_log.uid=xbt_files_users.uid WHERE fid=".(int)$torrentid." AND xbt_files_users.uid<>".$userid.$limit) or sqlerr(__FILE__,__LINE__);
$resp = 'd' . benc_str('interval') . 'i' . $announce_interval . 'e' . benc_str('peers') . (($compact = (((int)$_GET['compact'] == 1) || $REL_CONFIG['announce_packed'])) ? '' : 'l');
$no_peer_id = ((int)$_GET['no_peer_id'] == 1);

while($row = mysql_fetch_array($res)) {
	if($compact) {
		$peer_ip = explode('.', $row["ip"]);
		$plist .= pack("C*", $peer_ip[0], $peer_ip[1], $peer_ip[2], $peer_ip[3]). pack("n*", (int) $row["port"]);
	} else {
		$resp .= 'd' .
		benc_str('ip') . benc_str(long2ip($row['ip'])) .
		(!$no_peer_id ? benc_str("peer id") . benc_str("2d5452323333302d6930") : '') .
		benc_str('port') . 'i' . $row['port'] . 'e' . 'e';
	}
}

$updateset = array();
$snatch_updateset = array();

//$selfsql = mysql_query('SELECT seeder FROM peers WHERE '.$selfwhere) or sqlerr(__FILE__,__LINE__);
$selfsql = mysql_query("SELECT active,`left` FROM xbt_files_users WHERE ".$selfwhere) or sqlerr(__FILE__,__LINE__);
$self = mysql_fetch_assoc($selfsql);

if ($event == 'stopped') {
	//mysql_query('DELETE FROM peers WHERE '.$selfwhere) or sqlerr(__FILE__,__LINE__);
	mysql_query("UPDATE LOW_PRIORITY xbt_files_announce active='0' WHERE ".$selfwhere) or sqlerr(__FILE__,__LINE__);
	if (mysql_affected_rows()) {
		if ($self['active'] && $self['left']==0)
			$trupdateset[] = 'seeders = IF(seeders > 0, seeders - 1, 0)';
		else
			$trupdateset[] = 'leechers = IF(leechers > 0, leechers - 1, 0)';
	}
}
if (($event == 'completed' || !$left) && !$SNcompleted) {
	$snatch_updateset[] = "completedat = $time";
	//$updateset[] = 'times_completed = times_completed + 1';
	$snatch_updateset[] = "finished = 1";
}

if ($self['active'] != $seeder) {
	if ($seeder) {
		$trupdateset[] = 'seeders = seeders + 1';
		$trupdateset[] = 'leechers = IF(leechers > 0, leechers - 1, 0)';
	} else {
		$trupdateset[] = 'leechers = leechers + 1';
		$trupdateset[] = 'seeders = IF(seeders > 0, seeders - 1, 0)';
	}
}

$ret = mysql_query("INSERT LOW_PRIORITY INTO snatched (torrent, userid, startedat) VALUES ($torrentid, $userid, $time)");// or sqlerr(__FILE__,__LINE__);
if (!$ret && !$SNcompleted) {
	$snatch_updateset[] = "completedat = $time";
	$snatch_updateset[] = "finished = 1";
}

//
//INSERT or UPDATE peers/seeds data
//
//$ret = mysql_query("INSERT LOW_PRIORITY INTO peers (torrent, peer_id, ip, port, seeder, userid, last_action) VALUES ($torrentid, " . sqlesc($peer_id) . ", " . sqlesc($ip) . ", $port, $seeder, $userid,$time)");// or sqlerr(__FILE__,__LINE__);
$ret = mysql_query("INSERT LOW_PRIORITY INTO xbt_announce_log (peer_id, ipa, port, info_hash,`left0`, uid, mtime) VALUES (".sqlesc($peer_id) . ",".ip2long($ip).", ".sqlesc($port).",".sqlesc($info_hash).",".sqlesc($left).", ".sqlesc($userid).",".sqlesc($time).")");// or sqlerr(__FILE__,__LINE__);
$ret2 = mysql_query("INSERT LOW_PRIORITY INTO xbt_files_users (fid, uid, active, left, mtime) VALUES (".sqlesc($torrentid).",".sqlesc($userid).",'1',".sqlesc($left).",".sqlesc($time).")");
if ($ret2) {
	if ($seeder)
		$trupdateset[] = 'seeders = seeders + 1';
	else
		$trupdateset[] = 'leechers = leechers + 1';
}
else {
	//mysql_query("UPDATE LOW_PRIORITY peers SET port = $port, ip = '$ip', seeder = $seeder, last_action=$time WHERE $selfwhere") or sqlerr(__FILE__,__LINE__);
	mysql_query("UPDATE LOW_PRIORITY xbt_announce_log SET peer_id=".sqlesc($peer_id).", ipa = ".ip2long($ip).", port = ".sqlesc($port).", `left0`=".sqlesc($left).", mtime=".sqlesc($time)." WHERE $selfwhere_ann") or sqlerr(__FILE__,__LINE__);
	mysql_query("UPDATE LOW_PRIORITY xbt_files_users SET fid=".sqlesc($torrentid).", uid=".sqlesc($userid).", active='1', `left`=".sqlesc($left).", mtime=$time WHERE $selfwhere") or sqlerr(__FILE__,__LINE__);
}

if ($seeder) {
	$updateset[] = 'visible = 1';
	$updateset[] = 'last_action = '.$time;
}
if ($trupdateset) {
   	$trupdateset[] = 'lastchecked = '.$time;
	mysql_query('UPDATE LOW_PRIORITY trackers SET ' . join(", ", $trupdateset) . ' WHERE torrent = '.sqlesc($torrentid).' AND tracker="localhost"') or sqlerr(__FILE__,__LINE__);
}

if ($updateset)
	mysql_query('UPDATE LOW_PRIORITY torrents SET ' . join(", ", $updateset) . ' WHERE id = '.sqlesc($torrentid)) or sqlerr(__FILE__,__LINE__);

if ($snatch_updateset)
	mysql_query('UPDATE LOW_PRIORITY snatched SET ' . join(", ", $snatch_updateset) . ' WHERE torrent = '.sqlesc($torrentid).' AND userid = '.sqlesc($userid)) or sqlerr(__FILE__,__LINE__);;

//mysql_query("UPDATE LOW_PRIORITY users SET last_announced = $time WHERE id=$userid") or sqlerr(__FILE__,__LINE__);


if ($_SERVER["HTTP_ACCEPT_ENCODING"] == "gzip" && $REL_CONFIG['use_gzip']) {
	header("Content-Encoding: gzip");
	echo gzencode(benc_resp_raw($resp), 2, FORCE_GZIP);
} else
benc_resp_raw($resp);

?>

