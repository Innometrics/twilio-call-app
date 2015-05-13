<?php

// Namespaces and autoloading
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Innometrics\Helper;
require_once('vendor/autoload.php');

// Base application
$app = new Silex\Application();
$app['debug'] = true;

// Innometrics helper
$inno = new Helper();

$inno->setVars(array(
    'bucketName'    => getenv('INNO_BUCKET_ID'),
    'appKey'        => getenv('INNO_APP_KEY'),
    'appName'       => getenv('INNO_APP_ID'),
    'groupId'       => getenv('INNO_COMPANY_ID'),
    'apiUrl'        => getenv('INNO_API_HOST')
));

$inno->setVar('collectApp', getenv('INNO_APP_ID'));



//
// All ready, configure http requests handlers!
//

// Root
$app->get('/', function() {
    return 'Hello, I am Feedback Recorder application! Sorry but I have not web interface :(';
});

// Handle Profile Stream from Innometrics DH
$app->post('/', function(Request $request) use($app, $inno) {
    try {
        $data = $inno->getStreamData($request->getContent());
    } catch (\ErrorException $error) {
        error_log($error->getMessage());
        return $app->json(array(
            'error' => $error->getMessage()
        ));
    }

    $settings = array(
        'TWILIO_ACCOUNT_SID'    => null,
        'TWILIO_AUTH_TOKEN'     => null,
        'TWILIO_NUMBER'         => null,
        'NUMBER_EVENT_DATA' => 'phonenumber'
    );

    try {
        $settings = array_merge($settings, $inno->getSettings());
    } catch (\ErrorException $error) {
        error_log($error->getMessage());
        return $app->json(array(
            'error' => $error->getMessage()
        ));
    }

    $profile = $data['profile']['id'];
    $inno->setVar('profileId', $profile);
    $eventData = $data['data'];
    $numberEventDataName = $settings['NUMBER_EVENT_DATA'];
    $number = isset($eventData[$numberEventDataName]) ? $eventData[$numberEventDataName] : null;
    if (empty($number)) {
        $error = new \ErrorException('Event in Profile %s has not phone number in data "%s"', $profile, $numberEventDataName);
        error_log($error->getMessage());
        return $app->json(array(
            'error' => $error->getMessage()
        ));
    }

    $client = new Services_Twilio(
        $settings['TWILIO_ACCOUNT_SID'],
        $settings['TWILIO_AUTH_TOKEN']
    );

    try {
        $collectApp = $data['session']['collectApp'];
        $section = $data['session']['section'];
        $url = createUrlForPath($request, $profile, '/call') . sprintf('/%s/%s', $collectApp, $section);
        $client->account->calls->create(
            $settings['TWILIO_NUMBER'],
            $number,
            $url
        );
        error_log($url);
    } catch (Services_Twilio_RestException $e) {
        error_log($e->getMessage());
        return $app->json(array(
            'error' => "Twilio error: " . $e->getMessage()
        ));
    }

    return $app->json(array(
        'error' => false,
        'debug' => array(
            $settings['TWILIO_NUMBER'],
            $number,
            $url
        )
    ));
});

// Make a call
$app->post('/call/{profile}/{sign}/{collectApp}/{section}', function(Request $request, $profile, $sign, $collectApp, $section) use($app, $inno) {

    error_log('>>> /call/{profile}/{sign}/{collectApp}/{section}');
    if (!checkProfileSign($profile, $sign)) {
        error_log('Bad request');
        return $app->json(array(
            'error' => 'Bad request'
        ), 400);
    }

    $settings = array(
        'RECORD_TIMEOUT'    => 5,
        'RECORD_MAX_LENGTH' => 3600,
        'RECORD_PLAY_BEEP'  => true
    );

    try {
        $settings = array_merge($settings, $inno->getSettings());
    } catch (\ErrorException $error) {
        error_log($error->getMessage());
        return $app->json(array(
            'error' => $error->getMessage()
        ));
    }

    new Services_Twilio($settings['TWILIO_ACCOUNT_SID'], $settings['TWILIO_AUTH_TOKEN']);

    $action = createUrlForPath($request, $profile, '/afterCall') . sprintf('/%s/%s', $collectApp, $section);

    $twiml = new Services_Twilio_Twiml();
    $twiml->record(array(
        'action'    => $action,
        'timeout'   => $settings['RECORD_TIMEOUT'],
        'maxLength' => $settings['RECORD_MAX_LENGTH'],
        'playBeep'  => $settings['RECORD_PLAY_BEEP'],
        'method'    => 'GET'
    ));

    return new Response(
        $twiml,
        200,
        ['Content-Type' => 'application/xml']
    );

});

// Handle request from Twilio when record is ready
$app->get('/afterCall/{profile}/{sign}/{collectApp}/{section}', function(Request $request, $profile, $sign, $collectApp, $section) use($app, $inno) {
    error_log('>>> /afterCall/{profile}/{sign}/{collectApp}/{section}');
    if (!checkProfileSign($profile, $sign)) {
        error_log('Bad request');
        return $app->json(array(
            'error' => 'Bad request'
        ), 400);
    }

    // RecordingUrl
    // RecordingDuration
    // Digits

    $recordingUrl = $request->get('RecordingUrl');

    if (!empty($recordingUrl)) {
        $recordingUrlToMp3 = sprintf('%s.mp3', $recordingUrl);
        error_log($recordingUrlToMp3);

        $inno->setVar('profileId', $profile);
        $inno->setVar('collectApp', $collectApp);
        $inno->setVar('section', $section);
        $inno->setAttributes(array(
            'last-voice-feedback' => $recordingUrlToMp3
        ));
    }

    return $app->json(array(
        'error' => false
    ));
});

$app->run();

//
// Helpers functions
//

function getProfileSign ($profile) {
    return md5(base64_encode($profile));
}

function checkProfileSign ($profile, $sign) {
    return $sign === getProfileSign($profile);
}

function createUrlForPath (Request $request, $profile, $path = '/') {
    $sign = getProfileSign($profile);
    $path = sprintf('%s/%s/%s', $path, $profile, $sign);
    return $request->getUriForPath($path);
}
