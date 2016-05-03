<?php

class AlexaSDK_Graph extends AlexaSDK_Rest{
	
		private $resource = "https://graph.windows.net";
		
		private $apiVersion = "api-version=1.5";
		
		//private $tenantId = "f2a51796-fe73-4de5-992e-a6ba18f8551c";
		
		private $tenantDomainName = 'myorganization'; //"ede123e5-d464-4560-bd5f-7ee8d5807f08"; //"alexacrm.com"; 
		
		private $authorization;
	
		public function __construct(AlexaSDK_Settings $_settings) {
			
				parent::__construct($_settings);
				
				$this->authorization = new AlexaSDK_Oauth2($_settings, $this->resource);
		}
		
		public function get($feedName, $keyValue = NULL){
				$token = $this->authorization->getSecurityToken();
				
				$feedURL = "https://graph.windows.net/".$this->tenantDomainName."/".$feedName;
				
				if ($keyValue){
				  $feedURL .= '(\''. $keyValue .'\')';
				}
				
				$url = $feedURL."?".$this->apiVersion;
				
				$headers = array('Accept:application/json;odata=minimalmetadata',
					   'Content-Type:application/json;odata=minimalmetadata', 
					   'Prefer:return-content');
				
				return self::getRestResponse($url, '', $token, $headers, "GET");
		}
		
		public function add($feedName, $entity){
				$token = $this->authorization->getSecurityToken();
				
				$feedURL = "https://graph.windows.net/".$this->tenantDomainName."/".$feedName;
				$url = $feedURL."?".$this->apiVersion;
				
				$data = json_encode($entity);
				
				$headers = array('Content-Type: application/json;');
				
				return self::getRestResponse($url, $data, $token, $headers);
		}
	
		
		public function delete($feedName, $entity){
				$token = $this->authorization->getSecurityToken();
		}
		
		public function createApplication(){
				/*
				$requiredResourceAccess = new stdClass();
				$requiredResourceAccess->resourceAppId = "00000002-0000-0000-c000-000000000000";
				
				$resourceAccess = new stdClass();
				$resourceAccess->id = "311a71cc-e848-46a1-bdf8-97ff7156d8e6";
				$resourceAccess->type = "Scope";
				$requiredResourceAccess->resourceAccess = array(
					$resourceAccess,
				);*/
				
				
				$passwordCredentials = new stdClass();
				$passwordCredentials->customKeyIdentifier = NULL;
				$passwordCredentials->keyId =  self::getUuid();
				$passwordCredentials->startDate = date("c");
				$passwordCredentials->endDate = date("c", strtotime('+1 year'));
				$passwordCredentials->value = base64_encode(hash_hmac("MD5", self::getUuid(), "1a71cc"));
									

				/*
				 * Need to send to custom code:
				 * replyUrls
				 * identifierUris
				 */
				
				$application = array(
					"displayName" => "Demo 1",
					"availableToOtherTenants" => false,
					"homepage" => "https://localhost:44300/",
					"identifierUris" => array(
						"https://sntdn.onmicrosoft.com/WebApplication5"
					),
					"replyUrls" => array(
						"https://localhost:44300/",
					),
					"publicClient" => false,
					/*"requiredResourceAccess" => array(
						$requiredResourceAccess,
					),*/
					"passwordCredentials" => array(
						$passwordCredentials,
					),
				);
				
				return $this->add("applications", $application);
		}
}