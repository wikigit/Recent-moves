<?php /* Copyright (c) 2012, Derrick Coetzee (User:Dcoetzee)
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

 * Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer.

 * Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

/*** Options ***/
$default_limit = 50;

/***/

/* Get current time as a floating-point number of seconds since some reference
   point. */
function microtime_float()
{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}

/* Convert a Mediawiki timestamp to a more human-readable string
   representation. */
function timestamp_to_string($timestamp)
{
  preg_match('/([0-9][0-9][0-9][0-9])([0-9][0-9])([0-9][0-9])([0-9][0-9])([0-9][0-9])([0-9][0-9])/', $timestamp, $m);
  $month_names = array('', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
  return sprintf('%02d:%02d, %d %s %d', $m[4], $m[5], $m[3], $month_names[intval($m[2])], $m[1]);
}

function wiki_urlencode($s)
{
  return urlencode(preg_replace('/ /', '_', $s));
}

function build_url($namespace, $dir, $offset, $limit, $hidepatrolled)
{
  global $default_limit;
  $params = array();
  if ($namespace) { $params[] = "namespace=$namespace"; }
  if ($dir) { $params[] = "dir=$dir"; }
  if ($offset) { $params[] = "offset=$offset"; }
  if ($limit != $default_limit) { $params[] = "limit=$limit"; }
  if ($hidepatrolled) { $params[] = "hidepatrolled=$hidepatrolled"; }
  if (count($params) == 0) return '.'; else return '.?' . join("&", $params);
}

# Set PHP options - user abort is useful for "pausing" when it works.
ignore_user_abort(false);

# Get starting time to measure time elapsed later.
$time_start = microtime_float();

header("Content-Type: text/html; charset=utf-8");
echo '<html>' . "\r\n";
echo '<head><title>Recent moves</title></head>' . "\r\n";
echo '<style type="text/css">';
echo 'a:visited {color: #0B0080;}';
echo 'html, body { font-family: sans-serif;}';
echo 'body {font-size: 0.8em;}';
echo 'ul {line-height: 1.5em;}';
echo '</style>';
echo '</head>';
echo '<body>';
echo '<h1>Recent moves</h1>';

$namespace = intval($_GET['namespace']);
$offset = '';
if ($_GET['offset']) {
    $offset = preg_replace('/[^0-9]/', '', $_GET['offset']);
}
$limit = $default_limit;
if ($_GET['limit']) {
    $limit = intval($_GET['limit']);
}
$hidepatrolled = '';
if ($_GET['hidepatrolled']) {
    $hidepatrolled = intval($_GET['hidepatrolled']);
}
$dir = '';
if ($_GET['dir'] == 'prev') {
    $dir = 'prev';
}

# Connect to database
$toolserver_mycnf = parse_ini_file("/home/".get_current_user()."/.my.cnf");
$db = mysql_connect('enwiki-p.userdb.toolserver.org', $toolserver_mycnf['user'], $toolserver_mycnf['password']) or die(mysql_error());
mysql_select_db('enwiki_p', $db) or die(mysql_error());

$conditions = '';
if ($namespace > 0) {
    $conditions .= "AND log_namespace=$namespace ";
}
if ($offset) {
    if ($dir) {
        $conditions .= "AND log_timestamp > '$offset' ";
    } else {
        $conditions .= "AND log_timestamp < '$offset' ";
    }
}

$namespaces = array('', 'Talk', 'User', 'User talk', 'Wikipedia', 'Wikipedia talk', 'File', 'File talk', 'MediaWiki', 'MediaWiki talk', 'Template', 'Template talk', 'Help', 'Help talk', 'Category', 'Category talk', 'Portal', 'Portal talk', 'Book', 'Book talk');

# From http://stackoverflow.com/questions/353379/how-to-get-multiple-parameters-with-same-name-from-a-url-in-php
# $query  = explode('&', $_SERVER['QUERY_STRING']);
# $params = array();
# $params['namespace'] = array();
# foreach( $query as $param ) {
#   list($name, $value) = explode('=', $param);
#   $params[urldecode($name)][] = urldecode($value);
# }

print '<form name="input" action="." method="get">';
print 'Move-from namespace: ';
print '<select name="namespace">';
print "<option value=\"\">all but (Article)</option>";
for ($i=1; $i < count($namespaces); $i++) {
      print "<option value=\"$i\" ";
      if ($namespace == $i) print ' selected="yes"';
      print ">$namespaces[$i]</option>";
}
print '</select><br/>';
print '<input type="checkbox" name="hidepatrolled" value="1" ' . ($hidepatrolled ? 'checked' : '') . '/> Hide moves by autopatrollers<br/>';
if ($dir) { print "<input type=\"hidden\" name=\"dir\" value=\"$dir\"/>"; }
if ($offset) { print "<input type=\"hidden\" name=\"offset\" value=\"$offset\"/>"; }
if ($limit != $default_limit) { print "<input type=\"hidden\" name=\"limit\" value=\"$limit\"/>"; }
print ' <input type="submit" value="Go"/>';
print '</form>';

# $result = mysql_query($autopatroller_query) or die(mysql_error());
# while ($row = mysql_fetch_array($result)) {
#       print $row['ug_user']. ' ' . $row['is_autoreviewer'] . '<br/>';
# }

# When hidepatrolled is on, we don't know how many log entries to retrieve
# to fill the page, since some will be by autopatrollers. To deal with this
# we dynamically increase the limit until we get enough, or we get no more.
$adjusted_limit = $limit;
$count = 0;
do {
$last_count = $count;
$query = 'SELECT log_timestamp, log_namespace, log_title, log_params, log_comment, log_user, user_name ' .
         'FROM logging INNER JOIN user ON log_user=user_id ' .
#         'WHERE log_type=\'move\' AND log_namespace <> 0 AND log_params NOT LIKE \'%:%\' ' . $conditions .
         'WHERE log_type=\'move\' AND log_namespace <> 0 ' . $conditions .
         'ORDER BY log_timestamp ' . ($dir ? '' : 'DESC') . ' ' .
         'LIMIT ' . $adjusted_limit;

$result = mysql_query($query) or die(mysql_error());
$is_autoreviewer = array();
while ($row = mysql_fetch_array($result)) {
      $is_autoreviewer[$row['log_user']] = 0; # Set to 1 below if autoreviewer
}

$autopatroller_query =
   'SELECT DISTINCT ug_user ' .
   'FROM user_groups ' .
   'WHERE (ug_group=\'autoreviewer\' OR ug_group=\'sysop\') ' .
   'AND ug_user IN (' . join(',', array_keys($is_autoreviewer)). ')';

if (count(array_keys($is_autoreviewer)) > 0) {
    $result = mysql_query($autopatroller_query) or die(mysql_error());
    $is_autopatroller = array();
    while ($row = mysql_fetch_array($result)) {
	  $is_autoreviewer[$row['ug_user']] = 1;
    }
}

$result = mysql_query($query) or die(mysql_error());
$count = 0;
$text_items = array();
$newer_timestamp = '';
while ($count < $limit && $row = mysql_fetch_array($result)) {
      $old_title = $namespaces[$row['log_namespace']] . ':' . $row['log_title'];
      # $log_params = preg_split("/\\n/", $row['log_params']);
      # $new_title = $log_params[0];
      $log_params = preg_match('/target";s:[0-9]+:"([^"]*)/', $row['log_params'], $log_param_matches);
      $new_title = $log_param_matches[1];
      if (strpos($new_title, ':') !== false) {
          continue;
      }
      $user_display = htmlspecialchars($row['user_name']);
      $user_url = wiki_urlencode($row['user_name']);
      $user_id = $row['log_user'];

      if ($hidepatrolled && $is_autoreviewer[$user_id]) {
          continue;
      }

      $text_item = '';
      $text_item .=  '<li>';
      if (!$is_autoreviewer[$user_id]) { $text_item .= '<div style=\'background-color: #FFA;\'>'; }
      $text_item .=  timestamp_to_string($row['log_timestamp']);
      $text_item .=  " <a href=\"http://en.wikipedia.org/wiki/$new_title\">$new_title</a> from <a href=\"http://en.wikipedia.org/wiki/$old_title\">$old_title</a>";

      $text_item .=  ' | ';
      $text_item .=  " <a href=\"http://en.wikipedia.org/wiki/User:$user_url\">$user_display</a> (<a href=\"http://en.wikipedia.org/wiki/User_talk:$user_url\">talk</a> | <a href=\"http://en.wikipedia.org/wiki/Special:Contributions/$user_url\">contribs</a> | <a href=\"http://en.wikipedia.org/wiki/Special:Block/$user_url\">block</a>)";

      $comment = $row['log_comment'];
      while (preg_match('/(.*)\[\[([^\|\]]*)\|([^\]]*)\]\](.*)/', $comment, $m)) {
          $comment = $m[1] . '<a href="http://en.wikipedia.org/wiki/' . wiki_urlencode($m[2]) . '">' . htmlspecialchars($m[3]) . '</a>' . $m[4];
      }
      while (preg_match('/(.*)\[\[([^\]]*)\]\](.*)/', $comment, $m)) {
          $comment = $m[1] . '<a href="http://en.wikipedia.org/wiki/' . wiki_urlencode($m[2]) . '">' . htmlspecialchars($m[2]) . '</a>' . $m[4];
      }

      if ($comment <> '') {
          $text_item .=  ' <i>(' . $comment . ')</i>';
      }
      if (!$is_autoreviewer[$user_id]) { $text_item .= '</div>'; }
      $text_item .=  '</li>';
      $text_items[] = $text_item;

      $older_timestamp = $row['log_timestamp'];
      if (!$newer_timestamp) { $newer_timestamp = $row['log_timestamp']; }
      $count++;
}
if ($dir) {
    $text = join('', array_reverse($text_items));
    list($older_timestamp,$newer_timestamp) = array($newer_timestamp, $older_timestamp);
} else {
    $text = join('', $text_items);
}

$adjusted_limit *= 2;

# } while ($hidepatrolled && $count != $last_count && $count < $limit);
} while ($count != $last_count && $count < $limit);

# $paging_controls .= '<a href="' . build_url($namespace, $dir, $offset, $limit, $hidepatrolled) . '">text</a>';

$paging_controls = '';
$paging_controls .='<p>(';
if ($dir || $offset) {
    $paging_controls .= '<a href="' . build_url($namespace, '', '', $limit, $hidepatrolled) . '">latest</a>';
} else {
    $paging_controls .="latest";
}
$paging_controls .= ' | ';
if (!$dir || $offset) {
    $paging_controls .= '<a href="' . build_url($namespace, 'prev', '', $limit, $hidepatrolled) . '">earliest</a>';
} else {
    $paging_controls .="earliest";
}
$paging_controls .=') View (';
if (($dir != 'prev' && !$offset) || ($dir == 'prev' && $count < $limit)) {
   $paging_controls .= "newer $limit";
} else {
   $paging_controls .= '<a href="' . build_url($namespace, 'prev', $newer_timestamp, $limit, $hidepatrolled) . "\">newer $limit</a>";
}
$paging_controls .= " | ";
if (($dir == 'prev' && !$offset) || ($dir != 'prev' && $count < $limit)) {
   $paging_controls .= "older $limit";
} else {
   $paging_controls .= '<a href="' . build_url($namespace, '', $older_timestamp, $limit, $hidepatrolled) . "\">older $limit</a>";
}
$paging_controls .=') (';
$first = 1;
foreach (array(20, 50, 100, 250, 500) as $new_limit) {
    if ($first) { $first = 0; } else { $paging_controls .=' | '; }
    $paging_controls .= '<a href="' . build_url($namespace, $dir, $offset, $new_limit, $hidepatrolled) . "\">$new_limit</a>";
}
$paging_controls .= ')</p>';

print $paging_controls;
print "<ul>$text</ul>";
print $paging_controls;

# Close database connection
mysql_close($db);
unset($toolserver_mycnf);

/* Print a message at the bottom giving a link to the URL that produced this
   report, a timestamp, and the time it ran for, for reference and
   diagnostics. */
$time_delta = microtime_float() - $time_start;

echo '<a href="about/">About this tool</a>';

printf('<p><small>This report generated at ' . date('c')  . ' in %0.2f sec.</small></p>', $time_delta);

echo '</body>';
echo '</html>';

?>
