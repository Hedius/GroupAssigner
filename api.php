<?php
    session_start();

    /*
        Original Code for the Function getMime extracted from TS3 PHP Framework and has been modified
    */    
    function getMime($binary) {
        if(!preg_match('/\A(?:(\xff\xd8\xff)|(GIF8[79]a)|(\x89PNG\x0d\x0a)|(BM)|(\x49\x49(\x2a\x00|\x00\x4a))|(FORM.{4}ILBM))/', $binary, $matches))
        {
            return "application/octet-stream";
        }
        $type = array(
            1 => 'jpg',
            2 => 'gif',
            3 => 'png',
            5 => 'tff',
            6 => 'ilbm',
        );
        return $type[count($matches)-1];
    }
    
    
    function ts3connect() {
        require_once('libraries/TeamSpeak3/TeamSpeak3.php');
        if (strlen(QUERYDISPLAYNAME) < 3) {
            $extension = "";
        } else {
            $extension = '&nickname='.urlencode(QUERYDISPLAYNAME);
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
    
    if (!empty($_GET['addGroup']) and !empty($_SESSION['step']['client']) and !empty($_SESSION['dbid']) and $_SESSION['step']['client'] == 'assigner') {
        if (file_exists('config/config.php')) {
            require_once('config/config.php');
            if (in_array($_GET['addGroup'],json_decode(GROUPS))) {
                $ts3 = ts3connect();
                if (is_string($ts3)) {
                    echo json_encode(FALSE);
                } else {
                    try {
                        $grpcount = 0;
                        $notallowed = false;
                        foreach (explode(',', $ts3->clientGetByDbid($_SESSION['dbid'])->client_servergroups) as $grp) {
                            if (in_array($grp, json_decode(GROUPS)) and !in_array($grp,json_decode(GROUPSDISALLOW))) {
                                $grpcount++;
                            } else if (in_array($grp,json_decode(GROUPSDISALLOW))) {
                                $notallowed = true;
                                break;
                            }
                        }
                        if ($grpcount >= MAXGROUPS || $notallowed) {
                            echo json_encode(FALSE);
                        } else {
                            $ts3->serverGroupClientAdd($_GET['addGroup'], $_SESSION['dbid']);
                            echo json_encode(TRUE);
                        }
                    } catch (TeamSpeak3_Exception $e) {
                        echo json_encode(FALSE);
                    }
                }
            }
        } else {
            echo json_encode(FALSE);
        }
    } else if (!empty($_GET['delGroup']) and !empty($_SESSION['step']['client']) and !empty($_SESSION['dbid']) and $_SESSION['step']['client'] == 'assigner') {
        if (file_exists('config/config.php')) {
            require_once('config/config.php');
            if (in_array($_GET['delGroup'],json_decode(GROUPS))) {
                $ts3 = ts3connect();
                if (is_string($ts3)) {
                    echo json_encode(FALSE);
                } else {
                    $notallowed = false;
                    foreach (explode(',', $ts3->clientGetByDbid($_SESSION['dbid'])->client_servergroups) as $grp) {
                        if (in_array($grp,json_decode(GROUPSDISALLOW))) {
                            $notallowed = true;
                            break;
                        }
                    }
                    if (!$notallowed) {
                        try {
                            $ts3->serverGroupClientDel($_GET['delGroup'], $_SESSION['dbid']);
                            echo json_encode(TRUE);
                        } catch (TeamSpeak3_Exception $e) {
                            echo json_encode(FALSE);
                        }
                    } else {
                        echo json_encode(FALSE);
                    }
                }
            } else {
                echo json_encode(FALSE);
            }
        } else {
            echo json_encode(FALSE);
        }
    } else if (!empty($_GET['syncIcons']) and !empty($_SESSION['step']['admin']) and $_SESSION['step']['admin'] == 'configurator') {
        try {
            require_once('config/config.php');
            require_once('libraries/TeamSpeak3/TeamSpeak3.php');
            if (file_exists('config/icons.json')) {
                $json = json_decode(file_get_contents('config/icons.json'), 1);
            } else {
                $json = array();
            }
            $groups = array();
            $error = false;
            $ts3 = TeamSpeak3::factory("serverquery://".QUERYUSER.":".QUERYPASS."@".IP.":".QUERYPORT."/?server_port=".SERVERPORT."&nickname=".urlencode('Icon Sync'));
            foreach ($ts3->serverGroupList() as $sg) {
                if ($sg['iconid']) {
                    try {
                        $groups[$sg['sgid']] = $sg->iconDownload();
                    } catch (TeamSpeak3_Exception $e) {}
                }
            }
            foreach ($groups as $sgid => $binary) {
                if (!empty($binary)) {
                    $type = getMime($binary);
                    if ($type != 'application/octet-stream') {
                        file_put_contents('icons/'.$sgid.'.'.$type, $binary);
                        $json[$sgid] = 'icons/'.$sgid.'.'.$type;
                    }
                };
            }
            file_put_contents('config/icons.json', json_encode($json));
        } catch (Exception $e) {
            echo json_encode(FALSE);
            $error = true;
        }
        if (!$error) {
            echo json_encode(TRUE);
        }
    } else {
        echo json_encode(FALSE);
    }
?>
