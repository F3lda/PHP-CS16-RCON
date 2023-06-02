<?php
/**
 * @file index.php
 * 
 * @brief Example for CS16rcon class
 * @date 2023-04-23
 * @author F3lda
 * @update 2023-06-02
 */
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);


require_once('CS16rcon.php');



$server_ip = "192.168.0.111";
$server_port = 27015;
$server_rcon_password = "123456";

$cs = new CS16rcon($server_ip, $server_port, $server_rcon_password);
echo "<pre>";
echo var_dump($cs->getPlayers());
echo var_dump($cs->getServerInfo());
echo var_dump($cs->sendCommand("status"));
echo "</pre>";
?>
