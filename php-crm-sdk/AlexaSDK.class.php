<?php

/**
 * AlexaSDK.php
 * 
 * This file defines the AlexaSDK class that can be used to access
 * the Microsoft Dynamics CRM system through SOAP calls from PHP.
 * 
 * @author alexacrm.com.au
 * @version 1.0
 * @package AlexaSDK
 */

/**
 * This class creates and manages SOAP connections to a Microsoft Dynamics CRM server
 */
class AlexaSDK extends AlexaSDK_Abstract {

		/**
		 * Object of authentication class
		 * 
		 * @var mixed
		 */
		private $authentication;

		/**
		 * Object of settings class
		 * 
		 * @var AlexaSDK_Settings
		 */
		private $settings;

		/**
		 * Unique name of organization to connect
		 * 
		 * @var string
		 */
		private $organizationUniqueName;

		/**
		 * Organization domain
		 * 
		 * @var string
		 */
		//private $domain;

		/**
		 * Organization Service url
		 * 
		 * This refers to the organization that you specify in the URL when you access the web application.
		 * For example, for Contoso.crm.dynamics.com, the OrganizationName is Contoso. 
		 * ServerName refers to the name of the server, including the port number, for example, myserver or myserver:5555.
		 * 
		 * @var string
		 */
		private $organizationUrl;

		/**
		 * Discovery service url
		 * The IDiscoveryService web service provides information about the organizations available 
		 * on the Microsoft Dynamics CRM server using the SOAP protocol. This information includes 
		 * the web address (URL) for each organization. 
		 * 
		 * @var string
		 */
		private $discoveryUrl;

		/**
		 * 
		 * 
		 * @var AlexaSDK_SoapActions 
		 */
		private $soapActions;
		
		/* Cached Organization data */

		/**
		 * @ignore
		 */
		private $organizationDOM;
		

		/**
		 * @ignore
		 */
		private $organizationSecurityPolicy;

		/**
		 * @ignore
		 */
		private $organizationSecurityToken;

		/**
		 * Cached Entity Definitions 
		 * 
		 * @var Array associative array of cached entities
		 */
		private $cachedEntityDefintions = Array();

		/**
		 * Instance of AlexaSDK_Cache object 
		 * 
		 * @var AlexaSDK_Cache 
		 */
		public $cacheClass;

		/* Security Details */
		private $security = Array();

		/* Cached Discovery data */
		private $discoveryDOM;
		
		private $discoverySecurityPolicy;

		/** 
		 * Connection timeout for CURLOPT_TIMEOUT
		 * 
		 * @var integer $connectorTimeout time in seconds for waiting the response from Dynamics CRM web service
		 */
		protected static $connectorTimeout = 300;

		/**
		 * Cache lifetime in seconds 
		 * 
		 * @var integer $cacheTime time in seconds cache will be expired
		 */
		protected static $cacheTime = 28800;

		/* Maximum record to retrieve */
		protected static $maximumRecords = self::MAX_CRM_RECORDS;
		
		private $cachedEntitiesShortDefinitions = Array();

		/**
		 * Create a new instance of the AlexaSDK
		 * 
		 * @param Array $_settings
		 * @param boolean $_debug Enable debug mode TRUE or FALSE
		 * @throws Exception if provided $_settings not instance of AlexaSDK_Settings class
		 * @throws BadMethodCallException if $_settings doesn't contains Discovery URI, Username and Password
		 * @return AlexaSDK
		 */
		function __construct(Array $_settings, $_debug = FALSE, $_log = FALSE) {
			try{
				/* Enable or disable debug mode */
				self::$debugMode = $_debug;
				/* Enable or disable log mode */
				self::$enableLogs = $_log;
				/* Include classes */
				$this->includes();
				/* Create settings object */
				$this->settings = new AlexaSDK_Settings($_settings);
				/* Create new object of Cache classe */
				$this->cacheClass = new AlexaSDK_Cache($this->settings->cache);
				/* Check if we're using a cached login */
				/* if (is_array($discoveryUrl)) {
				  return $this->loadLoginCache($discoveryUrl);
				} */
				/* If either mandatory parameter is NULL, throw an Exception */
				if (!$this->checkConnectionSettings()) {
					switch ($this->settings->authMode) {
						case "OnlineFederation":
							throw new BadMethodCallException(get_class($this) . ' constructor requires Username and Password');
						case "Federation":
							throw new BadMethodCallException(get_class($this) . ' constructor requires the Discovery URI, Username and Password');
					}
				}
				/* Create authentication class to connect to CRM Online or Internet facing deployment via ADFS */
				switch ($this->settings->authMode) {
					case "OnlineFederation":
						$this->authentication = new AlexaSDK_OnlineFederation($this->settings, $this);
						break;
					case "Federation":
						$this->authentication = new AlexaSDK_Federation($this->settings, $this);
						break;
				}
				/* Load cahced data if it exists */
				$this->loadEntityDefinitionCache();

				$this->soapActions = new AlexaSDK_SoapActions($this);
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		private function includes() {
			include_once ( dirname(__FILE__) . "/Authentication/AlexaSDK_Authentication.class.php" );
			include_once ( dirname(__FILE__) . "/Authentication/AlexaSDK_OnlineFederation.class.php" );
			include_once ( dirname(__FILE__) . "/Authentication/AlexaSDK_Federation.class.php" );
			include_once ( dirname(__FILE__) . "/Authentication/AlexaSDK_Oauth2.php" );
			include_once ( dirname(__FILE__) . "/Helpers/AlexaSDK_Cache.class.php" );
			include_once ( dirname(__FILE__) . "/Helpers/AlexaSDK_FormValidator.class.php" );
		}

		/**
		 * Return the Authentication Mode used by the Discovery service 
		 * 
		 * @return Mixed string if one auth type, array if there is multiple authentication types
		 * @ignore
		 */
		protected function getDiscoveryAuthenticationMode() {
			try{
				/* If it's set, return the details from the Security array */
				if (isset($this->settings->authMode))
					return $this->settings->authMode;
				/* Get the Discovery DOM */
				$discoveryDOM = $this->getDiscoveryDOM();
				/* Get the Security Policy for the Organization Service from the WSDL */
				$this->discoverySecurityPolicy = self::findSecurityPolicy($discoveryDOM, 'DiscoveryService');
				/* Check the Authentication node existanse */
				if ($this->discoverySecurityPolicy->getElementsByTagName('Authentication')->length == 0) {
					throw new Exception('Could not find Authentication tag in provided Discovery Security policy XML');
					return FALSE;
				}
				/* Find the Authentication type used */
				$authMode = Array();
				if ($this->discoverySecurityPolicy->getElementsByTagName('Authentication')->length > 1) {
					foreach ($this->discoverySecurityPolicy->getElementsByTagName('Authentication') as $authentication) {
						array_push($authMode, $authentication->textContent);
					}
				} else {
					array_push($authMode, $this->discoverySecurityPolicy->getElementsByTagName('Authentication')->item(0)->textContent);
				}
				/* Retrun authType array */
				return $authMode;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Fetch and flatten the Discovery Service WSDL as a DOM
		 * @ignore
		 */
		public function getDiscoveryDOM() {
			try{
				/* If it's already been fetched, use the one we have */
				if ($this->discoveryDOM != NULL)
					return $this->discoveryDOM;
				/* Fetch the WSDL for the Discovery Service as a parseable DOM Document */
				AlexaSDK_Logger::log('Getting Discovery DOM WSDL data from: ' . $this->settings->discoveryUrl . '?wsdl' );

				$discoveryDOM = new DOMDocument();

				@$discoveryDOM->load($this->settings->discoveryUrl . '?wsdl');

				/* Flatten the WSDL and include all the Imports */
				$this->mergeWSDLImports($discoveryDOM);
				/* Cache the DOM in the current object */
				$this->discoveryDOM = $discoveryDOM;

				return $discoveryDOM;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Return the Authentication Address used by the Discovery service 
		 * @ignore
		 */
		protected function getDiscoveryAuthenticationAddress() {
			try{
				/* If it's set, return the details from the Security array */
				if (isset($this->security['discovery_authuri']))
					return $this->security['discovery_authuri'];
				/* If we don't already have a Security Policy, get it */
				if ($this->discoverySecurityPolicy == NULL) {
					/* Get the Discovery DOM */
					$discoveryDOM = $this->getDiscoveryDOM();
					/* Get the Security Policy for the Organization Service from the WSDL */
					$this->discoverySecurityPolicy = self::findSecurityPolicy($discoveryDOM, 'DiscoveryService');
				}

				if ($this->security['discovery_authmode'] == "Federation") {
					/* Find the Authentication type used */
					$authAddress = self::getFederatedSecurityAddress($this->discoverySecurityPolicy);
				} else if ($this->security['discovery_authmode'] == "OnlineFederation") {
					$authAddress = self::getOnlineFederationSecurityAddress($this->discoverySecurityPolicy);
				}
				return $authAddress;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Return the Authentication Address used by the Organization service 
		 * @ignore
		 */
		public function getOrganizationAuthenticationAddress() {
			try{
				/* If it's set, return the details from the Security array */
				if (isset($this->security['organization_authuri']))
					return $this->security['organization_authuri'];

				/* If we don't already have a Security Policy, get it */
				if ($this->organizationSecurityPolicy == NULL) {
					/* Get the Organization DOM */
					$organizationDOM = $this->getOrganizationDOM();
					/* Get the Security Policy for the Organization Service from the WSDL */
					$this->organizationSecurityPolicy = self::findSecurityPolicy($organizationDOM, 'OrganizationService');
				}
				/* Find the Authentication type used */
				$authAddress = self::getFederatedSecurityAddress($this->organizationSecurityPolicy);

				$this->security['organization_authuri'] = $authAddress;

				return $authAddress;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Return the Authentication Mode used by the Organization service 
		 * @ignore
		 */
		public function getOrganizationAuthenticationMode() {
			try{
				/* If it's set, return the details from the Security array */
				if (isset($this->security['organization_authmode']))
					return $this->security['organization_authmode'];

				/* Get the Organization DOM */
				$organizationDOM = $this->getOrganizationDOM();
				/* Get the Security Policy for the Organization Service from the WSDL */
				$this->organizationSecurityPolicy = self::findSecurityPolicy($organizationDOM, 'OrganizationService');
				/* Find the Authentication type used */
				$authType = $this->organizationSecurityPolicy->getElementsByTagName('Authentication')->item(0)->textContent;
				return $authType;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Search a Microsoft Dynamics CRM Security Policy for the Address for the Federated Security 
		 * @ignore
		 */
		protected static function getFederatedSecurityAddress(DOMNode $securityPolicyNode) {
			try{
				$securityURL = NULL;
				/* Find the EndorsingSupportingTokens tag */
				if ($securityPolicyNode->getElementsByTagName('EndorsingSupportingTokens')->length == 0) {
					throw new Exception('Could not find EndorsingSupportingTokens tag in provided security policy XML');
					return FALSE;
				}
				$estNode = $securityPolicyNode->getElementsByTagName('EndorsingSupportingTokens')->item(0);
				/* Find the Policy tag */
				if ($estNode->getElementsByTagName('Policy')->length == 0) {
					throw new Exception('Could not find EndorsingSupportingTokens/Policy tag in provided security policy XML');
					return FALSE;
				}
				$estPolicyNode = $estNode->getElementsByTagName('Policy')->item(0);
				/* Find the IssuedToken tag */
				if ($estPolicyNode->getElementsByTagName('IssuedToken')->length == 0) {
					throw new Exception('Could not find EndorsingSupportingTokens/Policy/IssuedToken tag in provided security policy XML');
					return FALSE;
				}
				$issuedTokenNode = $estPolicyNode->getElementsByTagName('IssuedToken')->item(0);
				/* Find the Issuer tag */
				if ($issuedTokenNode->getElementsByTagName('Issuer')->length == 0) {
					throw new Exception('Could not find EndorsingSupportingTokens/Policy/IssuedToken/Issuer tag in provided security policy XML');
					return FALSE;
				}
				$issuerNode = $issuedTokenNode->getElementsByTagName('Issuer')->item(0);
				/* Find the Metadata tag */
				if ($issuerNode->getElementsByTagName('Metadata')->length == 0) {
					throw new Exception('Could not find EndorsingSupportingTokens/Policy/IssuedToken/Issuer/Metadata tag in provided security policy XML');
					return FALSE;
				}
				$metadataNode = $issuerNode->getElementsByTagName('Metadata')->item(0);
				/* Find the Address tag */
				if ($metadataNode->getElementsByTagName('Address')->length == 0) {
					throw new Exception('Could not find EndorsingSupportingTokens/Policy/IssuedToken/Issuer/Metadata/.../Address tag in provided security policy XML');
					return FALSE;
				}
				$addressNode = $metadataNode->getElementsByTagName('Address')->item(0);
				/* Get the URI */
				$securityURL = $addressNode->textContent;
				if ($securityURL == NULL) {
					throw new Exception('Could not find Security URL in provided security policy WSDL');
					return FALSE;
				}
				return $securityURL;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Search a Microsoft Dynamics CRM 2011 Security Policy for the Address for the Federated Security 
		 * @ignore
		 */
		protected static function getOnlineFederationSecurityAddress(DOMNode $securityPolicyNode) {
			try{
				$securityURL = NULL;

				/* Find the SignedSupportingTokens tag */
				if ($securityPolicyNode->getElementsByTagName('SignedSupportingTokens')->length == 0) {
					throw new Exception('Could not find SignedSupportingTokens tag in provided security policy XML');
					return FALSE;
				}
				$estNode = $securityPolicyNode->getElementsByTagName('SignedSupportingTokens')->item(0);

				/* Find the Policy tag */
				if ($estNode->getElementsByTagName('Policy')->length == 0) {
					throw new Exception('Could not find EndorsingSupportingTokens/Policy tag in provided security policy XML');
					return FALSE;
				}
				$estPolicyNode = $estNode->getElementsByTagName('Policy')->item(0);
				/* Find the IssuedToken tag */
				if ($estPolicyNode->getElementsByTagName('IssuedToken')->length == 0) {
					throw new Exception('Could not find EndorsingSupportingTokens/Policy/IssuedToken tag in provided security policy XML');
					return FALSE;
				}
				$issuedTokenNode = $estPolicyNode->getElementsByTagName('IssuedToken')->item(0);
				/* Find the Issuer tag */
				if ($issuedTokenNode->getElementsByTagName('Issuer')->length == 0) {
					throw new Exception('Could not find EndorsingSupportingTokens/Policy/IssuedToken/Issuer tag in provided security policy XML');
					return FALSE;
				}
				$issuerNode = $issuedTokenNode->getElementsByTagName('Issuer')->item(0);
				/* Find the Metadata tag */
				if ($issuerNode->getElementsByTagName('Metadata')->length == 0) {
					throw new Exception('Could not find EndorsingSupportingTokens/Policy/IssuedToken/Issuer/Metadata tag in provided security policy XML');
					return FALSE;
				}

				$metadataNode = $issuerNode->getElementsByTagName('Metadata')->item(0);
				/* Find the Address tag */
				if ($metadataNode->getElementsByTagName('Address')->length == 0) {
					throw new Exception('Could not find EndorsingSupportingTokens/Policy/IssuedToken/Issuer/Metadata/.../Address tag in provided security policy XML');
					return FALSE;
				}
				$addressNode = $metadataNode->getElementsByTagName('Address')->item(0);

				/* Get the URI */
				$securityURL = $addressNode->textContent;
				if ($securityURL == NULL) {
					throw new Exception('Could not find Security URL in provided security policy WSDL');
					return FALSE;
				}
				return $securityURL;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Get the Trust Address for the Trust13UsernameMixed authentication method 
		 * @ignore
		 */
		protected static function getTrust13UsernameAddress(DOMDocument $authenticationDOM) {
			return self::getTrustAddress($authenticationDOM, 'UserNameWSTrustBinding_IWSTrust13Async');
		}

		/**
		 * Search the WSDL from an ADFS server to find the correct end-point for a 
		 * call to RequestSecurityToken with a given set of parmameters 
		 * @ignore
		 */
		protected static function getTrustAddress(DOMDocument $authenticationDOM, $trustName) {
			try{
				/* Search the available Ports on the WSDL */
				$trustAuthNode = NULL;
				foreach ($authenticationDOM->getElementsByTagName('port') as $portNode) {
					if ($portNode->hasAttribute('name') && $portNode->getAttribute('name') == $trustName) {
						$trustAuthNode = $portNode;
						break;
					}
				}
				if ($trustAuthNode == NULL) {
					throw new Exception('Could not find Port for trust type <' . $trustName . '> in provided WSDL');
					return FALSE;
				}
				/* Get the Address from the Port */
				$authenticationURI = NULL;
				if ($trustAuthNode->getElementsByTagName('address')->length > 0) {
					$authenticationURI = $trustAuthNode->getElementsByTagName('address')->item(0)->getAttribute('location');
				}
				if ($authenticationURI == NULL) {
					throw new Exception('Could not find Address for trust type <' . $trustName . '> in provided WSDL');
					return FALSE;
				}
				/* Return the found URI */
				return $authenticationURI;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Search a WSDL XML DOM for "import" tags and import the files into 
		 * one large DOM for the entire WSDL structure 
		 * @ignore
		 */
		protected function mergeWSDLImports(DOMNode &$wsdlDOM, $continued = false, DOMDocument &$newRootDocument = NULL) {
			try{
				static $rootNode = NULL;
				static $rootDocument = NULL;
				/* If this is an external call, find the "root" defintions node */
				if ($continued == false) {
					$rootNode = $wsdlDOM->getElementsByTagName('definitions')->item(0);
					$rootDocument = $wsdlDOM;
				}
				if ($newRootDocument == NULL)
					$newRootDocument = $rootDocument;
				AlexaSDK_Logger::log("Processing Node: ".$wsdlDOM->nodeName." which has ".$wsdlDOM->childNodes->length." child nodes");
				$nodesToRemove = Array();
				/* Loop through the Child nodes of the provided DOM */
				foreach ($wsdlDOM->childNodes as $childNode) {
					AlexaSDK_Logger::log("\tProcessing Child Node: ".$childNode->nodeName." ".(isset($childNode->localName) ? "(".$childNode->localName."). " : "").((isset($childNode->childNodes) && $childNode->childNodes) ? "which has ".$childNode->childNodes->length." child nodes".PHP_EOL : ""));
					/* If this child is an IMPORT node, get the referenced WSDL, and remove the Import */
					if ($childNode->localName == 'import') {
						/* Get the location of the imported WSDL */
						if ($childNode->hasAttribute('location')) {
							$importURI = $childNode->getAttribute('location');
						} else if ($childNode->hasAttribute('schemaLocation')) {
							$importURI = $childNode->getAttribute('schemaLocation');
						} else {
							$importURI = NULL;
						}
						/* Only import if we found a URI - otherwise, don't change it! */
						if ($importURI != NULL) {
							AlexaSDK_Logger::log("\tImporting data from: " . $importURI );
							$importDOM = new DOMDocument();
							@$importDOM->load($importURI);
							/* Find the "Definitions" on this imported node */
							$importDefinitions = $importDOM->getElementsByTagName('definitions')->item(0);
							/* If we have "Definitions", import them one by one - Otherwise, just import at this level */
							if ($importDefinitions != NULL) {
								/* Add all the attributes (namespace definitions) to the root definitions node */
								foreach ($importDefinitions->attributes as $attribute) {
									/* Don't copy the "TargetNamespace" attribute */
									if ($attribute->name != 'targetNamespace') {
										$rootNode->setAttributeNode($attribute);
									}
								}
								$this->mergeWSDLImports($importDefinitions, true, $importDOM);
								foreach ($importDefinitions->childNodes as $importNode) {
									if (isset($importNode) && $importNode){
									AlexaSDK_Logger::log("\t\tInserting Child: ".$importNode->C14N(true));
									}
									$importNode = $newRootDocument->importNode($importNode, true);
									$wsdlDOM->insertBefore($importNode, $childNode);
								}
							} else {
								$importNode = $newRootDocument->importNode($importDOM->firstChild, true);
								$wsdlDOM->insertBefore($importNode, $childNode);
							}
							AlexaSDK_Logger::log("\t\tRemoving Child: ".$childNode->C14N(true));
							$nodesToRemove[] = $childNode;
						}
					} else {
						AlexaSDK_Logger::log('Preserving node: '.$childNode->localName);
						if ($childNode->hasChildNodes()) {
							$this->mergeWSDLImports($childNode, true);
						}
					}
				}
				/* Actually remove the nodes (not done in the loop, as it messes up the ForEach pointer!) */
				foreach ($nodesToRemove as $node) {
					$wsdlDOM->removeChild($node);
				}
				return $wsdlDOM;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Parse the results of a RetrieveEntity into a useable PHP object
		 * @ignore
		 */
		protected static function parseRetrieveEntityResponse($soapResponse) {
			try{
				/* Load the XML into a DOMDocument */
				$soapResponseDOM = new DOMDocument();
				$soapResponseDOM->loadXML($soapResponse);
				/* Find the ExecuteResult node with Type b:RetrieveRecordChangeHistoryResponse */
				$executeResultNode = NULL;
				foreach ($soapResponseDOM->getElementsByTagName('ExecuteResult') as $node) {
					if ($node->hasAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type') && self::stripNS($node->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type')) == 'RetrieveEntityResponse') {
						$executeResultNode = $node;
						break;
					}
				}
				unset($node);
				if ($executeResultNode == NULL) {
					throw new Exception('Could not find ExecuteResult for RetrieveEntityResponse in XML provided');
					return FALSE;
				}
				/* Find the Value node with Type d:EntityMetadata */
				$entityMetadataNode = NULL;
				foreach ($executeResultNode->getElementsByTagName('value') as $node) {
					if ($node->hasAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type') && self::stripNS($node->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type')) == 'EntityMetadata') {
						$entityMetadataNode = $node;
						break;
					}
				}
				unset($node);
				if ($entityMetadataNode == NULL) {
					throw new Exception('Could not find returned EntityMetadata in XML provided');
					return FALSE;
				}

				/* Assemble a simpleXML class for the details to return  NOTE: always return false for some reason */
				/*$responseData = simplexml_import_dom($entityMetadataNode);*/

				$returnValue = preg_replace('/(<)([a-z]:)/', '<', preg_replace('/(<\/)([a-z]:)/', '</', $soapResponse));

				$simpleXML = simplexml_load_string($returnValue);

				if (!$simpleXML) {
					throw new Exception('Unable to load metadata simple_xml_class');
					return FALSE;
				}

				$responseData = $simpleXML->Body->ExecuteResponse->ExecuteResult->Results->KeyValuePairOfstringanyType->value;

				if (!$responseData) {
					throw new Exception('Unable to load metadata simple_xml_class KeyValuePairOfstringanyType value');
					return FALSE;
				}

				/* Return the SimpleXML object */
				return $responseData;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Parse the results of a RetrieveMultipleRequest into a useable PHP object
		 * @param AlexaSDK $conn
		 * @param String $soapResponse
		 * @param Boolean $simpleMode
		 * @ignore
		 */
		protected static function parseRetrieveAllEntitiesResponse(AlexaSDK $conn, $soapResponse) {
			try{
				/* Load the XML into a DOMDocument */
				$soapResponseDOM = new DOMDocument();
				$soapResponseDOM->loadXML($soapResponse);
				/* Find the RetrieveMultipleResponse */
				$retrieveMultipleResponseNode = NULL;
				foreach ($soapResponseDOM->getElementsByTagName('ExecuteResponse') as $node) {
					$retrieveMultipleResponseNode = $node;
					break;
				}
				unset($node);
				if ($retrieveMultipleResponseNode == NULL) {
					throw new Exception('Could not find ExecuteResponse node in XML provided');
					return FALSE;
				}
				/* Find the RetrieveMultipleResult node */
				$retrieveMultipleResultNode = NULL;
				foreach ($retrieveMultipleResponseNode->getElementsByTagName('Results') as $node) {
					$retrieveMultipleResultNode = $node;
					break;
				}
				unset($node);
				if ($retrieveMultipleResultNode == NULL) {
					throw new Exception('Could not find ExecuteResult node in XML provided');
					return FALSE;
				}
				/* Assemble an associative array for the details to return */
				$responseDataArray = Array();

				/* Loop through the Entities returned */
				foreach ($retrieveMultipleResultNode->getElementsByTagName('EntityMetadata') as $entityNode) {

					if ($entityNode->getElementsByTagName("IsValidForAdvancedFind")->item(0)->textContent == "true") {

						$responseElement["LogicalName"] = $entityNode->getElementsByTagName("LogicalName")->item(0)->textContent;
						$responseElement["DisplayName"] = $entityNode->getElementsByTagName("DisplayName")->item(0)->getElementsByTagName("UserLocalizedLabel")->item(0)->getElementsByTagName("Label")->item(0)->textContent;

						array_push($responseDataArray, $responseElement);
					}
				}
				/* Convert the Array to a stdClass Object */
				$responseData = $responseDataArray;
				return $responseData;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Get the SOAP Endpoint for the Federation Security service 
		 * @ignore
		 */
		public function getFederationSecurityURI($service) {
			try{
				/* If it's set, return the details from the Security array */
				if (isset($this->security[$service . '_authendpoint']))
					return $this->security[$service . '_authendpoint'];

				/* Fetch the WSDL for the Authentication Service as a parseable DOM Document */
				AlexaSDK_Logger::log('Getting WSDL data for Federation Security URI from: ' . $this->security[$service . '_authuri'] );
				$authenticationDOM = new DOMDocument();
				@$authenticationDOM->load($this->security[$service . '_authuri']);
				/* Flatten the WSDL and include all the Imports */
				$this->mergeWSDLImports($authenticationDOM);

				// Note: Find the real end-point to use for my security request - for now, we hard-code to Trust13 Username & Password using known values
				// See http://code.google.com/p/php-dynamics-crm-2011/issues/detail?id=4
				$authEndpoint = self::getTrust13UsernameAddress($authenticationDOM);
				return $authEndpoint;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Get the SOAP Endpoint for the OnlineFederation Security service 
		 * @ignore
		 */
		protected function getOnlineFederationSecurityURI($service) {
			try{
				/* If it's set, return the details from the Security array */
				if (isset($this->security[$service . '_authendpoint']))
					return $this->security[$service . '_authendpoint'];

				/* Fetch the WSDL for the Authentication Service as a parseable DOM Document */
				AlexaSDK_Logger::log('Getting WSDL data for OnlineFederation Security URI from: ' . $this->security[$service . '_authuri'] );
				$authenticationDOM = new DOMDocument();
				@$authenticationDOM->load($this->security[$service . '_authuri']);
				/* Flatten the WSDL and include all the Imports */
				$this->mergeWSDLImports($authenticationDOM);

				$authEndpoint = self::getLoginOnmicrosoftAddress($authenticationDOM);

				return $authEndpoint;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Search a Microsoft Dynamics CRM 2011 WSDL for the Security Policy for a given Service
		 * @ignore
		 */
		protected static function findSecurityPolicy(DOMDocument $wsdlDocument, $serviceName) {
			try{
				/* Find the selected Service definition from the WSDL */
				$selectedServiceNode = NULL;

				foreach ($wsdlDocument->getElementsByTagName('service') as $serviceNode) {
					if ($serviceNode->hasAttribute('name') && $serviceNode->getAttribute('name') == $serviceName) {
						$selectedServiceNode = $serviceNode;
						break;
					}
				}
				if ($selectedServiceNode == NULL) {
					throw new Exception('Could not find definition of Service <' . $serviceName . '> in provided WSDL');
					return FALSE;
				}
				/* Now find the Binding for the Service */
				$bindingName = NULL;
				foreach ($selectedServiceNode->getElementsByTagName('port') as $portNode) {
					if ($portNode->hasAttribute('name')) {
						$bindingName = $portNode->getAttribute('name');
						break;
					}
				}
				if ($bindingName == NULL) {
					throw new Exception('Could not find binding for Service <' . $serviceName . '> in provided WSDL');
					return FALSE;
				}
				/* Find the Binding definition from the WSDL */
				$bindingNode = NULL;
				foreach ($wsdlDocument->getElementsByTagName('binding') as $bindingNode) {
					if ($bindingNode->hasAttribute('name') && $bindingNode->getAttribute('name') == $bindingName) {
						break;
					}
				}
				if ($bindingNode == NULL) {
					throw new Exception('Could not find defintion of Binding <' . $bindingName . '> in provided WSDL');
					return FALSE;
				}
				/* Find the Policy Reference */
				$policyReferenceURI = NULL;
				foreach ($bindingNode->getElementsByTagName('PolicyReference') as $policyReferenceNode) {
					if ($policyReferenceNode->hasAttribute('URI')) {
						/* Strip the leading # from the PolicyReferenceURI to get the ID */
						$policyReferenceURI = substr($policyReferenceNode->getAttribute('URI'), 1);
						break;
					}
				}
				if ($policyReferenceURI == NULL) {
					throw new Exception('Could not find Policy Reference for Binding <' . $bindingName . '> in provided WSDL');
					return FALSE;
				}
				/* Find the Security Policy from the WSDL */
				$securityPolicyNode = NULL;
				foreach ($wsdlDocument->getElementsByTagName('Policy') as $policyNode) {
					if ($policyNode->hasAttribute('wsu:Id') && $policyNode->getAttribute('wsu:Id') == $policyReferenceURI) {
						$securityPolicyNode = $policyNode;
						break;
					}
				}
				if ($securityPolicyNode == NULL) {
					throw new Exception('Could not find Policy with ID <' . $policyReferenceURI . '> in provided WSDL');
					return FALSE;
				}
				/* Return the selected node */
				return $securityPolicyNode;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Fetch and flatten the Organization Service WSDL as a DOM
		 * @ignore
		 */
		public function getOrganizationDOM() {
			try{
				/* If it's already been fetched, use the one we have */
				if ($this->organizationDOM != NULL)
					return $this->organizationDOM;
				if ($this->settings->organizationUrl == NULL) {
					throw new Exception('Cannot get Organization DOM before determining Organization URI');
				}

				/* Fetch the WSDL for the Organization Service as a parseable DOM Document */
				AlexaSDK_Logger::log('Getting WSDL data for Organization DOM from: ' . $this->settings->organizationUrl . '?wsdl' );
				$organizationDOM = new DOMDocument();
				@$organizationDOM->load($this->settings->organizationUrl . '?wsdl');
				/* Flatten the WSDL and include all the Imports */
				$this->mergeWSDLImports($organizationDOM);

				/* Cache the DOM in the current object */
				$this->organizationDOM = $organizationDOM;

				return $organizationDOM;
			}catch(Exception $e){
				AlexaSDK_Logger::log("Exception", $e);
				throw $e;
			}
		}

		/**
		 * Send a RetrieveEntity request to the Dynamics CRM 2011 server and return the results as a structured Object
		 *
		 * @param String $entityType the LogicalName of the Entity to be retrieved (Incident, Account etc.)
		 * @param String $entityId the internal Id of the Entity to be retrieved (without enclosing brackets)
		 * @param Array $entityFilters array listing all fields to be fetched, or null to get all columns
		 * @param Boolean $showUnpublished
		 * 
		 * @return stdClass a PHP Object containing all the data retrieved.
		 */
		public function retrieveEntity($entityType, $entityId = NULL, $entityFilters = NULL, $showUnpublished = false) {
			/* Get the raw XML data */
			$rawSoapResponse = $this->retrieveEntityRaw($entityType, $entityId, $entityFilters, $showUnpublished);

			/* Parse the raw XML data into an Object */
			$soapData = self::parseRetrieveEntityResponse($rawSoapResponse);

			/* Return the structured object */
			return $soapData;
		}

		/**
		 * Send a RetrieveEntity request to the Dynamics CRM server and return the results as raw XML
		 *
		 * This is particularly useful when debugging the responses from the server
		 * 
		 * @param string $entityType the LogicalName of the Entity to be retrieved (Incident, Account etc.)
		 * @return string the raw XML returned by the server, including all SOAP Envelope, Header and Body data.
		 */
		public function retrieveEntityRaw($entityType, $entityId = NULL, $entityFilters = NULL, $showUnpublished = false) {
			/* Send the sequrity request and get a security token */
			$securityToken = $this->authentication->getOrganizationSecurityToken();
			/* Generate the XML for the Body of a RetrieveEntity request */
			$executeNode = AlexaSDK_SoapRequestsGenerator::generateRetrieveEntityRequest($entityType, $entityId, $entityFilters, $showUnpublished);
			/* Turn this into a SOAP request, and send it */
			$retrieveEntityRequest = $this->generateSoapRequest($this->settings->organizationUrl, $this->soapActions->getSoapAction('organization', 'Execute'), $securityToken, $executeNode);
			$soapResponse = self::getSoapResponse($this->settings->organizationUrl, $retrieveEntityRequest);

			return $soapResponse;
		}

		/**
		 * Send a RetrieveMultipleEntities request to the Dynamics CRM server
		 * and return the results as a structured Object
		 * Each Entity returned is processed into an appropriate AlexaSDK_Entity object
		 *
		 * @param string $entityType logical name of entities to retrieve
		 * @param boolean $allPages indicates if the query should be resent until all possible data is retrieved
		 * @param string $pagingCookie if multiple pages are returned, send the paging cookie to get pages 2 and onwards.  Use NULL to get the first page.  Ignored if $allPages is specified.
		 * @param integer $limitCount maximum number of records to be returned per page
		 * @param boolean $simpleMode indicates if we should just use stdClass, instead of creating Entities
		 * @return stdClass a PHP Object containing all the data retrieved.
		 */
		public function retrieveMultipleEntities($entityType, $allPages = TRUE, $pagingCookie = NULL, $limitCount = NULL, $pageNumber = NULL, $simpleMode = FALSE) {
			$queryXML = new DOMDocument();
			$fetch = $queryXML->appendChild($queryXML->createElement('fetch'));
			$fetch->setAttribute('version', '1.0');
			$fetch->setAttribute('output-format', 'xml-platform');
			$fetch->setAttribute('mapping', 'logical');
			$fetch->setAttribute('distinct', 'false');
			$entity = $fetch->appendChild($queryXML->createElement('entity'));
			$entity->setAttribute('name', $entityType);
			$entity->appendChild($queryXML->createElement('all-attributes'));
			$queryXML->saveXML($fetch);

			return $this->retrieveMultiple($queryXML->C14N(), $allPages, $pagingCookie, $limitCount, $pageNumber, $simpleMode);
		}

		/**
		 * Send a Retrieve request to the Dynamics CRM 2011 server and return the results as raw XML
		 * This function is typically used just after creating something (where you get the ID back
		 * as the return value), as it is more efficient to use RetrieveMultiple to search directly if 
		 * you don't already have the ID.
		 *
		 * This is particularly useful when debugging the responses from the server
		 * 
		 * @param AlexaSDK_Entity $entity the Entity to retrieve - must have an ID specified
		 * @param array $fieldSet array listing all fields to be fetched, or null to get all fields
		 * @return string the raw XML returned by the server, including all SOAP Envelope, Header and Body data.
		 */
		public function retrieveRaw(AlexaSDK_Entity $entity, $fieldSet = NULL) {
			/* Determine the Type & ID of the Entity */
			$entityType = $entity->LogicalName;
			$entityId = $entity->ID;
			/* Send the sequrity request and get a security token */
			$securityToken = $this->authentication->getOrganizationSecurityToken();
			/* Generate the XML for the Body of a RetrieveRecordChangeHistory request */
			$executeNode = AlexaSDK_SoapRequestsGenerator::generateRetrieveRequest($entityType, $entityId, $fieldSet);
			/* Turn this into a SOAP request, and send it */
			$retrieveRequest = $this->generateSoapRequest($this->settings->organizationUrl, $this->soapActions->getSoapAction('organization', 'Retrieve'), $securityToken, $executeNode);
			$soapResponse = self::getSoapResponse($this->settings->organizationUrl, $retrieveRequest);

			return $soapResponse;
		}
		
		public function retrieveOrganizations() {
			/* Request a Security Token for the Discovery Service */
			$securityToken = $this->authentication->getDiscoverySecurityToken();
			/* Generate a Soap Request for the Retrieve Organization Request method of the Discovery Service */
			$discoverySoapRequest = $this->generateSoapRequest($this->settings->discoveryUrl, $this->soapActions->getSoapAction('discovery', 'Execute'), $securityToken, AlexaSDK_SoapRequestsGenerator::generateRetrieveOrganizationRequest());

			$discovery_data = self::getSoapResponse($this->settings->discoveryUrl, $discoverySoapRequest);

			$organizationDetails = Array();
			$discoveryDOM = new DOMDocument();
			$discoveryDOM->loadXML($discovery_data);

			if ($discoveryDOM->getElementsByTagName('OrganizationDetail')->length > 0) {
				foreach ($discoveryDOM->getElementsByTagName('OrganizationDetail') as $organizationNode) {
					$organization = Array();
					foreach ($organizationNode->getElementsByTagName('Endpoints')->item(0)->getElementsByTagName('KeyValuePairOfEndpointTypestringztYlk6OT') as $endpointDOM) {

						$organization["Endpoints"][$endpointDOM->getElementsByTagName('key')->item(0)->textContent] = $endpointDOM->getElementsByTagName('value')->item(0)->textContent;
					}

					if ($organizationNode->getElementsByTagName('FriendlyName')->length > 0) {
						$organization["FriendlyName"] = $organizationNode->getElementsByTagName('FriendlyName')->item(0)->textContent;
					}

					if ($organizationNode->getElementsByTagName('OrganizationId')->length > 0) {
						$organization["OrganizationId"] = $organizationNode->getElementsByTagName('OrganizationId')->item(0)->textContent;
					}

					if ($organizationNode->getElementsByTagName('OrganizationVersion')->length > 0) {
						$organization["OrganizationVersion"] = $organizationNode->getElementsByTagName('OrganizationVersion')->item(0)->textContent;
					}

					if ($organizationNode->getElementsByTagName('State')->length > 0) {
						$organization["State"] = $organizationNode->getElementsByTagName('State')->item(0)->textContent;
					}

					if ($organizationNode->getElementsByTagName('UniqueName')->length > 0) {
						$organization["UniqueName"] = $organizationNode->getElementsByTagName('UniqueName')->item(0)->textContent;
					}

					if ($organizationNode->getElementsByTagName('UrlName')->length > 0) {
						$organization["UrlName"] = $organizationNode->getElementsByTagName('UrlName')->item(0)->textContent;
					}

					array_push($organizationDetails, $organization);
				}
			}
			return $organizationDetails;
		}

		public function retrieveOrganization($webApplicationUrl) {
			$organizationDetails = NULL;
			$parsedUrl = parse_url($webApplicationUrl);

			$organizations = $this->retrieveOrganizations();

			foreach ($organizations as $organization) {
				if (substr_count($organization["Endpoints"]["WebApplication"], $parsedUrl["host"])) {
					$organizationDetails = $organization;
				}
			}
			return $organizationDetails;
		}
		
		/**
		 * Create a new usable Dynamics CRM Entity object
		 *          
		 * @param String $logicalName Allows constructing arbritrary Entities by setting the EntityLogicalName directly
		 * @param String $ID Allows constructing arbritrary Entities by setting the EntityLogicalName directly
		 */
		public function entity($logicalName, $ID = NULL, $colmnset = NULL){
			return new AlexaSDK_Entity($this, $logicalName, $ID, $colmnset);
		}

		/**
		 * Get the connector timeout value
		 * @return int the maximum time the connector will wait for a response from the CRM in seconds
		 */
		public static function getConnectorTimeout() {
			return self::$connectorTimeout;
		}

		/**
		 * Set the connector timeout value
		 * @param int $_connectorTimeout maximum time the connector will wait for a response from the CRM in seconds
		 */
		public static function setConnectorTimeout($_connectorTimeout) {
			if (!is_int($_connectorTimeout))
				return;
			self::$connectorTimeout = $_connectorTimeout;
		}

		/**
		 * Get the cache time value
		 * @return int cache data lifetime in seconds
		 */
		public static function getCacheTime() {
			return self::$cacheTime;
		}

		/**
		 * Set the cache time value
		 * @param int $_cacheTime cache data lifetime in seconds
		 */
		public static function setCacheTime($_cacheTime) {
			if (!is_int($_cacheTime))
				return;
			self::$cacheTime = $_cacheTime;
		}

		/**
		 * Get the Discovery URL which is currently in use
		 * @return string the URL of the Discovery Service
		 */
		public function getDiscoveryURI() {
			return $this->discoveryURI;
		}

		/**
		 * Get the Organization Unique Name which is currently in use
		 * @return string the Unique Name of the Organization
		 */
		public function getOrganization() {
			return $this->organizationUniqueName;
		}

		/**
		 * Get the maximum records for a query
		 * @return int the maximum records that will be returned from RetrieveMultiple per page
		 */
		public static function getMaximumRecords() {
			return self::$maximumRecords;
		}

		/**
		 * Set the maximum records for a query
		 * @param int $_maximumRecords the maximum number of records to fetch per page
		 */
		public static function setMaximumRecords($_maximumRecords) {
			if (!is_int($_maximumRecords))
				return;
			self::$maximumRecords = $_maximumRecords;
		}

		/**
		 * SEE GetSOAPResponse
		 * 
		 * @param $soapUrl
		 * @param $content
		 * @param $requestType
		 * @ignore
		 * @return Array $header Formatted headers
		 */
		private static function formatHeaders($soapUrl, $content, $requestType = "POST") {
			$scheme = parse_url($soapUrl);
			/* Setup headers array */
			$headers = array(
				$requestType . " " . $scheme["path"] . " HTTP/1.1",
				"Host: " . $scheme["host"],
				'Connection: Keep-Alive',
				"Content-type: application/soap+xml; charset=UTF-8",
				"Content-length: " . strlen($content),
			);
			return $headers;
		}
		
		
		public static function getSoapResponse($soapUrl, $content, $throwException = true){
				
				try{
			
					$response = self::getResponse($soapUrl, $content, $throwException);
				
				}catch(SoapFault $ex){
					//AlexaSDK_Logger::log("Soap Fault", $ex);
					throw new Exception($ex->getMessage(), $ex->getCode());
				}
			
				return $response;
				
		}
		

		/**
		 * Send the SOAP message, and get the response 
		 * @ignore
		 * @return string response XML
		 */
		public static function getResponse($soapUrl, $content, $throwException = true) {
			/* Separate the provided URI into Path & Hostname sections */
			$urlDetails = parse_url($soapUrl);
			/* Format cUrl headers */
			$headers = self::formatHeaders($soapUrl, $content);

			$cURLHandle = curl_init();
			curl_setopt($cURLHandle, CURLOPT_URL, $soapUrl);
			curl_setopt($cURLHandle, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($cURLHandle, CURLOPT_TIMEOUT, self::$connectorTimeout);
			curl_setopt($cURLHandle, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($cURLHandle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
			curl_setopt($cURLHandle, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($cURLHandle, CURLOPT_POST, 1);
			curl_setopt($cURLHandle, CURLOPT_POSTFIELDS, $content);
			curl_setopt($cURLHandle, CURLOPT_HEADER, false);
			/* Execute the cURL request, get the XML response */
			$responseXML = curl_exec($cURLHandle);
			/* Check for cURL errors */
			if (curl_errno($cURLHandle) != CURLE_OK) {
				throw new Exception('cURL Error: ' . curl_error($cURLHandle));
			}
			/* Check for HTTP errors */
			$httpResponse = curl_getinfo($cURLHandle, CURLINFO_HTTP_CODE);
			curl_close($cURLHandle);
			/* Determine the Action in the SOAP Response */
			$responseDOM = new DOMDocument();
			$responseDOM->loadXML($responseXML);
			/* Check we have a SOAP Envelope */
			if ($responseDOM->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Envelope')->length < 1) {
				throw new Exception('Invalid SOAP Response: HTTP Response ' . $httpResponse . PHP_EOL . $responseXML );
			}
			/* Fast fix for CRM expiration, sets plugin connection to false */
			if ($responseDOM->getElementsByTagNameNS('http://schemas.microsoft.com/Passport/SoapServices/SOAPFault', 'value')->length > 0 && function_exists("ASDK") && ASDK()) {
				$errorCode = $responseDOM->getElementsByTagNameNS('http://schemas.microsoft.com/Passport/SoapServices/SOAPFault', 'value')->item(0)->textContent;
				if ($errorCode == "0x80048831") {
					$options = get_option(ACRM()->prefix.'options');
					$options["connected"] = false;
					update_option(ACRM()->prefix.'options', $options);
				}
			}
			/* Check we have a SOAP Header */
			if ($responseDOM->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Envelope')->item(0)
							->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Header')->length < 1) {
				throw new Exception('Invalid SOAP Response: No SOAP Header! ' . PHP_EOL . $responseXML);
			}
			/* Get the SOAP Action */
			$actionString = $responseDOM->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Envelope')->item(0)
							->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Header')->item(0)
							->getElementsByTagNameNS('http://www.w3.org/2005/08/addressing', 'Action')->item(0)->textContent;
			AlexaSDK_Logger::log(__FUNCTION__ . ': SOAP Action in returned XML is "' . $actionString . '"');
			/* Handle known Error Actions */
			if (in_array($actionString, self::$SOAPFaultActions) && $throwException) {
				// Get the Fault Code
				$faultCode = $responseDOM->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Envelope')->item(0)
								->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Body')->item(0)
								->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Fault')->item(0)
								->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Code')->item(0)
								->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Value')->item(0)->nodeValue;
				/* Strip any Namespace References from the fault code */
				$faultCode = self::stripNS($faultCode);
				// Get the Fault String
				$faultString = $responseDOM->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Envelope')->item(0)
								->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Body')->item(0)
								->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Fault')->item(0)
								->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Reason')->item(0)
								->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Text')->item(0)->nodeValue . PHP_EOL;
				throw new SoapFault($faultCode, $faultString);
			}
			/* Return XML response string */
			return $responseXML;
		}


		/**
		 * Create the XML String for a Soap Request 
		 * @ignore
		 */
		protected function generateSoapRequest($serviceURI, $soapAction, $securityToken, DOMNode $bodyContentNode) {
			$soapRequestDOM = new DOMDocument();
			$soapEnvelope = $soapRequestDOM->appendChild($soapRequestDOM->createElementNS('http://www.w3.org/2003/05/soap-envelope', 's:Envelope'));
			$soapEnvelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:a', 'http://www.w3.org/2005/08/addressing');
			$soapEnvelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:u', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd');
			/* Get the SOAP Header */
			$soapHeaderNode = $this->generateSoapHeader($serviceURI, $soapAction, $securityToken);
			$soapEnvelope->appendChild($soapRequestDOM->importNode($soapHeaderNode, true));
			/* Create the SOAP Body */
			$soapBodyNode = $soapEnvelope->appendChild($soapRequestDOM->createElement('s:Body'));
			$soapBodyNode->appendChild($soapRequestDOM->importNode($bodyContentNode, true));
			return $soapRequestDOM->saveXML($soapEnvelope);
		}

		/**
		 * Generate a Soap Header using the specified service URI and SoapAction
		 * Include the details from the Security Token for login
		 * @ignore
		 */
		protected function generateSoapHeader($serviceURI, $soapAction, $securityToken) {
			$soapHeaderDOM = new DOMDocument();
			$headerNode = $soapHeaderDOM->appendChild($soapHeaderDOM->createElement('s:Header'));
			$headerNode->appendChild($soapHeaderDOM->createElement('a:Action', $soapAction))->setAttribute('s:mustUnderstand', '1');
			$headerNode->appendChild($soapHeaderDOM->createElement('a:ReplyTo'))->appendChild($soapHeaderDOM->createElement('a:Address', 'http://www.w3.org/2005/08/addressing/anonymous'));
			$headerNode->appendChild($soapHeaderDOM->createElement('a:MessageId', 'urn:uuid:' . parent::getUuid()));
			$headerNode->appendChild($soapHeaderDOM->createElement('a:To', $serviceURI))->setAttribute('s:mustUnderstand', '1');
			$securityHeaderNode = $this->authentication->getSecurityHeaderNode($securityToken);
			$headerNode->appendChild($soapHeaderDOM->importNode($securityHeaderNode, true));

			return $headerNode;
		}

		/**
		 * Utility function that checks base CRM Connection settings
		 * Checks the Discovery URL, username and password in provided settings and verifies all the necessary data exists
		 * @return boolean indicator showing if the connection details are okay
		 * @ignore
		 */
		private function checkConnectionSettings() {
			/* username and password are common for authentication modes */
			if ($this->settings->username == NULL || $this->settings->password == NULL){
				return FALSE;
			}
			if ($this->settings->authMode == "Federation" && $this->settings->discoveryUrl == NULL){
				return FALSE;
			}			
			return TRUE;
		}

		/**
		 * Send a RetrieveMultiple request to the Dynamics CRM server
		 * and return the results as a structured Object
		 * Each Entity returned is processed into an appropriate AlexaSDK_Entity object
		 *
		 * @param string $queryXML the Fetch XML string (as generated by the Advanced Find tool on Microsoft Dynamics CRM 2011)
		 * @param boolean $allPages indicates if the query should be resent until all possible data is retrieved
		 * @param string $pagingCookie if multiple pages are returned, send the paging cookie to get pages 2 and onwards.  Use NULL to get the first page.  Ignored if $allPages is specified.
		 * @param integer $limitCount maximum number of records to be returned per page
		 * @param boolean $simpleMode indicates if we should just use stdClass, instead of creating Entities
		 * @return stdClass a PHP Object containing all the data retrieved.
		 */
		public function retrieveMultiple($queryXML, $allPages = TRUE, $pagingCookie = NULL, $limitCount = NULL, $pageNumber = NULL, $simpleMode = FALSE) {
			/* Prepare an Object to hold the returned data */
			$soapData = NULL;
			/* If we need all pages, ignore any supplied paging cookie */
			if ($allPages)
				$pagingCookie = NULL;
			do {
				/* Get the raw XML data */
				$rawSoapResponse = $this->retrieveMultipleRaw($queryXML, $pagingCookie, $limitCount, $pageNumber);
				/* Parse the raw XML data into an Object */
				$tmpSoapData = self::parseRetrieveMultipleResponse($this, $rawSoapResponse, $simpleMode, $queryXML);
				/* If we already had some data, add the old Entities */
				if ($soapData != NULL) {
					$tmpSoapData->Entities = array_merge($soapData->Entities, $tmpSoapData->Entities);
					$tmpSoapData->Count += $soapData->Count;
				}
				/* Save the new Soap Data */
				$soapData = $tmpSoapData;
				/* Check if the PagingCookie is present & needed */
				if ($soapData->MoreRecords && $soapData->PagingCookie == NULL) {
					/* Paging Cookie is not present in returned data, but is expected! */
					/* Check if a Paging Cookie was supplied */
					if ($pagingCookie == NULL) {
						/* This was the first page */
						$pageNo = 1;
					} else {
						/* This is the page from the last PagingCookie, plus 1 */
						$pageNo = self::getPageNo($pagingCookie) + 1;
					}
					/* Create a new paging cookie for this page */
					$pagingCookie = '<cookie page="' . $pageNo . '"></cookie>';
					$soapData->PagingCookie = $pagingCookie;
				} else {
					/* PagingCookie exists, or is not needed */
					$pagingCookie = $soapData->PagingCookie;
				}

				/* Loop while there are more records, and we want all pages */
			} while ($soapData->MoreRecords && $allPages);

			/* Return the compiled structure */
			return $soapData;
		}

		/**
		 * Send a RetrieveMultiple request to the Dynamics CRM server
		 * and return the results as a structured Object
		 * Each Entity returned is processed into a simple stdClass
		 * 
		 * Note that this function is faster than using Entities, but not as strong
		 * at handling complicated return types.
		 *
		 * @param string $queryXML the Fetch XML string (as generated by the Advanced Find tool on Microsoft Dynamics CRM 2011)
		 * @param boolean $allPages indicates if the query should be resent until all possible data is retrieved
		 * @param string $pagingCookie if multiple pages are returned, send the paging cookie to get pages 2 and onwards.  Use NULL to get the first page.  Ignored if $allPages is specified.
		 * @param integer $limitCount maximum number of records to be returned per page
		 * @return stdClass a PHP Object containing all the data retrieved.
		 */
		public function retrieveMultipleSimple($queryXML, $allPages = TRUE, $pagingCookie = NULL, $pageNumber = NULL, $limitCount = NULL) {
			return $this->retrieveMultiple($queryXML, $allPages, $pagingCookie, $limitCount, $pageNumber, true);
		}

		/**
		 * retrieve a single Entity based on queryXML
		 * 
		 * @param string $queryXML the Fetch XML string (as generated by the Advanced Find tool on Microsoft Dynamics CRM)
		 * @return AlexaSDK_Entity a PHP Object containing all the data retrieved.
		 */
		public function retrieveSingle($queryXML) {
			$result = $this->retrieveMultiple($queryXML, FALSE, NULL, 1, NULL, false);

			return ($result->Count) ? $result->Entities[0] : NULL;
		}

		/**
		 * Send a RetrieveMultiple request to the Dynamics CRM server
		 * and return the results as raw XML
		 *
		 * This is particularly useful when debugging the responses from the server
		 * 
		 * @param string $queryXML the Fetch XML string (as generated by the Advanced Find tool on Microsoft Dynamics CRM 2011)
		 * @param string $pagingCookie if multiple pages are returned, send the paging cookie to get pages 2 and onwards.  Use NULL to get the first page
		 * @param integer $limitCount maximum number of records to be returned per page
		 * @return string the raw XML returned by the server, including all SOAP Envelope, Header and Body data.
		 */
		public function retrieveMultipleRaw($queryXML, $pagingCookie = NULL, $limitCount = NULL, $pageNumber = NULL) {
			/* Send the sequrity request and get a security token */
			$securityToken = $this->authentication->getOrganizationSecurityToken();
			/* Generate the XML for the Body of a RetrieveMulitple request */
			$executeNode = AlexaSDK_SoapRequestsGenerator::generateRetrieveMultipleRequest($queryXML, $pagingCookie, $limitCount, $pageNumber);
			/* Turn this into a SOAP request, and send it */
			$retrieveMultipleSoapRequest = $this->generateSoapRequest($this->settings->organizationUrl, $this->soapActions->getSoapAction('organization', 'RetrieveMultiple'), $securityToken, $executeNode);
			$soapResponse = self::getSoapResponse($this->settings->organizationUrl, $retrieveMultipleSoapRequest);

			return $soapResponse;
		}

		

		/**
		 * Parse the results of a RetrieveMultipleRequest into a useable PHP object
		 * @param AlexaSDK $conn
		 * @param String $soapResponse
		 * @param Boolean $simpleMode
		 * @ignore
		 */
		public static function parseRetrieveMultipleResponse(AlexaSDK $conn, $soapResponse, $simpleMode, $queryXML = NULL) {
			/* Load the XML into a DOMDocument */
			$soapResponseDOM = new DOMDocument();
			$soapResponseDOM->loadXML($soapResponse);
			/* Find the RetrieveMultipleResponse */
			$retrieveMultipleResponseNode = NULL;
			foreach ($soapResponseDOM->getElementsByTagName('RetrieveMultipleResponse') as $node) {
				$retrieveMultipleResponseNode = $node;
				break;
			}
			unset($node);
			if ($retrieveMultipleResponseNode == NULL) {
				throw new Exception('Could not find RetrieveMultipleResponse node in XML provided');
				return FALSE;
			}
			/* Find the RetrieveMultipleResult node */
			$retrieveMultipleResultNode = NULL;
			foreach ($retrieveMultipleResponseNode->getElementsByTagName('RetrieveMultipleResult') as $node) {
				$retrieveMultipleResultNode = $node;
				break;
			}
			unset($node);
			if ($retrieveMultipleResultNode == NULL) {
				throw new Exception('Could not find RetrieveMultipleResult node in XML provided');
				return FALSE;
			}
			/* Assemble an associative array for the details to return */
			$responseDataArray = Array();
			$responseDataArray['EntityName'] = $retrieveMultipleResultNode->getElementsByTagName('EntityName')->length == 0 ? NULL : $retrieveMultipleResultNode->getElementsByTagName('EntityName')->item(0)->textContent;
			$responseDataArray['MoreRecords'] = ($retrieveMultipleResultNode->getElementsByTagName('MoreRecords')->item(0)->textContent == 'true');
			$responseDataArray['PagingCookie'] = $retrieveMultipleResultNode->getElementsByTagName('PagingCookie')->length == 0 ? NULL : $retrieveMultipleResultNode->getElementsByTagName('PagingCookie')->item(0)->textContent;
			$responseDataArray['Entities'] = Array();
			/* Loop through the Entities returned */
			foreach ($retrieveMultipleResultNode->getElementsByTagName('Entities')->item(0)->getElementsByTagName('Entity') as $entityNode) {
				/* If we are in "SimpleMode", just create the Attributes as a stdClass */
				if ($simpleMode) {
					/* Create an Array to hold the Entity properties */
					$entityArray = Array();
					/* Identify the Attributes */
					$keyValueNodes = $entityNode->getElementsByTagName('Attributes')->item(0)->getElementsByTagName('KeyValuePairOfstringanyType');
					/* Add the Attributes in the Key/Value Pairs of String/AnyType to the Array */
					self::addAttributes($entityArray, $keyValueNodes);
					/* Identify the FormattedValues */
					$keyValueNodes = $entityNode->getElementsByTagName('FormattedValues')->item(0)->getElementsByTagName('KeyValuePairOfstringstring');
					/* Add the Formatted Values in the Key/Value Pairs of String/String to the Array */
					self::addFormattedValues($entityArray, $keyValueNodes);
					/* Add the Entity to the Entities Array as a stdClass Object */
					$responseDataArray['Entities'][] = (Object) $entityArray;
				} else {
					/* Generate a new Entity from the DOMNode */
					$entity = AlexaSDK_Entity::fromDOM($conn, $responseDataArray['EntityName'], $entityNode, $queryXML);
					/* Add the Entity to the Entities Array as a AlexaSDK_Entity Object */
					$responseDataArray['Entities'][] = $entity;
				}
			}
			/* Record the number of Entities */
			$responseDataArray['Count'] = count($responseDataArray['Entities']);

			/* Convert the Array to a stdClass Object */
			$responseData = (Object) $responseDataArray;
			return $responseData;
		}

		/**
		 * Add a list of Attributes to an Array of Attributes, using appropriate handling
		 * of the Attribute type, and avoiding over-writing existing attributes
		 * already in the array 
		 * 
		 * Optionally specify an Array of sub-keys, and a particular sub-key
		 * - If provided, each sub-key in the Array will be created as an Object attribute,
		 *   and the value will be set on the specified sub-key only (e.g. (New, Old) / New)
		 * 
		 * @ignore
		 */
		protected static function addAttributes(Array &$targetArray, DOMNodeList $keyValueNodes, Array $keys = NULL, $key1 = NULL) {
			foreach ($keyValueNodes as $keyValueNode) {
				/* Get the Attribute name (key) */
				$attributeKey = $keyValueNode->getElementsByTagName('key')->item(0)->textContent;
				/* Check the Value Type */
				$attributeValueType = $keyValueNode->getElementsByTagName('value')->item(0)->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type');
				/* Strip any Namespace References from the Type */
				$attributeValueType = self::stripNS($attributeValueType);
				switch ($attributeValueType) {
					case 'AliasedValue':
						/* For an AliasedValue, the Key is Alias.Field, so just get the Alias */
						list($attributeKey, ) = explode('.', $attributeKey, 2);
						/* Entity Logical Name => the Object Type */
						$entityLogicalName = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('EntityLogicalName')->item(0)->textContent;
						/* Attribute Logical Name => the actual Attribute of the Aliased Object */
						$attributeLogicalName = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('AttributeLogicalName')->item(0)->textContent;
						$entityAttributeValue = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Value')->item(0)->textContent;
						/* See if this Alias is already in the Array */
						if (array_key_exists($attributeKey, $targetArray)) {
							/* It already exists, so grab the existing Object and set the new Attribute */
							$attributeValue = $targetArray[$attributeKey];
							$attributeValue->$attributeLogicalName = $entityAttributeValue;
							/* Pull it from the array, so we don't set a duplicate */
							unset($targetArray[$attributeKey]);
						} else {
							/* Create a new Object with the Logical Name, and this Attribute */
							$attributeValue = (Object) Array('LogicalName' => $entityLogicalName, $attributeLogicalName => $entityAttributeValue);
						}
						break;
					case 'EntityReference':
						$attributeLogicalName = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('LogicalName')->item(0)->textContent;
						$attributeId = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Id')->item(0)->textContent;
						$attributeName = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Name')->item(0)->textContent;
						$attributeValue = (Object) Array('LogicalName' => $attributeLogicalName,
									'Id' => $attributeId,
									'Name' => $attributeName);
						break;
					case 'OptionSetValue':
						$attributeValue = $keyValueNode->getElementsByTagName('value')->item(0)->getElementsByTagName('Value')->item(0)->textContent;
						break;
					case 'dateTime':
						$attributeValue = $keyValueNode->getElementsByTagName('value')->item(0)->textContent;
						$attributeValue = self::parseTime($attributeValue, '%Y-%m-%dT%H:%M:%SZ');
						break;
					default:
						$attributeValue = $keyValueNode->getElementsByTagName('value')->item(0)->textContent;
				}
				/* If we are working normally, just store the data in the array */
				if ($keys == NULL) {
					/* Assume that if there is a duplicate, it's a formatted version of this */
					if (array_key_exists($attributeKey, $targetArray)) {
						$responseDataArray[$attributeKey] = (Object) Array('Value' => $attributeValue,
									'FormattedValue' => $targetArray[$attributeKey]);
					} else {
						$targetArray[$attributeKey] = $attributeValue;
					}
				} else {
					/* Store the data in the array for this AuditRecord's properties */
					if (array_key_exists($attributeKey, $targetArray)) {
						/* We assume it's already a "good" Object, and just set this key */
						if (isset($targetArray[$attributeKey]->$key1)) {
							/* It's already set, so add the Un-formatted version */
							$targetArray[$attributeKey]->$key1 = (Object) Array(
										'Value' => $attributeValue,
										'FormattedValue' => $targetArray[$attributeKey]->$key1);
						} else {
							/* It's not already set, so just set this as a value */
							$targetArray[$attributeKey]->$key1 = $attributeValue;
						}
					} else {
						/* We need to create the Object */
						$obj = (Object) Array();
						foreach ($keys as $k) {
							$obj->$k = NULL;
						}
						/* And set the particular property */
						$obj->$key1 = $attributeValue;
						/* And store the Object in the target Array */
						$targetArray[$attributeKey] = $obj;
					}
				}
			}
		}

		/**
		 * Find the PageNumber in a PagingCookie
		 * 
		 * @param String $pagingCookie
		 * @ignore
		 */
		public static function getPageNo($pagingCookie) {
			/* Turn the pagingCookie into a DOMDocument so we can read it */
			$pagingDOM = new DOMDocument();
			$pagingDOM->loadXML($pagingCookie);
			/* Find the page number */
			$pageNo = $pagingDOM->documentElement->getAttribute('page');
			return (int) $pageNo;
		}


		/**
		 * Check if an Entity Definition has been cached
		 * 
		 * @param String $entityLogicalName Logical Name of the entity to check for in the Cache
		 * @return boolean true if this Entity has been cached
		 */
		public function isEntityDefinitionCached($entityLogicalName) {
			/* Check if this entityLogicalName is in the Cache */
			if (array_key_exists($entityLogicalName, $this->cachedEntityDefintions)) {
				return true;
			} else {
				return false;
			}
		}

		public function setCachedEntityShortDefinition($entitiesDefinitions) {
			$this->cachedEntitiesShortDefinitions = $entitiesDefinitions;

			$this->cacheClass->set("entities_definitions", serialize($this->cachedEntitiesShortDefinitions), self::$cacheTime);
		}

		/**
		 * Cache the definition of an Entity
		 * 
		 * @param String $entityLogicalName
		 * @param SimpleXMLElement $entityData
		 * @param Array $propertiesArray
		 * @param Array $propertyValuesArray
		 * @param Array $mandatoriesArray
		 * @param Array $optionSetsArray
		 * @param String $displayName
		 * @return void
		 */
		public function setCachedEntityDefinition($entityLogicalName, SimpleXMLElement $entityData, Array $propertiesArray, Array $propertyValuesArray, Array $mandatoriesArray, Array $optionSetsArray, $displayName, $entitytypecode, $entityDisplayName, $entityDisplayCollectionName, $entityDescription, Array $manyToManyRelationships, Array $manyToOneRelationships, Array $oneToManyRelationships) {
			/* Store the details of the Entity Definition in the Cache */
			$this->cachedEntityDefintions[$entityLogicalName] = Array(
				/* $entityData->asXML(), */ $propertiesArray, $propertyValuesArray,
				$mandatoriesArray, $optionSetsArray, $displayName,
				$entitytypecode, $entityDisplayName, $entityDisplayCollectionName,
				$entityDescription, $manyToManyRelationships, $manyToOneRelationships, $oneToManyRelationships);

			/* Store entitiy definition in cache */
			$this->cacheClass->set("entities", serialize($this->cachedEntityDefintions), self::$cacheTime);
		}

		/**
		 * Get the Definition of an Entity from the Cache
		 * 
		 * @param String $entityLogicalName
		 * @param SimpleXMLElement $entityData
		 * @param Array $propertiesArray
		 * @param Array $propertyValuesArray
		 * @param Array $mandatoriesArray
		 * @param Array $optionSetsArray
		 * @param String $displayName
		 * @return boolean true if the Cache was retrieved
		 */
		public function getCachedEntityDefinition($entityLogicalName, &$entityData, Array &$propertiesArray, Array &$propertyValuesArray, Array &$mandatoriesArray, Array &$optionSetsArray, &$displayName, &$entitytypecode, &$entityDisplayName, &$entityDisplayCollectionName, &$entityDescription, Array &$manyToManyRelationships, Array &$manyToOneRelationships, Array &$oneToManyRelationships) {
			/* Check that this Entity Definition has been Cached */
			if ($this->isEntityDefinitionCached($entityLogicalName)) {
				/* Populate the containers and return true
				 * Note that we rely on PHP's "Copy on Write" functionality to prevent massive memory use:
				 * the only array that is ever updated inside an Entity is the propertyValues array (and the
				 * localProperties array) - the other data therefore becomes a single reference during
				 * execution.
				 */
				$propertiesArray = $this->cachedEntityDefintions[$entityLogicalName][0];
				$propertyValuesArray = $this->cachedEntityDefintions[$entityLogicalName][1];
				$mandatoriesArray = $this->cachedEntityDefintions[$entityLogicalName][2];
				$optionSetsArray = $this->cachedEntityDefintions[$entityLogicalName][3];
				$displayName = $this->cachedEntityDefintions[$entityLogicalName][4];
				$entitytypecode = $this->cachedEntityDefintions[$entityLogicalName][5];
				$entityDisplayName = $this->cachedEntityDefintions[$entityLogicalName][6];
				$entityDisplayCollectionName = $this->cachedEntityDefintions[$entityLogicalName][7];
				$entityDescription = $this->cachedEntityDefintions[$entityLogicalName][8];
				$manyToManyRelationships = $this->cachedEntityDefintions[$entityLogicalName][9];
				$manyToOneRelationships = $this->cachedEntityDefintions[$entityLogicalName][10];
				$oneToManyRelationships = $this->cachedEntityDefintions[$entityLogicalName][11];

				return true;
			} else {
				/* Not found - clear passed containers and return false */
				$entityData = NULL;
				$propertiesArray = NULL;
				$propertyValuesArray = NULL;
				$mandatoriesArray = NULL;
				$optionSetsArray = NULL;
				$displayName = NULL;
				$entitytypecode = NULL;
				$entityDisplayName = NULL;
				$entityDisplayCollectionName = NULL;
				$entityDescription = NULL;
				$manyToManyRelationships = NULL;
				$manyToOneRelationships = NULL;
				$oneToManyRelationships = NULL;
				return false;
			}
		}

		/**
		 * Get all the details of the Connector that would be needed to
		 * bypass the normal login process next time...
		 * Note that the Entity definition cache, the DOMs and the security 
		 * policies are excluded from the Cache.
		 * @return Array 
		 */
		private function getLoginCache() {
			return Array(
				$this->discoveryURI,
				$this->organizationUniqueName,
				$this->settings->organizationUrl,
				$this->security,
				NULL,
				$this->discoverySoapActions,
				$this->discoveryExecuteAction,
				NULL,
				NULL,
				$this->organizationSoapActions,
				$this->organizationCreateAction,
				$this->organizationDeleteAction,
				$this->organizationExecuteAction,
				$this->organizationRetrieveAction,
				$this->organizationRetrieveMultipleAction,
				$this->organizationUpdateAction,
				NULL,
				$this->organizationSecurityToken,
				Array(),
				self::$connectorTimeout,
				self::$maximumRecords,);
		}

		/**
		 * Restore the cached details
		 * @param Array $loginCache
		 * @return list Cached Login details
		 */
		private function loadLoginCache(Array $loginCache) {
			list(
					$this->discoveryURI,
					$this->organizationUniqueName,
					$this->organizationURI,
					$this->security,
					$this->discoveryDOM,
					$this->discoverySoapActions,
					$this->discoveryExecuteAction,
					$this->discoverySecurityPolicy,
					$this->organizationDOM,
					$this->organizationSoapActions,
					$this->organizationCreateAction,
					$this->organizationDeleteAction,
					$this->organizationExecuteAction,
					$this->organizationRetrieveAction,
					$this->organizationRetrieveMultipleAction,
					$this->organizationUpdateAction,
					$this->organizationSecurityPolicy,
					$this->organizationSecurityToken,
					/* $this->cachedEntityDefintions, */
					self::$connectorTimeout,
					self::$maximumRecords) = $loginCache;
		}

		/**
		 * Restore the cached Entity Definitions details
		 * 
		 * @return void
		 */
		private function loadEntityDefinitionCache() {
			/* Need to Define Clean cache mechanism */
			$entities = $this->cacheClass->get('entities');
			if ($entities != null) {
				$this->cachedEntityDefintions = unserialize($entities);
			}

			$entitesDefitions = $this->cacheClass->get('entities_definitions');
			if ($entitesDefitions) {
				$this->cachedEntitiesShortDefinitions = unserialize($entitesDefitions);
			}
		}

		/**
		 * Send a Retrieve request to the Dynamics CRM 2011 server and return the results as a structured Object
		 * This function is typically used just after creating something (where you get the ID back
		 * as the return value), as it is more efficient to use RetrieveMultiple to search directly if 
		 * you don't already have the ID.
		 *
		 * @param AlexaSDK_Entity $entity the Entity to retrieve - must have an ID specified
		 * @param array $fieldSet array listing all fields to be fetched, or null to get all fields
		 * @return AlexaSDK_Entity (subclass) a Strongly-Typed Entity containing all the data retrieved.
		 */
		public function retrieve(AlexaSDK_Entity $entity, $fieldSet = NULL) {
			/* Only allow "Retrieve" for an Entity with an ID */
			if ($entity->ID == self::EmptyGUID) {
				throw new Exception('Cannot Retrieve an Entity without an ID.');
				return FALSE;
			}
			/* Get the raw XML data */
			$rawSoapResponse = $this->retrieveRaw($entity, $fieldSet);
			/* Parse the raw XML data into an Object */
			$newEntity = self::parseRetrieveResponse($this, $entity->LogicalName, $rawSoapResponse);
			/* Return the structured object */
			return $newEntity;
		}

		/**
		 * Send a Create request to the Dynamics CRM server, and return the ID of the newly created Entity
		 * 
		 * @param AlexaSDK_Entity $entity the Entity to create
		 * @return mixed EntityId on success, FALSE on failure
		 */
		public function create(AlexaSDK_Entity &$entity) {
			/* Only allow "Create" for an Entity with no ID */
			if ($entity->ID != self::EmptyGUID) {
				throw new Exception('Cannot Create an Entity that already exists.');
				return FALSE;
			}
			/* Send the sequrity request and get a security token */
			$securityToken = $this->authentication->getOrganizationSecurityToken();
			/* Generate the XML for the Body of a Create request */
			$createNode = AlexaSDK_SoapRequestsGenerator::generateCreateRequest($entity);
			
			AlexaSDK_Logger::log(PHP_EOL . 'Create Request: ' . PHP_EOL . $createNode->C14N() . PHP_EOL );
			/* Turn this into a SOAP request, and send it */
			$createRequest = $this->generateSoapRequest($this->settings->organizationUrl, $this->soapActions->getSoapAction('organization', 'Create'), $securityToken, $createNode);
			
			$soapResponse = self::getSoapResponse($this->settings->organizationUrl, $createRequest);
			
			AlexaSDK_Logger::log(PHP_EOL . 'Create Response: ' . PHP_EOL . $soapResponse . PHP_EOL );

			/* Load the XML into a DOMDocument */
			$soapResponseDOM = new DOMDocument();
			$soapResponseDOM->loadXML($soapResponse);
			/* Find the CreateResponse */
			$createResponseNode = NULL;
			foreach ($soapResponseDOM->getElementsByTagName('CreateResponse') as $node) {
				$createResponseNode = $node;
				break;
			}
			unset($node);
			if ($createResponseNode == NULL) {
				throw new Exception('Could not find CreateResponse node in XML returned from Server');
				return FALSE;
			}
			/* Get the EntityID from the CreateResult tag */
			$entityID = $createResponseNode->getElementsByTagName('CreateResult')->item(0)->textContent;
			$entity->ID = $entityID;
			$entity->reset();
			
			return $entityID;
		}


		/**
		 * Send an Update request to the Dynamics CRM server, and return update response status
		 *
		 * @param AlexaSDK_Entity $entity the Entity to update
		 * @return string Formatted raw XML response of update request
		 */
		public function update(AlexaSDK_Entity &$entity) {
			/* Only allow "Update" for an Entity with an ID */
			if ($entity->ID == self::EmptyGUID) {
				throw new Exception('Cannot Update an Entity without an ID.');
				return FALSE;
			}
			/* Send the sequrity request and get a security token */
			$securityToken = $this->authentication->getOrganizationSecurityToken();
			/* Generate the XML for the Body of an Update request */
			$updateNode = AlexaSDK_SoapRequestsGenerator::generateUpdateRequest($entity);
			AlexaSDK_Logger::log(PHP_EOL . 'Update Request: ' . PHP_EOL . $updateNode->C14N() . PHP_EOL );
			/* Turn this into a SOAP request, and send it */
			$updateRequest = $this->generateSoapRequest($this->settings->organizationUrl, $this->soapActions->getSoapAction('organization', 'Update'), $securityToken, $updateNode);
			/* Get response */
			$soapResponse = self::getSoapResponse($this->settings->organizationUrl, $updateRequest);
			AlexaSDK_Logger::log(PHP_EOL . 'Update Response: ' . PHP_EOL . $soapResponse . PHP_EOL );
			/* Load the XML into a DOMDocument */
			$soapResponseDOM = new DOMDocument();
			$soapResponseDOM->loadXML($soapResponse);
			/* Find the UpdateResponse */
			$updateResponseNode = NULL;
			foreach ($soapResponseDOM->getElementsByTagName('UpdateResponse') as $node) {
				$updateResponseNode = $node;
				break;
			}
			unset($node);
			if ($updateResponseNode == NULL) {
				throw new Exception('Could not find UpdateResponse node in XML returned from Server');
				return FALSE;
			}
			/* Update occurred successfully */
			return $updateResponseNode->C14N();
		}

		/**
		 * Send a Delete request to the Dynamics CRM server, and return delete response status
		 *
		 * @param AlexaSDK_Entity $entity the Entity to delete
		 * @return boolean TRUE on successful delete, false on failure
		 */
		public function delete(AlexaSDK_Entity &$entity) {
			/* Only allow "Delete" for an Entity with an ID */
			if ($entity->ID == self::EmptyGUID) {
				throw new Exception('Cannot Delete an Entity without an ID.');
				return FALSE;
			}

			/* Send the sequrity request and get a security token */
			$securityToken = $this->authentication->getOrganizationSecurityToken();
			/* Generate the XML for the Body of a Delete request */
			$deleteNode = AlexaSDK_SoapRequestsGenerator::generateDeleteRequest($entity);

			AlexaSDK_Logger::log(PHP_EOL . 'Delete Request: ' . PHP_EOL . $deleteNode->C14N() . PHP_EOL );

			/* Turn this into a SOAP request, and send it */
			$deleteRequest = $this->generateSoapRequest($this->settings->organizationUrl, $this->soapActions->getSoapAction('organization', 'Delete'), $securityToken, $deleteNode);
			$soapResponse = self::getSoapResponse($this->settings->organizationUrl, $deleteRequest);

			AlexaSDK_Logger::log(PHP_EOL . 'Delete Response: ' . PHP_EOL . $soapResponse . PHP_EOL );

			/* Load the XML into a DOMDocument */
			$soapResponseDOM = new DOMDocument();
			$soapResponseDOM->loadXML($soapResponse);

			/* Find the DeleteResponse */
			$deleteResponseNode = NULL;
			foreach ($soapResponseDOM->getElementsByTagName('DeleteResponse') as $node) {
				$deleteResponseNode = $node;
				break;
			}
			unset($node);
			if ($deleteResponseNode == NULL) {
				throw new Exception('Could not find DeleteResponse node in XML returned from Server');
				return FALSE;
			}
			/* Delete occurred successfully */
			return TRUE;
		}
		
		public function request(){
			
		}
		

		/**
		 * ExecuteAction Request
		 * 
		 * @param string $requestName name of Action to request
		 * @param Array(optional)
		 * @return stdClass returns std class object of responsed data
		 */
		public function executeAction($requestName, $parameters = NULL, $requestType = NULL) {
			try{
				/* Send the sequrity request and get a security token */
				$securityToken = $this->authentication->getOrganizationSecurityToken();
				/* Generate the XML for the Body of a Execute Action request */
				$executeActionNode = AlexaSDK_SoapRequestsGenerator::generateExecuteActionRequest($requestName, $parameters, $requestType);

				AlexaSDK_Logger::log(PHP_EOL . 'ExecuteAction Request: ' . PHP_EOL . $executeActionNode->C14N() . PHP_EOL );
				/* Turn this into a SOAP request, and send it */
				$executeActionRequest = $this->generateSoapRequest($this->settings->organizationUrl, $this->soapActions->getSoapAction('organization', 'Execute'), $securityToken, $executeActionNode);

				$soapResponse = self::getSoapResponse($this->settings->organizationUrl, $executeActionRequest);

				AlexaSDK_Logger::log(PHP_EOL . 'ExecuteAction Response: ' . PHP_EOL . $soapResponse . PHP_EOL );

				/* Load the XML into a DOMDocument */
				$soapResponseDOM = new DOMDocument();
				$soapResponseDOM->loadXML($soapResponse);
				/* Find the UpdateResponse */
				$executeResultNode = NULL;
				foreach ($soapResponseDOM->getElementsByTagName('ExecuteResult') as $node) {
					$executeResultNode = $node;
					break;
				}
				unset($node);
				if ($executeResultNode == NULL) {
					throw new Exception('Could not find ExecuteResult node in XML returned from Server');
					return FALSE;
				}

				$keyValuesArray = Array();

				foreach ($executeResultNode->getElementsByTagName('KeyValuePairOfstringanyType') as $keyValueNode) {
					$keyValuesArray[$keyValueNode->getElementsByTagName('key')->item(0)->textContent] = $keyValueNode->getElementsByTagName('value')->item(0)->textContent;
				}
				/* Add the Entity to the KeyValues Array as a stdClass Object */
				$responseDataArray = (Object) $keyValuesArray;

				/* Return structured Key/Value object */
				return $responseDataArray;
			}catch(Exception $ex){
				AlexaSDK_Logger::log("Exception:", $ex);
				throw $ex;
			}
		}

		/**
		 * UNFINISHED METHOD
		 * Retrieves changes in metadata
		 * @todo 
		 */
		public function retrieveMetadataChanges() {
			/* Send the sequrity request and get a security token */
			$securityToken = $this->authentication->getOrganizationSecurityToken();
			/* Generate the XML for the Body of a Retrieve Metadata Changes request */
			$retrieveMetadataChangesNode = AlexaSDK_SoapRequestsGenerator::generateRetrieveMetadataChangesRequest();
			/* Turn this into a SOAP request, and send it */
			$retrieveMetadataChangesRequest = $this->generateSoapRequest($this->settings->organizationUrl, $this->soapActions->getSoapAction('organization', 'Execute'), $securityToken, $retrieveMetadataChangesNode);

			$soapResponse = self::getSoapResponse($this->settings->organizationUrl, $retrieveMetadataChangesRequest);
		}

		/*
		 *  METHODS TO REFACTOR 
		 */

		/**
		 * @ignore
		 * @deprecated Wil be changed soon
		 * NEED TO REFACTOR
		 * ADD cache definition to retrieved entities
		 */
		function retrieveAllEntities() {

			if (!empty($this->cachedEntitiesShortDefinitions)) {
				return $this->cachedEntitiesShortDefinitions;
			}

			$securityToken = $this->authentication->getOrganizationSecurityToken();

			$request = '<Execute xmlns="http://schemas.microsoft.com/xrm/2011/Contracts/Services">
							<request i:type="b:RetrieveAllEntitiesRequest" xmlns:b="http://schemas.microsoft.com/xrm/2011/Contracts" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">
								<b:Parameters xmlns:c="http://schemas.datacontract.org/2004/07/System.Collections.Generic">
									<b:KeyValuePairOfstringanyType>
										<c:key>EntityFilters</c:key>
										<c:value i:type="d:EntityFilters" xmlns:d="http://schemas.microsoft.com/xrm/2011/Metadata">Entity</c:value>
									</b:KeyValuePairOfstringanyType>
									<b:KeyValuePairOfstringanyType>
										<c:key>RetrieveAsIfPublished</c:key>
										<c:value i:type="d:boolean" xmlns:d="http://www.w3.org/2001/XMLSchema">false</c:value>
									</b:KeyValuePairOfstringanyType>
								</b:Parameters>
								<b:RequestId i:nil="true"/>
								<b:RequestName>RetrieveAllEntities</b:RequestName>
							</request>
						</Execute>';


			$doc = new DOMDocument();
			$doc->loadXML($request);
			$executeNode = $doc->getElementsByTagName('Execute')->item(0);


			$retrieveEntityRequest = $this->generateSoapRequest($this->settings->organizationUrl, $this->soapActions->getSoapAction('organization', 'Execute'), $securityToken, $executeNode);
			/* Determine the Action in the SOAP Response */
			$responseDOM = new DOMDocument();
			$responseDOM->loadXML($retrieveEntityRequest);

			$rawSoapResponse = $this->getSoapResponse($this->settings->organizationUrl, $retrieveEntityRequest);

			$entitiesDefinition = self::parseRetrieveAllEntitiesResponse($this, $rawSoapResponse, false);

			$this->setCachedEntityShortDefinition($entitiesDefinition);

			return $entitiesDefinition;
		}
}
