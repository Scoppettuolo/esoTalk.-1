<?php
/**
 * esoTalk sitemap generator.
 * Uses the forum database abstraction so SQLite and MySQL/MariaDB behave alike.
 */
define("IN_ESO", 1);
if (!defined("PATH_ROOT")) define("PATH_ROOT", __DIR__);
if (!defined("PATH_CONFIG")) define("PATH_CONFIG", PATH_ROOT . DIRECTORY_SEPARATOR . "config");

require "config.default.php";
@include "config/config.php";
if (!isset($config)) exit;
$config = array_merge($defaultConfig, $config);

if (file_exists("config/versions.php")) require "config/versions.php";
require "lib/functions.php";
require "lib/database.php";

if (!file_exists("sitemap.xml") or filemtime("sitemap.xml") < time() - (int)$config["sitemapCacheTime"] - 200) {
	set_time_limit(0);

	$driver = $config["databaseDriver"] ?? "mysqli";
	$sqlitePath = $config["sqlitePath"] ?? (PATH_CONFIG . "/esotalk.sqlite");
	$db = new Database(
		$config["mysqlHost"] ?? ($config["dbHost"] ?? "localhost"),
		$config["mysqlUser"] ?? ($config["dbUser"] ?? ""),
		$config["mysqlPass"] ?? ($config["dbPass"] ?? ""),
		$config["mysqlDB"] ?? ($config["dbName"] ?? ""),
		$config["characterEncoding"] ?? "utf8mb4",
		$driver,
		$sqlitePath
	);
	if (!$db->connected()) {
		http_response_code(500);
		header("Content-type: text/plain; charset=utf-8");
		echo "The sitemap database connection could not be opened.";
		exit;
	}

	if (!file_exists("sitemap.general.xml")) {
		writeFile("sitemap.general.xml", "<?xml version='1.0' encoding='UTF-8'?><urlset xmlns='http://www.sitemaps.org/schemas/sitemap/0.9'><url><loc>{$config["baseURL"]}</loc><changefreq>hourly</changefreq><priority>1.0</priority></url></urlset>");
	}

	$i = 1;
	if (!defined("URLS_PER_SITEMAP")) define("URLS_PER_SITEMAP", 40000);
	if (!defined("ZLIB")) define("ZLIB", extension_loaded("zlib") ? ".gz" : null);
	$now = time();

	while (true) {
		$filename = "sitemap.conversations.$i.xml" . ZLIB;
		$offset = ($i - 1) * URLS_PER_SITEMAP;
		$query = "SELECT conversationId, slug,
			posts / NULLIF((UNIX_TIMESTAMP() - startTime) / 86400, 0) AS postsPerDay,
			CASE WHEN lastActionTime IS NULL OR lastActionTime=0 THEN startTime ELSE lastActionTime END AS lastUpdated,
			posts
			FROM {$config["tablePrefix"]}conversations
			WHERE private=0
			LIMIT {$offset}," . URLS_PER_SITEMAP;
		$r = $db->query($query);
		if (!$r || !$db->numRows($r)) break;

		$urlset = "<?xml version='1.0' encoding='UTF-8'?><urlset xmlns='http://www.sitemaps.org/schemas/sitemap/0.9'>";
		while (($row = $db->fetchRow($r)) !== false) {
			list($conversationId, $slug, $postsPerDay, $lastUpdated, $posts) = $row;
			$postsPerDay = $postsPerDay === null ? 0 : (float)$postsPerDay;
			$lastUpdated = (int)$lastUpdated ?: $now;
			$urlset .= "<url><loc>{$config["baseURL"]}" . makeLink($conversationId, $slug) . "</loc><lastmod>" . gmdate("Y-m-d\\TH:i:s+00:00", $lastUpdated) . "</lastmod><changefreq>";
			if ($postsPerDay < 0.006) $urlset .= "yearly";
			elseif ($postsPerDay < 0.07) $urlset .= "monthly";
			elseif ($postsPerDay < 0.3) $urlset .= "weekly";
			elseif ($postsPerDay < 3) $urlset .= "daily";
			else $urlset .= "hourly";
			$urlset .= "</changefreq>";
			if ($posts < 50) { }
			elseif ($posts < 100) $urlset .= "<priority>0.6</priority>";
			elseif ($posts < 500) $urlset .= "<priority>0.7</priority>";
			elseif ($posts < 1000) $urlset .= "<priority>0.8</priority>";
			else $urlset .= "<priority>0.9</priority>";
			$urlset .= "</url>";
		}
		$urlset .= "</urlset>";
		if (ZLIB) $urlset = gzencode($urlset, 9);
		writeFile($filename, $urlset);
		$i++;
	}

	$sitemap = "<?xml version='1.0' encoding='UTF-8'?><sitemapindex xmlns='http://www.sitemaps.org/schemas/sitemap/0.9'><sitemap><loc>{$config["baseURL"]}sitemap.general.xml</loc></sitemap>";
	for ($j = 1; $j < $i; $j++) $sitemap .= "<sitemap><loc>{$config["baseURL"]}sitemap.conversations.$j.xml" . ZLIB . "</loc><lastmod>" . gmdate("Y-m-d\\TH:i:s+00:00") . "</lastmod></sitemap>";
	$sitemap .= "</sitemapindex>";
	writeFile("sitemap.xml", $sitemap);
}

header("Content-type: text/xml");
$handle = @fopen("sitemap.xml", "r");
if ($handle) {
	echo fread($handle, filesize("sitemap.xml"));
	fclose($handle);
}
?>
