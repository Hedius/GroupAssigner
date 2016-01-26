<?php    

    /*
        DEFAULT VARIABLES / ACTIONS
    */
    $error = array();
    session_start();
    if (file_exists('config/config.php')) {
        require_once('config/config.php');
    } else {
        session_unset();
    }
    
    
    function ts3connect() {
        require_once('libraries/TeamSpeak3/TeamSpeak3.php');
        if (strlen(QUERYDISPLAYNAME) < 3) {
            $extension = "";
        } else {
            $extension = '&displayname='.urlencode(QUERYDISPLAYNAME);
        }
        try {
            $ts3 = TeamSpeak3::factory(
                'serverquery://'.QUERYUSER.':'.QUERYPASS.'@'.IP.':'.QUERYPORT.'?server_port='.SERVERPORT.$extension
            );
        } catch (TeamSpeak3_Exception $e) {
            return $e;
        }
        return $ts3;
    }
    
    function getClientIp() {
        if (!empty($_SERVER['HTTP_CLIENT_IP']))
            return $_SERVER['HTTP_CLIENT_IP'];
        else if(!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        else if(!empty($_SERVER['HTTP_X_FORWARDED']))
            return $_SERVER['HTTP_X_FORWARDED'];
        else if(!empty($_SERVER['HTTP_FORWARDED_FOR']))
            return $_SERVER['HTTP_FORWARDED_FOR'];
        else if(!empty($_SERVER['HTTP_FORWARDED']))
            return $_SERVER['HTTP_FORWARDED'];
        else if(!empty($_SERVER['REMOTE_ADDR']))
            return $_SERVER['REMOTE_ADDR'];
        else
            return false;
    }
    
    function randStr($length = 5) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
    

    if (empty($_SESSION['step']['client'])) {
        if (!file_exists('config/config.php')) {
            $_SESSION['step']['client'] = '';
            $error[] = array('danger', 'No Config File found! Please use the <b><a href="admin.php">Setup</a></b> to Configure the Group Assigner!');
        } else {
            $_SESSION['step']['client'] = 'client_selection';
        }
    }
    

    if ($_SESSION['step']['client'] == 'client_selection') {
        if (empty($ts3)) $ts3 = ts3connect();
        if (is_string($ts3)) {
            $error[] = array('danger', 'Can not Connect to Server! '.$ts3);
        } else {
            $detected_clients = $ts3->clientList(array('client_type' => '0', 'connection_client_ip' => getClientIp()));
            if (!empty($_POST['uid'])) {
                if (strlen($_POST['uid']) != 28 || substr($_POST['uid'], -1, 1) != '=') {
                    $error[] = array('danger', 'Invalid UID Format!');
                } else {
                    $skip = false;
                    try {
                        $client = $ts3->clientGetByUid($_POST['uid']);
                    } catch (TeamSpeak3_Exception $e) {
                        $skip = true;
                        if ($e->getMessage() == 'invalid clientID') {
                            $error[] = array('danger', 'No Client with this UID online found!');
                        } else {
                            $error[] = array('danger', 'Error happened :/ ('.$e.')');
                        }
                    }
                    if (!$skip) {
                        $disallow = false;
                        foreach (json_decode(GROUPSDISALLOW,1) as $grp) {
                            if (in_array($grp, explode(',', $client->client_servergroups))) {
                                $disallow = true;
                                break;
                            }
                        }
                        if (!$disallow) {
                            $_SESSION['dbid'] = $client->client_database_id;
                            $_SESSION['clid'] = $client->clid;
                            $_SESSION['step']['client'] = 'verify'; 
                        } else{
                            $error[] = array('danger', 'You are in a Server Group wich dont allows you to use this Assigner Tool');
                        }
                    }
                }
            } else if (!empty($_POST['clid'])) {
                $found = false;
                foreach ($detected_clients as $client) {
                    if ($client->clid == $_POST['clid']) {
                        $disallow = false;
                        foreach (json_decode(GROUPSDISALLOW,1) as $grp) {
                            if (in_array($grp, explode(',', $client->client_servergroups))) {
                                $disallow = true;
                                break;
                            }
                        }
                        if (!$disallow) {
                            $_SESSION['dbid'] = $client->client_database_id;
                            $_SESSION['clid'] = $client->clid;
                            $_SESSION['step']['client'] = 'verify';
                            $found = true;
                        } else{
                            $error[] = array('danger', 'You are in a Server Group wich dont allows you to use this Assigner Tool');
                            $found = true;
                        }
                        break;
                    }
                }
                if (!$found) {
                    $error[] = array('danger', 'Was not able to find selected Client!');
                }
            }
        }
    }
    
    
    
    
    if ($_SESSION['step']['client'] and !empty($_SESSION['verify_code']) and !empty($_POST['code'])) {
        if ($_SESSION['verify_code'] === $_POST['code']) {
            unset($_SESSION['verify_code']);
            if (RULESACTIVATE) {
                $_SESSION['step']['client'] = 'rules';
            } else {
                $_SESSION['step']['client'] = 'assigner';
            }
        } else {
            $invalidCode = true;
            if ($_SESSION['spam_protection'] < 3) {
                $error[] = array('danger', 'Invalid Code given! New Code has been sent!');
            } else {
                $error[] = array('danger', 'Invalid Code given!');
            }
        }
    }
    
    
    
    
    if ($_SESSION['step']['client'] == 'verify') {
        if (empty($_SESSION['spam_protection'])) {
            $_SESSION['spam_protection'] = 0;
        }
        if (!empty($_GET['changeUser'])) {
            if ($_SESSION['spam_protection'] == 3) {
                $_SESSION['spam_protection'] = 2;
            }
            unset($_SESSION['verify_code']);
            unset($_SESSION['clid']);
            unset($_SESSION['dbid']);
            $_SESSION['step']['client'] = 'client_selection';
            header("Refresh:0");
            die();
        }
        if (empty($ts3)) $ts3 = ts3connect();
        if (is_string($ts3)) {
            $error[] = array('danger', 'Can not Connect to Server! '.$ts3);
        } else {
            if (
                empty($_SESSION['verify_code']) 
                || !empty($_GET['request_new_code']) 
                || !empty($invalidCode)
            ) {
                if ($_SESSION['spam_protection'] < 3) {
                    $_SESSION['verify_code'] = randStr(8);
                    $skip = false;
                    try {
                        $ts3->clientGetById($_SESSION['clid'])->poke('Website Verification Code: '.$_SESSION['verify_code']);
                        $_SESSION['spam_protection']++; 
                    } catch (TeamSpeak3_Exception $e) {
                        $skip = true;
                        if ($e->getMessage() == 'invalid clientID') {
                            try {
                                $ts3->clientGetByDbid($_SESSION['dbid'])->poke('Website Verification Code: '.$_SESSION['verify_code']);
                                $_SESSION['spam_protection']++; 
                                $error[] = array('info', 'No Client has been found with given Client ID! Sent to Database ID instead...');
                            } catch (TeamSpeak3_Exception $e) {
                                $error[] = array('danger', 'No Client online found!');
                            }
                        } else {
                            $error[] = array('danger', 'Error happened :/ ('.$e.')');
                        }
                    }
                } else {
                    $error[] = array('danger', 'SPAM PROTECTION: Will not send more Verification Codes!');
                }
            }
        }
    }
    
    
    
    
    if ($_SESSION['step']['client'] == 'rules') {
        if (!empty($_GET['decline'])) {
            session_unset();
            header("Refresh:0");
            die();
        } else if (!empty($_GET['accept'])) {
            $continue = true;
            if (empty($ts3)) $ts3 = ts3connect();
            if (is_string($ts3)) {
                $error[] = array('danger', 'Can not Connect to Server! '.$ts3);
            } else {
                if (RULESACCEPTGROUP != 0 and is_numeric(RULESACCEPTGROUP)) {
                    try {
                        $notallowed = false;
                        foreach (explode(',', $ts3->clientGetByDbid($_SESSION['dbid'])->client_servergroups) as $grp) {
                            if (in_array($grp,json_decode(GROUPSDISALLOW))) {
                                $notallowed = true;
                                break;
                            }
                        }
                        if (!$notallowed) $ts3->serverGroupClientAdd(RULESACCEPTGROUP, $_SESSION['dbid']);
                    } catch (Teamspeak3_Exception $e) {
                        if ($e->getMessage() != 'duplicate entry') {
                            $error[] = array('danger', 'Error while trying to Add Group! '.$e);
                            $continue = false;
                        }
                    }
                }
                if ($continue) $_SESSION['step']['client'] = 'assigner';
            }
        }
    }
    
    if ($_SESSION['step']['client'] == 'assigner') {
        $icons = array();
        if (file_exists('config/icons.json')) $icons = json_decode(file_get_contents('config/icons.json'),1);
        if (empty($ts3)) $ts3 = ts3connect();
        if (is_string($ts3)) {
            $error[] = array('danger', 'Can not Connect to Server! '.$ts3);
        } else {
            try {
                $groups = $ts3->servergroupList(array('type' => 1));
                $cgroups = explode(',',$ts3->clientGetByDbid($_SESSION['dbid'])->client_servergroups);
            } catch (Teamspeak3_Exception $e) {
                $error[] = array('danger', 'A error occured while trying to get Server Groups and Client Groups! '. $e);
            }
        }
    }
    
?>
<html>
    <head>
        <title>Group Assigner</title>
        <link href="style/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body>
        <div class="row-fluid"> 
        <?php foreach ($error as $e) { ?>
            <div class="alert alert-<?php echo $e[0]; ?>" role="alert"><?php echo $e[1]; ?></div>
        <?php } ?>
            <?php if ($_SESSION['step']['client'] == 'client_selection') { ?>
                <div style="margin-top:5%;" class="col-md-6 col-md-offset-3 col-sm-8 col-sm-offset-2 col-lg-4 col-sm-offset-4">
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h5><b><font color="#fcfcfc">Select Client</font></b></h5>
                        </div>
                        <div class="panel-body">
                            <form action="index.php" method="POST">
                                <?php if (count($detected_clients) > 1) { ?>
                                    <div class="form-group">
                                        <label for="clid">Who are you?</label>
                                        <select class="form-control" name="clid" id="clid">
                                            <?php foreach ($detected_clients as $client) { ?>
                                                <option value="<?php echo $client->clid ?>"><?php echo $client->client_nickname; ?></option>
                                            <?php } ?>
                                        </select>   
                                    </div>
                                <?php } else if (count($detected_clients) == 1) { ?>
                                    <?php 
                                        $clid = array_keys($detected_clients)[0];
                                    ?>
                                    <div class="form-group">
                                        <label for="dbid">Is this you?</label>
                                        <input type="text" class="form-control" value="<?php echo $detected_clients[$clid]->client_nickname; ?>" disabled />
                                        <input type="hidden" name="clid" id="clid" value="<?php echo $detected_clients[$clid]->clid; ?>" />
                                    </div>
                                <?php } else { ?>
                                    <div class="form-group">
                                        The Auto Detection was not able to find a Client on the TeamSpeak Server!<br/>
                                        Please Connect to the TeamSpeak Server or if you are connected enter you UID manually<br/>
                                    </div>
                                    <div class="form-group">
                                        <label for="uid">Unique User ID</label>
                                        <input type="text" class="form-control" name="uid" id="uid" placeholder="Unique User ID"/>
                                    </div>
                                <?php } ?>
                                <div class="form-group">
                                    <div class="pull-right">
                                        <button type="submit" class="btn btn-primary">Verify</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="container">
                        <?php echo hex2bin('436f70797269676874203c6120687265663d22687474703a2f2f6d756c7469766974616d696e2e777466223e6d756c7469766974616d696e2e7774663c2f613e'); ?>
                    </div>
                </div>
            <?php } ?>
            <?php if ($_SESSION['step']['client'] == 'verify') { ?>
                <div style="margin-top:5%;" class="col-md-6 col-md-offset-3 col-sm-8 col-sm-offset-2 col-lg-4 col-sm-offset-4">
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h5><b><font color="#fcfcfc">Enter Verification Code</font></b></h5>
                        </div>
                        <div class="panel-body">
                            <form action="index.php" method="POST">
                                    <div class="form-group">
                                        <label for="uid">Verification Code</label>
                                        <input type="text" class="form-control" name="code" id="code" placeholder="Verification Code"/>
                                    </div>
                                <div class="form-group">
                                    <div class="pull-right">
                                        <button type="submit" class="btn btn-primary">Verify</button>
                                    </div>
                                </div>
                            </form>
                            <div class="btn-group" role="group">
                                <a href="index.php?request_new_code=1" type="button" class="btn btn-default">Resend Verification Code</a>
                                <a href="index.php?changeUser=1" type="button" class="btn btn-default">Change User</a>
                            </div>
                        </div>
                    </div>
                    <div class="container">
                        <?php echo hex2bin('436f70797269676874203c6120687265663d22687474703a2f2f6d756c7469766974616d696e2e777466223e6d756c7469766974616d696e2e7774663c2f613e'); ?>
                    </div>
                </div>
            <?php } ?>
            <?php if ($_SESSION['step']['client'] == 'rules') { ?>
                <div style="margin-top:5%;" class="col-md-6 col-md-offset-3 col-sm-8 col-sm-offset-2 col-lg-4 col-sm-offset-4">
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h5><b><font color="#fcfcfc">Rules</font></b></h5>
                        </div>
                        <div class="panel-body">
                            <ul class="list-group">
                                <?php foreach (json_decode(RULES) as $index => $rule) { ?>
                                    <a href="#" class="list-group-item">
                                        <h4 class="list-group-item-heading"><code>§<?php echo $index + 1; ?></code></h4>
                                        <p class="list-group-item-text"><?php echo $rule; ?></p>
                                    </a>
                                <?php }  ?>
                            </ul>
                            <center>
                                <div class="btn-group" role="group">
                                    <a href="index.php?decline=1" type="button" class="btn btn-default">Decline</a>
                                    <a href="index.php?accept=1" type="button" class="btn btn-primary">Accept</a>
                                </div>
                            </center>
                        </div>
                    </div>
                    <div class="container">
                        <?php echo hex2bin('436f70797269676874203c6120687265663d22687474703a2f2f6d756c7469766974616d696e2e777466223e6d756c7469766974616d696e2e7774663c2f613e'); ?>
                    </div>
                </div>
            <?php } ?>
            <?php if ($_SESSION['step']['client'] == 'assigner') { ?>
                <style>
                    .notInGroup {
                        opacity: 0.1;
                    }
                </style>
                <div style="margin-top:5%;" class="col-md-6 col-md-offset-3 col-sm-8 col-sm-offset-2 col-lg-4 col-sm-offset-4">
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h5><b><font color="#fcfcfc">Assigner</font></b><span class="badge pull-right" id="grpdisplay">0 / 0 Groups Assigned</span></h5>
                        </div>
                        <div class="panel-body">
                            <table style="font-size:13px;" class="table table-sm">
                                <tr>
                                    <th>Icon</th>
                                    <th>Group Name (ID)</th>
                                    <th>Icon</th>
                                    <th>Group Name (ID)</th>
                                </tr>
                                <?php 
                                    $half = floor(count($groups) / 2);
                                    $i = 0;
                                    $active = json_decode(GROUPS);
                                    $grpcount = 0;
                                    foreach ($cgroups as $grp) {
                                        if (in_array($grp, $active)) $grpcount++;
                                    }
                                ?>
                                <?php foreach ($groups as $grp) { ?>
                                    <?php if (array_key_exists($grp->sgid, $icons) and in_array($grp->sgid, $active)) { ?>
                                    <?php if ($i % 2 == 0) echo '<tr>'; ?>
                                        <td onclick="toggleGroup(<?php echo $grp->sgid; ?>)"><img <?php if (!in_array($grp->sgid, $cgroups)) echo 'class="notInGroup"'; ?> id="img_<?php echo $grp->sgid ?>" height="16" width="16" src="<?php echo $icons[$grp->sgid] ?>"></img></td>
                                        <td onclick="toggleGroup(<?php echo $grp->sgid; ?>)"><button class="btn btn-primary btn-xs"><?php echo $grp->name ?></button></td>
                                    <?php if ($i % 2 == 1) echo '</tr>'; ?>
                                <?php $i++;}} ?>
                            </table>
                        </div>
                    </div>
                    <div class="container">
                        <?php echo hex2bin('436f70797269676874203c6120687265663d22687474703a2f2f6d756c7469766974616d696e2e777466223e6d756c7469766974616d696e2e7774663c2f613e'); ?>
                    </div>
                </div>
                <script>
                    var maxGroups = <?php echo MAXGROUPS ?>;
                    var currentGroups = <?php echo $grpcount ?>;
                    var badge = document.getElementById('grpdisplay');
                
                    function toggleGroup(grpid) {
                        var img = document.getElementById('img_'+grpid);
                        if (img.className == 'notInGroup') {
                            $.ajax({url: "api.php?addGroup="+grpid, success: function(result){
                                if (result == 'true') {
                                    currentGroups = currentGroups + 1;
                                    updateGroups();
                                    img.className = '';
                                }
                            }});
                        } else {
                            $.ajax({url: "api.php?delGroup="+grpid, success: function(result){
                                if (result == 'true') {
                                    currentGroups = currentGroups - 1;
                                    updateGroups();
                                    img.className = 'notInGroup';
                                }
                            }});
                        }
                    }
                    
                    function updateGroups() {
                        badge.innerHTML = currentGroups+'/'+maxGroups+' Groups Assigned';
                    }
                    
                    updateGroups();
                </script>
            <?php } ?>
        </div>
    </body>
    <script src="style/js/jquery.min.js"></script>
    <script src="style/js/bootstrap.min.js"></script>
</html>