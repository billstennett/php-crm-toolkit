<?php

class AlexaSDK_Rest extends AlexaSDK_Abstract{
		
		/* Connection Details */
		protected static $connectorTimeout = 6000;
		
		private $settings;
		
	    public function __construct(AlexaSDK_Settings $_settings) {
				$this->settings = $_settings;
		}
		
		public static function getRestResponse($url, $content, $token = NULL, $extraHeaders = array(), $requestType = "POST"){
			/* Separate the provided URI into Path & Hostname sections */
			$urlDetails = parse_url($url);
			
			$path = $urlDetails["path"];
			$path .= (isset($urlDetails["query"])) ? "?".$urlDetails["query"] : "";
			
			/* Setup headers array */
			$headers = array(
				$requestType . " " . $path . " HTTP/1.1",
				"Host: " . $urlDetails["host"],
				'Connection: Keep-Alive',
				/*"Content-type: application/x-www-form-urlencoded; charset=UTF-8",*/
				"Content-length: " . strlen($content),
			);
			/* Add access token to request */
			if ($token != NULL){
				array_push($headers, "Authorization: Bearer ".$token->access_token);
			}
			
			$headers = array_merge($headers, $extraHeaders);
			
			$cURLHandle = curl_init();
			curl_setopt($cURLHandle, CURLOPT_URL, $url);
			curl_setopt($cURLHandle, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($cURLHandle, CURLOPT_TIMEOUT, self::$connectorTimeout);
			curl_setopt($cURLHandle, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($cURLHandle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
			curl_setopt($cURLHandle, CURLOPT_HTTPHEADER, $headers);
			if ($requestType == "POST"){
				curl_setopt($cURLHandle, CURLOPT_POST, 1);
				curl_setopt($cURLHandle, CURLOPT_POSTFIELDS, $content);
			}
			curl_setopt($cURLHandle, CURLOPT_HEADER, false);
			/* Execute the cURL request, get the rest response */
			$responseJSON = curl_exec($cURLHandle);        
			/* Check for cURL errors */
			if (curl_errno($cURLHandle) != CURLE_OK) {
				throw new Exception('cURL Error: '.curl_error($cURLHandle));
			}
			/* Check for HTTP errors */
			$httpResponse = curl_getinfo($cURLHandle, CURLINFO_HTTP_CODE);
			curl_close($cURLHandle);
			/* Decode response from server */
			$response = json_decode($responseJSON);
			/* Check that response in JSON format */
			if (!$response){
				throw new Exception('Invalid REST Response: HTTP Response '.$httpResponse.PHP_EOL.$responseJSON.PHP_EOL);
			}
			
			return $response;
		}
	
	
}