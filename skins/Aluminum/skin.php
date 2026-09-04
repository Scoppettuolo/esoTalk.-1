<?php
/**
 * This file is part of esoTalk.
 * Copyright (C) 2026 Scoppettuolo / esoTalk contributors
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
 * Aluminum skin: alternate skin inspired by Mac OS X design elements
 * with dark gradients and push buttons.
 */
class Aluminum extends Skin {

public $name = "Aluminum";
public $version = "1.0";
public $author = "Toby Zerner";
public $numberOfColors = 27;

// Define name and file type of resources.
public $logo = "logo.svg";
public $icon = "icon.png";
public $favicon = "favicon.ico";
public $avatarLeft = "avatarLeft.svg";
public $avatarRight = "avatarRight.svg";
public $avatarThumb = "avatarThumb.svg";

// Add stylesheets and a favicon to the page header.
public function init()
{
	global $config;
	$this->eso->addCSS("skins/base.css");
	$this->eso->addCSS("skins/{$config["skin"]}/styles.css");
		}

// Generate button HTML.
public function button($attributes)
{
	$class = $id = $style = ""; $attr = " type='submit'";
	foreach ($attributes as $k => $v) {
		if ($k == "class") $class = " $v";
		elseif ($k == "id") $id = " id='$v'";
		elseif ($k == "style") $style = " style='$v'";
		else $attr .= " $k='$v'";
	}
	return "<span class='button$class'$id$style><input$attr/></span>";
}

}

?>
