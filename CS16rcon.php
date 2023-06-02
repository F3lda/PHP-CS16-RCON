<?php
/**
 * @file CS16rcon.php
 * 
 * @brief Simple PHP library/class for communication with Counter-Strike 1.6 server over RCON protocol
 * @date 2023-04-23
 * @author F3lda
 * @update 2023-06-02
 */
class CS16rcon {

	protected $protocol = 'udp';
	
	protected $socket = null;
	
	protected $password = '';
	
	/*
	* constructor
	*
	* @param	string	$server - server ip
	* @param	integer	$port - server port
	* @param	string	$password - server rcon password
	*/
	public function __construct ($server, $port, $password) {
		$this->password = $password;
		
		$this->socket = @fsockopen($this->protocol."://".$server, $port, $errno, $errstr, $timeout = 30) or
			die("Unable to open socket: $errstr ($errno)\n");
		
		//stream_set_blocking($this->socket, 0);
		
		stream_set_timeout($this->socket, 10, 0); // 10 seconds, 0 microseconds
	}
	
	/*
	* destructor
	*/
	public function __destruct () {
		$this->close();
	}
	
	/*
	* close the connection to the server
	*/
	public function close() {
        if ($this->socket) {
			fclose($this->socket);
        }
	}
	
	
	
	/*
	* get value from the start of the binary response string
	*
	* @param	string	$data - binary string
	* @param	string	$type - value type
	* @return	by $type
	*/
	protected function trimValue(&$data, $type) {
		if ($type == "byte") { //byte or uint8
			$value = substr($data, 0, 1);
			$data = substr($data, 1);
			return ord($value);
			
		} else if ($type == "short") { //int16
			$value = substr($data, 0, 2);
			$data = substr($data, 2);
			$unpacked = unpack('sint', $value);
			return $unpacked["int"];
			
		} else if ($type == "long" || $type == "int") { //int32
			$value = substr($data, 0, 4);
			$data = substr($data, 4);
			$unpacked = unpack('iint', $value);
			return $unpacked["int"];
			
		} else if ($type == "float") { //float32
			$value = substr($data, 0, 4);
			$data = substr($data, 4);
			$unpacked = unpack('fint', $value);
			return $unpacked["int"];
			
		} else if ($type == "longlong") { //uint64
			$value = substr($data, 0, 8);
			$data = substr($data, 8);
			$unpacked = unpack('Qint', $value);
			return $unpacked["int"];
			
		} else if ($type == "char") { //char
			$value = substr($data, 0, 1);
			$data = substr($data, 1);
			return $value;
			
		} else if ($type == "string") { //char field terminated by 0x00
			$str = '';
			while ($data != '' && ($char = $this->trimValue($data, 'char')) != chr(0)) {
				$str .= $char;
			}
			return $str;
		}
	}



	/*
	* receive data from the server
	*
	* @return	string
	*/
	protected function receive() {
		$data = '';
		
		$size = @fread($this->socket, 4);
		$size = unpack('iint', $size);
		if ($size != false) {
			if ($size['int'] == -1) { // -1 (0xFFFFFFFF) - Simple Response Format
				//echo "Simple Response Format<br>".PHP_EOL;
				stream_set_blocking($this->socket, 0);
				$data = fread($this->socket, 1400);
				stream_set_blocking($this->socket, 1);
				
			} else if ($size['int'] == -2) { // -2 (0xFFFFFFFE) - Multi-packet Response Format
				//echo "Multi-packet Response Format<br>".PHP_EOL;
				//TODO
				/*while(($data .= fread($this->socket, 4096)) != '') {
					echo "reading...<br>".PHP_EOL;
				}*/
				
				/*while(!feof($fp)) {
					$data .= fread($this->socket, 8192);
				}*/
				
				/*echo var_dump($size);
				echo "<br>".PHP_EOL;	
				echo var_dump($data)."<br>".PHP_EOL;*/
			}
		}
		
		return $data;
	}
	
	/*
	* send data to the server
	*
	* @param	string	$string
	* @return	string - response
	*/
	protected function send($string) {
		fputs($this->socket, $string);
		$data = $this->receive();
		return $data;
	}
	
	
	
	/*
	* send RCON command to the server
	*
	* @param	string	$cmd - RCON command
	* @return	string - response
	*/
	public function sendCommand($cmd) {
		$data = $this->send("\xFF\xFF\xFF\xFFchallenge rcon");
		$data = trim($data);
		$sec_pos = strpos($data, ' ', strpos($data, ' ') + 1);
		$data = substr($data, $sec_pos + 1);
		$data = $this->send("\xFF\xFF\xFF\xFFrcon ".$data." ".$this->password." ".$cmd."");
		$this->trimValue($data, "byte"); // remove first byte
		return $data;
	}
	
	// INFO: https://developer.valvesoftware.com/wiki/Server_queries
	/*
	* get server info
	*
	* @return	array
	*/
	public function getServerInfo() {
		$data = $this->send("\xFF\xFF\xFF\xFFTSource Engine Query\x00");
		$server = array();
		
		$value = $this->trimValue($data, "byte"); // header 'I' (0x49)
		$server["Protocol"] = $this->trimValue($data, "byte");
		$server["Name"] = $this->trimValue($data, "string");
		$server["Map"] = $this->trimValue($data, "string");
		$server["Folder"] = $this->trimValue($data, "string");
		$server["Game"] = $this->trimValue($data, "string");
		$server["ID"] = $this->trimValue($data, "short");
		$server["Players"] = $this->trimValue($data, "byte");
		$server["MaxPlayers"] = $this->trimValue($data, "byte");
		$server["Bots"] = $this->trimValue($data, "byte");
		$server["ServerType"] = $this->trimValue($data, "char");
		$server["Environment"] = $this->trimValue($data, "char");
		$server["Visibility"] = $this->trimValue($data, "byte");
		$server["VAC"] = $this->trimValue($data, "byte");
		$server["Version"] = $this->trimValue($data, "string");
		$server["EDF"] = $this->trimValue($data, "byte");
		
		if($server["EDF"] & 0x80) {
			$server["Port"] = $this->trimValue($data, "short");
		}
		if($server["EDF"] & 0x10) {
			//$server["SteamID"] = $this->trimValue($data, "longlong"); // PHP 64bit version
			$server["SteamID"] = bin2hex($this->trimValue($data, "char").$this->trimValue($data, "char").$this->trimValue($data, "char").$this->trimValue($data, "char").$this->trimValue($data, "char").$this->trimValue($data, "char").$this->trimValue($data, "char").$this->trimValue($data, "char"));
		}
		if($server["EDF"] & 0x40) {
			$server["SpectatorPort"] = $this->trimValue($data, "short");
			$server["SpectatorName"] = $this->trimValue($data, "string");
		}
		if($server["EDF"] & 0x20) {
			$server["Keywords"] = $this->trimValue($data, "string");
		}
		if($server["EDF"] & 0x01) {
			//$server["GameID"] = $this->trimValue($data, "longlong"); // PHP 64bit version
			$server["GameID"] = bin2hex($this->trimValue($data, "char").$this->trimValue($data, "char").$this->trimValue($data, "char").$this->trimValue($data, "char").$this->trimValue($data, "char").$this->trimValue($data, "char").$this->trimValue($data, "char").$this->trimValue($data, "char"));
		}
		
		return $server;
	}
	
	/*
	* get players info
	*
	* @return	array
	*/
	public function getPlayers() {
		// request challenge id
		$data = $this->send("\xFF\xFF\xFF\xFF\x55\xFF\xFF\xFF\xFF");
		$value = $this->trimValue($data, "byte"); // header 'A' (0x41)
		$value = $this->trimValue($data, "string"); // challenge number
		
		// request player data
		$data = $this->send("\xFF\xFF\xFF\xFF\x55".$value);
		$value = $this->trimValue($data, "byte"); // header 'D' (0x44)
		$value = $this->trimValue($data, "byte"); // number of players

		// read player data
		$players = array();
		while ($data != '') {
			$player = array();
			$player["index"] = $this->trimValue($data, 'byte');
			$player["name"] = $this->trimValue($data, 'string');
			$player["score"] = $this->trimValue($data, 'long');
			$player["time"] = $this->trimValue($data, 'float');
			$hours = floor($player["time"] / 3600);
			$mins = floor($player["time"] / 60 % 60);
			$secs = floor($player["time"] % 60);
			$player["time"] = sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
			$players[] = $player;
		}
		
		return $players;
	}
}
