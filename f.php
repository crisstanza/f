<?php

/*
	This is the "F" framework.
*/

date_default_timezone_set('UTC');

/*
	type=numeric|string|bool
	mandatory=true|false
	id=true|false
	transient=true|false
*/
class ServiceRequest {

	/** type=string&mandatory=false */
	var $op;

}

abstract class F {

	public static $DB_USER;
	public static $DB_PASS;
	public static $DB_HOST;
	public static $DB_BASE;

	public static $SMTP_USER;
	public static $SMTP_PASS;
	public static $SMTP_HOST;
	public static $SMTP_PORT;

	public static $SMTP_USE_FUNCTION_MAIL = true;

	public static function setFromStringName($class_name) {
		return new $class_name;
	}

	public static function setFromRequestParameters($instance) {
		$class_name = get_class($instance);
		$members = get_class_vars($class_name);
		foreach ($members as $name => $value) {
			$parameterValue = isset($_REQUEST[$name]) ? urldecode($_REQUEST[$name]) : '';
			$parameterValueParsed = I::parse($class_name, $name, $parameterValue);
			$instance->{$name} = $parameterValueParsed;
		}
		return $instance;
	}

	public static function setFromRequestBody($instance) {
		$class_name = get_class($instance);
		$members = get_class_vars($class_name);
		$requestBody = self::getRequestBody();
		$lines = explode("\n", $requestBody);
		$i = 0;
		foreach ($members as $name => $value) {
			$parameterValue = $lines[$i++];
			$parameterValueParsed = F::parse($class_name, $name, $parameterValue);
			$instance->{$name} = $parameterValueParsed;
		}
		return $instance;
	}

	public static function getFromInstance($instance) {
		$members = get_object_vars($instance);
		ksort($members, SORT_REGULAR);
		$result = array();
		foreach ($members as $name => $value) {
			$lineValue = $value;
			array_push($result, $lineValue, "\n");
		}
		array_pop($result);
		return join($result);
	}

	public static function getRequestBody() {
		// return stream_get_contents(STDIN);
		$rawInput = fopen('php://input', 'r');
		$tempStream = fopen('php://temp', 'r+');
		stream_copy_to_stream($rawInput, $tempStream);
		rewind($tempStream);
		return stream_get_contents($tempStream);
	}

	public static function validate($obj) {
		$class_name = get_class($obj);
		$members = get_object_vars($obj);
		foreach ($members as $name => $value) {
			$mandatory = I::mandatory($class_name, $name);
			if ($mandatory && empty($value)) {
				return array('property' => $name, 'name' => 'mandatory');
			}
		}
		$invalid = false;
		return $invalid;
	}

	public static function isPost() {
		$method = $_SERVER['REQUEST_METHOD'];
		return $method == 'POST';
	}

	public static function isGet() {
		$method = $_SERVER['REQUEST_METHOD'];
		return $method == 'GET';
	}

	public static function isPut() {
		$method = $_SERVER['REQUEST_METHOD'];
		return $method == 'PUT';
	}

	public static function isDelete() {
		$method = $_SERVER['REQUEST_METHOD'];
		return $method == 'DELETE';
	}

}

abstract class I {

	public static function annotationValue($class_name, $property, $annotation_name) {
		$rc = new ReflectionClass($class_name);
		$comment = $rc->getProperty($property)->getDocComment();
		$start = strpos($comment, '/**') + 3;
		$end = strpos($comment, '*/') - 3;
		$annotationString = trim(substr($comment, $start, $end));
		parse_str($annotationString, $annotation);
		return isset($annotation[$annotation_name]) ? $annotation[$annotation_name] : '';
	}

	public static function parse($class_name, $property, $value) {
		$type = self::type($class_name, $property);
		switch ($type) {
    		case 'bool':
    			return N::booleanValue($value);
        	break;
			default:
				return $value;
        	break;
		}
	}

	public static function type($class_name, $property) {
		return self::annotationValue($class_name, $property, 'type');
	}

	public static function mandatory($class_name, $property) {
		return N::booleanValue(self::annotationValue($class_name, $property, 'mandatory'));
	}

	public static function transient($class_name, $property) {
		return N::booleanValue(self::annotationValue($class_name, $property, 'transient'));
	}

}

abstract class N {

	public static function booleanValue($value) {
		return strtolower($value) == 'true' || $value == 1 ? '1': '0';
	}

}

abstract class D {

	/*
		Códigos de erro conhecidos:
			- 1040 : too many connections
			- 1044 : usuário inválido
			- 1045 : senha inválida
			- 1049 : banco de dados desconhecido
			- 1054 : coluna desconhecida na cláusula where
			- 1062 : chave duplicada em consulta sql executada
			- 1064 : erro de sintaxe em consulta sql executada
			- 2002, 2003 : servidor de banco de dados desligado ou host inválido
			- 1146 : ???
	*/
	private function open() {
		@$con = mysql_connect(F::$DB_HOST, F::$DB_USER, F::$DB_PASS);
		if (!$con) {
			throw new Exception(mysql_errno());
		}
		@$db = mysql_select_db(F::$DB_BASE, $con);
		if (!$db) {
			throw new Exception(mysql_errno());
		}
		return $con;
	}

	private function close($con, $conWasNull) {
		if ($conWasNull) {
			@$rs = mysql_close($con);
			if (!$rs) {
				throw new Exception(mysql_errno().' '.mysql_error());
			}
		}
	}

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	public function setFromResultSet($row, $obj) {
		$members = get_class_vars(get_class($obj));
		foreach ($members as $name => $value) {
			$transient = I::transient(get_class($obj), $name);
			if (!$transient) {
				$obj->{$name} = $row[$name];
			}
		}
		return $obj;
	}

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	private static function quotes($class_name, $property, $str, $con) {
		$type = I::type($class_name, $property);
		if (strlen(trim($str)) <= 0) { // Warning: trim() expects parameter 1 to be string, object given in /Users/crisstanza/www/meuorcamento/f.php on line 194
			return "NULL";
		} else if (is_numeric($str) || is_bool($str)){
			return $str;
		} else {
			return "'".mysql_real_escape_string($str, $con)."'";
		}
	}

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	private function mysqlQuery($sql, $con) {
		@$rs = mysql_query($sql, $con);
		if (!$rs) {
			if ($con == null) {
				throw new Exception('$con == null');
			} else {
				throw new Exception(mysql_errno().' '.mysql_error());
			}
		}
		return $rs;
	}

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	private static function sqlFindById($con, $obj) {
		$class_name = get_class($obj);
		$sql = array();
		array_push($sql, 'SELECT * FROM ', strtolower($class_name), ' WHERE id=', self::quotes($class_name, 'id', $obj->id, $con));
		return join($sql);
	}

	private static function sqlInsert($con, $obj) {
		$class_name = get_class($obj);
		$members = get_object_vars($obj);
		$sql = array();
		array_push($sql, 'INSERT INTO ', strtolower($class_name), ' (');
		foreach ($members as $name => $value) {
			if ($name != 'id') {
				$transient = I::transient($class_name, $name);
				if (!$transient) {
					array_push($sql, $name, ', ');
				}
			}
		}
		array_pop($sql);
		array_push($sql, ')', PHP_EOL);
		array_push($sql, 'VALUES (');
		foreach ($members as $name => $value) {
			if ($name != 'id') {
				$transient = I::transient($class_name, $name);
				if (!$transient) {
					array_push($sql, self::quotes($class_name, $name, $value, $con), ', ');
				}
			}
		}
		array_pop($sql);
		array_push($sql, ')', PHP_EOL);
		return join($sql);
	}

	private static function sqlUpdate($con, $obj) {
		$class_name = get_class($obj);
		$members = get_object_vars($obj);
		$sql = array();
		array_push($sql, 'UPDATE ', strtolower($class_name), ' SET', PHP_EOL);
		foreach ($members as $name => $value) {
			if ($name != 'id') {
				$transient = I::transient($class_name, $name);
				if (!$transient) {
					array_push($sql, '	', $name, '=', self::quotes($class_name, $name, $value, $con), ', ', PHP_EOL);
				}
			} else {
				$id_value = $value;
			}
		}
		array_pop($sql);
		array_pop($sql);
		array_push($sql, PHP_EOL);
		array_push($sql, 'WHERE id=', self::quotes($class_name, $name, $id_value, $con));
		return join($sql);
	}

	private static function findAllSQL($obj, $con) {
		$class_name = get_class($obj);
		$members = get_object_vars($obj);
		$sql = array();
		array_push($sql, 'SELECT * FROM ', strtolower($class_name), ' WHERE', PHP_EOL);
		foreach ($members as $name => $value) {
			if (strlen(trim($value)) > 0) {
				$transient = I::transient($class_name, $name);
				if (!$transient) {
					array_push($sql, '	', $name, '=', DAO::quotes($class_name, $name, $value, $con), ' AND ', PHP_EOL);
				}
			}
		}
		array_push($sql, '1=1');
		return join($sql);
	}

	public function sqlCount($con, $columnName, $obj) {
		$sql = array();
		array_push($sql, 'SELECT COUNT(*) AS ', $columnName, ' FROM (', self::findAllSQL($obj, $con), ')');
		return join($sql);
	}

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	public function findById($obj) {
		$con = self::open();
		$sql = self::sqlFindById($con, $obj);
		$rs = self::mysqlQuery($sql, $con);
		$result = null;
		if($row = mysql_fetch_assoc($rs)) {
			$result = call_user_func_array(array('self', 'rowToObjectTransformer'), array($row, $con));
		}
		self::close($con, true);
		return $result;
	}

	public function findAll($obj) {
		$con = self::open();
		$sql = self::sqlFindAll($con, $obj);
		$rs = self::mysqlQuery($sql, $con);
		$list = array();
		while($row = mysql_fetch_assoc($rs)) {
			$list[] = call_user_func_array(array('self', 'rowToObjectTransformer'), array($row, $con));
		}
		self::close($con, true);
		return $list;
	}

	public function count($obj) {
		$con = self::open();
		$columnName = 'total';
		$sql = self::sqlCount($con, $columnName, $obj);
		$rs = self::mysqlQuery($sql, $con);
		$result = null;
		if($row = mysql_fetch_assoc($rs)) {
			$result = $row[$columnName];
		}
		self::close($con, true);
		return $result;
	}

	public function insert($obj) {
		$con = self::open();
		$sql = self::sqlInsert($con, $obj);
		$rs = self::mysqlQuery($sql, $con);
		$obj->id = mysql_insert_id($con);
		self::close($con, true);
		return $obj;
	}

	public function update($obj) {
		$con = self::open();
		$sql = self::sqlUpdate($con, $obj);
		$rs = self::mysqlQuery($sql, $con);
		self::close($con, true);
		return $obj;
	}

}

abstract class E {

	public static function sendMail($from, $nameFrom, $to, $nameTo, $subject, $message, $ccArray=null, $bccArray=null) {
		$result = array('status' => 0, 'msg' => '', 'response' => array());
		if (F::$SMTP_USE_FUNCTION_MAIL) {

			$headers .= "From: ".self::encodeHeaderValue($nameFrom)." <$from>" . $newLine;
			$headers .= "Reply-To: ".self::encodeHeaderValue($nameFrom)." <$from>" . $newLine;
			$success = mail($to, $subject, $message, $headers);

			if ($success == 1) {
				$result['status'] = 0;
			} else {
				$result['status'] = $success;
			}

		} else {

			$smtpServer = F::$SMTP_HOST;
			$port = F::$SMTP_PORT;
			$timeout = 30;
			$username = F::$SMTP_USER;
			$password = F::$SMTP_PASS;
			$localhost = $_SERVER['SERVER_NAME'];
			$newLine = "\r\n";

			$errorNumber = 0;
			$errorString = '';
			@$smtpConnect = fsockopen($smtpServer, $port, $errorNumber, $errorString, $timeout);
			if(!$smtpConnect) {
				$result['status'] = 1;
				$result['msg'] = "[$errorNumber] $errorString ($smtpServer)";
				return $result;
			}
			$smtpResponse = fgets($smtpConnect);
			array_push($result['response'], $smtpResponse);

			fputs($smtpConnect,"AUTH LOGIN" . $newLine);
			$smtpResponse = fgets($smtpConnect);
			array_push($result['response'], $smtpResponse);

			fputs($smtpConnect, base64_encode($username) . $newLine);
			$smtpResponse = fgets($smtpConnect);
			array_push($result['response'], $smtpResponse);

			fputs($smtpConnect, base64_encode($password) . $newLine);
			$smtpResponse = fgets($smtpConnect);
			array_push($result['response'], $smtpResponse);

			fputs($smtpConnect, "HELO $localhost" . $newLine);
			$smtpResponse = fgets($smtpConnect);
			array_push($result['response'], $smtpResponse);

			fputs($smtpConnect, "MAIL FROM: $from" . $newLine);
			$smtpResponse = fgets($smtpConnect);
			array_push($result['response'], $smtpResponse);

			fputs($smtpConnect, "RCPT TO: $to" . $newLine);
			$smtpResponse = fgets($smtpConnect);
			array_push($result['response'], $smtpResponse);

			fputs($smtpConnect, "DATA" . $newLine);
			$smtpResponse = fgets($smtpConnect);
			array_push($result['response'], $smtpResponse);

			$headers = "MIME-Version: 1.0" . $newLine;
			$headers .= "Content-type: text/html; charset=UTF-8" . $newLine;
			$headers .= "To: ".self::encodeHeaderValue($nameTo)." <$to>" . $newLine;
			$headers .= "From: ".self::encodeHeaderValue($nameFrom)." <$from>" . $newLine;
			$headers .= "Reply-To: ".self::encodeHeaderValue($nameFrom)." <$from>" . $newLine;

			if ( $ccArray != null ) {
				foreach ( $ccArray as $cc ) {
					$headers .= "Cc: ".self::encodeHeaderValue($cc['name'])." <".$cc['email'].">" . $newLine;
				}
			}
			if ( $bccArray != null ) {
				foreach ( $bccArray as $bcc ) {
					$headers .= "Bcc: ".self::encodeHeaderValue($bcc['name'])." <".$bcc['email'].">" . $newLine;
				}
			}

			fputs($smtpConnect, "Subject: ".self::encodeHeaderValue($subject)."\n$headers\n\n$message\n.\n");
			$smtpResponse = fgets($smtpConnect);
			array_push($result['response'], $smtpResponse);

			fputs($smtpConnect, "QUIT" . $newLine);
			$smtpResponse = fgets($smtpConnect);
			array_push($result['response'], $smtpResponse);

		}
		return $result;
	}

	public static function encodeHeaderValue($str) {
		return empty($str) ? '' : '=?UTF-8?B?'.base64_encode($str).'?=';
	}

}

?>