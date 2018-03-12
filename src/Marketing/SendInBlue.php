<?php
namespace Svbk\WP\Email\Marketing;

use Svbk\WP\Email\Contact;
use Exception;
use SendinBlue\Client as SendInBlue_Client;

class SendInBlue extends ServiceInterface {

	public $id = 'sendinblue';

	public $config;
	public $client;
	
	public $client_contacts;
	
	public $name_attribute = 'NOME';

	public function __construct( $api_key = null ) {
		
		if( $api_key ) {
			$this->config = new SendInBlue_Client\Client\Configuration;
			$this->config->setApiKey( 'api-key', $api_key );
		} else {
			$this->config = SendInBlue_Client\Configuration::getDefaultConfiguration();
		}
		
		if( ! $this->config->getApiKey('api-key') ) {
			throw new Exceptions\ApiKeyInvalid();
		}
		
		$this->client = new SendInBlue_Client\ApiClient( $this->config );
		$this->client_contacts = new SendInBlue_Client\Api\ContactsApi( $this->client );
	}

	public function createContact( Contact $contact, $update = true ) {

		$data = $this->parseContact( $contact );

		if( $contact->email ) {
			$data['email']= $contact->email;
		}

		$data['updateEnabled'] = $update;
		
		$createContact = new SendInBlue_Client\Model\CreateContact( $data );

		if ( ! empty( $contact->lists ) ) {
			$createContact->setListIds( $contact->lists );
		}

		try {
			$existing_contact = $this->getContact( $contact );
		} catch ( Exceptions\ContactNotExists $e ) {	
			$existing_contact =  false;
		}
		
		try {
			if( $update && ( false !== $existing_contact ) ) {
				$raw_result = $this->saveContact( $contact );
			} else {
				$raw_result = $this->client_contacts->createContact( $createContact );
			}
		} catch ( SendInBlue_Client\ApiException $e ) {
			throw new Exceptions\ServiceError( $e->getResponseBody()->message );
		} catch ( Exception $e ) {
			throw new Exceptions\ServiceError( $e->getMessage() );
		}
		
		if( empty( $raw_result->id ) ) {
			$existing_contact = $this->getContact( $contact );
			$user_id = $existing_contact->id;
		} else {
			$user_id = $raw_result->id;	
		}
		
		do_action('svbk_email_contact_created', $user_id, $raw_result, $data, $this );
		do_action('svbk_email_contact_created_sendinblue', $user_id, $raw_result, $data, $this );		

		return $user_id;
	}


	public function getContact( $search_contact ){
		
		try {
			$raw_result = $this->client_contacts->getContactInfo( $search_contact->email );
		} catch ( SendInBlue_Client\ApiException $e ) {
			
			$error = $e->getResponseBody();
			
			if( 'document_not_found' === $error->code ) {
				throw new Exceptions\ContactNotExists();
			}
			
			throw new Exceptions\ServiceError( $e->getResponseBody()->message );
			
		} catch ( Exception $e ) {
			throw new Exceptions\ServiceError( $e->getMessage() );
		}
	
		return $this->castContact( $raw_result );
	}

	public function listSubscribe( Contact $contact, $lists = array() ) {
		
		foreach( $lists as $list_id ) {
			$contact->listSubscribe( $list_id );
		}		
		
		$this->saveContact( $contact );
		
		return $result;
	}

	public function listUnsubscribe( Contact $contact, $lists = array() ) {
		
		foreach( $lists as $list_id ) {
			$contact->listUnsubscribe( $list_id );
		}		
		
		$result = $this->saveContact(
			$contact, [
				'unlinkListIds' => $lists,
			]
		);
		
		return $result;
	}

	public function saveContact( Contact $contact, $custom_attributes = array() ) {

		$updateContact = new SendInBlue_Client\Model\UpdateContact( $custom_attributes );

		$data = $this->parseContact( $contact );
		
		if( !empty( $user_attributes ) ) {
			$updateContact->setAttributes( $user_attributes );
		}
		
		if ( ! empty( $contact->lists ) ) {
			$updateContact->setListIds( $contact->lists );
		}
		
		try {
			$result = $this->client_contacts->updateContact( $contact->email, $updateContact );
		} catch ( SendInBlue_Client\ApiException $e ) {
			
			$error = $e->getResponseBody();
			
			if( 'document_not_found' === $error->code ) {
				throw new Exceptions\ContactNotExists();
			}			
			
			throw new Exceptions\ServiceError( $e->getResponseBody()->message );
		} catch ( Exception $e ) {
			throw new Exceptions\ServiceError( $e->getMessage() );
		}

	}
	
	protected function parseContact( Contact $contact ){
		
		$data =  array();

		if( ! empty( $contact->attributes ) ) {
			$data['attributes'] = $contact->attributes;
		}

		if( $contact->first_name ) {
			$data['attributes'][$this->name_attribute] = $contact->first_name;
		}
		
		if( $contact->last_name ){
			$data['attributes']['SURNAME'] = $contact->last_name;
		}

		if( $contact->phone ) {
			$data['attributes']['sms'] = $contact->phone;
			$data['attributes']['sms'] = $contact->phone;
		}		
		
		return $data;
	}
	
	public function castContact( $raw_result ){
		
		$contact = new Contact();
		
		if ( $raw_result->getId() ) {
			$contact->id = $raw_result->getId();
		}
		
		if ( $raw_result->getEmail() ) {
			$contact->email = $raw_result->getEmail();
		}
		
		if ( $raw_result->getListIds() ) {
			$contact->lists = $raw_result->getListIds();
		}		
		
		$attributes = $raw_result->getAttributes();
		
		if( !empty( $attributes[$this->name_attribute] )  ) {
			$contact->first_name = $attributes[$this->name_attribute];
		}
		
		if( !empty( $attributes['SURNAME'] ) ) {
			$contact->last_name = $attributes['SURNAME'];
		}	
		
		if( !empty( $attributes['sms'] ) ) {
			$contact->phone = $attributes['sms'];
		}			
		
		return $contact;
		
	}
	
}
