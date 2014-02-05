<?php

require_once 'config.php';

$schedRegex = '/M?T?W?(?:Th)?F?S?\s+(?:[\-0-9:]+?(?:A|P)M){1,2}/';

function parseTime($timeStr) {
  $time = array(
    'time' => array(
      'h' => 0,
      'm' => 0 
    ),
    'merid' => 0
  );
  $len = strlen($timeStr);
  if ($timeStr[$len-1] == 'M') {
    if ($timeStr[$len-2] == 'P') {
      $time['time']['h'] += 12;
      $time['merid'] = 2;
    } else $time['merid'] = 1;
    $timeStr = substr($timeStr, 0, $len-2);
  }
  $arr = explode(':', $timeStr);
  $time['time']['h'] += +$arr[0] % 12;
  if (count($arr) == 2) {
    $time['time']['m'] += +$arr[1];
  }
  return $time;
}

function parseDays($daysStr) {
  $dayMap = array(
    'Th' => 'TH',
    'M' => 'MO',
    'T' => 'TU',
    'W' => 'WE',
    'F' => 'FR',
    'S' => 'SA'
  );
  $days = array();
  $len = strlen($daysStr);
  for ($i = 0; $i < $len; ++$i) {
    foreach ($dayMap as $dayCode => $dayCal) {
      if ($i + strlen($dayCode) <= $len) {
        if (substr_compare($daysStr, $dayCode, $i, strlen($dayCode)) == 0) {
          $days[] = $dayCal;
          break;
        }
      }
    }
  }
  return $days;
}

function parseSched($schedStr) {
  list($daysStr, $timesStr) = explode(' ', $schedStr);
  $days = parseDays($daysStr);
  list($timeStartStr, $timeEndStr) = explode('-', $timesStr);
  $timeStart = parseTime($timeStartStr);
  $timeEnd = parseTime($timeEndStr);
  if ($timeStart['merid'] == 0 && $timeEnd['merid'] == 2) {
    $timeStart['time']['h'] += 12;
  }
  return array($days,  $timeStart, $timeEnd);
}

function formatTimeToMysql($time) {
  return sprintf("%02d:%02d:00", $time['time']['h'], $time['time']['m']);
}

try {
  $conn = new PDO("{$config['driver']}:host={$config['host']};dbname={$config['dbname']}", $_GET['user'], $_GET['password'], array(
    PDO::ATTR_PERSISTENT => false,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING
  ));
} catch (PDOException $e) {
  echo $e;
  die("<p>Could not connect to database. Wrong username/password?</p>");
}


for ($ascii = ord('A'); $ascii <= ord('Z'); ++$ascii) {
$ch = curl_init('https://crs.upd.edu.ph/schedule/120132/' . chr($ascii));
$fp = fopen('/tmp/schedreader-page.html','w');
curl_setopt_array($ch, array(
  CURLOPT_HEADER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_FILE => $fp
));

curl_exec($ch);
curl_close($ch);
fclose($fp);

$dom = new DOMDocument('1.0');
$dom->loadHTMLFile('/tmp/schedreader-page.html');

$xpath = new DOMXPath($dom);
$nodes = $xpath->evaluate('//table[@id="tbl_schedule"]/tbody/tr');
if ($nodes) {
  // Prepare statements
  $classStmt = $conn->prepare('INSERT INTO classes (code, class, year, sem) VALUES (:code, :class, :year, :sem)');
  $meetingStmt = $conn->prepare('INSERT INTO class_meetings (code, time_start, time_end) VALUES (:code, :time_start, :time_end)');
  $dayStmt = $conn->prepare('INSERT INTO meeting_days (code, time_start, time_end, day) VALUES (:code, :time_start, :time_end, :day)');


  $nodesLength = $nodes->length;
  for ($i = 0; $i < $nodesLength; ++$i) {
    $node = $nodes->item($i);
    $children = $node->childNodes;
    if (!isset($children) || !is_object($children)) continue;
    $classCode = +$children->item(0)->textContent;
    $class = $children->item(2)->textContent;
    //$credits = +$children->item(4)->textContent;
    $sri = $children->item(6);
    if (!isset($sri->childNodes) || !is_object($sri->childNodes)) {
      continue;
    }
    $schedule = trim($sri->childNodes->item(0)->textContent);
    echo "$classCode ; $class ; $schedule <br>";
    /*
    $restrictions = trim($children->item(2)->textContent);
    $instructors = trim($children->item(4)->textContent);
    $slots = $children->item(8);
    if (strstr($slots->textContent, 'DISSOLVED') === FALSE) {
      list($availableSlots, $totalSlots, $demand) = explode('/', $slots->textContent);
    } else {
      $availableSlots = $totalSlots = $demand = null;
    }
    $availableSlots = +trim($availableSlots);
    $totalSlots = +trim($totalSlots);
    $demand = +trim($demand);
    $remarks = $children->item(10)->textContent;*/
    $conn->beginTransaction();
    $classStmt->bindParam(':code', $classCode);
    $classStmt->bindParam(':class', $class);
    // TODO: un-hardcode year and sem
    $year = 2013;
    $sem = 2;
    $classStmt->bindParam(':year', $year);
    $classStmt->bindParam(':sem', $sem);
    $classStmt->execute();

    $matches = array();
    $nMatches = preg_match_all($schedRegex, $schedule, $matches);
    for ($j = 0; $j < $nMatches; ++$j) {
      list($days, $timeStart, $timeEnd) = parseSched($matches[0][$j]);
      $timeStartStr = formatTimeToMysql($timeStart);
      $timeEndStr = formatTimeToMysql($timeEnd);
      echo sprintf("Days: %s <br> Time Start: %s <br> Time End: %s <br>", print_r($days), $timeStartStr, $timeEndStr);

      $meetingStmt->bindParam(':code', $classCode);
      $meetingStmt->bindParam(':time_start', $timeStartStr);
      $meetingStmt->bindParam(':time_end', $timeEndStr);
      $meetingStmt->execute();

      foreach ($days as $k => $day) {
        $dayStmt->bindParam(':code', $classCode);
        $dayStmt->bindParam(':time_start', $timeStartStr);
        $dayStmt->bindParam(':time_end', $timeEndStr);
        $dayStmt->bindParam(':day', $day);
        $dayStmt->execute();
      }
    }
    if ($nMatches > 0) {
      echo "# matches: $nMatches <br>";
      echo "match 1: {$matches[0][0]} <br>";
      $conn->commit();   
    } else {
      $conn->rollBack();
    }
  }
} else {
  echo "Joo fail!";
}
}
