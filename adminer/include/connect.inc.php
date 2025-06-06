<?php
namespace Adminer;

if (isset($_GET["status"])) {
	$_GET["variables"] = $_GET["status"];
}
if (isset($_GET["import"])) {
	$_GET["sql"] = $_GET["import"];
}

if (
	!(DB != ""
		? connection()->select_db(DB)
		: isset($_GET["sql"]) || isset($_GET["dump"]) || isset($_GET["database"]) || isset($_GET["processlist"]) || isset($_GET["privileges"]) || isset($_GET["user"]) || isset($_GET["variables"])
			|| $_GET["script"] == "connect" || $_GET["script"] == "kill"
	)
) {
	if (DB != "" || $_GET["refresh"]) {
		restart_session();
		set_session("dbs", null);
	}
	if (DB != "") {
		header("HTTP/1.1 404 Not Found");
		page_header(lang('Database') . ": " . h(DB), lang('Invalid database.'), true);
	} else {
		if ($_POST["db"] && !$error) {
			queries_redirect(substr(ME, 0, -1), lang('Databases have been dropped.'), drop_databases($_POST["db"]));
		}

		page_header(lang('Select database'), $error, false);
		echo "<p class='links'>\n";
		foreach (
			array(
				'database' => lang('Create database'),
				'privileges' => lang('Privileges'),
				'processlist' => lang('Process list'),
				'variables' => lang('Variables'),
				'status' => lang('Status'),
			) as $key => $val
		) {
			if (support($key)) {
				echo "<a href='" . h(ME) . "$key='>$val</a>\n";
			}
		}
		echo "<p>" . lang('%s version: %s through PHP extension %s', get_driver(DRIVER), "<b>" . h(connection()->server_info) . "</b>", "<b>" . connection()->extension . "</b>") . "\n";
		echo "<p>" . lang('Logged as: %s', "<b>" . h(logged_user()) . "</b>") . "\n";
		if (isset(adminer()->plugins) && is_array(adminer()->plugins)) {
			echo "<p>" . lang('Loaded plugins') . ":\n<ul>\n";
			foreach (adminer()->plugins as $plugin) {
				$reflection = new \ReflectionObject($plugin);
				echo "<li><b>" . get_class($plugin) . "</b>" . h(preg_match('~^/[\s*]+(.+)~', $reflection->getDocComment(), $match) ? ": $match[1]" : "") . "\n";
			}
			echo "</ul>\n";
		}
		$databases = adminer()->databases();
		if ($databases) {
			$scheme = support("scheme");
			$collations = collations();
			echo "<form action='' method='post'>\n";
			echo "<table class='checkable odds'>\n";
			echo script("mixin(qsl('table'), {onclick: tableClick, ondblclick: partialArg(tableClick, true)});");
			echo "<thead><tr>"
				. (support("database") ? "<td>" : "")
				. "<th>" . lang('Database') . (get_session("dbs") !== null ? " - <a href='" . h(ME) . "refresh=1'>" . lang('Refresh') . "</a>" : "")
				. "<td>" . lang('Collation')
				. "<td>" . lang('Tables')
				. "<td>" . lang('Size') . " - <a href='" . h(ME) . "dbsize=1'>" . lang('Compute') . "</a>" . script("qsl('a').onclick = partial(ajaxSetHtml, '" . js_escape(ME) . "script=connect');", "")
				. "</thead>\n"
			;

			$databases = ($_GET["dbsize"] ? count_tables($databases) : array_flip($databases));

			foreach ($databases as $db => $tables) {
				$root = h(ME) . "db=" . urlencode($db);
				$id = h("Db-" . $db);
				echo "<tr>" . (support("database") ? "<td>" . checkbox("db[]", $db, in_array($db, (array) $_POST["db"]), "", "", "", $id) : "");
				echo "<th><a href='$root' id='$id'>" . h($db) . "</a>";
				$collation = h(db_collation($db, $collations));
				echo "<td>" . (support("database") ? "<a href='$root" . ($scheme ? "&amp;ns=" : "") . "&amp;database=' title='" . lang('Alter database') . "'>$collation</a>" : $collation);
				echo "<td align='right'><a href='$root&amp;schema=' id='tables-" . h($db) . "' title='" . lang('Database schema') . "'>" . ($_GET["dbsize"] ? $tables : "?") . "</a>";
				echo "<td align='right' id='size-" . h($db) . "'>" . ($_GET["dbsize"] ? db_size($db) : "?");
				echo "\n";
			}

			echo "</table>\n";
			echo (support("database")
				? "<div class='footer'><div>\n"
					. "<fieldset><legend>" . lang('Selected') . " <span id='selected'></span></legend><div>\n"
					. input_hidden("all") . script("qsl('input').onclick = function () { selectCount('selected', formChecked(this, /^db/)); };") // used by trCheck()
					. "<input type='submit' name='drop' value='" . lang('Drop') . "'>" . confirm() . "\n"
					. "</div></fieldset>\n"
					. "</div></div>\n"
				: ""
			);
			echo input_token();
			echo "</form>\n";
			echo script("tableCheck();");
		}
	}

	page_footer("db");
	exit;
}

if (support("scheme")) {
	if (DB != "" && $_GET["ns"] !== "") {
		if (!isset($_GET["ns"])) {
			redirect(preg_replace('~ns=[^&]*&~', '', ME) . "ns=" . get_schema());
		}
		if (!set_schema($_GET["ns"])) {
			header("HTTP/1.1 404 Not Found");
			page_header(lang('Schema') . ": " . h($_GET["ns"]), lang('Invalid schema.'), true);
			page_footer("ns");
			exit;
		}
	}
}
