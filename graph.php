<?php
/*
The MIT License (MIT)

Copyright (c) 2014 Matt Paine

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/
if (file_exists("/etc/freepbx_graph_config.php")) {
    require_once ("/etc/freepbx_graph_config.php");
}
else {
    die("Cannot find the config file");
}
if (empty($database_host)) {
    $database_host = '127.0.0.1';
}
if (empty($database_user)) {
    $database_user = 'freepbxuser';
}
if (empty($database_db)) {
    $database_db = 'asterisk';
}
$mysqli = new mysqli($database_host, $database_user, $database_password, $database_db);

$nodes = array();
$edges = array();
$extensionsToCreate = array(-1);

function dotescape($string) {
    $string = str_replace('{', '(', $string);
    $string = str_replace('}', ')', $string);
    return $string;
}
function getEdge($destination, $type = '') {
    $nodeName = getNodeName($destination, $type);
    switch ($type) {
        case 'destination':
            $parts = explode(',', $destination);
            if (substr($parts[0], 0, 9) == 'ext-local' && substr($parts[1], 0, 2) == 'vm') {
                $port = 'vm' . substr($parts[1], 2, 1);
            }
            else if ($parts[0] == 'ext-fax') {
                $port = 'fax';
            }
            break;
    }
    if ($port) {
        return array($nodeName, $port);
    }
    else {
        return $nodeName;
    }
}
function getNodeName($destination, $type = '') {
    global $nodes, $extensionsToCreate;
    switch ($type) {
        case 'DID':
            $destination = sprintf('did,%s-%s', $destination['cidnum'], $destination['extension']);
            break;
        case 'SetCID':
            $destination = sprintf('app-setcid,%s', $destination['cid_id']);
            break;
        case 'TimeConditions':
            $destination = sprintf('timeconditions,%s', $destination['timeconditions_id']);
            break;
        case 'IVR':
            $destination = sprintf('ivr-%s', $destination['id']);
            break;
        case 'RingGroup':
            $destination = sprintf('ext-group,%s', $destination['grpnum']);
            break;
        case 'DayNight':
            $destination = sprintf('app-daynight,%s', $destination['ext']);
            break;
        case 'Extension':
            $destination = $destination['extension'];
            break;
        case 'Announcement':
            $destination = sprintf('app-announcement-%s,s', $destination['announcement_id']);
            break;
        case 'Queue':
            $destination = sprintf('ext-queues,%s', $destination['extension']);
            break;
        case 'destination':
            $parts = explode(',', $destination);
            if ($parts[0] == 'app-blackhole') {
                $rand = $parts[1] . rand();
                $nodes[$rand] = createSimpleNode($parts[1]);
                $destination = $rand;
            }
            else if (substr($parts[0], 0, 4) == 'ivr-') {
                $destination = $parts[0];
            }   
            else if (substr($parts[0], 0, 9) == 'ext-local' && substr($parts[1], 0, 2) == 'vm') {
                $extensionsToCreate[] = substr($parts[1], 3);
                $destination = sprintf('%s', substr($parts[1], 3));
            }
            else if ($parts[0] == 'from-did-direct' || $parts[0] == 'ext-fax') {
                $destination = $parts[1];
                $extensionsToCreate[] = $destination;
            }
            else {
                $destination = sprintf('%s,%s', $parts[0], $parts[1]);
            };
            break;
    }
    return $destination;
}
function createSimpleNode($name, $shape = 'ellipse') {
    return sprintf('label="%s" shape="%s"', $name, $shape);
}
function createDidNode($did) {
    return sprintf('label="{ <in> DID - %s | <cid> %s | <did> %s }" shape="record"', $did['description'], $did['cidnum'], $did['extension']);
}
function createSetCidNode($setCid) {
    return sprintf('label="{ <in> Set Caller ID - %s | %s | %s }" shape="record"', $setCid['description'], dotescape($setCid['cid_name']), dotescape($setCid['cid_num']));
}
function createTimeConditionsNode($timecondition) {
    global $mysqli;
    $timeranges = array();
    $query = $mysqli->query("SELECT * FROM timegroups_details WHERE timegroupid = " . $timecondition['time']);
    while ($row = $query->fetch_array(MYSQLI_ASSOC)) {
        $timeranges[] = str_replace('|', ' - ', $row['time']);
    }
    return sprintf('label="{ <in> Time Condition - %s | %s | { <out0> match | <out1> unmatched} }" shape="record"', $timecondition['displayname'], implode(' | ', $timeranges));
}
function createIvrNode($ivr, $nodeName) {
    global $mysqli, $edges;
    $destinations = array();
    $query = $mysqli->query("SELECT * FROM ivr_entries WHERE ivr_id = " . $ivr['id'] . " ORDER BY selection");
    while ($row = $query->fetch_array()) {
        $destinations[] = sprintf('<%s> %s', "ivr".$row['selection'], $row['selection']);
        $edges[] = array(
            array($nodeName, "ivr".$row['selection']),
            getNodeName($row['dest'], 'destination'),
        );
    }
    $destinations[] = '<out0> timeout';
    $destinations[] = '<out1> invalid';
    return sprintf('label="{ <in> IVR - %s | { %s } }" shape="record"', $ivr['description'], implode(' | ', $destinations));
}
function createRingGroupNode($ringgroup) {
    global $edges;
    $details = sprintf('%s - %s seconds', $ringgroup['strategy'], $ringgroup['grptime']);
    $destinations = array();
    $destinations[] = '<out0> failover';
    return sprintf('label="{ <in> RingGroup - %s (%d) | %s | { %s } | <out0> failover }" shape="record"', $ringgroup['description'], $ringgroup['grpnum'],  $details, implode(' | ', explode(' | ', $ringgroup['grplist'])));
}
function createDayNightNode($daynight, $nodeName) {
    global $mysqli, $edges;
    $query = $mysqli->query("SELECT * FROM daynight WHERE ext = " . $daynight['ext']);
    $daynight = array('ext' => $daynight['ext']);
    while ($row = $query->fetch_array(MYSQLI_ASSOC)) {
        $daynight[$row['dmode']] = $row['dest'];
    }

    $query = $mysqli->query("SELECT * FROM featurecodes WHERE modulename = 'daynight' AND featurename = 'toggle-mode-" . $daynight['ext'] . "'");
    while ($row = $query->fetch_array(MYSQLI_ASSOC)) {
        // TODO: Maybe a custom mode has been selected
        $daynight['featurecode'] = $row['defaultcode'];
    }
    $edges[] = array(
        array($nodeName, 'day'),
        getNodeName($daynight['day'], 'destination'),
    );
    $edges[] = array(
        array($nodeName, 'night'),
        getNodeName($daynight['night'], 'destination'),
    );
    return sprintf('label="{ <in> Flow Control - %s (%s) | { <day> %s | <night> %s } }" shape="record"',
        $daynight['fc_description'],
        $daynight['featurecode'],
        'day',
        'night');
}
function createExtensionNode($extension) {
    global $mysqli;
    $lines = array(sprintf('<in> %s - %s', $extension['extension'], $extension['name']));
    if ($extension['voicemail'] == 'default') {
        $lines[] = '<vmu> VM Unavailable';
        $lines[] = '<vmb> VM Busy';
    }
    $query = $mysqli->query(sprintf("SELECT * FROM fax_users WHERE user = '%s'", $extension['extension']));
    while ($row = $query->fetch_array(MYSQLI_ASSOC)) {
        if ($row['faxenabled'] == 'true') {
            $lines[] = '<fax> ' . $row['faxemail'];
        }
    }
    return sprintf('label="{ %s }" shape="record"', implode(' | ', $lines));
}
function createAnnouncementNode($announcement)
{
    global $mysqli;
    $query = $mysqli->query(sprintf("SELECT * FROM recordings WHERE id = %d", $announcement['recording_id']));
    $name = '';
    while ($row = $query->fetch_array(MYSQLI_ASSOC)) {
        $name = $row['displayname'];
    }
    return sprintf('label="{ <in> Announcement - %s | Recording: %s }" shape="record"',
        $announcement['description'],
        $name);
}
function createQueueNode ($queue)
{
    return sprintf('label="{ Queue - %s }" shape="record"',
        $queue['descr']);
}

function createNode($data, $type, $nodeName) {
    $ret = null;
    switch ($type) {
        case 'DID':
            $ret = createDidNode($data);
            break;
        case 'SetCID':
            $ret = createSetCidNode($data);
            break;
        case 'TimeConditions':
            $ret = createTimeConditionsNode($data);
            break;
        case 'IVR':
            $ret = createIvrNode($data, $nodeName);
            break;
        case 'RingGroup':
            $ret = createRingGroupNode($data);
            break;
        case 'DayNight':
            $ret = createDayNightNode($data, $nodeName);
            break;
        case 'Extension':
            $ret = createExtensionNode($data);
            break;
        case 'Announcement':
            $ret = createAnnouncementNode($data);
            break;
        case 'Queue':
            $ret = createQueueNode($data);
            break;
    }
    return $ret;
}

// BUILT-INS
$nodes["START"] = createSimpleNode("START", 'octagon');

function buildNodesFromQuery($query, $type, $destination, $extra = null) {
    global $mysqli, $nodes, $edges;
    $result = $mysqli->query($query);
    if (!is_array($destination)) {
        $destination = array($destination);
    }
    while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
        $nodeName = getNodeName($row, $type);
        $nodes[$nodeName] = createNode($row, $type, $nodeName);
        foreach ($destination as $key => $dest) {
            //$edges[] = array(array($nodeName, "out$key"), getNodeName($row[$dest], 'destination'));
            $edges[] = array(array($nodeName, "out$key"), getEdge($row[$dest], 'destination'));
        }
        if ($extra) {
            call_user_func_array($extra, array($nodeName, $row, $type));
        }
    }
}

// DID
function extraForDid($nodeName, $row, $type) {
    global $edges;
    $edges[] = array(getNodeName("START"), $nodeName);
}
buildNodesFromQuery("SELECT * FROM incoming;", 'DID', 'destination', 'extraForDid');
buildNodesFromQuery("SELECT * FROM setcid", 'SetCID', 'dest');
buildNodesFromQuery("SELECT * FROM timeconditions", 'TimeConditions', array('truegoto', 'falsegoto'));
buildNodesFromQuery("SELECT * FROM ivr_details", 'IVR', array('timeout_destination', 'invalid_destination'));
buildNodesFromQuery("SELECT * FROM ringgroups", 'RingGroup', 'postdest');
buildNodesFromQuery("SELECT * FROM daynight WHERE dmode = 'fc_description'", 'DayNight', array());
buildNodesFromQuery(sprintf("SELECT * FROM users WHERE extension in (%s)", implode(',', $extensionsToCreate)), 'Extension', array());
buildNodesFromQuery("SELECT * FROM announcement", 'Announcement', 'post_dest');
buildNodesFromQuery("SELECT * FROM queues_config", 'Queue', 'dest');
?>

<html>
    <head>
    </head>
    <body onload="">
        <script type="text/vnd.graphviz" id="phone_graph">
digraph G {
    graph [
        rankdir = "TB"
    ];
    node [
        shape = "ellipse"
    ];

    <?php foreach ($nodes as $name => $node) : ?>
        "<?php echo $name;?>" [
            <?php echo $node; ?>
        ];
    <?php endforeach; ?>
    <?php foreach ($edges as $edge) : ?>
        <?php if (is_array($edge[0])) : ?>
            "<?php echo $edge[0][0]; ?>"<?php echo ($edge[0][1]) ? ':'.$edge[0][1].':s' : ''?>
        <?php else : ?>
            "<?php echo $edge[0]; ?>"
        <?php endif; ?>
        ->
        <?php if (is_array($edge[1])) : ?>
            "<?php echo $edge[1][0]; ?>"<?php echo ($edge[1][1]) ? ':'.$edge[1][1].':w' : ''?>
        <?php else : ?>
            "<?php echo $edge[1]; ?>"
        <?php endif; ?>
    <?php endforeach; ?>
}
        </script>
        <!-- Thankyou: http://mdaines.github.io/viz.js/viz.js -->
        <script src="viz.js"></script>
        <script>
            var message;
            try {
                message = Viz(document.getElementById('phone_graph').innerHTML, 'svg');
            } catch (e) {
                message = e.toString();
            }
            document.body.innerHTML += message;
        </script>
    </body>
</html>
<?php
