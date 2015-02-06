<?php
ini_set('display_errors', 1); 
error_reporting(E_ALL);
session_start();

require_once "config.php"; // defines $my_appid, $my_secret, and $onedrive_url

$host = "http://1dapp.azurewebsites.net"; // this page's url
$signin = "https://login.live.com/oauth20_authorize.srf?client_id=" . $my_appid . "&scope=wl.skydrive_update,wl.signin&response_type=code&redirect_uri=" . $host; // MSA OAuth2.0 login endpoint
$oauthurl = "https://login.live.com/oauth20_token.srf"; // MSA OAuth2.0 token endpoint

/**
 * parse_http_response_header
 *
 * @param array $headers as in $http_response_header
 * @return array status and headers grouped by response, last first
 */
function parse_http_response_header(array $headers)
{
    $responses = array();
    $buffer = NULL;
    foreach ($headers as $header)
    {
        if ('HTTP/' === substr($header, 0, 5))
        {
            // add buffer on top of all responses
            if ($buffer) array_unshift($responses, $buffer);
            $buffer = array();

            list($version, $code, $phrase) = explode(' ', $header, 3) + array('', FALSE, '');

            $buffer['status'] = array(
                'line' => $header,
                'version' => $version,
                'code' => (int) $code,
                'phrase' => $phrase
            );
            $fields = &$buffer['fields'];
            $fields = array();
            continue;
        }
        list($name, $value) = explode(': ', $header, 2) + array('', '');
        // header-names are case insensitive
        $name = strtoupper($name);
        // values of multiple fields with the same name are normalized into
        // a comma separated list (HTTP/1.0+1.1)
        if (isset($fields[$name]))
        {
            $value = $fields[$name].','.$value;
        }
        $fields[$name] = $value;
    }
    unset($fields); // remove reference
    array_unshift($responses, $buffer);

    return $responses;
}

if(isset($_GET['code']))
{
	unset($_SESSION['code']);
	$_SESSION['code'] = $_GET['code'];
	ob_start();	
	header("Location: " . $host);
	
}else if(isset($_SESSION['code'])){
	$code = $_SESSION['code'];
	$url = $_SESSION['url'];
	$filename = $_SESSION['filename'];
	$appid = $_SESSION['appid'];	
	unset($_SESSION['code']);
	unset($_SESSION['url']);
	unset($_SESSION['filename']);
	unset($_SESSION['appid']);
	
	$code_data = array(
			'client_id' 	=>	$my_appid, 
			'client_secret' =>	$my_secret, 
			'redirect_uri' 	=>	$host, 
			'code'			=>	$code, 
			'grant_type'	=>	'authorization_code',
	);

	// use key 'http' even if you send the request to https://...
	$code_options = array(
		'http'	=>	array(
					'header'  =>	"Content-type: application/x-www-form-urlencoded\r\n",
					'method'  =>	'POST',
					'content' =>	http_build_query($code_data)
		)
	);
	
	$code_context  = stream_context_create($code_options);
	$auth_result = file_get_contents($oauthurl, false, $code_context);
	
	# handle errors
	
	/* $auth_result = 
	{
		"token_type":"bearer",
		"expires_in":3600,
		"scope":"wl.skydrive_update",
		"access_token":"jDXSEyMGmi7F",
		"authentication_token":"gxM0E5NEUifQ.hY",
		"user_id":"8192e7f2cb07f9c36c4ae8ad8d904ab1"
	}
	*/
	
	$auth_json = json_decode($auth_result, true);
	$access_token = $auth_json["access_token"];
	
	/*
	 * POST /drive/root/children
	 */
	$onedrive_request_body = "{\n\t" . 
								"'name': '" . $filename . "',\n\t" . 
								"'@content.sourceUrl': '" . $url . "',\n\t" .
								"'file': { }\n" .
							"}";

	$onedrive_request = array(
		'http'	=>	array(
				'method'	=>	'POST',
				'header' 	=>	"Content-type: application/json\r\n" .
								"Prefer: respond-async\r\n" .
								"Authorization: bearer " . $access_token . "\r\n",
				'content' 	=>	$onedrive_request_body
		)
	);	

	$onedrive_context  = stream_context_create($onedrive_request);
	$onedrive_result = file_get_contents($onedrive_url, false, $onedrive_context);	
	
	/*
	 * Parsing the response
	 */
	$onedrive_responses = parse_http_response_header($http_response_header);
	$is_onedrive_error = true;
	$onedrive_response_status_code = 0;
	$onedrive_status_location = null;
	
	# Get the status code & location header
	if(count($onedrive_responses) > 0)
	{
		$onedrive_last_response = $onedrive_responses[0];
		$onedrive_response_status_code = $onedrive_last_response["status"]["code"];
		$onedrive_status_location = $onedrive_last_response["fields"]["LOCATION"];
	} // else there's a bug in my parsing code or bad response header
	
	if($onedrive_response_status_code == 202 && $onedrive_status_location != null)
	{
		$is_onedrive_error = false;
	}

	?>
	<!doctype html>
	<html lang="en">
	<head>
		<meta charset="utf-8">
		<title>OneDrive - Save from URL</title>
	  
	<?php
	if(!$is_onedrive_error)
	{
	?>
		<link rel="stylesheet" href="http://code.jquery.com/ui/1.11.2/themes/smoothness/jquery-ui.css">
		<script type="text/javascript" src="http://code.jquery.com/jquery-1.10.2.min.js"></script>
		<script type="text/javascript" src="http://code.jquery.com/ui/1.11.2/jquery-ui.min.js"></script>
		<script type="text/javascript" src="http://jslog.me/js/jslog.js"></script>
		<script>
			var jslog = new JsLog({ 
				key: "34cbd1bf28960e15127c76233f959f76dcb30de6",
				version: 4
			});
			
			// V4 schema: Name,Status,Time,StatusEndpoint,URL,AdditionalKey,AdditionalKeyValue
			
			var percentageComplete = []; // array of all the percentage completes seen, keyed off of time
			var failureStatusCodes = []; // array of all the failure status codes
			var start = new Date().getTime(); // roughly the start time of the download
			var status_location = "<?php echo $onedrive_status_location; ?>"; // where to get the status of the download
			var url = "<?php echo $url; ?>"; // the url of the contents to be downloaded
			
			console.log("[DownloadFromURL]:Start,0," + status_location + "," + url); // log start
			
			function getStatus()
			{
				$.ajax({
					url: status_location,
					context: document.body,
					dataType: 'json',
					headers: {
						"Authorization": "bearer <?php echo $access_token; ?>",
					},
					
					statusCode: {
						202: function(data, textStatus, xhr) {
						
							if(data.status == "NotStarted")
							{
								$(".progress-label").text("Waiting for OneDrive...");	
								if(data.statusDescription != null) {
									$("#error").text(data.statusDescription);
								}
								
							}else if(data.status == "InProgress"){
								var currentPercentageComplete = parseFloat(data.percentageComplete);
								
								// If the status just went to InProgress, add it.
								// If the percentageComplete increased from the previous one, add it
								if(percentageComplete.length == 0 || percentageComplete[percentageComplete.length - 1] < currentPercentageComplete) {
									var end = new Date().getTime();
									var lengthInMS = end-start;
									
									percentageComplete[lengthInMS] = currentPercentageComplete;
									console.log("[DownloadFromURL]:" + data.status + "," + lengthInMS + "," + status_location + "," + url + ",NewPercentage," + currentPercentageComplete);
								}
								
								updateProgress(currentPercentageComplete);
								
							}else if(data.status == "Waiting"){
								$(".progress-label").text("Waiting for OneDrive...");	
								
								if(data.statusDescription != null) {
									$("#error").text(data.statusDescription);
								}
								
							}else if(data.status == "Failed"){
								$(".progress-label").text("Failed to Save to OneDrive");
								if(data.statusDescription != null) {
									$("#error").text(data.statusDescription);
								}
								
							}else{
								var end = new Date().getTime();
								var lengthInMS = end-start;
								console.log("[DownloadFromURL]:" + data.status + "," + lengthInMS + "," + status_location);
								if(data.statusDescription != null) {
									console.log("[DownloadFromURL]:" + data.status + "," + lengthInMS + "," + status_location + "," + url + ",Unknown-data.Status," + data.statusDescription);
								}
							}
						},
						
						0: function (data, textStatus, xhr) {
							percentageComplete.push(100.0);
							updateProgress(100.0);
						},
						
						303: function (data, textStatus, xhr) {
							percentageComplete.push(100.0);
							updateProgress(100.0);
						},
						
						400: function (data, textStatus, xhr) {
							failureStatusCodes.push(xhr.status);
						},
						
						401: function (data, textStatus, xhr) { // the auth token expired
							failureStatusCodes.push(xhr.status);
						},
						
						402: function (data, textStatus, xhr) {
							failureStatusCodes.push(xhr.status);
						},
						
						403: function (data, textStatus, xhr) {
							failureStatusCodes.push(xhr.status);
						},

						404: function (data, textStatus, xhr) {
							failureStatusCodes.push(xhr.status);
						},				

						408: function (data, textStatus, xhr) {
							failureStatusCodes.push(xhr.status);
						},

						429: function (data, textStatus, xhr) { // we're getting rated limited
							failureStatusCodes.push(xhr.status);
						},
						
						500: function (data, textStatus, xhr) {
							failureStatusCodes.push(xhr.status);
						}
					},
					
					complete: function() {
					
						if(failureStatusCodes.length >= 5)
						{
							onFail();
				
						// Get the status again if we don't know the percentage complete
						}else if(percentageComplete.length == 0) {
							getStatus();
							
						// Get the status again
						}else if(percentageComplete.length > 0 && // we've percentageComplete value AND
								percentageComplete[percentageComplete.length - 1] < 100.0 && // the last percentageComplete value is < 100 AND
								percentageComplete[percentageComplete.length - 1] >= 0.0) // the last percentageComplete value is >= 0
						{
							getStatus();
						}
					}
				});		
			}
		
			$(document).ready(function() {
				$("#progressbar").progressbar({
					value: 0.0,
					max: 100.0
				});	
				
				getStatus();
			});
			
			function updateProgress(x)
			{				
				if(x == 100) {
					$("#progressbar").progressbar("value", x);
					$(".progress-label").text("Complete!");
					var end = new Date().getTime();
					var lengthInMS = end-start;
					var lengthInSec = Math.round(lengthInMS/1000);
					$("#time").text("Took " + lengthInSec + " seconds to succeed");
					console.log("[DownloadFromURL]:Pass," + lengthInMS + "," + status_location + "," + url + ",NewPercentage,100.0");
				}else{
					$("#progressbar").progressbar("value", x);
					$(".progress-label").text((Math.round(x * 100) / 100) + "%")
				}
			}
			
			function onFail()
			{
				var end = new Date().getTime();
				var lengthInMS = end-start;
				var lengthInSec = Math.round(lengthInMS/1000);
				$(".progress-label").text("Failed to save to OneDrive  :(");
				$("#time").text("Took " + lengthInSec + " seconds to fail");
				console.log("[DownloadFromURL]:Fail," + lengthInMS + "," + status_location + "," + url + ",FailureStatusCodes," + failureStatusCodes.toString());
			}
		</script>
		
		<style>
			.ui-progressbar {
				position: relative;
				width: 512px;
				height: 25px;
			}
			.progress-label {
				position: relative;
				text-align: center;
				font-weight: bold;
				text-shadow: 1px 1px 0 #fff;
			}
		</style>
	<?php
	} # Else there was an error, which we'll display later
	?>
	</head>
	<body>
	<?php
	echo "<h2>Status</h2>";
	
	if(!$is_onedrive_error)
	{
		echo "<div id='progressbar'><div class='progress-label'>Saving to your OneDrive...</div></div>";
		echo "<br /><div id='time'></div>";
		echo "<br /><div id='error' style='color: red;'></div>";
	}else{
		echo "<div>There was an error when uploading your file.   See below for debugging information.</div>";
	}
	
	echo "<h2>HTTP Request</h2>";
	echo "<textarea style=\"margin: 0px; height: 275px; width: 1024px;\">";
	echo $onedrive_request["http"]["method"] . " " . $onedrive_url . " HTTP/1.1\n";
	echo $onedrive_request["http"]["header"] . "\n\n";
	echo $onedrive_request_body;
	echo "</textarea>";
	
	echo "<br /><br />";
	
	echo "<h2>HTTP Response</h2>";
	echo "<textarea style=\"margin: 0px; height: 250px; width: 1024px;\">";
	foreach ($http_response_header as $header)
	{
		echo $header . "\n";
	}

	if(!empty($onedrive_result)) 
	{
		echo "\n\n";
		echo json_encode($onedrive_result, JSON_PRETTY_PRINT);
	}
	echo "</textarea>";
	?>
	
	</body>
	</html>
	<?php
	
}else if(isset($_GET['url']) && isset($_GET['filename']) && isset($_GET['appid']) && ctype_xdigit($_GET['appid'])){
	# Validate that the app id is valid.
	# Go to https://login.live.com/oauth20_authorize.srf?client_id=$_GET['appid']
	# If valid, #error=invalid_request
	# If not, 
	
	unset($_SESSION['url']);	
	unset($_SESSION['filename']);
	unset($_SESSION['appid']);	
	$_SESSION['url'] = $_GET['url'];
	$_SESSION['filename'] = $_GET['filename'];
	$_SESSION['appid'] = $_GET['appid'];
	ob_start();
	header("Location: " . $signin);
}else{
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>OneDrive - Save from URL</title>
<style>
body {
	min-width: 1020px;
	font: 13px/1.4 Helvetica, arial, freesans, clean, sans-serif, "Segoe UI Emoji", "Segoe UI Symbol";
	color: #333;
	background-color: #fff;
}

table {
	display: table;
	border-collapse: separate;
	border-spacing: 2px;
	border-color: gray;
}

thead {
	display: table-header-group;
	vertical-align: middle;
	border-color: inherit;
}

tr {
	display: table-row;
	vertical-align: inherit;
	border-color: inherit;
}

th {
	font-weight: bold;
}

td, th {
	display: table-cell;
	vertical-align: inherit;
	padding: 6px 13px;
	border: 1px solid #ddd;
}

tbody {
	display: table-row-group;
	vertical-align: middle;
	border-color: inherit;
}

code {
	font-family: monospace, monospace;
	font-size: 1em;
}
</style>
<body>
<h2>Invalid Parameters</h2>
<table>
<thead>
<tr>
<th>Query Parameter Name</th>
<th>Value</th>
<th>Description</th>
</tr>
</thead>
<tbody>
<tr>
<td><code>url</code></td>
<td><code>string</code></td>
<td>the URL of the file to be downloaded</td>
</tr>

<tr>
<td><code>appid</code></td>
<td><code>string</code></td>
<td>the Microsoft account <code>Client ID</code> from the <a href="https://account.live.com/developers/applications">MSA developer portal</a></td>
</tr>


<tr>
<td><code>filename</code></td>
<td><code>string</code></td>
<td>the name that the file should be named as</td>
</tr>

</tbody>
</table>
	
</body>
</html>
<?php
}
?>