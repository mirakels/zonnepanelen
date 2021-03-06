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
// versie: 1.1
// auteur: André Rijkeboer
// datum:  18-03-2019
// omschrijving: ophalen van de stroom en energie gegevens van de inverter (1 dag) volgende keren

include('config.php');

$d1 = array_key_exists('date', $_GET) ? $_GET['date'] : "";
if ($d1 == '') { $d1 = date("d-m-Y H:i:s", time()); }
$midnight = date("Y-m-d 00:00:00", strtotime($d1));
$today    = (new DateTime("today " . $midnight))->getTimestamp();
$tomorrow = (new DateTime("tomorrow " . $midnight))->getTimestamp();

$total = array();
$diff = array();

// open MySQL database
$mysqli = new mysqli($host, $user, $passwd, $db, $port);
// enkel of drie fase inverter
$table = $inverter == 1 ? 'telemetry_inverter' : 'telemetry_inverter_3phase';
$cols  = $inverter == 1 ? 'p_active'           : 'p_active1+p_active2+p_active3';

// haal de gegevens van de inverter op. Totaal van de_day, laatste p_active
$result = $mysqli->query(
		'SELECT max(timestamp) as timestamp, (max(e_total)-min(e_total)) as de_day_total, ' .
		'	(select ' . $cols . ' from ' . $table . ' order by timestamp desc limit 1) as p_active' .
		' FROM ' . $table .
		' WHERE timestamp BETWEEN ' . $today . ' AND ' . $tomorrow
		);
$row = mysqli_fetch_assoc($result);
$diff['jaar']   = gmdate("Y", $row['timestamp']);
$diff['maand']  = gmdate("m", $row['timestamp'])-1;
$diff['dag']    = gmdate("d", $row['timestamp']);
$diff['uur']    = gmdate("H", $row['timestamp']);
$diff['minuut'] = gmdate("i", $row['timestamp']);
$diff['sec']    = gmdate("s", $row['timestamp']);
$diff['p1_volume_prd'] = sprintf("%.3f", $row['de_day_total']/1000);
$diff['p1_current_power_prd'] = $row['p_active'];
//voeg het resultaat toe aan de total-array
array_push($total, $diff);

// sluit DB		
$thread_id = $mysqli->thread_id;
$mysqli->kill($thread_id);
$mysqli->close();	
//Output total results as JSON
echo json_encode($total);
?>
