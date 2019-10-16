<?php

define('DEBUG', true);
define('DO_JSON_PRETTY_PRINT', false);

$username = '***';
$password = '***';
$project_id = '***';

$pages_log_file = __DIR__ . '/_log.json';
$pages_log_array = array();

########################################################################################################################
#
#    Rate Limiting
#
#    https://docs.tiledesk.com/apis/api#rate-limiting
#
#    As an API consumer, you should expect to be able to make at least 200 requests per minute.
#    If the rate limit is exceeded, Tiledesk will respond with a HTTP 429 Too Many Requests
#    response code and a body that details the reason for the rate limiter kicking in.
#
########################################################################################################################
function endpoint_get_contents($endpoint, $use_include_path = false, $use_context = true, &$response_header = null)
{
    global $username, $password;

    // set the variables that define the limits:
    $max_requests_time_range = 60; // seconds
    $max_requests_limit = 190;

    $sleep_time = 60; // seconds

    static $requests_counter = 0;
    static $start_time = 0;

    $current_time = time();

    $context = stream_context_create(array(
        'http' => array(
            'header' => 'Authorization: Basic ' . base64_encode($username . ':' . $password),
        ),
    ));

    if (!$start_time) {
        $start_time = $current_time;
    }

    if (($current_time <= $start_time + $max_requests_time_range) and ($requests_counter++ >= $max_requests_limit)) {

        fwrite(STDERR, "\n-------- RATE LIMIT EXCEEDED --------\n");
        fwrite(STDERR, "\nELAPSED SECOND: " . ($current_time - $start_time) . "\n");
        fwrite(STDERR, "\nREQUESTS: " . $requests_counter . "\n");
        fwrite(STDERR, "\n-------------------------------------\n");

        sleep($sleep_time);

        $requests_counter = 0;
        $start_time = $current_time;
    }

    if ($use_context) {
        $content = file_get_contents($endpoint, $use_include_path, $context);
    } else {
        $content = file_get_contents($endpoint, $use_include_path);
    }

    $response_header = $http_response_header; // $http_response_header will be created in the local scope

    foreach ($http_response_header as $current_http_response_header) {

        if (preg_match('|^HTTP\/|i', $current_http_response_header) and strpos($current_http_response_header, '200') === false) {
            // Successful match
            fwrite(STDERR, "\n------- BAD HTTP RESPONSE HEADER: " . $current_http_response_header . "\n");
        }
    }

    return $content;
}

########################################################################################################################
#
#    Get all requests
#
#    https://docs.tiledesk.com/apis/api/requests#get-all-requests
#
#    Allows an account to list all the requests for the project.
#
########################################################################################################################
function get_all_requests($project_id, $start_page = 0)
{
    global $pages_log_array;

    if (DEBUG) {
        echo "\n-------- Get all requests --------\n\n";
    }

    $service_url = 'https://api.tiledesk.com/v1/' . $project_id . '/requests?sortField=createdAt&direction=1';

    $current_page = $start_page;
    $counter = 1;

    do {
        $page_url = $service_url . '&page=' . $current_page;

        if (DEBUG) {
            echo "\npage URL: " . $page_url . "\n\n";
        }

        $json_response = endpoint_get_contents($page_url);

        $requests_obj = json_decode($json_response);

        if ($current_page_count = count($requests_obj->requests)) {

            if (DEBUG) {
                echo "\ncurrent page: " . $current_page . " - current page count: " . $current_page_count . "\n\n";
            }

            if (DO_JSON_PRETTY_PRINT) {
                $json_response = json_encode($requests_obj, JSON_PRETTY_PRINT);
            }

            $out_dir = __DIR__ . '/requests';
            @mkdir($out_dir, 0777, true);

            $out_file_name = $out_dir . '/requests_page_' . $current_page . '.json';

            if (DEBUG and file_exists($out_file_name)) {
                fwrite(STDERR, "\nDUPLICATE FILE: " . $out_file_name . "\n\n");
            }

            file_put_contents($out_file_name, $json_response);

            foreach ($requests_obj->requests as $request) {
                if (DEBUG) {
                    echo $counter++ . ') ' . $request->request_id . "\n";
                }
                $request_status = get_a_request_by_id($project_id, $current_page, $request->request_id);

                if ($request_status != '1000') { // 1000=closed | 100=pooled | 200=served
                    if (DEBUG) {
                        echo '- save unclosed request - page: ' . $current_page . ' - request_id: ' . $request->request_id . "\n";
                    }
                    update_pages_log_file('unclosed_requests', array('page' => $current_page, 'request_id' => $request->request_id), true);
                }
            }
        } else {

            if (DEBUG) {
                echo "\ncurrent page: " . $current_page . " - current page count: " . $current_page_count . "\n\n";
            }
        }

        $current_page++;

    } while ($current_page_count);

    update_pages_log_file('last_requests_page', ($current_page - 2));
}

########################################################################################################################
#
#    Get a request by id
#
#    https://docs.tiledesk.com/apis/api/requests#get-a-request-by-id
#
#    Fetches a request by his or her request_id
#
########################################################################################################################
function get_a_request_by_id($project_id, $current_page, $request_id)
{
    $service_url = 'https://api.tiledesk.com/v1/' . $project_id . '/requests/' . $request_id;

    $json_response = endpoint_get_contents($service_url);

    $request_obj = json_decode($json_response);

    if (DO_JSON_PRETTY_PRINT) {
        $json_response = json_encode($request_obj, JSON_PRETTY_PRINT);
    }

    $out_dir = __DIR__ . '/requests/requests_page_' . $current_page . '/' . $request_id;
    @mkdir($out_dir, 0777, true);

    $out_file_name = $out_dir . '/' . $request_id . '.json';

    if (DEBUG and file_exists($out_file_name)) {
        fwrite(STDERR, "\nDUPLICATE FILE: " . $out_file_name . "\n\n");
    }

    file_put_contents($out_file_name, $json_response);

    get_request_messages($project_id, $current_page, $request_id);

    return ($request_obj->status); // 1000=closed | 100=pooled | 200=served
}

########################################################################################################################
#
#    Get the messages of a request by id
#
#    https://docs.tiledesk.com/apis/api/messages
#
#    Fetches the messages by his or her request_id
#
########################################################################################################################
function get_request_messages($project_id, $current_page, $request_id)
{
    $service_url = 'https://api.tiledesk.com/v1/' . $project_id . '/requests/' . $request_id . '/messages';

    $json_response = endpoint_get_contents($service_url);

    $messages_array = json_decode($json_response);

    if (DO_JSON_PRETTY_PRINT) {
        $json_response = json_encode($messages_array, JSON_PRETTY_PRINT);
    }

    $out_dir = __DIR__ . '/requests/requests_page_' . $current_page . '/' . $request_id;
    @mkdir($out_dir, 0777, true);

    $out_file_name = $out_dir . '/' . $request_id . '_messages.json';

    if (DEBUG and file_exists($out_file_name)) {
        fwrite(STDERR, "\nDUPLICATE FILE: " . $out_file_name . "\n\n");
    }

    file_put_contents($out_file_name, $json_response);

    foreach ($messages_array as $key => $message) {

        if (isset($message->metadata->src)) {

            $storage_url = $message->metadata->src;
            $file_ext = mime2ext($message->metadata->type);

            if ($storage_data = endpoint_get_contents($storage_url, false, false)) {

                $out_dir = __DIR__ . '/requests/requests_page_' . $current_page . '/' . $request_id;
                @mkdir($out_dir, 0777, true);

                $out_file_name = $out_dir . '/' . $request_id . '_message_' . $key . '_attach.' . $file_ext;

                if (DEBUG and file_exists($out_file_name)) {
                    fwrite(STDERR, "\nDUPLICATE FILE: " . $out_file_name . "\n\n");
                }

                file_put_contents($out_file_name, $storage_data);
            }

            if (DEBUG) {
                echo "\t" . $key . ' - ' . $storage_url . "\n";
            }

        } else if (strpos($message->text, 'File: https://firebasestorage.googleapis.com') === 0 or strpos($message->text, 'Image: https://firebasestorage.googleapis.com') === 0) {

            $storage_url = explode(' ', $message->text);
            $storage_url = $storage_url[1];

            $http_response_header = array();

            if ($storage_data = endpoint_get_contents($storage_url, false, false, $http_response_header)) {

                foreach ($http_response_header as $current_http_response_header) {

                    if (preg_match('/^Content-Type:/i', $current_http_response_header)) {
                        // Successful match
                        $file_ext = mime2ext(strtolower(trim(substr($current_http_response_header, strlen('Content-Type:')))));
                    }
                }

                $out_dir = __DIR__ . '/requests/requests_page_' . $current_page . '/' . $request_id;
                @mkdir($out_dir, 0777, true);

                $out_file_name = $out_dir . '/' . $request_id . '_message_' . $key . '_attach.' . $file_ext;

                if (DEBUG and file_exists($out_file_name)) {
                    fwrite(STDERR, "\nDUPLICATE FILE: " . $out_file_name . "\n\n");
                }

                file_put_contents($out_file_name, $storage_data);

                if (DEBUG) {
                    echo "\t" . $key . ' - ' . $storage_url . "\n";
                }
            }
        }
    }
}

########################################################################################################################
#
#    Get all leads
#
#    https://docs.tiledesk.com/apis/api/leads
#
#    Allows an account to list all the leads.
#
########################################################################################################################
function get_all_leads($project_id, $start_page = 0)
{

    if (DEBUG) {
        echo "\n-------- Get all leads --------\n\n";
    }

    $service_url = 'https://api.tiledesk.com/v1/' . $project_id . '/leads?sortField=createdAt&direction=1';

    $current_page = $start_page;
    $counter = 1;

    do {
        $page_url = $service_url . '&page=' . $current_page;

        if (DEBUG) {
            echo "\npage URL: " . $page_url . "\n\n";
        }

        $json_response = endpoint_get_contents($page_url);

        $leads_obj = json_decode($json_response);

        if ($current_page_count = count($leads_obj->leads)) {

            if (DEBUG) {
                echo "\ncurrent page: " . $current_page . " - current page count: " . $current_page_count . "\n\n";
            }

            if (DO_JSON_PRETTY_PRINT) {
                $json_response = json_encode($leads_obj, JSON_PRETTY_PRINT);
            }

            $out_dir = __DIR__ . '/leads';
            @mkdir($out_dir, 0777, true);

            $out_file_name = $out_dir . '/leads_page_' . $current_page . '.json';

            if (DEBUG and file_exists($out_file_name)) {
                fwrite(STDERR, "\nDUPLICATE FILE: " . $out_file_name . "\n\n");
            }

            file_put_contents($out_file_name, $json_response);

            foreach ($leads_obj->leads as $lead) {
                if (DEBUG) {
                    echo $counter++ . ') ' . $lead->_id . "\n";
                }
                get_a_lead_by_id($project_id, $current_page, $lead->_id);
            }
        } else {

            if (DEBUG) {
                echo "\ncurrent page: " . $current_page . " - current page count: " . $current_page_count . "\n\n";
            }
        }

        $current_page++;

    } while ($current_page_count);

    update_pages_log_file('last_leads_page', ($current_page - 2));
}

########################################################################################################################
#
#    Get a lead by id
#
#    https://docs.tiledesk.com/apis/api/leads#get-a-lead-by-id
#
#    Fetches a lead by his or her Lead ID
#
########################################################################################################################
function get_a_lead_by_id($project_id, $current_page, $lead_id)
{
    $service_url = 'https://api.tiledesk.com/v1/' . $project_id . '/leads/' . $lead_id;

    $json_response = endpoint_get_contents($service_url);

    $lead_obj = json_decode($json_response);

    if (DO_JSON_PRETTY_PRINT) {
        $json_response = json_encode($lead_obj, JSON_PRETTY_PRINT);
    }

    $out_dir = __DIR__ . '/leads/leads_page_' . $current_page;
    @mkdir($out_dir, 0777, true);

    $out_file_name = $out_dir . '/lead_page_' . $current_page . '_' . $lead_id . '.json';

    if (DEBUG and file_exists($out_file_name)) {
        fwrite(STDERR, "\nDUPLICATE FILE: " . $out_file_name . "\n\n");
    }

    file_put_contents($out_file_name, $json_response);
}

########################################################################################################################
#
#    Get all activities
#
#    https://docs.tiledesk.com/apis/api/activities
#
#    Allows an admin to list all the activities for the project.
#
########################################################################################################################
function get_all_activities($project_id, $start_page = 0)
{
    if (DEBUG) {
        echo "\n-------- Get all activities --------\n\n";
    }

    $service_url = 'https://api.tiledesk.com/v1/' . $project_id . '/activities?sortField=createdAt&direction=1';

    $current_page = $start_page;
    $counter = 1;

    do {
        $page_url = $service_url . '&page=' . $current_page;

        if (DEBUG) {
            echo "\npage URL: " . $page_url . "\n\n";
        }

        $json_response = endpoint_get_contents($page_url);

        $activities_obj = json_decode($json_response);

        if ($current_page_count = count($activities_obj->activities)) {

            if (DEBUG) {
                echo "\ncurrent page: " . $current_page . " - current page count: " . $current_page_count . "\n\n";
            }

            if (DO_JSON_PRETTY_PRINT) {
                $json_response = json_encode($activities_obj, JSON_PRETTY_PRINT);
            }

            $out_dir = __DIR__ . '/activities';
            @mkdir($out_dir, 0777, true);

            $out_file_name = $out_dir . '/activities_page_' . $current_page . '.json';

            if (DEBUG and file_exists($out_file_name)) {
                fwrite(STDERR, "\nDUPLICATE FILE: " . $out_file_name . "\n\n");
            }

            file_put_contents($out_file_name, $json_response);

            foreach ($activities_obj->activities as $activitie) {
                if (DEBUG) {
                    echo $counter++ . ') ' . $activitie->_id . "\n";
                }
                //get_a_activitie_by_id($project_id, $current_page, $activitie->_id);
            }
        } else {

            if (DEBUG) {
                echo "\ncurrent page: " . $current_page . " - current page count: " . $current_page_count . "\n\n";
            }
        }

        $current_page++;

    } while ($current_page_count);

    update_pages_log_file('last_activities_page', ($current_page - 2));
}

########################################################################################################################
#
#    Converting mime types to extension in php · GitHub
#
#    https://gist.github.com/alexcorvi/df8faecb59e86bee93411f6a7967df2c
#
########################################################################################################################
function mime2ext($mime)
{
    $mime_map = array('application/bmp' => 'bmp', 'application/cdr' => 'cdr', 'application/coreldraw' => 'cdr', 'application/excel' => 'xl', 'application/gpg-keys' => 'gpg', 'application/java-archive' => 'jar', 'application/json' => 'json', 'application/mac-binary' => 'bin', 'application/mac-binhex' => 'hqx', 'application/mac-binhex40' => 'hqx', 'application/mac-compactpro' => 'cpt', 'application/macbinary' => 'bin', 'application/msexcel' => 'xls', 'application/msword' => 'doc', 'application/octet-stream' => 'pdf', 'application/oda' => 'oda', 'application/ogg' => 'ogg', 'application/pdf' => 'pdf', 'application/pgp' => 'pgp', 'application/php' => 'php', 'application/pkcs-crl' => 'crl', 'application/pkcs10' => 'p10', 'application/pkcs7-mime' => 'p7c', 'application/pkcs7-signature' => 'p7s', 'application/pkix-cert' => 'crt', 'application/pkix-crl' => 'crl', 'application/postscript' => 'ai', 'application/powerpoint' => 'ppt', 'application/rar' => 'rar', 'application/s-compressed' => 'zip', 'application/smil' => 'smil', 'application/videolan' => 'vlc', 'application/vnd.google-earth.kml+xml' => 'kml', 'application/vnd.google-earth.kmz' => 'kmz', 'application/vnd.mif' => 'mif', 'application/vnd.mpegurl' => 'm4u', 'application/vnd.ms-excel' => 'xlsx', 'application/vnd.ms-office' => 'ppt', 'application/vnd.ms-powerpoint' => 'ppt', 'application/vnd.msexcel' => 'csv', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/wbxml' => 'wbxml', 'application/wmlc' => 'wmlc', 'application/x-binary' => 'bin', 'application/x-binhex40' => 'hqx', 'application/x-bmp' => 'bmp', 'application/x-cdr' => 'cdr', 'application/x-compress' => 'z', 'application/x-compressed' => '7zip', 'application/x-coreldraw' => 'cdr', 'application/x-director' => 'dcr', 'application/x-dos_ms_excel' => 'xls', 'application/x-dvi' => 'dvi', 'application/x-excel' => 'xls', 'application/x-gtar' => 'gtar', 'application/x-gzip-compressed' => 'tgz', 'application/x-gzip' => 'gzip', 'application/x-httpd-php-source' => 'php', 'application/x-httpd-php' => 'php', 'application/x-jar' => 'jar', 'application/x-java-application' => 'jar', 'application/x-javascript' => 'js', 'application/x-mac-binhex40' => 'hqx', 'application/x-macbinary' => 'bin', 'application/x-ms-excel' => 'xls', 'application/x-msdownload' => 'exe', 'application/x-msexcel' => 'xls', 'application/x-pem-file' => 'pem', 'application/x-photoshop' => 'psd', 'application/x-php' => 'php', 'application/x-pkcs10' => 'p10', 'application/x-pkcs12' => 'p12', 'application/x-pkcs7-certreqresp' => 'p7r', 'application/x-pkcs7-mime' => 'p7c', 'application/x-pkcs7-signature' => 'p7a', 'application/x-pkcs7' => 'rsa', 'application/x-rar-compressed' => 'rar', 'application/x-rar' => 'rar', 'application/x-shockwave-flash' => 'swf', 'application/x-stuffit' => 'sit', 'application/x-tar' => 'tar', 'application/x-troff-msvideo' => 'avi', 'application/x-win-bitmap' => 'bmp', 'application/x-x509-ca-cert' => 'crt', 'application/x-x509-user-cert' => 'pem', 'application/x-xls' => 'xls', 'application/x-zip-compressed' => 'zip', 'application/x-zip' => 'zip', 'application/xhtml+xml' => 'xhtml', 'application/xls' => 'xls', 'application/xml' => 'xml', 'application/xspf+xml' => 'xspf', 'application/zip' => 'zip', 'audio/ac3' => 'ac3', 'audio/aiff' => 'aif', 'audio/midi' => 'mid', 'audio/mp3' => 'mp3', 'audio/mpeg' => 'mp3', 'audio/mpeg3' => 'mp3', 'audio/mpg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/wave' => 'wav', 'audio/x-acc' => 'aac', 'audio/x-aiff' => 'aif', 'audio/x-au' => 'au', 'audio/x-flac' => 'flac', 'audio/x-m4a' => 'm4a', 'audio/x-ms-wma' => 'wma', 'audio/x-pn-realaudio-plugin' => 'rpm', 'audio/x-pn-realaudio' => 'ram', 'audio/x-realaudio' => 'ra', 'audio/x-wav' => 'wav', 'image/bmp' => 'bmp', 'image/cdr' => 'cdr', 'image/gif' => 'gif', 'image/jp2' => 'jp2', 'image/jpeg' => 'jpeg', 'image/jpm' => 'jp2', 'image/jpx' => 'jp2', 'image/ms-bmp' => 'bmp', 'image/pjpeg' => 'jpeg', 'image/png' => 'png', 'image/svg+xml' => 'svg', 'image/tiff' => 'tiff', 'image/vnd.adobe.photoshop' => 'psd', 'image/vnd.microsoft.icon' => 'ico', 'image/x-bitmap' => 'bmp', 'image/x-bmp' => 'bmp', 'image/x-cdr' => 'cdr', 'image/x-ico' => 'ico', 'image/x-icon' => 'ico', 'image/x-ms-bmp' => 'bmp', 'image/x-png' => 'png', 'image/x-win-bitmap' => 'bmp', 'image/x-windows-bmp' => 'bmp', 'image/x-xbitmap' => 'bmp', 'message/rfc822' => 'eml', 'multipart/x-zip' => 'zip', 'text/calendar' => 'ics', 'text/comma-separated-values' => 'csv', 'text/css' => 'css', 'text/html' => 'html', 'text/json' => 'json', 'text/php' => 'php', 'text/plain' => 'txt', 'text/richtext' => 'rtx', 'text/rtf' => 'rtf', 'text/srt' => 'srt', 'text/vtt' => 'vtt', 'text/x-comma-separated-values' => 'csv', 'text/x-log' => 'log', 'text/x-php' => 'php', 'text/x-scriptzsh' => 'zsh', 'text/x-vcard' => 'vcf', 'text/xml' => 'xml', 'text/xsl' => 'xsl', 'video/3gp' => '3gp', 'video/3gpp' => '3gp', 'video/3gpp2' => '3g2', 'video/avi' => 'avi', 'video/mj2' => 'jp2', 'video/mp4' => 'mp4', 'video/mpeg' => 'mpeg', 'video/msvideo' => 'avi', 'video/ogg' => 'ogg', 'video/quicktime' => 'mov', 'video/vnd.rn-realvideo' => 'rv', 'video/webm' => 'webm', 'video/x-f4v' => 'f4v', 'video/x-flv' => 'flv', 'video/x-ms-asf' => 'wmv', 'video/x-ms-wmv' => 'wmv', 'video/x-msvideo' => 'avi', 'video/x-sgi-movie' => 'movie', 'zz-application/zz-winassoc-cdr' => 'cdr');

    return isset($mime_map[$mime]) === true ? $mime_map[$mime] : false;
}

########################################################################################################################
#
#    Get old unclosed request
#
########################################################################################################################
function get_old_unclosed_requests($project_id)
{
    global $pages_log_array;

    $check_duplicate_request = array();

    if (DEBUG) {
        echo "\n-------- Get old unclosed request --------\n\n";
    }

    if (is_array($pages_log_array['unclosed_requests'])) {

        $unclosed_requests = $pages_log_array['unclosed_requests'];

        // unset $pages_log_array['unclosed_requests'] and delete it from log file
        update_pages_log_file('unclosed_requests', null, null, 'delete');

        foreach ($unclosed_requests as $request) {

            if ( isset($check_duplicate_request[$request['request_id']]) ) {
                continue;
            } else {
                $check_duplicate_request[$request['request_id']] = 1;
            }

            if (DEBUG) {
                echo '- get old unclosed request - page: ' . $request['page'] . ' - request_id: ' . $request['request_id'] . "\n";
            }

            $request_status = get_a_request_by_id($project_id, $request['page'], $request['request_id']);

            if ($request_status != '1000') { // 1000=closed | 100=pooled | 200=served
                update_pages_log_file('unclosed_requests', array('page' => $request['page'], 'request_id' => $request['request_id']), true);
                if (DEBUG) {
                    echo '- save unclosed request - page: ' . $request['page'] . ' - request_id: ' . $request['request_id'] . "\n";
                }
            }
        }
    }
}

########################################################################################################################
#
#    Save current status
#
########################################################################################################################
function update_pages_log_file($key, $val, $multiple_values = false, $action = 'add')
{
    global $pages_log_file, $pages_log_array;

    if ($action == 'add') {

        if ($multiple_values) {
            $pages_log_array[$key][] = $val;
        } else {
            $pages_log_array[$key] = $val;
        }

    } else if ($action == 'delete') {

        unset($pages_log_array[$key]);
    }

    file_put_contents($pages_log_file, json_encode($pages_log_array));
}

########################################################################################################################
#
#    inizialize_pages_log_array()
#
########################################################################################################################
function inizialize_pages_log_array()
{
    global $pages_log_file, $pages_log_array;

    if (!file_exists($pages_log_file)) {
        $pages_log_array = array('last_activities_page' => 0, 'last_leads_page' => 0, 'last_requests_page' => 0);
        file_put_contents($pages_log_file, json_encode($pages_log_array));
    } else {
        $pages_log_array = json_decode(file_get_contents($pages_log_file), true);
    }
}

inizialize_pages_log_array();
get_all_activities($project_id, $pages_log_array['last_activities_page']);
get_all_leads($project_id, $pages_log_array['last_leads_page']);
get_all_requests($project_id, $pages_log_array['last_requests_page']);
get_old_unclosed_requests($project_id);
