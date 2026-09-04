#!/usr/bin/env php
<?php
/**
 * esoTalk CLI installer.
 * SQLite is the default; MySQL/MariaDB remains available with --driver=mysqli.
 */

if (PHP_SAPI !== "cli") die("This script can only be run from the command line.\n");
define("IN_ESO", true);
chdir(dirname(__DIR__));
require "config.default.php";

$options = getopt("", array(
	"driver::", "sqlite-path::", "host::", "user::", "pass::", "db::", "prefix::",
	"admin-user:", "admin-pass:", "admin-email:", "forum-title:", "forum-description::",
	"base-url::", "language::", "force", "help"
));

if (isset($options["help"]) || !isset($options["admin-user"], $options["admin-pass"], $options["admin-email"], $options["forum-title"])) {
	echo <<<HELP
esoTalk CLI Installer

Required:
  --admin-user=       Administrator username
  --admin-pass=       Administrator password (minimum 6 characters)
  --admin-email=      Administrator email
  --forum-title=      Forum title

Database (optional; SQLite is the default):
  --driver=           sqlite (default) or mysqli
  --sqlite-path=      SQLite file path (default: config/esotalk.sqlite)
  --host=             MySQL/MariaDB host (default: 127.0.0.1)
  --user=             MySQL/MariaDB user (default: root)
  --pass=             MySQL/MariaDB password (default: empty)
  --db=               MySQL/MariaDB database name
  --prefix=           Table prefix (default: empty)
  --force             Replace tables that already exist with this prefix

Other:
  --forum-description=  Forum description
  --base-url=           Full base URL (default: http://localhost/)
  --language=           Language pack (default: English (casual))
  --help                Show this help

Examples:
  php install/cli-install.php --admin-user=admin --admin-pass='S3cureP@ss!' \\
    --admin-email=admin@example.com --forum-title='My Forum'
  php install/cli-install.php --driver=mysqli --host=localhost --user=root --db=esotalk \\
    --admin-user=admin --admin-pass='S3cureP@ss!' --admin-email=admin@example.com --forum-title='My Forum'

HELP;
	exit(isset($options["help"]) ? 0 : 1);
}

$driver = strtolower((string)($options["driver"] ?? "sqlite"));
if (!in_array($driver, array("sqlite", "mysqli"), true)) die("ERROR: --driver must be sqlite or mysqli.\n");
$prefix = (string)($options["prefix"] ?? "");
if (!preg_match("/^[A-Za-z0-9_]*$/", $prefix) || strlen($prefix) > 20) die("ERROR: Invalid table prefix.\n");
$adminUser = (string)$options["admin-user"];
$adminPass = (string)$options["admin-pass"];
$adminEmail = (string)$options["admin-email"];
$forumTitle = trim((string)$options["forum-title"]);
$forumDescription = trim((string)($options["forum-description"] ?? "A place to talk"));
$baseURL = rtrim((string)($options["base-url"] ?? "http://localhost/"), "/") . "/";
$languageName = (string)($options["language"] ?? "English (casual)");
$availableLanguages = array();
foreach ((array)glob(__DIR__ . "/../languages/*.php") as $languageFile) $availableLanguages[] = pathinfo($languageFile, PATHINFO_FILENAME);
if (!in_array($languageName, $availableLanguages, true)) $languageName = "English (casual)";
$language = array();
$messages = array();
$selectedLanguageFile = __DIR__ . "/../languages/" . $languageName . ".php";
if (is_file($selectedLanguageFile)) include $selectedLanguageFile;
$installText = isset($language["install"]) ? $language["install"] : array();
if ($adminUser === "" || $forumTitle === "") die("ERROR: Administrator username and forum title are required.\n");
if (strlen($adminPass) < 6) die("ERROR: Administrator password must contain at least 6 characters.\n");
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) die("ERROR: Invalid administrator email.\n");
if (!preg_match("#^https?://#i", $baseURL)) die("ERROR: --base-url must start with http:// or https://.\n");

foreach (array("config", "sessions", "avatars") as $directory) {
	if (!is_dir($directory) && !@mkdir($directory, 0775, true)) die("ERROR: Cannot create $directory/.\n");
	if (!is_writable($directory)) die("ERROR: $directory/ is not writable.\n");
}

function cliExecute($db, $sql) {
	if ($db instanceof PDO) return $db->exec($sql) !== false;
	return mysqli_query($db, $sql) !== false;
}
function cliPrepared($db, $sql, array $values) {
	if ($db instanceof PDO) {
		$stmt = $db->prepare($sql);
		foreach ($values as $i => $value) $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR));
		return $stmt->execute();
	}
	$stmt = mysqli_prepare($db, $sql);
	if (!$stmt) return false;
	$types = ""; foreach ($values as $value) $types .= is_int($value) ? "i" : (is_float($value) ? "d" : "s");
	if ($values) { $refs = array(); foreach ($values as $i => &$value) $refs[$i] = &$value; array_unshift($refs, $types); call_user_func_array(array($stmt, "bind_param"), $refs); }
	$ok = mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt); return $ok;
}
function cliFail($db, $sql) {
	$error = $db instanceof PDO ? "SQLite error" : mysqli_error($db);
	fwrite(STDERR, "ERROR: $error\nQuery: $sql\n");
	exit(1);
}

if ($driver === "sqlite") {
	if (!extension_loaded("pdo_sqlite")) die("ERROR: PDO SQLite is not enabled.\n");
	$path = trim((string)($options["sqlite-path"] ?? "config/esotalk.sqlite"));
	if ($path === "") $path = "config/esotalk.sqlite";
	$unixAbsolute = DIRECTORY_SEPARATOR === "/" && substr($path, 0, 1) === "/";
		$windowsAbsolute = preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || substr($path, 0, 2) === "\\\\";
		if ($path !== ":memory:" && !$unixAbsolute && !$windowsAbsolute) $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($path, "/\\");
	if ($path !== ":memory:" && !is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true)) die("ERROR: Cannot create the SQLite directory.\n");
	try { $db = new PDO("sqlite:" . $path, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)); $db->exec("PRAGMA foreign_keys = ON"); }
	catch (Throwable $e) { die("ERROR: " . $e->getMessage() . "\n"); }
	$schema = array(
		"DROP TABLE IF EXISTS `{$prefix}conversations`",
		"CREATE TABLE `{$prefix}conversations` (conversationId INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT NOT NULL,slug TEXT DEFAULT NULL,sticky INTEGER NOT NULL DEFAULT 0,locked INTEGER NOT NULL DEFAULT 0,private INTEGER NOT NULL DEFAULT 0,posts INTEGER NOT NULL DEFAULT 0,startMember INTEGER NOT NULL,startTime INTEGER NOT NULL,lastPostMember INTEGER DEFAULT NULL,lastPostTime INTEGER DEFAULT NULL,lastActionTime INTEGER DEFAULT NULL)",
		"CREATE INDEX IF NOT EXISTS `{$prefix}conversations_startMember` ON `{$prefix}conversations` (startMember)",
		"CREATE INDEX IF NOT EXISTS `{$prefix}conversations_lastPostTime` ON `{$prefix}conversations` (lastPostTime)",
		"CREATE INDEX IF NOT EXISTS `{$prefix}conversations_sticky` ON `{$prefix}conversations` (sticky,lastPostTime)",
		"DROP TABLE IF EXISTS `{$prefix}posts`",
		"CREATE TABLE `{$prefix}posts` (postId INTEGER PRIMARY KEY AUTOINCREMENT,conversationId INTEGER NOT NULL,memberId INTEGER NOT NULL,time INTEGER NOT NULL,editMember INTEGER DEFAULT NULL,editTime INTEGER DEFAULT NULL,deleteMember INTEGER DEFAULT NULL,title TEXT NOT NULL,content TEXT NOT NULL)",
		"CREATE INDEX IF NOT EXISTS `{$prefix}posts_memberId` ON `{$prefix}posts` (memberId)",
		"CREATE INDEX IF NOT EXISTS `{$prefix}posts_conversationId` ON `{$prefix}posts` (conversationId)",
		"CREATE INDEX IF NOT EXISTS `{$prefix}posts_time` ON `{$prefix}posts` (time)",
		"DROP TABLE IF EXISTS `{$prefix}status`",
		"CREATE TABLE `{$prefix}status` (conversationId INTEGER NOT NULL,memberId TEXT NOT NULL,allowed INTEGER NOT NULL DEFAULT 0,starred INTEGER NOT NULL DEFAULT 0,lastRead INTEGER NOT NULL DEFAULT 0,draft TEXT,PRIMARY KEY (conversationId,memberId))",
		"DROP TABLE IF EXISTS `{$prefix}members`",
		"CREATE TABLE `{$prefix}members` (memberId INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,email TEXT NOT NULL UNIQUE,password TEXT NOT NULL,salt TEXT NOT NULL DEFAULT '',color INTEGER NOT NULL DEFAULT 1,account TEXT NOT NULL DEFAULT 'Member',language TEXT DEFAULT '',avatarAlignment TEXT NOT NULL DEFAULT 'alternate',avatarFormat TEXT DEFAULT NULL,emailVerified INTEGER NOT NULL DEFAULT 1,emailOnPrivateAdd INTEGER NOT NULL DEFAULT 1,emailOnStar INTEGER NOT NULL DEFAULT 1,disableJSEffects INTEGER NOT NULL DEFAULT 0,disableLinkAlerts INTEGER NOT NULL DEFAULT 1,emoticons INTEGER NOT NULL DEFAULT 0,markedAsRead INTEGER DEFAULT NULL,lastSeen INTEGER DEFAULT NULL,lastAction TEXT DEFAULT NULL,resetPassword TEXT DEFAULT NULL)",
		"DROP TABLE IF EXISTS `{$prefix}tags`",
		"CREATE TABLE `{$prefix}tags` (tag TEXT NOT NULL,conversationId INTEGER NOT NULL,PRIMARY KEY (conversationId,tag))",
		"DROP TABLE IF EXISTS `{$prefix}actions`",
		"CREATE TABLE `{$prefix}actions` (ip INTEGER NOT NULL,memberId INTEGER DEFAULT NULL,action TEXT NOT NULL,time INTEGER NOT NULL)",
		"DROP TABLE IF EXISTS `{$prefix}logins`",
		"CREATE TABLE `{$prefix}logins` (loginId INTEGER PRIMARY KEY AUTOINCREMENT,cookie TEXT DEFAULT NULL,ip INTEGER NOT NULL DEFAULT 0,userAgent TEXT DEFAULT NULL,memberId INTEGER NOT NULL,action TEXT NOT NULL DEFAULT 'login',firstTime INTEGER DEFAULT NULL,lastTime INTEGER DEFAULT NULL)",
		"CREATE INDEX IF NOT EXISTS `{$prefix}logins_cookie` ON `{$prefix}logins` (cookie)"
	);
} else {
	if (!extension_loaded("mysqli")) die("ERROR: mysqli is not enabled.\n");
	$host = (string)($options["host"] ?? "127.0.0.1"); $user = (string)($options["user"] ?? "root"); $pass = (string)($options["pass"] ?? ""); $name = trim((string)($options["db"] ?? ""));
	if ($name === "") die("ERROR: --db is required for --driver=mysqli.\n");
	mysqli_report(MYSQLI_REPORT_OFF); $db = @mysqli_connect($host, $user, $pass, $name);
	if (!$db) die("ERROR: MySQL connection failed: " . (mysqli_connect_error() ?: "unknown error") . "\n");
	mysqli_set_charset($db, "utf8mb4");
	$schema = array(
		"DROP TABLE IF EXISTS `{$prefix}conversations`",
		"CREATE TABLE `{$prefix}conversations` (conversationId int unsigned NOT NULL AUTO_INCREMENT,title varchar(63) NOT NULL,slug varchar(63) DEFAULT NULL,sticky tinyint(1) NOT NULL DEFAULT 0,locked tinyint(1) NOT NULL DEFAULT 0,private tinyint(1) NOT NULL DEFAULT 0,posts smallint(5) unsigned NOT NULL DEFAULT 0,startMember int unsigned NOT NULL,startTime int unsigned NOT NULL,lastPostMember int unsigned DEFAULT NULL,lastPostTime int unsigned DEFAULT NULL,lastActionTime int unsigned DEFAULT NULL,PRIMARY KEY (conversationId),KEY conversations_startMember (startMember),KEY conversations_lastPostTime (lastPostTime),KEY conversations_sticky (sticky,lastPostTime)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
		"DROP TABLE IF EXISTS `{$prefix}posts`",
		"CREATE TABLE `{$prefix}posts` (postId int unsigned NOT NULL AUTO_INCREMENT,conversationId int unsigned NOT NULL,memberId int unsigned NOT NULL,time int unsigned NOT NULL,editMember int unsigned DEFAULT NULL,editTime int unsigned DEFAULT NULL,deleteMember int unsigned DEFAULT NULL,title varchar(63) NOT NULL,content mediumtext NOT NULL,PRIMARY KEY (postId),KEY posts_memberId (memberId),KEY posts_conversationId (conversationId),KEY posts_time (time)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
		"DROP TABLE IF EXISTS `{$prefix}status`",
		"CREATE TABLE `{$prefix}status` (conversationId int unsigned NOT NULL,memberId varchar(31) NOT NULL,allowed tinyint(1) NOT NULL DEFAULT 0,starred tinyint(1) NOT NULL DEFAULT 0,lastRead smallint unsigned NOT NULL DEFAULT 0,draft text,PRIMARY KEY (conversationId,memberId)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
		"DROP TABLE IF EXISTS `{$prefix}members`",
		"CREATE TABLE `{$prefix}members` (memberId int unsigned NOT NULL AUTO_INCREMENT,name varchar(31) NOT NULL,email varchar(63) NOT NULL,password char(60) NOT NULL,salt char(32) NOT NULL DEFAULT '',color tinyint unsigned NOT NULL DEFAULT 1,account enum('Administrator','Moderator','Member','Suspended','Unvalidated') NOT NULL DEFAULT 'Member',language varchar(31) DEFAULT '',avatarAlignment enum('alternate','right','left','none') NOT NULL DEFAULT 'alternate',avatarFormat enum('jpg','png','gif','webp') DEFAULT NULL,emailVerified tinyint(1) NOT NULL DEFAULT 1,emailOnPrivateAdd tinyint(1) NOT NULL DEFAULT 1,emailOnStar tinyint(1) NOT NULL DEFAULT 1,disableJSEffects tinyint(1) NOT NULL DEFAULT 0,disableLinkAlerts tinyint(1) NOT NULL DEFAULT 1,emoticons tinyint(1) NOT NULL DEFAULT 0,markedAsRead int unsigned DEFAULT NULL,lastSeen int unsigned DEFAULT NULL,lastAction varchar(191) DEFAULT NULL,resetPassword char(32) DEFAULT NULL,PRIMARY KEY (memberId),UNIQUE KEY members_name (name),UNIQUE KEY members_email (email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
		"DROP TABLE IF EXISTS `{$prefix}tags`",
		"CREATE TABLE `{$prefix}tags` (tag varchar(31) NOT NULL,conversationId int unsigned NOT NULL,PRIMARY KEY (conversationId,tag)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
		"DROP TABLE IF EXISTS `{$prefix}actions`",
		"CREATE TABLE `{$prefix}actions` (ip int unsigned NOT NULL,memberId int unsigned DEFAULT NULL,action varchar(63) NOT NULL,time int unsigned NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
		"DROP TABLE IF EXISTS `{$prefix}logins`",
		"CREATE TABLE `{$prefix}logins` (loginId int unsigned NOT NULL AUTO_INCREMENT,cookie char(32) DEFAULT NULL,ip int unsigned NOT NULL DEFAULT 0,userAgent varchar(191) DEFAULT NULL,memberId int unsigned NOT NULL,action varchar(63) NOT NULL DEFAULT 'login',firstTime int unsigned DEFAULT NULL,lastTime int unsigned DEFAULT NULL,PRIMARY KEY (loginId),KEY logins_cookie (cookie)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);
}

if ($driver === "sqlite" && !isset($options["force"])) {
	// The schema is intentionally recreated only when explicitly requested through --force.
	// A new SQLite file is safe to initialize without this check.
}
foreach ($schema as $sql) {
	if (!isset($options["force"]) && preg_match('/^DROP TABLE/i', $sql)) continue;
	if (!cliExecute($db, $sql)) cliFail($db, $sql);
}

$hash = password_hash($adminPass, PASSWORD_DEFAULT); $now = time();
if (!cliPrepared($db, "INSERT INTO `{$prefix}members` (memberId,name,email,password,salt,color,account,language,emailVerified) VALUES (1,?,?,?,?,?,'Administrator',?,1)", array($adminUser, $adminEmail, $hash, "", random_int(1, 27), $languageName))) cliFail($db, "admin member");
$welcomeTitleTemplate = isset($installText["defaultWelcomeTitle"]) ? $installText["defaultWelcomeTitle"] : "Welcome to %s!";
$welcomeTitle = sprintf($welcomeTitleTemplate, $forumTitle); $slug = trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $forumTitle)), '-') ?: 'welcome';
if (!cliPrepared($db, "INSERT INTO `{$prefix}conversations` (conversationId,title,slug,sticky,posts,startMember,startTime,lastPostMember,lastPostTime,lastActionTime,private) VALUES (1,?,?,1,1,1,?,1,?,?,0)", array($welcomeTitle, $slug, $now, $now, $now))) cliFail($db, "welcome conversation");
if (!cliPrepared($db, "INSERT INTO `{$prefix}posts` (postId,conversationId,memberId,time,title,content) VALUES (1,1,1,?,?,?)", array($now, $welcomeTitle, sprintf((string)($installText["postWelcomeContent"] ?? "<p>Welcome to %s!</p>"), $forumTitle, $forumTitle, rtrim($baseURL, "/") . "/index.php/2/", $forumTitle)))) cliFail($db, "welcome post");
$defaultTags = isset($installText["defaultTags"]) && is_array($installText["defaultTags"]) ? array_values($installText["defaultTags"]) : array("welcome", "introduction");
foreach ($defaultTags as $defaultTag) cliPrepared($db, "INSERT INTO `{$prefix}tags` (conversationId,tag) VALUES (?,?)", array(1, $defaultTag));

$config = array(
	"databaseDriver" => $driver, "sqlitePath" => $driver === "sqlite" ? $path : "config/esotalk.sqlite",
	"mysqlHost" => $driver === "mysqli" ? $host : "127.0.0.1", "mysqlUser" => $driver === "mysqli" ? $user : "", "mysqlPass" => $driver === "mysqli" ? $pass : "", "mysqlDB" => $driver === "mysqli" ? $name : "",
	"forumTitle" => $forumTitle, "forumDescription" => $forumDescription, "language" => $languageName, "baseURL" => $baseURL, "tablePrefix" => $prefix,
	"cookieName" => "esotalk", "cookieExpire" => 2592000, "skin" => "Plastic", "minPasswordLength" => 6, "results" => 20, "postsPerPage" => 20,
	"avatarMaxWidth" => 200, "avatarMaxHeight" => 200, "avatarThumbWidth" => 64, "avatarThumbHeight" => 64, "useFriendlyURLs" => true, "useModRewrite" => false,
	"characterEncoding" => "utf8mb4", "hashingMethod" => "bcrypt", "rootAdmin" => 1, "verboseFatalErrors" => true
);
$phpConfig = "<?php\nif (!defined(\"IN_ESO\")) exit;\n\$config = " . var_export($config, true) . ";\n";
if (@file_put_contents("config/config.php", $phpConfig) === false) die("ERROR: Could not write config/config.php.\n");
file_put_contents("config/versions.php", "<?php\nif (!defined(\"IN_ESO\")) exit;\n\$versions = array(\"eso\" => \"" . addslashes(ESO_VERSION) . "\");\n");
if (!file_exists("config/skin.php")) file_put_contents("config/skin.php", "<?php\nif (!defined(\"IN_ESO\")) exit;\n\$config[\"skin\"] = \"Plastic\";\n");
if (!file_exists("config/plugins.php")) file_put_contents("config/plugins.php", "<?php\nif (!defined(\"IN_ESO\")) exit;\n\$config[\"loadedPlugins\"] = array(\"Emoticons\");\n");
if (!file_exists("config/custom.php")) file_put_contents("config/custom.php", "<?php\nif (!defined(\"IN_ESO\")) exit;\n");
if (!file_exists("config/custom.css")) file_put_contents("config/custom.css", "");

echo "Installation complete.\nDriver: $driver\nForum: $forumTitle\nAdministrator: $adminUser\n";
?>
