<?php
/** Experimental driver for IMAP created just for fun. Features:
* - list mailboxes with number of messages (Rows) and unread messages (Data Free)
* - list messages in each mailbox - limit and offset works but there's no search and order
* - for each message, there's subject, from, to, date and some flags
* - editing the message shows some other information
* - inserting, deleting or updating the message does nothing
*/

namespace Adminer;

add_driver("imap", "IMAP");

if (isset($_GET["imap"])) {
	define('Adminer\DRIVER', "imap");

	if (extension_loaded("imap")) {
		class Db {
			public $extension = "IMAP";
			public $error;
			public $server_info = "?"; // imap_mailboxmsginfo() doesn't return anything useful
			private $mailbox;
			private $imap;

			function connect($server, $username, $password) {
				$this->mailbox = "{" . "$server:993/ssl}"; // Adminer disallows specifying privileged port in server name
				$this->imap = @imap_open($this->mailbox, $username, $password, 0, 1);
				if (!$this->imap) {
					$this->error = imap_last_error();
				}
				return $this->imap;
			}

			function select_db($database) {
				return ($database == "imap");
			}

			function query($query, $unbuffered = false) {
				preg_match('~\sFROM (\w+).*?(?:\sWHERE uid = (\d+))?.*?(?:\sLIMIT (\d+)(?:\sOFFSET (\d+))?)?~s', $query, $match);
				list(, $table, $uid, $limit, $offset) = $match;
				if ($uid) {
					$return = array((array) imap_fetchstructure($this->imap, $uid, FT_UID));
				} else {
					imap_reopen($this->imap, "$this->mailbox$table");
					$check = imap_check($this->imap);
					$range = ($offset + 1) . ":" . ($limit ? min($check->Nmsgs, $offset + $limit) : $check->Nmsgs);
					$return = array();
					foreach (imap_fetch_overview($this->imap, $range) as $row) {
						// imap_utf8 doesn't work with some strings
						$row->subject = iconv_mime_decode($row->subject, 2, "utf-8");
						$row->from = iconv_mime_decode($row->from, 2, "utf-8");
						$row->to = iconv_mime_decode($row->to, 2, "utf-8");
						$row->udate = gmdate("Y-m-d H:i:s", $row->udate);
						$return[] = array_merge(array_fill_keys(array_keys(fields($table)), null), (array) $row);
					}
				}
				return new Result($return);
			}

			function quote($string) {
				return $string;
			}

			function tables_list() {
				static $return;
				if ($return === null) {
					$return = array();
					foreach (imap_list($this->imap, $this->mailbox, "*") as $val) {
						$return[substr($val, strlen($this->mailbox))] = "table";
					}
				}
				return array_reverse($return);
			}

			function table_status($name, $fast) {
				if ($fast) {
					return array("Name" => $name);
				}
				$return = imap_status($this->imap, $this->mailbox . $name, SA_ALL);
				return array(
					"Name" => $name,
					"Rows" => $return->messages,
					"Auto_increment" => $return->uidnext,
					"Data_free" => $return->unseen,
				);
			}
		}

		class Result {
			public $num_rows;
			private $result;

			function __construct($result) {
				$this->result = $result;
				$this->num_rows = count($result);
			}

			function fetch_assoc() {
				$row = current($this->result);
				next($this->result);
				return $row;
			}
		}
	}

	class Driver extends SqlDriver {
		static $possibleDrivers = array("imap");
		static $jush = "imap";
		public $editFunctions = array(array("json"));
	}

	function logged_user() {
		return $_GET["username"];
	}

	function get_databases($flush) {
		return array("imap");
	}

	function collations() {
		return array();
	}

	function db_collation($db, $collations) {
	}

	function information_schema($db) {
	}

	function indexes($table, $connection2 = null) {
		return array(array("type" => "PRIMARY", "columns" => array("uid")));
	}

	function fields($table) {
		$return = array();
		foreach (
			array( // taken from imap_fetch_overview
				'subject' => 'the messages subject',
				'from' => 'who sent it',
				'to' => 'recipient',
				'date' => 'when was it sent',
				'message_id' => 'Message-ID',
				'references' => 'is a reference to this message id',
				'in_reply_to' => 'is a reply to this message id',
				'size' => 'size in bytes',
				'uid' => 'UID the message has in the mailbox',
				'msgno' => 'message sequence number in the mailbox',
				'recent' => 'flagged as recent',
				'flagged' => 'flagged',
				'answered' => 'flagged as answered',
				'deleted' => 'flagged for deletion',
				'seen' => 'flagged as already read',
				'draft' => 'flagged as being a draft',
				'udate' => 'the GMT time of the arrival date',
			) as $name => $comment
		) {
			$return[$name] = array(
				"field" => $name,
				"type" => (preg_match('~^(size|uid|msgno)$~', $name) ? "int" : ""),
				"privileges" => array("select" => 1),
				"comment" => $comment,
			);
		}
		return $return;
	}

	function convert_field($field) {
	}

	function unconvert_field($field, $return) {
		return $return;
	}

	function limit($query, $where, $limit, $offset = 0, $separator = " ") {
		return " $query$where" . ($limit !== null ? $separator . "LIMIT $limit" . ($offset ? " OFFSET $offset" : "") : "");
	}

	function idf_escape($idf) {
		return $idf; //! maybe {}
	}

	function table($idf) {
		return idf_escape($idf);
	}

	function foreign_keys($table) {
		return array();
	}

	function tables_list() {
		global $connection;
		return $connection->tables_list();
	}

	function table_status($name = "", $fast = false) {
		global $connection;
		if ($name != "") {
			return $connection->table_status($name, $fast);
		}
		$return = array();
		foreach (tables_list() as $table => $type) {
			$return[$table] = $connection->table_status($table, $fast);
		}
		return $return;
	}

	function count_tables($databases) {
		return array(reset($databases) => count(tables_list()));
	}

	function error() {
		global $connection;
		return h($connection->error);
	}

	function is_view($table_status) {
		return false;
	}

	function found_rows($table_status, $where) {
		return $table_status["Rows"];
	}

	function connect($credentials) {
		$connection = new Db;
		if ($connection->connect($credentials[0], $credentials[1], $credentials[2])) {
			return $connection;
		}
		return $connection->error;
	}

	function support($feature) {
		return preg_match("~^()$~", $feature);
	}
}
