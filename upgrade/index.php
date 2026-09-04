<?php
/**
 * This file is part of esoTalk.
 * Copyright (C) 2023-2026 Scoppettuolo / esoTalk contributors
 * <https://github.com/Scoppettuolo>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/agpl-3.0.html>.
 */
define("IN_ESO", 1);

/**
 * Upgrader: this page is loaded whenever the build version in
 * config.default.php differs from the one the forum is up-to-date with
 * (in config.php).  Sets up the Upgrade controller.
 */

// No timeout.
@set_time_limit(0);

// Get the new version and the current version, and compare them.  If we don't need to upgrade, home we go!
require "../config.default.php";
require "../config/versions.php";
if ($versions["eso"] == ESO_VERSION) {
	header("Location: ../index.php");
	exit;
}

// Require essential files.
require "../lib/functions.php";
require "../lib/classes.php";
require "../lib/database.php";
require "../config/config.php";

// The historical upgrader contains MySQL-only ALTER/ENUM/ENGINE statements.
// Do not run it against SQLite; fresh SQLite installations are created by
// install/index.php with the current schema. A clear message is safer than a
// partial migration that could corrupt the forum.
if (strtolower((string)($config["databaseDriver"] ?? "mysqli")) === "sqlite") {
	header("Content-type: text/html; charset=utf-8");
	echo "<!doctype html><meta charset='utf-8'><title>esoTalk SQLite upgrade</title><h1>SQLite upgrade requires a current schema</h1><p>This esoTalk build does not run the historical MySQL-only upgrader against SQLite. Make a backup of <code>" . htmlspecialchars((string)($config["sqlitePath"] ?? "config/esotalk.sqlite"), ENT_QUOTES, "UTF-8") . "</code>, then use <a href='../install/index.php'>the current installer</a> only if you are performing a planned migration.</p>";
	exit;
}
require "upgrade.controller.php";

// Sanitize the request data using sanitize().
$_POST = sanitize($_POST);
$_GET = sanitize($_GET);
$_COOKIE = sanitize($_COOKIE);

// Set up the upgrade controller and start the upgrade.
$upgrade = new Upgrade();
$upgrade->init();

?>
