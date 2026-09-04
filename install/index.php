<?php
/**
 * esoTalk 1.0.3 single-page installer.
 * SQLite is the default backend; MySQL/MariaDB through mysqli remains available.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('IN_ESO', true);
define('PATH_ROOT', dirname(__DIR__));
define('PATH_CONFIG', PATH_ROOT . DIRECTORY_SEPARATOR . 'config');
define('PATH_INSTALL', __DIR__);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$version = defined('ESO_VERSION') ? ESO_VERSION : '1.0.3';
if (is_file(PATH_ROOT . '/config.default.php')) {
	require_once PATH_ROOT . '/config.default.php';
	if (defined('ESO_VERSION')) $version = ESO_VERSION;
}

$languageFiles = array();
foreach ((array)glob(PATH_ROOT . '/languages/*.php') as $languageFile) $languageFiles[] = pathinfo($languageFile, PATHINFO_FILENAME);
$installLanguage = trim((string)($_POST['installLanguage'] ?? 'English (casual)'));
if (!in_array($installLanguage, $languageFiles, true)) $installLanguage = 'English (casual)';
$language = array();
$messages = array();
$languageFile = PATH_ROOT . '/languages/' . $installLanguage . '.php';
if (is_file($languageFile)) include $languageFile;
$installText = isset($language['install']) && is_array($language['install']) ? $language['install'] : array();
$translate = function($key, $fallback) use (&$installText, &$language) {
	return isset($installText[$key]) ? $installText[$key] : (isset($language[$key]) ? $language[$key] : $fallback);
};

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ensureDir(string $path): bool {
	if (!is_dir($path)) @mkdir($path, 0775, true);
	if (!is_dir($path) || !is_writable($path)) return false;
	$test = $path . DIRECTORY_SEPARATOR . '.write_' . bin2hex(random_bytes(4));
	if (@file_put_contents($test, '1') === false) return false;
	@unlink($test);
	return true;
}
function detectBaseUrl(): string {
	$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443');
	$scheme = $https ? 'https' : 'http';
	$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
	$script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'));
	$base = dirname($script);
	if ($base === '/' || $base === '.' || $base === '\\') $base = '';
	return $scheme . '://' . $host . $base . '/';
}
function absoluteSQLitePath(string $path): string {
	$path = trim($path);
	if ($path === '') $path = PATH_CONFIG . '/esotalk.sqlite';
	if ($path === ':memory:') return $path;
	$unixAbsolute = DIRECTORY_SEPARATOR === '/' && substr($path, 0, 1) === '/';
	$windowsAbsolute = preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || substr($path, 0, 2) === '\\\\';
	if ($unixAbsolute || $windowsAbsolute) return $path;
	return PATH_ROOT . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
}
function installQuery($db, string $query): bool {
	if ($db instanceof PDO) return $db->exec($query) !== false;
	return (bool)mysqli_query($db, $query);
}
function installPrepared($db, string $query, array $params): bool {
	if ($db instanceof PDO) {
		$stmt = $db->prepare($query);
		foreach ($params as $i => $value) {
			$type = is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR);
			$stmt->bindValue($i + 1, $value, $type);
		}
		return $stmt->execute();
	}
	$stmt = mysqli_prepare($db, $query);
	if (!$stmt) return false;
	$types = '';
	foreach ($params as $value) $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
	if ($params) {
		$refs = array();
		foreach ($params as $i => &$value) $refs[$i] = &$value;
		array_unshift($refs, $types);
		call_user_func_array(array($stmt, 'bind_param'), $refs);
	}
	$ok = mysqli_stmt_execute($stmt);
	mysqli_stmt_close($stmt);
	return $ok;
}
function closeInstallDb($db): void {
	if ($db instanceof PDO) { $db = null; return; }
	if ($db) mysqli_close($db);
}

$locked = is_file(PATH_INSTALL . DIRECTORY_SEPARATOR . 'lock');
$errors = array();
$success = false;
$baseUrl = detectBaseUrl();
$databaseDriver = strtolower((string)($_POST['databaseDriver'] ?? 'sqlite'));
if (!in_array($databaseDriver, array('sqlite', 'mysqli'), true)) $databaseDriver = 'sqlite';
$sqlitePath = (string)($_POST['sqlitePath'] ?? (PATH_CONFIG . '/esotalk.sqlite'));
$dbHost = trim((string)($_POST['mysqlHost'] ?? '127.0.0.1'));
$dbUser = trim((string)($_POST['mysqlUser'] ?? 'root'));
$dbPass = (string)($_POST['mysqlPass'] ?? '');
$dbName = trim((string)($_POST['mysqlDB'] ?? 'esotalk'));
$forumTitle = trim((string)($_POST['forumTitle'] ?? 'My Forum'));
$forumDescription = trim((string)($_POST['forumDescription'] ?? 'A place to talk'));
$baseURL = rtrim(trim((string)($_POST['baseURL'] ?? $baseUrl)), '/') . '/';
$prefix = trim((string)($_POST['tablePrefix'] ?? ''));
$adminUser = trim((string)($_POST['adminUser'] ?? 'admin'));
$adminEmail = trim((string)($_POST['adminEmail'] ?? ''));
$adminPass = (string)($_POST['adminPass'] ?? '');
$adminPass2 = (string)($_POST['adminPass2'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked && isset($_POST['do_install'])) {
		if ($forumTitle === '') $errors[] = $translate('forumTitleRequired', 'Forum title is required.');
		if ($adminUser === '') $errors[] = $translate('nameRequired', 'Admin username is required.');
		if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = $translate('emailRequired', 'Valid admin email is required.');
		if (strlen($adminPass) < 6) $errors[] = $translate('passwordTooShort', 'Password must be at least 6 characters.');
		if ($adminPass !== $adminPass2) $errors[] = $translate('passwordsDoNotMatch', 'Passwords do not match.');
		if (!preg_match('#^https?://#i', $baseURL)) $errors[] = $translate('baseURLInvalid', 'Base URL must start with http:// or https://.');
		if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix) || strlen($prefix) > 20) $errors[] = $translate('tablePrefixInvalidChars', 'Table prefix may contain only letters, numbers and underscores (maximum 20 characters).');
	foreach (array('config', 'sessions', 'avatars') as $dir) if (!ensureDir(PATH_ROOT . DIRECTORY_SEPARATOR . $dir)) $errors[] = "Folder {$dir}/ is not writable.";

	$db = null;
	try {
		if ($databaseDriver === 'sqlite') {
			if (!extension_loaded('pdo_sqlite')) $errors[] = 'PDO SQLite no está habilitado. Activa extension=pdo_sqlite en php.ini y reinicia Apache.';
			$sqlitePath = absoluteSQLitePath($sqlitePath);
			if (!$errors) {
				if ($sqlitePath !== ':memory:' && !ensureDir(dirname($sqlitePath))) $errors[] = 'El directorio de la base SQLite no permite escritura.';
				if (!$errors) {
					$db = new PDO('sqlite:' . $sqlitePath, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
					$db->exec('PRAGMA foreign_keys = ON');
				}
			}
		} else {
			if (!extension_loaded('mysqli')) $errors[] = 'mysqli no está habilitado. Activa extension=mysqli en php.ini y reinicia Apache.';
			if ($dbName === '') $errors[] = 'El nombre de la base de datos MySQL es obligatorio.';
			if (!$errors) {
				mysqli_report(MYSQLI_REPORT_OFF);
				$db = @mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);
				if (!$db) $errors[] = 'Falló la conexión con MySQL: ' . (mysqli_connect_error() ?: 'unknown error');
				else @mysqli_set_charset($db, 'utf8mb4');
			}
		}

		if (!$errors && $db) {
			$tables = array();
			if ($db instanceof PDO) $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
		else if ($result = mysqli_query($db, 'SHOW TABLES')) while ($row = mysqli_fetch_row($result)) $tables[] = $row[0];
		$expected = array($prefix . 'conversations', $prefix . 'posts', $prefix . 'members', $prefix . 'tags');
		$conflicts = array_intersect($expected, $tables);
		if ($conflicts && empty($_POST['confirmTablePrefix'])) $errors[] = 'Tables already exist with this prefix: ' . implode(', ', $conflicts) . '. Submit again to overwrite them.';
		if ($conflicts && !empty($_POST['confirmTablePrefix'])) {
			// The DDL below drops the application tables before recreating them.
		}
		if (!$errors) {
			$p = $prefix;
			if ($databaseDriver === 'sqlite') {
				$sqls = array(
					"DROP TABLE IF EXISTS `{$p}conversations`",
					"CREATE TABLE `{$p}conversations` (conversationId INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT NOT NULL,slug TEXT DEFAULT NULL,sticky INTEGER NOT NULL DEFAULT 0,locked INTEGER NOT NULL DEFAULT 0,private INTEGER NOT NULL DEFAULT 0,posts INTEGER NOT NULL DEFAULT 0,startMember INTEGER NOT NULL,startTime INTEGER NOT NULL,lastPostMember INTEGER DEFAULT NULL,lastPostTime INTEGER DEFAULT NULL,lastActionTime INTEGER DEFAULT NULL)",
					"CREATE INDEX IF NOT EXISTS `{$p}conversations_startMember` ON `{$p}conversations` (startMember)",
					"CREATE INDEX IF NOT EXISTS `{$p}conversations_lastPostTime` ON `{$p}conversations` (lastPostTime)",
					"CREATE INDEX IF NOT EXISTS `{$p}conversations_sticky` ON `{$p}conversations` (sticky,lastPostTime)",
					"DROP TABLE IF EXISTS `{$p}posts`",
					"CREATE TABLE `{$p}posts` (postId INTEGER PRIMARY KEY AUTOINCREMENT,conversationId INTEGER NOT NULL,memberId INTEGER NOT NULL,time INTEGER NOT NULL,editMember INTEGER DEFAULT NULL,editTime INTEGER DEFAULT NULL,deleteMember INTEGER DEFAULT NULL,title TEXT NOT NULL,content TEXT NOT NULL)",
					"CREATE INDEX IF NOT EXISTS `{$p}posts_memberId` ON `{$p}posts` (memberId)",
					"CREATE INDEX IF NOT EXISTS `{$p}posts_conversationId` ON `{$p}posts` (conversationId)",
					"CREATE INDEX IF NOT EXISTS `{$p}posts_time` ON `{$p}posts` (time)",
					"DROP TABLE IF EXISTS `{$p}status`",
					"CREATE TABLE `{$p}status` (conversationId INTEGER NOT NULL,memberId TEXT NOT NULL,allowed INTEGER NOT NULL DEFAULT 0,starred INTEGER NOT NULL DEFAULT 0,lastRead INTEGER NOT NULL DEFAULT 0,draft TEXT,PRIMARY KEY (conversationId,memberId))",
					"DROP TABLE IF EXISTS `{$p}members`",
					"CREATE TABLE `{$p}members` (memberId INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,email TEXT NOT NULL UNIQUE,password TEXT NOT NULL,salt TEXT NOT NULL DEFAULT '',color INTEGER NOT NULL DEFAULT 1,account TEXT NOT NULL DEFAULT 'Member',language TEXT DEFAULT '',avatarAlignment TEXT NOT NULL DEFAULT 'alternate',avatarFormat TEXT DEFAULT NULL,emailVerified INTEGER NOT NULL DEFAULT 1,emailOnPrivateAdd INTEGER NOT NULL DEFAULT 1,emailOnStar INTEGER NOT NULL DEFAULT 1,disableJSEffects INTEGER NOT NULL DEFAULT 0,disableLinkAlerts INTEGER NOT NULL DEFAULT 1,emoticons INTEGER NOT NULL DEFAULT 0,markedAsRead INTEGER DEFAULT NULL,lastSeen INTEGER DEFAULT NULL,lastAction TEXT DEFAULT NULL,resetPassword TEXT DEFAULT NULL)",
					"DROP TABLE IF EXISTS `{$p}tags`",
					"CREATE TABLE `{$p}tags` (tag TEXT NOT NULL,conversationId INTEGER NOT NULL,PRIMARY KEY (conversationId,tag))",
					"DROP TABLE IF EXISTS `{$p}actions`",
					"CREATE TABLE `{$p}actions` (ip INTEGER NOT NULL,memberId INTEGER DEFAULT NULL,action TEXT NOT NULL,time INTEGER NOT NULL)",
					"DROP TABLE IF EXISTS `{$p}logins`",
					"CREATE TABLE `{$p}logins` (loginId INTEGER PRIMARY KEY AUTOINCREMENT,cookie TEXT DEFAULT NULL,ip INTEGER NOT NULL DEFAULT 0,userAgent TEXT DEFAULT NULL,memberId INTEGER NOT NULL,action TEXT NOT NULL DEFAULT 'login',firstTime INTEGER DEFAULT NULL,lastTime INTEGER DEFAULT NULL)",
					"CREATE INDEX IF NOT EXISTS `{$p}logins_cookie` ON `{$p}logins` (cookie)"
				);
			} else {
				$sqls = array(
					"DROP TABLE IF EXISTS `{$p}conversations`",
					"CREATE TABLE `{$p}conversations` (conversationId int unsigned NOT NULL AUTO_INCREMENT,title varchar(63) NOT NULL,slug varchar(63) DEFAULT NULL,sticky tinyint(1) NOT NULL DEFAULT 0,locked tinyint(1) NOT NULL DEFAULT 0,private tinyint(1) NOT NULL DEFAULT 0,posts smallint(5) unsigned NOT NULL DEFAULT 0,startMember int unsigned NOT NULL,startTime int unsigned NOT NULL,lastPostMember int unsigned DEFAULT NULL,lastPostTime int unsigned DEFAULT NULL,lastActionTime int unsigned DEFAULT NULL,PRIMARY KEY (conversationId),KEY conversations_startMember (startMember),KEY conversations_lastPostTime (lastPostTime),KEY conversations_sticky (sticky,lastPostTime)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
					"DROP TABLE IF EXISTS `{$p}posts`",
					"CREATE TABLE `{$p}posts` (postId int unsigned NOT NULL AUTO_INCREMENT,conversationId int unsigned NOT NULL,memberId int unsigned NOT NULL,time int unsigned NOT NULL,editMember int unsigned DEFAULT NULL,editTime int unsigned DEFAULT NULL,deleteMember int unsigned DEFAULT NULL,title varchar(63) NOT NULL,content mediumtext NOT NULL,PRIMARY KEY (postId),KEY posts_memberId (memberId),KEY posts_conversationId (conversationId),KEY posts_time (time)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
					"DROP TABLE IF EXISTS `{$p}status`",
					"CREATE TABLE `{$p}status` (conversationId int unsigned NOT NULL,memberId varchar(31) NOT NULL,allowed tinyint(1) NOT NULL DEFAULT 0,starred tinyint(1) NOT NULL DEFAULT 0,lastRead smallint unsigned NOT NULL DEFAULT 0,draft text,PRIMARY KEY (conversationId,memberId)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
					"DROP TABLE IF EXISTS `{$p}members`",
					"CREATE TABLE `{$p}members` (memberId int unsigned NOT NULL AUTO_INCREMENT,name varchar(31) NOT NULL,email varchar(63) NOT NULL,password char(60) NOT NULL,salt char(32) NOT NULL DEFAULT '',color tinyint unsigned NOT NULL DEFAULT 1,account enum('Administrator','Moderator','Member','Suspended','Unvalidated') NOT NULL DEFAULT 'Member',language varchar(31) DEFAULT '',avatarAlignment enum('alternate','right','left','none') NOT NULL DEFAULT 'alternate',avatarFormat enum('jpg','png','gif','webp') DEFAULT NULL,emailVerified tinyint(1) NOT NULL DEFAULT 1,emailOnPrivateAdd tinyint(1) NOT NULL DEFAULT 1,emailOnStar tinyint(1) NOT NULL DEFAULT 1,disableJSEffects tinyint(1) NOT NULL DEFAULT 0,disableLinkAlerts tinyint(1) NOT NULL DEFAULT 1,emoticons tinyint(1) NOT NULL DEFAULT 0,markedAsRead int unsigned DEFAULT NULL,lastSeen int unsigned DEFAULT NULL,lastAction varchar(191) DEFAULT NULL,resetPassword char(32) DEFAULT NULL,PRIMARY KEY (memberId),UNIQUE KEY members_name (name),UNIQUE KEY members_email (email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
					"DROP TABLE IF EXISTS `{$p}tags`",
					"CREATE TABLE `{$p}tags` (tag varchar(31) NOT NULL,conversationId int unsigned NOT NULL,PRIMARY KEY (conversationId,tag)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
					"DROP TABLE IF EXISTS `{$p}actions`",
					"CREATE TABLE `{$p}actions` (ip int unsigned NOT NULL,memberId int unsigned DEFAULT NULL,action varchar(63) NOT NULL,time int unsigned NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
					"DROP TABLE IF EXISTS `{$p}logins`",
					"CREATE TABLE `{$p}logins` (loginId int unsigned NOT NULL AUTO_INCREMENT,cookie char(32) DEFAULT NULL,ip int unsigned NOT NULL DEFAULT 0,userAgent varchar(191) DEFAULT NULL,memberId int unsigned NOT NULL,action varchar(63) NOT NULL DEFAULT 'login',firstTime int unsigned DEFAULT NULL,lastTime int unsigned DEFAULT NULL,PRIMARY KEY (loginId),KEY logins_cookie (cookie)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
				);
			}
			foreach ($sqls as $sql) if (!installQuery($db, $sql)) throw new RuntimeException($db instanceof PDO ? 'SQLite schema error.' : mysqli_error($db));
			$hash = password_hash($adminPass, PASSWORD_DEFAULT);
			$now = time();
				if (!installPrepared($db, "INSERT INTO `{$p}members` (memberId,name,email,password,salt,color,account,language,emailVerified) VALUES (1,?,?,?,?,?,'Administrator',?,1)", array($adminUser, $adminEmail, $hash, '', random_int(1, 27), $installLanguage))) throw new RuntimeException('Could not create administrator.');
				$welcomeTitle = sprintf((string)$translate('defaultWelcomeTitle', 'Welcome to %s!'), $forumTitle);
			$slug = trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $forumTitle)), '-') ?: 'welcome';
			if (!installPrepared($db, "INSERT INTO `{$p}conversations` (conversationId,title,slug,sticky,posts,startMember,startTime,lastPostMember,lastPostTime,lastActionTime,private) VALUES (1,?,?,1,1,1,?,1,?,?,0)", array($welcomeTitle, $slug, $now, $now, $now))) throw new RuntimeException('Could not create welcome conversation.');
				$welcomeContent = sprintf((string)$translate('postWelcomeContent', "<p>Welcome to %s!</p>"), $forumTitle, $forumTitle, rtrim($baseURL, '/') . '/index.php/2/', $forumTitle);
				if (!installPrepared($db, "INSERT INTO `{$p}posts` (postId,conversationId,memberId,time,title,content) VALUES (1,1,1,?,?,?)", array($now, $welcomeTitle, $welcomeContent))) throw new RuntimeException('Could not create welcome post.');
				$defaultTags = isset($installText['defaultTags']) && is_array($installText['defaultTags']) ? array_values($installText['defaultTags']) : array('welcome', 'introduction');
				foreach ($defaultTags as $defaultTag) installPrepared($db, "INSERT INTO `{$p}tags` (conversationId,tag) VALUES (?,?)", array(1, $defaultTag));

			$cfg = array(
				'databaseDriver' => $databaseDriver, 'sqlitePath' => $databaseDriver === 'sqlite' ? $sqlitePath : 'config/esotalk.sqlite',
				'mysqlHost' => $dbHost, 'mysqlUser' => $dbUser, 'mysqlPass' => $dbPass, 'mysqlDB' => $dbName,
					'forumTitle' => $forumTitle, 'forumDescription' => $forumDescription, 'language' => $installLanguage, 'baseURL' => $baseURL,
				'tablePrefix' => $prefix, 'cookieName' => 'esotalk', 'cookieExpire' => 2592000, 'skin' => 'Plastic', 'minPasswordLength' => 6,
				'results' => 20, 'postsPerPage' => 20, 'avatarMaxWidth' => 200, 'avatarMaxHeight' => 200, 'avatarThumbWidth' => 64,
				'avatarThumbHeight' => 64, 'useFriendlyURLs' => true, 'useModRewrite' => false, 'characterEncoding' => 'utf8mb4',
				'hashingMethod' => 'bcrypt', 'rootAdmin' => 1, 'verboseFatalErrors' => true
			);
			$phpCfg = "<?php\nif (!defined(\"IN_ESO\")) exit;\n\$config = " . var_export($cfg, true) . ";\n";
			if (@file_put_contents(PATH_CONFIG . '/config.php', $phpCfg) === false) throw new RuntimeException('Could not write config/config.php.');
			@file_put_contents(PATH_CONFIG . '/versions.php', "<?php\nif (!defined(\"IN_ESO\")) exit;\n\$versions = array(\"eso\" => \"" . addslashes($version) . "\");\n");
			if (!is_file(PATH_CONFIG . '/custom.php')) @file_put_contents(PATH_CONFIG . '/custom.php', "<?php\nif (!defined(\"IN_ESO\")) exit;\n");
			if (!is_file(PATH_CONFIG . '/custom.css')) @file_put_contents(PATH_CONFIG . '/custom.css', '');
			@file_put_contents(PATH_CONFIG . '/skin.php', "<?php\nif (!defined(\"IN_ESO\")) exit;\n\$config[\"skin\"] = \"Plastic\";\n");
			$plugins = array('Emoticons');
			if (extension_loaded('gd')) $plugins[] = 'Captcha';
			@file_put_contents(PATH_CONFIG . '/plugins.php', "<?php\nif (!defined(\"IN_ESO\")) exit;\n\$config[\"loadedPlugins\"] = " . var_export($plugins, true) . ";\n");
			@file_put_contents(PATH_INSTALL . '/lock', 'installed ' . date('c'));
			$success = true; $locked = true;
			}
		}
		} catch (Throwable $e) { $errors[] = $e->getMessage(); }
	closeInstallDb($db);
}

$warnings = array();
if (!extension_loaded('gd')) $warnings[] = 'GD está desactivado; los avatares y CAPTCHA pueden no funcionar hasta activar extension=gd.';
if (!extension_loaded('mbstring')) $warnings[] = 'mbstring se recomienda para gestionar UTF-8 correctamente.';
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar esoTalk</title>
<style>body{margin:0;background:#f2f4f7;color:#334;font:15px Arial,sans-serif}.box{max-width:760px;margin:40px auto;background:#fff;padding:28px 34px;box-shadow:0 2px 12px #0002;border-radius:8px}h1{margin-top:0}.sec{border-bottom:1px solid #ddd;margin:22px 0 12px;padding-bottom:7px;font-weight:bold}label{display:block;margin:10px 0 4px}input,select{box-sizing:border-box;width:100%;padding:10px;border:1px solid #bbc5d1;border-radius:4px;font-size:15px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.btn{margin-top:24px;padding:11px 18px;background:#2868b2;color:#fff;border:0;border-radius:4px;font-weight:bold;cursor:pointer}.alert{padding:12px 15px;border-radius:4px;margin:12px 0}.err{background:#fee6e6;color:#8b1e1e}.warn{background:#fff7dc;color:#725400}small{color:#667;display:block;margin-top:4px}@media(max-width:650px){.box{margin:0;border-radius:0;padding:20px}.grid{grid-template-columns:1fr}}</style></head><body><div class="box"><h1>Instalar esoTalk <?=h($version)?></h1>
<?php if ($success): ?><div class="alert warn"><strong>Instalación completada.</strong><p>La base de datos y la cuenta de administrador fueron creadas.</p></div><a class="btn" href="../">Ir al foro</a>
<?php elseif ($locked): ?><div class="alert err"><strong>Ya está instalado.</strong><p>Elimina <code>install/lock</code> sólo si realmente quieres ejecutar de nuevo el instalador.</p></div><a class="btn" href="../">Ir al foro</a>
<?php else: ?><?php if ($errors): ?><div class="alert err"><strong>Corrige lo siguiente:</strong><ul><?php foreach ($errors as $e): ?><li><?=h($e)?></li><?php endforeach; ?></ul></div><?php endif; ?><?php if ($warnings): ?><div class="alert warn"><strong>Notas:</strong><ul><?php foreach ($warnings as $w): ?><li><?=h($w)?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" autocomplete="off"><div class="sec">Idioma</div><label>Idioma del instalador</label><select name="installLanguage"><?php foreach ($languageFiles as $availableLanguage): ?><option value="<?=h($availableLanguage)?>" <?=$availableLanguage === $installLanguage ? 'selected' : ''?>><?=h($availableLanguage)?></option><?php endforeach; ?></select><small>Selecciona el idioma que usará el nuevo foro.</small><div class="sec">Foro</div><label>Título</label><input name="forumTitle" required value="<?=h($forumTitle)?>"><label>Descripción</label><input name="forumDescription" value="<?=h($forumDescription)?>"><label>URL base</label><input name="baseURL" required value="<?=h($baseURL)?>"><div class="sec">Base de datos</div><label>Controlador</label><select name="databaseDriver" id="databaseDriver"><option value="sqlite" <?=$databaseDriver==='sqlite'?'selected':''?>>SQLite (recomendado)</option><option value="mysqli" <?=$databaseDriver==='mysqli'?'selected':''?>>MySQL / MariaDB (mysqli)</option></select><div id="sqliteFields"><label>Ruta del archivo SQLite</label><input name="sqlitePath" value="<?=h($sqlitePath)?>"><small>Ruta absoluta o relativa al directorio del foro. Valor predeterminado: config/esotalk.sqlite.</small></div><div id="mysqlFields"><div class="grid"><div><label>Servidor MySQL</label><input name="mysqlHost" value="<?=h($dbHost)?>"></div><div><label>Nombre de la base de datos</label><input name="mysqlDB" value="<?=h($dbName)?>"></div></div><div class="grid"><div><label>Usuario</label><input name="mysqlUser" value="<?=h($dbUser)?>"></div><div><label>Contraseña</label><input type="password" name="mysqlPass" value="<?=h($dbPass)?>"></div></div></div><label>Prefijo de tablas</label><input name="tablePrefix" value="<?=h($prefix)?>" placeholder="eso_"><div class="sec">Administrador</div><label>Nombre de usuario</label><input name="adminUser" required value="<?=h($adminUser)?>"><label>Correo electrónico</label><input type="email" name="adminEmail" required value="<?=h($adminEmail)?>"><div class="grid"><div><label>Contraseña</label><input type="password" name="adminPass" required minlength="6"></div><div><label>Confirmar</label><input type="password" name="adminPass2" required minlength="6"></div></div><input type="hidden" name="confirmTablePrefix" value="<?=!empty($_POST['confirmTablePrefix'])?'1':''?>"><button class="btn" type="submit" name="do_install" value="1">Instalar foro</button></form><script>(function(){var s=document.getElementById('databaseDriver'),a=document.getElementById('sqliteFields'),b=document.getElementById('mysqlFields');function u(){var x=s.value==='sqlite';a.style.display=x?'block':'none';b.style.display=x?'none':'block'}s.addEventListener('change',u);u()})();</script>
<?php endif; ?></div></body></html>
