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

/**
 * Classes: this file contains all the base classes which are extended
 * throughout the software...
 */
if (!defined("IN_ESO")) exit;

// Hookable => a class in which code can be hooked on to.
// Extend this class and then use $this->callHook("uniqueMarker") in the class code to call any code which has been hooked via $classInstance->addHook("uniqueMarker", "function").
#[\AllowDynamicProperties]
class Hookable {

public $hookedFunctions = array();

// Run all collective hooked functions for the specified marker.
public function callHook($marker, $parameters = array(), $return = false)
{
	if (!empty($this->hookedFunctions[$marker])) {
		
		// Add the instance of this class to the parameters.
		// We can't use array_unshift here because call-time pass-by-reference has been deprecated.
		$parameters = is_array($parameters) ? array_merge(array(&$this), $parameters) : array(&$this);
		
		// Loop through the functions which have been hooked on this hook and execute them.
		// If this hook requires a return value and the function we're running returns something, return that.
		foreach ($this->hookedFunctions[$marker] as $function) {
			if (($returned = call_user_func_array($function, $parameters)) and $return) return $returned;
		}
	}
}

// Hook a function.
public function addHook($hook, $function)
{
	$this->hookedFunctions[$hook][] = $function;
}

}


// Defines a view and handles input.
// Extend this class and then use $eso->registerController() to register your new controller.
#[\AllowDynamicProperties]
class Controller extends Hookable {

public $action;
public $view;
public $title;
public $eso;

public function init() {}
public function ajax() {}

// Render the page according to the controller's $view.
public function render()
{
	global $language, $messages, $config;
	include $this->eso->skin->getView($this->view);
}

}

// Defines a plugin.
// Extend this class to make a plugin. See the plugin documentation for more information.
class Plugin extends Hookable {

public $id;
public $name;
public $version;
public $author;
public $description;

// Constructor: include the config file or write the default config if it doesn't exist.
public function __construct()
{
	if (!empty($this->defaultConfig)) {
		global $config;
		$filename = sanitizeFileName($this->id);
		if (!file_exists(PATH_CONFIG . "/$filename.php")) writeConfigFile(PATH_CONFIG . "/$filename.php", '$config["' . escapeDoubleQuotes($this->id) . '"]', $this->defaultConfig);
		include PATH_CONFIG . "/$filename.php";
	}
}

// For automatic version checking, call this function (parent::init()) at the beginning of a plugin's init() function.
public function init()
{
	// Compare the version of the code ($this->version) to the installed one (config/versions.php).
	// If it's different, run the upgrade() function, and write the new version number to config/versions.php.
	global $versions;
	if (!isset($versions[$this->id]) or $versions[$this->id] != $this->version) {
		$this->upgrade(@$versions[$this->id]);
		$versions[$this->id] = $this->version;
		writeConfigFile(PATH_CONFIG . "/versions.php", '$versions', $versions);	
	}
}

public function settings() {}
public function saveSettings() {}
public function upgrade($oldVersion) {}
public function enable() {}

}

// Defines a skin.
// Extend this class to make a skin.
class Skin {

public $name;
public $version;
public $author;
public $views;

public function init() {}

// Generate button HTML.
public function button($attributes)
{
	$attr = " type='submit'";
	foreach ($attributes as $k => $v) $attr .= " $k='$v'";
	return "<input$attr/>";
}

// Register a custom view.
// Whenever a controller attempts to include $view, this new $file associated with $view will be included instead.
public function registerView($view, $file)
{
	$this->views[$view] = $file;
}

public function getView($view)
{
	return empty($this->views[$view]) ? "views/$view" : $this->views[$view];
}

public function getForumLogo()
{
	global $config;
	if (isset($this->eso->skin->logo) and file_exists(PATH_SKINS . "/{$config["skin"]}/" . $this->eso->skin->logo)) $logo = $this->eso->skin->logo;
	elseif (file_exists(PATH_SKINS . "/{$config["skin"]}/logo.svg")) $logo = "logo.svg";
	else $logo = "";
	return !empty($config["forumLogo"]) ? $config["forumLogo"] : "skins/{$config["skin"]}/" . $logo;
}

public function getForumIcon()
{
	global $config;
	if (isset($this->eso->skin->icon) and file_exists(PATH_SKINS . "/{$config["skin"]}/" . $this->eso->skin->icon)) $icon = $this->eso->skin->icon;
	elseif (file_exists(PATH_SKINS . "/{$config["skin"]}/icon.png")) $icon = "icon.png";
	else $icon = "";
	return !empty($config["forumIcon"]) ? $config["forumIcon"] : "skins/{$config["skin"]}/" . $icon;
}

}

?>
