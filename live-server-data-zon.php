<?php
//
// Copyright (C) 2019 André Rijkeboer
//
// This file is part of zonnepanelen, which shows telemetry data from
// the TCP traffic of SolarEdge PV inverters.
//
// zonnepanelen is free software: you can redistribute it and/or modify it
// under the terms of the GNU General Public License as published by the
// Free Software Foundation, either version 3 of the License, or (at
// your option) any later version.
//
// zonnepanelen is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
// General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with zonnepanelen.  If not, see <http://www.gnu.org/licenses/>.
//
// versie: 1.67.0
// auteur: André Rijkeboer
// datum:  17-04-2019
// omschrijving: ophalen van de tekstgegevens van het zonnepanelensysteem

include('config.php');

$d1 = array_key_exists('date', $_GET) ? $_GET['date'] : "";
if ($d1 == '') { $d1 = date("d-m-Y H:i:s", time()); }
$midnight = date("Y-m-d 00:00:00", strtotime($d1));
$today    = (new DateTime("today " . $midnight))->getTimestamp();
$tomorrow = (new DateTime("tomorrow " . $midnight))->getTimestamp();

$total = array();
$mode = array();
$diff = array();
// mode inverter
$mode[0] = '';
$mode[1] = 'OFF';
$mode[2] = 'SLEEPING';
$mode[3] = 'STARTING';
$mode[4] = 'MPPT';
$mode[5] = 'THROTTLED';
$mode[6] = 'SHUTTING_DOWN';
$mode[7] = '';
$mode[8] = 'STANDBY';
$mode[9] = '';

//open MySQL database
$mysqli = new mysqli($host, $user, $passwd, $db, $port);
if ($aantal < 0) { $aantal = 0;}
// bepaal de eerste dag van de data in de database
$query = "SELECT min(timestamp) as timestamp FROM telemetry_optimizers";
$result = $mysqli->query($query);
$row = mysqli_fetch_assoc($result);
$begin = gmdate("Y-m-d 00:00:00", $row['timestamp']);

// zet gegevens van de panelen op 0 voor het geval dat
// midnight < begin of de sql query faalt
for ($i = 1; $i <= $aantal; $i++) {
	$diff['O' . $i]	= 0;
	$diff['C' . $i]	= 0;
	$diff['TM' . $i]	= 0;
	$diff['VI' . $i]	= 0;
	$diff['VU' . $i]	= 0;
	$diff['S' . $i]	= 0;
	$diff['T' . $i]	= 0;
	$diff['E' . $i]	= 0;
	$diff['VM' . $i]	= 0;
	$diff['VMT' . $i]	= "    00:00:00";
}
$diff['IT']	= $d1;
$diff['ITMIN']	= 0;
$diff['ITMAX']	= 0;
$diff['ITACT']	= 0;
$diff['IVACT']	= 0;
$diff['IVMAX']	= 0;
$diff['IE']	= 0;
$diff['MODE']	= '';
$diff['v_dc']	= 0;
// haal gegevens van de panelen op
If ($midnight >= $begin) {
	// PANEL DATA
	// loop through all records and make calculations in php. Usin max() and sum() functions
	// makes things complicated or needs multiple queries.
	$format = '%d-%m-%Y %H:%i:%s';
	$query = sprintf("SELECT HEX(op_id) optimizer, FROM_UNIXTIME(timestamp, '%s') time, v_in, v_out, i_in, temperature, uptime, e_day
			FROM telemetry_optimizers
			WHERE timestamp > %s AND timestamp < %s
			order BY HEX(op_id), timestamp",
			$format, $today, $tomorrow);
	$result = $mysqli->query($query);

	if ($result) {
		$prev_id = 0;
		$prev_uptime = 0;
		$prev_e_day = 0;
		$max = 0;
		while ($row = mysqli_fetch_assoc($result)) {
			for ($i = 1; $i <= $aantal; $i++){
				if ($row['optimizer'] == $op_id[$i][0]) {
					$diff['O' . $i] += ($i == $prev_id and $row['uptime'] > $prev_uptime)   ? $row['e_day'] - $prev_e_day
														: $row['e_day'];
					if ($max < $diff['O' . $i]){
						$max = $diff['O' . $i];
					}
					$prev_id = $i;
					$prev_uptime = $row['uptime'];
					$prev_e_day = $row['e_day'];
					$diff['TM' . $i]	= $row['time'];
					$diff['VI' . $i]	= $row['v_in'];
					$diff['VU' . $i]	= $row['v_out'];
					$diff['S' . $i]	= $row['i_in'];
					$diff['T' . $i]	= $row['temperature'];
					$v_m = $row['v_in']*$row['i_in'];
					if ($v_m > $diff['VM' . $i]) {
						$diff['VM' . $i]	= $v_m;
						$diff['VMT' . $i]	= $row['time'];
					}
				}
			}
		}
		// convert to proper values
		if ($max == 0) {$max = 1;}
		for ($i = 1; $i <= $aantal; $i++) {
			$diff['C' . $i]   = sprintf("%.2f", $diff['O' . $i]/$max);
			$diff['O' . $i]   = sprintf("%.2f", $diff['O' . $i] * 0.25);
			$diff['VI' . $i]  = sprintf("%.2f", $diff['VI' . $i] * 0.125);
			$diff['VU' . $i]  = sprintf("%.2f", $diff['VU' . $i] * 0.125);
			$diff['S' . $i]   = sprintf("%.2f", $diff['S' . $i] * 0.00625);
			$diff['T' . $i]  *= 2;
			$diff['E' . $i]   = sprintf("%.2f", $diff['VI' . $i] * $diff['S' . $i]);
			$diff['VM' . $i]  = sprintf("%.2f", $diff['VM' . $i]*0.125*0.00625);
			$diff['VMT' . $i] = substr($diff['VMT' . $i], 11);
		}
	}

	// INVERTER DATA
	// Collect min/max over the day
	// By using two queries there is no need to go through all data of the day
	$table = $inverter == 1 ? "telemetry_inverter " : "telemetry_inverter_3phase ";
	$cols = $inverter == 1 ? "p_active" : "p_active1+p_active2+p_active3";
	$query = sprintf("SELECT MIN(temperature) t_min, MAX(temperature) t_max, MAX(%s) p_max, max(e_total)-min(e_total) e_day
			  FROM %s
			  WHERE timestamp BETWEEN %s AND %s
			  ", $cols, $table, $today, $tomorrow);
	$result = $mysqli->query($query);
	if ($result) {
		$row = mysqli_fetch_assoc($result);
		$diff['ITMIN']	= sprintf("%.1f", $row['t_min']);
		$diff['ITMAX']	= sprintf("%.1f", $row['t_max']);
		$diff['IVMAX']	= sprintf("%.0f", $row['p_max']);
		$diff['IE']	= sprintf("%.3f", $row['e_day']/1000);
	}

	// Collect last/current off the day
	$cols = $inverter == 1 ? "v_ac, i_ac, frequency, p_active"
				: "v_ac1, v_ac2, v_ac3, i_ac1, i_ac2, i_ac3, frequency1, frequency2, frequency3, p_active1, p_active2, p_active3, p_active1+p_active2+p_active3 p_active";

	$query = sprintf("SELECT FROM_UNIXTIME(timestamp, '%s') datum, temperature t_act, mode, FORMAT(v_dc,3) as v_dc,
				 %s
			  FROM %s
			  WHERE timestamp BETWEEN %s AND %s ORDER BY timestamp DESC limit 1
			  ", $format, $cols, $table, $today, $tomorrow);
	$result = $mysqli->query($query);
	if ($result) {
		$row = mysqli_fetch_assoc($result);

		$diff['IT']	= $row['datum'];
		$diff['ITACT']	= sprintf("%.1f", $row['t_act']);
		$diff['IVACT']	= sprintf("%.0f", $row['p_active']);
		$diff['MODE']	= $mode[$row['mode']];
		$diff['v_dc']	= sprintf("%.3f", $row['v_dc']);
		if ($inverter == 1) {
			$diff['v_ac']		= sprintf("%.1f", $row['v_ac']);
			$diff['i_ac']		= sprintf("%.3f", $row['i_ac']);
			$diff['frequency']	= sprintf("%.2f", $row['frequency']);
			$diff['p_active']	= sprintf("%.0f", $row['p_active']);
		}else{
			$diff['v_ac1']		= sprintf("%.1f", $row['v_ac1']);
			$diff['v_ac2']		= sprintf("%.1f", $row['v_ac2']);
			$diff['v_ac3']		= sprintf("%.1f", $row['v_ac3']);
			$diff['i_ac1']		= sprintf("%.3f", $row['i_ac1']);
			$diff['i_ac2']		= sprintf("%.3f", $row['i_ac2']);
			$diff['i_ac3']		= sprintf("%.3f", $row['i_ac3']);
			$diff['frequency1']	= sprintf("%.2f", $row['frequency1']);
			$diff['frequency2']	= sprintf("%.2f", $row['frequency2']);
			$diff['frequency3']	= sprintf("%.2f", $row['frequency3']);
			$diff['p_active1']	= sprintf("%.0f", $row['p_active1']);
			$diff['p_active2']	= sprintf("%.0f", $row['p_active2']);
			$diff['p_active3']	= sprintf("%.0f", $row['p_active3']);
		}
	}
}

//voeg het resultaat toe aan de total-array
array_push($total, $diff);

// Sluit DB
$thread_id = $mysqli->thread_id;
$mysqli->kill($thread_id);
$mysqli->close();
//Output totale resultaat als JSON
echo json_encode($total);
?>
