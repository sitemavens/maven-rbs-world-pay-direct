<?php

/*
  Plugin Name: Maven RBS World Pay Direct Gateway
  Plugin URI:
  Description:
  Author: Site Mavens
  Version: 0.1
  Author URI:
 */

namespace MavenRbsWordlPayDirectGateway;

// Exit if accessed directly 
if ( !defined( 'ABSPATH' ) )
	exit;


//If the validation was already loaded
if ( !class_exists( 'MavenValidation' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'maven-validation.php';
}

// Check if Maven is activate, if not, just return.
if ( \MavenValidation::isMavenMissing() )
	return;

use Maven\Settings\OptionType,
	Maven\Settings\Option;

class RbsWordlPayDirectGateway extends \Maven\Gateways\Gateway {

	public function __construct () {

		parent::__construct( "RBS World Pay" );

		$defaultOptions = array(
			new Option(
					"currencyCode", "Currency Code", 'GBP', '', OptionType::Input
			),
			new Option(
					"merchantID", "Merchant ID", '', '', OptionType::Input
			),
			new Option(
					"user", "User", '', '', OptionType::Input
			),
			new Option(
					"password", "Password", '', '', OptionType::Password
			)
		);

		$this->setParameterPrefix( "" );
		$this->setItemDelimiter( "" );

		$this->addSettings( $defaultOptions );
	}

	/**
	 *
	 * @return \XMLWriter
	 */
	private function getXmlWriter () {

		$xml = new \XMLWriter();
		$xml->openMemory();
		$xml->startDocument( '1.0', 'UTF-8' );
		$xml->writeDtd( 'paymentService', '-//WorldPay/DTD RBS WorldPay PaymentService v1//EN', 'http://dtd.wp3.rbsworldpay.com//paymentService_v1.dtd' );

		$xml->startElement( 'paymentService' );
		$xml->writeAttribute( 'version', '1.4' );
		$xml->writeAttribute( 'merchantCode', $this->getSetting( 'merchantID' ) );

		return $xml;
	}

	private function addAmountElement ( \XMLWriter $xml, $addCreditFlag = false ) {
		$xml->startElement( 'amount' );
		$xml->writeAttribute( 'value', $this->getFormatedAmount() );
		$xml->writeAttribute( 'currencyCode', $this->getSetting( 'currencyCode' ) );
		$xml->writeAttribute( 'exponent', '2' ); //$this->getParam('config_decimals'));

		if ( $addCreditFlag ) {
			$xml->writeAttribute( 'debitCreditIndicator', 'credit' );
		}
		$xml->endElement(); // amount
	}

	private function getOrderResponse ( $xml ) {
		$this->raw_response = $xml;
		$x = @simplexml_load_string( $xml );

		$node = $x->reply;
		if ( $node ) {
			if ( $node->orderStatus ) {
				$node = $node->orderStatus;
				if ( $node ) {
					if ( $node->error ) {
						$this->error = true;
						$error_desc = ( string ) $node->error;
						$error_raw = $error_desc;

						if ( strpos( $error_desc, "The Payment Method is not available" ) > 0 )
							$error_desc = "We are sorry but this transaction has been denied. There are a variety of possible reasons for this, which we can help you to resolve. Please double check your information or reach out to us for assistance via email at ";

						$this->setErrorDescription( $error_desc );
					} else if ( $node->payment ) {

						if ( $node->payment->lastEvent && $node->payment->lastEvent == 'REFUSED' ) {
							$this->setApproved( false );
							$this->setDeclined( true );
							$this->setError( true );
							$this->setErrorDescription( "Credit card declined" );
							$this->setTransactionId( -1 );
						} else {
							$this->setApproved( true );
							$this->setTransactionId( ( string ) $node->attributes()->orderCode );
						}
					}
				}
			} else {
				if ( $node->error ) {

					$this->setError( true );
					$this->setErrorDescription( ( string ) $node->error );
				}
			}
		} else {
			$this->setError( true );
			//$this->setErrorDescription( "Error 201- We are sorry but this transaction has been denied. There are a variety of possible reasons for this, which we can help you to resolve. Please double check your information or reach out to us for assistance via email at <a href='mailto:pablo@peterharrington.co.uk'>pablo@peterharrington.co.uk</a>.";
		}
	}

	private function addSessionElement ( \XMLWriter $xml ) {

		$request = \Maven\Core\Request::current();

		$ipAddy = $request->getIp();

		$xml->startElement( 'session' );
		$xml->writeAttribute( 'shopperIPAddress', $ipAddy );
		$xml->writeAttribute( 'id', session_id() );
		$xml->endElement(); //session
	}

	private function getFormatedAmount () {

		$amount = $this->getAmount();
		$amount = number_format( $amount, 2 );

		// WorldPay wants an amount without any decimals or commas
		$n = preg_replace( '/[^0-9]/', '', $amount );
		$amount = ( $amount < 0) ? ('-' . $n) : $n;

		return $amount;
	}

//	"invoice_num"
//		"description"
//		"amount"
	private function getOrderXML ( $isFollowUpRequest ) {
		$xml = $this->getXmlWriter();
		$xml->startElement( 'submit' );
		$xml->startElement( 'order' );
		$xml->writeAttribute( 'orderCode', $this->getInvoiceNumber() );

		$xml->writeElement( 'description', $this->getDescription() );

		$this->addAmountElement( $xml );

		$xml->startElement( 'orderContent' );
		$xml->writeCdata( '<center></center>' );
		$xml->endElement();

		$xml->startElement( 'paymentDetails' );
		$this->addCreditCardElement( $xml );
		$this->addSessionElement( $xml );

		$xml->endElement(); // paymentDetails

		$xml->startElement( 'shopper' );
		$xml->writeElement( 'shopperEmailAddress', $this->getEmail() );
		$this->addBrowserDetails( $xml );
		$xml->endElement(); //shopper

		$xml->startElement( 'shippingAddress' );
		$this->addAddressNode( $xml, 'ship' );
		$xml->endElement(); // shippingAddress

		$xml->endElement(); // order
		$xml->endElement(); // submit
		$xml->endElement(); // paymentService

		return $xml->outputMemory( true );
	}

	private function addBrowserDetails ( \XMLWriter $xml ) {
		$xml->startElement( 'browser' );
		$xml->writeElement( 'acceptHeader', $_SERVER['HTTP_ACCEPT'] );
		$xml->writeElement( 'userAgentHeader', $_SERVER['HTTP_USER_AGENT'] );
		$xml->endElement(); // browser
	}

	private function getCreditCardMethodCode () {

		//return 'VISA-SSL';

		$ccType = $this->getParameter( 'card_type' );

		$methodCode = "VISA-SSL";
		switch ( $ccType ) {
			case "visa":
				$methodCode = 'VISA-SSL';
				break;
			case "mc":
				$methodCode = 'ECMC-SSL';
				break;
			case "amex":
				$methodCode = 'AMEX-SSL';
				break;
		}

		return $methodCode;
	}

	private function addCreditCardElement ( \XMLWriter $xml ) {

		$xml->startElement( $this->getCreditCardMethodCode() );
		$xml->writeElement( 'cardNumber', $this->getCCNumber() );
		$xml->startElement( 'expiryDate' );
		$xml->startElement( 'date' );
		$xml->writeAttribute( 'month', $this->getCCMonth() );
		$xml->writeAttribute( 'year', $this->getCCYear() );
		$xml->endElement(); // date
		$xml->endElement(); // expiryDate

		$xml->writeElement( 'cardHolderName', $this->getCCHolderName() );

		$cvv2 = $this->getCCVerificationCode();

//		if ( !$cvv2 ) {
//			$cvv2 = $this->getParameter( 'card_code' );
//		}

		if ( $cvv2 ) {
			$xml->writeElement( 'cvc', $cvv2 );
		}

		$xml->startElement( 'cardAddress' );
		$this->addAddressNode( $xml, 'bill' );
		$xml->endElement(); // cardAddress
		$xml->endElement(); // cardType
	}

	private function getExpirationMonth () {
		return str_pad( $this->getParameter( 'exp_month' ), 2, '0', STR_PAD_LEFT );
	}

	private function addAddressNode ( \XMLWriter $xml, $paramType ) {
		$xml->startElement( 'address' );
		$xml->writeElement( 'firstName', $this->getFirstName() );
		$xml->writeElement( 'lastName', $this->getLastName() );
		//$xml->writeElement('street',          trim($this->get_parameter('address')));

		$xml->startElement( 'street' );
		$xml->writeCdata( trim( $this->getAddress() ) );
		$xml->endElement();


		$xml->startElement( 'postalCode' );
		$xml->writeCdata( trim( $this->getZip() ) );
		$xml->endElement();

		//$xml->writeElement('postalCode',      $this->get_parameter('zip'));
		$xml->writeElement( 'city', $this->getCity() );
		$xml->writeElement( 'countryCode', $this->getCountry() );
		//$xml->writeElement('telephoneNumber', $this->getParam('bill_phone')); // we don't have a ship_phone, so always use bill_phone
		$xml->endElement(); // address
	}

	private function postXML ( $xml, $cookies = false ) {

		$ch = curl_init();
		//var_dump($this->get_url());
		curl_setopt( $ch, CURLOPT_URL, $this->getUrl() );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 900 );
		curl_setopt( $ch, CURLOPT_FAILONERROR, false );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		//echo ($xml );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-type: text/xml' ) );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $xml );
		//curl_setopt( $ch, CURLINFO_HEADER_OUT, true );


		if ( $cookies !== false && is_numeric( $cookies ) ) {
			if ( $cookies == self::SET_COOKIE ) {
				$cookieFile = $_SESSION['cookieFile'];

				//CURLOPT_COOKIEJAR is used when cURL is reading cookie data from disk. 
				curl_setopt( $ch, CURLOPT_COOKIEJAR, $cookieFile );
			} else if ( $cookies == self::READ_COOKIE ) {

				$cookieFile = $_SESSION['cookieFile'];

				//CURLOPT_COOKIEFILE is used when cURL is writing the cookie data to disk.  
				curl_setopt( $ch, CURLOPT_COOKIEFILE, $cookieFile );
			}
		}

		$info = "";
		$response = curl_exec( $ch );
        do_action('maven-gateway-debug', $this->getInvoiceNumber(), $response, $xml);
		if ( $ch ) {
			//$info = curl_getinfo($ch);
			curl_close( $ch );
		}


		return $response;
	}

	private $order;

	/**
	 * 
	 * @param array $args 
	 * 
	 */
	public function execute () {
		$user = $this->getSetting( 'user' );
		$password = $this->getSetting( 'password' );

		$this->setLiveUrl( "https://{$user}:{$password}@secure.worldpay.com/jsp/merchant/xml/paymentService.jsp" );
		$this->setTestUrl( "https://{$user}:{$password}@secure-test.worldpay.com/jsp/merchant/xml/paymentService.jsp" );

		$order = $this->getOrderXML( true );
		$this->order = $order;

		$response = $this->getOrderResponse( $this->postXML( $order ) );

		return $response;
	}

	public function getAvsCode () {
		if ( $this->response_fields && isset( $this->response_fields[5] ) )
			return $this->response_fields[5];

		return false;
	}

	public function register ( $gateways ) {

		$gateways[$this->getKey()] = $this;

		return $gateways;
	}

}

$rbsWordlPayDirectGateway = new RbsWordlPayDirectGateway();
\Maven\Core\HookManager::instance()->addFilter( 'maven/gateways/register', array( $rbsWordlPayDirectGateway, 'register' ) );


