<?php
if (php_sapi_name() !== "cli") {
    die("\033[41m\033[37mYou may only run this inside of the PHP Command Line! If you did run this in the command line, please report: \"".php_sapi_name()."\" to the InstagramLive-PHP Repo!\033[0m");
}

logM("Loading InstagramLive-PHP v0.4...");
set_time_limit(0);
date_default_timezone_set('America/New_York');

//Load Depends from Composer...
require __DIR__.'/vendor/autoload.php';
use InstagramAPI\Instagram;
use InstagramAPI\Request\Live;

require_once 'config.php';
/////// (Sorta) Config (Still Don't Touch It) ///////
$debug = false;
$truncatedDebug = false;
/////////////////////////////////////////////////////

if (IG_USERNAME == "USERNAME" || IG_PASS == "PASSWORD") {
    logM("\033[41m\033[37mDefault Username and Passwords have not been changed! Exiting...\033[0m");
    exit();
}

//Login to Instagram
logM("Logging into Instagram...");
$ig = new Instagram($debug, $truncatedDebug);

try {
    $loginResponse = $ig->login(IG_USERNAME, IG_PASS);

    if ($loginResponse !== null && $loginResponse->isTwoFactorRequired()) {
        logM("Two-Factor Required! Please check your phone for an SMS Code!");
        $twoFactorIdentifier = $loginResponse->getTwoFactorInfo()->getTwoFactorIdentifier();
        print "\nType your 2FA Code from SMS> ";
        $handle = fopen ("php://stdin","r");
        $verificationCode = trim(fgets($handle));
        logM("Logging in with 2FA Code...");
        $ig->finishTwoFactorLogin(IG_USERNAME, IG_PASS, $twoFactorIdentifier, $verificationCode);
    }
} catch (\Exception $e) {
    if (strpos($e->getMessage(), "Challenge") !== false) {
        logM("\033[41m\033[37mAccount Flagged: Please sign out of all phones and try logging into instagram.com from this computer before trying to run this script again!\033[0m");
        exit();
    }
    echo "\033[41m\033[37mError While Logging in to Instagram: ".$e->getMessage()."\033[0m\n";
    exit(0);
}

//Block Responsible for Creating the Livestream.
try {
    if (!$ig->isMaybeLoggedIn) {
        logM("\033[41m\033[37mCouldn't Login! Exiting!\033[0m");
        exit();
    }
    logM("Logged In! \033[32mCreating Livestream\033[0m...");
    $stream = $ig->live->create();
    $broadcastId = $stream->getBroadcastId();
    // Switch from RTMPS to RTMP upload URL, since RTMPS doesn't work well.
    $streamUploadUrl = preg_replace(
        '#^rtmps://([^/]+?):443/#ui',
        'rtmp://\1:80/',
        $stream->getUploadUrl()
    );

    //Grab the stream url as well as the stream key.
    $split = preg_split("[".$broadcastId."]", $streamUploadUrl);

    $streamUrl = $split[0];
    $streamKey = $broadcastId.$split[1];

    logM("\n");
    logM("================================ Stream URL ================================\n\033[36m".$streamUrl."\033[0m\n================================ Stream URL ================================");

    logM("======================== Current Stream Key ========================\n\033[45m\033[37m".$streamKey."\033[0m\n======================== Current Stream Key ========================");
    logM("\n");

    logM("\033[41m\033[37m!!!!! Please Start Streaming in your encoder then start live withe command \033[43m\033[30m'start'\033[41m\033[37m !!!!!\033[0m");

    logM("\n");

    logM("Live Stream is Ready for Commands:");
    printHepler();

    newCommand($ig->live, $broadcastId, $streamUrl, $streamKey);
    logM("\033[41m\033[37mSomething Went Super Wrong! Attempting to At-Least Clean Up!\033[0m");
    $ig->live->getFinalViewerList($broadcastId);
    $ig->live->end($broadcastId);
} catch (\Exception $e) {
    echo "\033[41m\033[37mError While Creating Livestream: ".$e->getMessage()."\033[0m\n";
}

/**
 * The handler for interpreting the commands passed via the command line.
 */
function newCommand(Live $live, $broadcastId, $streamUrl, $streamKey) {
    print "\n> ";
    $handle = fopen ("php://stdin","r");
    $line = trim(fgets($handle));
    if($line == 'ecomments') {
        $live->enableComments($broadcastId);
        logM("Enabled Comments!");
    } elseif ($line == 'dcomments') {
        $live->disableComments($broadcastId);
        logM("\033[43m\033[30mDisabled Comments!\033[0m");
    } elseif ($line == 'stop' || $line == 'end') {
        fclose($handle);
        //Needs this to retain, I guess?
        $live->getFinalViewerList($broadcastId);
        $live->end($broadcastId);
        logM("Stream Ended!\nWould you like to keep the stream archived for 24 hours? Type \"yes\" to do so or anything else to not.");
        print "> ";
        $handle = fopen ("php://stdin","r");
        $archived = trim(fgets($handle));
        if ($archived == 'yes') {
            logM("Adding to Archive!");
            $live->addToPostLive($broadcastId);
            logM("Livestream added to archive!");
        }
    } elseif ($line == 'url') {
        logM("================================ Stream URL ================================\n\033[36m".$streamUrl."\033[0m\n================================ Stream URL ================================");
    } elseif ($line == 'key') {
        logM("======================== Current Stream Key ========================\n\033[45m\033[30m".$streamKey."\033[0m\n======================== Current Stream Key ========================");
    } elseif ($line == 'info') {
        $info = $live->getInfo($broadcastId);
        $status = $info->getStatus();
        $muted = var_export($info->is_Messages(), true);
        $count = $info->getViewerCount();
        logM("Info:\nStatus: $status\nMuted: $muted\nViewer Count: $count");
    } elseif ($line == 'viewers') {
        logM("Viewers:");
        $live->getInfo($broadcastId);
        foreach ($live->getViewerList($broadcastId)->getUsers() as &$cuser) {
            logM("@".$cuser->getUsername()." (".$cuser->getFullName().")");
        }
    } elseif ($line == 'help') {
        printHepler();
    }
    elseif ($line == 'start') {
        $live->start($broadcastId);
        logM("\033[42m\033[37mLive is started\033[0m");

        $live->disableComments($broadcastId);
        logM("\033[43m\033[30m/!\\ Comments are disable by default!\033[0m");
    } elseif ($line == 'exit') {
        logM("Wrapping up and exiting...");
        exit();
    } else {
       logM("Invalid Command. Type \"help\" for help!");
    }
    fclose($handle);
    newCommand($live, $broadcastId, $streamUrl, $streamKey);
}

function printHepler() {
    logM("Commands:\nhelp - Prints this message\nsart - Start the live\nurl - Prints Stream URL\nkey - Prints Stream Key\ninfo - Grabs Stream Info\nviewers - Grabs Stream Viewers\necomments - Enables Comments\ndcomments - Disables Comments\nstop - Stops the Live Stream\nexit - Exite programm");
}

/**
 * Logs a message in console but it actually uses new lines.
 */
function logM($message) {
    print $message."\n";
}