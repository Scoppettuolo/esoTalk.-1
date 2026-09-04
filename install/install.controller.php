<?php
/**
 * Legacy installer compatibility shim.
 *
 * The maintained installer is install/index.php. The old multi-step controller
 * was MySQL-only and could create a configuration that the current runtime did
 * not understand, so legacy requests are delegated instead of running a second
 * installation implementation.
 */
if (!defined("IN_ESO")) exit;

function getInstallLanguages($langDir = null) {
	if ($langDir === null) $langDir = defined("PATH_ROOT") ? PATH_ROOT . "/languages" : dirname(__DIR__) . "/languages";
	$languages = array();
	if (!is_dir($langDir)) return $languages;
	foreach (glob(rtrim($langDir, "/\\") . "/*.php") ?: array() as $file) {
		$name = basename($file, ".php");
		if (substr($name, 0, 1) === ".") continue;
		$language = array();
		include $file;
		if (isset($language["install"])) $languages[] = $name;
	}
	sort($languages);
	return $languages;
}

class Install {
	public $errors = array();
	public $step = "redirect";

	public function init() {
		header("Location: index.php");
		exit;
	}

	public function ajax() {
		global $language;
		$action = (string)($_POST["action"] ?? "");
		if ($action === "changeLanguage") {
			if (!isset($_POST["token"]) || !hash_equals((string)($_SESSION["token"] ?? ""), (string)$_POST["token"])) return array("success" => false, "message" => $language["Invalid security token"] ?? "Invalid security token.");
			$name = basename((string)($_POST["language"] ?? ""));
			if (!in_array($name, getInstallLanguages(), true)) return array("success" => false, "message" => $language["Invalid language"] ?? "Invalid language.");
			$_SESSION["installLanguage"] = $name;
			return array("success" => true, "token" => $_SESSION["token"] ?? "");
		}
		if ($action !== "validate") return null;
		$field = (string)($_POST["field"] ?? "");
		$value = (string)($_POST["value"] ?? "");
		if (in_array($field, array("forumTitle", "forumDescription", "adminUser", "adminEmail", "adminPass", "adminConfirm", "tablePrefix"), true) && $value === "") return array("validated" => false, "message" => $language["This field is required"] ?? "This field is required.");
		if ($field === "adminEmail" && !filter_var($value, FILTER_VALIDATE_EMAIL)) return array("validated" => false, "message" => $language["Enter a valid email address"] ?? "Enter a valid email address.");
		if ($field === "adminPass" && strlen($value) < 6) return array("validated" => false, "message" => $language["The password must contain at least 6 characters"] ?? "The password must contain at least 6 characters.");
		if ($field === "tablePrefix" && !preg_match("/^[A-Za-z0-9_]{0,20}$/", $value)) return array("validated" => false, "message" => $language["Invalid table prefix"] ?? "Invalid table prefix.");
		return array("validated" => true, "message" => "");
	}
}
?>
