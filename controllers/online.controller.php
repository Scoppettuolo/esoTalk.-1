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
if (!defined("IN_ESO")) exit;

/**
 * Online controller: fetches a list of members currently online, ready
 * to be displayed in the view.
 */
class online extends Controller {
	
public $view = "online.view.php";

public function init()
{
	global $language, $config;

	if ($config["onlineMembers"] == false) redirect("");

	// Set the title and make sure this page isn't indexed.
	$this->title = $language["Online members"];
	$this->eso->addToHead("<meta name='robots' content='noindex, noarchive'/>");
	
	// Fetch a list of members who have been logged in the members table as 'online' in the last $config["userOnlineExpire"] seconds.
	$colors = (int)$this->eso->skin->numberOfColors;
	$expire = (int)$config["userOnlineExpire"];
	$this->online = $this->eso->db->fetchPrepared(
		"SELECT memberId, name, avatarFormat, IF(color>?, ?, color), account, lastSeen, lastAction FROM {$config["tablePrefix"]}members WHERE UNIX_TIMESTAMP()-?<lastSeen ORDER BY lastSeen DESC",
		"iii", $colors, $colors, $expire
	);
	$this->numberOnline = $this->eso->db->numRows($this->online);
}
	
}

?>
