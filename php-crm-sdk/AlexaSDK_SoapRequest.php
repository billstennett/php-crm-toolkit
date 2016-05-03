<?php

class AlexaSDK_SoapRequest{
	
		protected $authnetication;
	
	
		public function __construct() {
			;
		}
		
		
		public function create(AlexaSDK_Entity &$entity){
			
				/* Send the sequrity request and get a security token */
				$securityToken = $this->authentication->getOrganizationSecurityToken();
				/* Generate the XML for the Body of a Create request */
				$createNode = AlexaSDK_SoapRequestsGenerator::generateCreateRequest($entity);
			
				AlexaSDK_SoapRequestsGenerator::generateCreateRequest($entity);
				
				
				
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
		
		
		
		public function request($requestType ){
			
			
			
				switch(strtolower($requestType)){
					case "create":
						
						AlexaSDK_SoapRequestsGenerator::generateCreateRequest($entity);
						
						break;
					case "update":
						break;
					case "delete":
						break;
					case "retrievemetadatachanges":
						break;
					case "retrieve":
						break;
					case "retrieveorganization":
						break;
					case "retrievemultiple":
						break;
					case "retrieveentity":
						break;
					case "executeaction":
						break;
				}
		}
}

