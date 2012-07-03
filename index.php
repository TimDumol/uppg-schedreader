<?php
// Copyright Tim Dumol 2011
// BSD License
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_Gdata
 * @subpackage Demos
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */


require_once 'Zend/Gdata/Calendar.php';

require_once 'Gdata_OAuth_Helper.php';

require_once 'config.php';

session_start();

// Application constants. Replace these values with your own.
$APP_NAME = $config['oauthApp'];
$APP_URL = $config['oauthUrl'];
$scopes = array(
  'http://www.google.com/calendar/feeds/'
);

$CONSUMER_KEY = $config['oauthKey'];
$CONSUMER_SECRET = $config['oauthSecret'];
$consumer = new Gdata_OAuth_Helper($CONSUMER_KEY, $CONSUMER_SECRET);

// Main controller logic.
switch (@$_REQUEST['action']) {
case 'logout':
  logout($APP_URL);
  break;
case 'request_token':
  $_SESSION['REQUEST_TOKEN'] = serialize($consumer->fetchRequestToken(
    implode(' ', $scopes), $APP_URL . '?action=access_token'));
  $consumer->authorizeRequestToken();
  break;
case 'access_token':
  $_SESSION['ACCESS_TOKEN'] = serialize($consumer->fetchAccessToken());
  header('Location: ' . $APP_URL);
  break;
case 'make_calendar':
  // Create service.
  $accessToken = unserialize($_SESSION['ACCESS_TOKEN']);

  $httpClient = $accessToken->getHttpClient(
    $consumer->getOauthOptions());
  $calService = new Zend_Gdata_Calendar($httpClient, $APP_NAME);

  try {
    // For some reason, Zend doesn't support creating calendars -.-
    // Create calendar.
    $cal_str = <<<EOT
{
  "data": {
    "title": "{$_POST['cal_name']}",
    "timeZone": "Asia/Manila",
    "hidden": false,
    "location": "Quezon City"
  }
}
EOT;
    // TODO: Somehow make this (starting dates) unhardcoded.
    $map = array("TU" => 19, "WE" => 13, "TH" => 14, "FR" => 15, "SA" => 16, "MO" => 11);
    $response = $calService->post($cal_str, "https://www.google.com/calendar/feeds/default/owncalendars/full",
      null, "application/json");
    $json_cal_entry = json_decode($response->getBody());
    $uri = $json_cal_entry->data->eventFeedLink;
    // Create events.
    $decoded = json_decode($_POST['schedule']);

    // connect to DB
    $conn = new PDO("{$config['driver']}:host={$config['host']};dbname={$config['dbname']}", $config['user'], $config['password'], array(
    PDO::ATTR_PERSISTENT => true,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING
  ));

    // TODO: Somehow unhard-code the year and sem
    $stmt = $conn->prepare(<<<'EOT'
SELECT c.class class, TIME_FORMAT(m.time_start, '%H%i') time_start,
       TIME_FORMAT(m.time_end, '%H%i') time_end, GROUP_CONCAT(d.day SEPARATOR ',') days
  FROM (classes c
  INNER JOIN class_meetings m
    ON c.code = m.code)
  INNER JOIN meeting_days d
    ON d.code = m.code
      AND d.time_start = m.time_start
      AND d.time_end = m.time_end
  GROUP BY
    c.code, c.year, c.sem, c.class, m.time_start, m.time_end
  HAVING
    c.code = :code
    AND c.year = 2012
    AND c.sem = 1
EOT
);

    foreach ($decoded as $classCode) {
      $stmt->bindParam(':code', $classCode);
      if ($stmt->execute()) {
        $scheds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($scheds as $sched) {
          print_r($sched);
          echo '<hr>';
          $event = $calService->newEventEntry();
          $event->title = $calService->newTitle($sched['class']);
          $event->content = $calService->newContent($sched['class']);
          // TODO: Somehow make the year/month unhardcoded
          $dateVal = "DATETIME:201206" . $map[substr($sched['days'], 0, 2)];
          // TODO: Somehow make the end year/month unhardcoded
          $recurrence = "DTSTART;TZID=Asia/Manila;VALUE={$dateVal}T{$sched['time_start']}00\r\n" .
            "DTEND;TZID=Asia/Manila;VALUE={$dateVal}T{$sched['time_end']}00\r\n" .
            "RRULE:FREQ=WEEKLY;BYDAY=" . $sched['days'] . ";UNTIL=20121008\r\n";
          $event->recurrence = $calService->newRecurrence($recurrence);
          echo "$recurrence\n";
          $newEvent = $calService->insertEvent($event, $uri);
        }
      }
    }
  } catch (Zend_Gdata_App_Exception $e) {
    echo "Error: " . $e->getMessage();
  }

  // Done.
?>
<html>
  <head>
    <title>UP Programming Guild Schedulre Reader</title>
  </head>
  <body>
    <h1>Congratulations!</h1>
    <p>Your calendar was successfully created.</p>
    <p>Click <a href="/">here</a> to return to the main site.</p>
    <p>If you are a member/recruit of UPPG, please share your new calendar with everyone@upprogrammingguild.org by going to <a href="http://calendar.upprogrammingguild.org">the UPPG Calendar</a> and sharing the newly created calendar. Thanks!</p>
  </body>
</html>
<?php
  break;
default:
  renderHTML($APP_URL);
}

/**
 * Removes session data and redirects the user to a URL.
 *
 * @param string $redirectUrl The URL to direct the user to after session data
 *     is destroyed.
 * @return void
 */
function logout($redirectUrl) {
  session_destroy();
  header('Location: ' . $redirectUrl);
  exit;
}

/**
 * Prints basic properties of a Google Data feed.
 *
 * @param Zend_Gdata_Feed $feed A feed object to print.
 * @return void
 */
function printFeed($feed) {
  echo '<ol>';
  foreach ($feed->entries as $entry) {
    $alternateLink = '';
    foreach ($entry->link as $link) {
      if ($link->getRel() == 'alternate') {
        $alternateLink = $link->getHref();
      }
    }
    echo "<li><a href=\"$alternateLink\" target=\"_new\">$entry->title</a></li>";
  }
  echo '</ol>';
}

/**
 * Renders the page's HTML.
 *
 * @return void
 */
function renderHTML($APP_URL) {
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset=utf-8 />
    <link href="css/style.css" type="text/css" rel="stylesheet"/>
  </head>
  <body>
    <h1>Welcome to the UPPG Schedule Reader</h1>
    <?php if (!isset($_SESSION['ACCESS_TOKEN'])) { ?>
    <button onclick="location.href='<?php echo "$APP_URL?action=request_token" ?>';">Click here to authorize the UPPG Schedule Reader to access your Google Calendar</button>
    <p>For UPPG members/recruits, please use your provided upprogrammingguild.org account to access the calendar.</p>
    <?php } else { ?>
    <div id="logout"><a href="<?php echo "$APP_URL?action=logout"; ?>">Logout</a></div>
    <form method="POST" action="<?php echo "$APP_URL?action=make_calendar"; ?>">
      <ol>
        <li>
          <p>Browse to the CRS Grades Viewing page</p>
        </li>
        <li>
          <p>Please paste the following code into the address bar. Please note that Chrome removes the "javascript:" at the start of the code when pasting into the address bar. Just type it in yourself (that is, paste the code below into the address bar of the CRS Grades Viewing page, then type "javascript:" at the start of the address bar.)</p>
          <textarea disabled id='injector'>javascript:(function($) {
    var arr = [];
    var $tables = $('table[id="tbl_grade-info"]');
    var $table = $tables.eq($tables.length - 2);
    $table.find('tbody > tr > td:nth-child(2)').each(function(idx) {
      var $this = $(this);
      arr.push(+$this.text());
    });
    $(document.body).append('&lt;div id="sreader-overlay">&lt;/div>');
    var $overlay = $('#sreader-overlay');
    $overlay.css({
        position: 'fixed',
        width: '100%',
        height: '200px',
        'border-bottom': 'thin solid #444',
        top: 0,
        left: 0,
        background: '#eee'
    });
    $overlay.append('&lt;div>&lt;div>Copy and paste the following to SchedReader:&lt;/div>'
      + '&lt;textarea readonly>' + JSON.stringify(arr) + '&lt;/textarea>&lt;/div>');
    $('#sreader-overlay > div').css({
        padding: '1em'
    });
    $('#sreader-overlay textarea').css({
        width: '80%',
        height: '10em'
    });
})(jQuery);</textarea>
        </li>
        <li>
          <p>Please copy the resulting text into this textbox:</p>
          <textarea id='schedule' name='schedule' placeholder='Paste your schedule here'></textarea>
        </li>
        <li>
          <p>Please enter a name for the new calendar:</p>
          <input type="text" name="cal_name" />
        </li>
        <li>
          <button type="submit">Create your calendar</button>
          <p>Please note that calendar creation takes some time. Please wait until calendar creation is finished before closing the tab.</p>
        </li>
      </ol>
    </form>
    <?php } ?>
  </body>
</html>
<?php } ?>
