<?php namespace Oca\X2RestClient;

use Exception; // from laravel
use GuzzleHttp\Client as GuzzleClient;

class Client
{
    private $guzzle;

    public function __construct($base_url, $apiUser, $apiKey)
    {
        // just a guzzle config
        $config = array(
            'base_url' => $base_url,
            'defaults' => [
                'headers'  => ['Content-Type' => 'application/json'],
                'auth' =>  [ $apiUser, $apiKey ],
            ],
        );
        $this->guzzle = new GuzzleClient($config);
    }

    public function createContact( $attributes, $mapper = null ){
        //@todo may need to do htmlpurifier since x2engine does this on webform submits https://laracasts.com/discuss/channels/tips/htmlpurifier-in-laravel-5 (1st comment)
        //   https://github.com/ezyang/htmlpurifier
        //@todo update contact if duplicate found
        $contactInfo = array();
        // get fieldnames to verify data
        $fieldNames = $this->getFieldNames('Contacts');
        if($mapper){
            foreach($attributes as $key=>$value){
                if(isset($mapper[$key])){
                    // check if the mapping was correct.
                    if(isset($fieldNames[$mapper[$key]])){
                        $contactInfo{$mapper[$key]}=$value; // Found in field map, used mapped attribute
                    } else {
                        throw new Exception($mapper[$key] . ' field mapped in with an invalid fieldName.'); //@todo, we should probably just log the error... not throw an exception
                    }
                }else{
                    $contactInfo[$key]=$value; // No match anywhere, assume it's a Contact attribute @todo should we do this???
                }
            }
        // we are going to assume the field names are the same as in x2 so no mapping is needed... but we'll check below.
        } else {
            foreach($attributes as $key=>$value){
                // check if the mapping was correct.
                if(isset($fieldNames[$key])){
                    $contactInfo[$key]=$value; // Found in field map, used mapped attribute
                } else {
                    throw new Exception($key . ' field mapped in with an invalid fieldName.'); //@todo, we should probably just log the error and ignore... not throw an exception
                }
            }
        }

        $res = $this->guzzle->post( 'Contacts', ['body' => json_encode($contactInfo)] );
        $contact = $res->json();
        if ( isset($contact['id']) ){
            return $contact;
        }
        //throw new Exception($key . ' field mapped in with an invalid fieldName.'); @todo need some kind of error if ID does not exist.

        return '$res'; //@todo create verification method, return res->json().

        /**
         * @todo tracking key handling
         * @todo fingerprint handling
         */
    }

    /**
     * Returns an array of contact's attributes given an email
     *
     * @param array $emails
     * @param bool $flatten, false if you want the returned array to include search field names as keys
     * @return array|null
     * @throws Exception
     */
    public function getContactsByEmails($emails, $flatten = true, $dedup = true){
        $emailFields = $this->getEmailFields('Contacts');
        if(is_array($emails)){
            $contacts = array();
            foreach ($emailFields as $field) {
                $contactList = $this->getEntityByEmailField('Contacts', $emails, $field['fieldName']);
                if($contactList){ // if null... do nothing
                    $contacts[$field['fieldName']] = $contactList;
                }
            }

            if($dedup){
                $dedup = array();
                foreach($contacts as $fname => $clists){
                    foreach($clists as $key => $contact){
                        if( isset($dedup[$contact['id']]) ){
                           unset($contacts[$fname][$key]);
                        }
                        $dedup[$contact['id']] = 1;
                    }
                }
            }

            // loop through all emails and email fields
            /*
             * below is NOT needed b/c submitting an array of emails acts like an "or"
            foreach($emails as $email){
                // lets try an "_or" search by all the different email fields
                foreach ($emailFields as $field) {
                    $check = $this->getContactsByEmail($email, $field);
                    if($check){
                        $contactslist[] = $check;
                    }
                }
            }
            */
            if($flatten){
                $contacts = $this->flattenEntityList($contacts);
            }
            return $contacts;
        } else {
            throw new Exception('$emails should be an array');
        }
    }

    /**
     * @param string $entity string
     * @param array|string $email
     * @param string $field
     * @return mixed|null
     */
    public function getEntityByEmailField($entity, $email, $fieldName = 'email'){
        // limit to 500, probably never have 500 contacts when checking for duplicates... so if we receive 500, we know something is wrong
        $query = array('_limit' => 500, $fieldName => $email);
        $query = http_build_query($query);
        $res = $this->guzzle->get("$entity?$query");
        $contacts = $res->json();
        if (count($contacts) == 500 ) {
            return null; // something must have gone wrong.
        }
        return $contacts;
    }

    /**
     *
     * This is used if you make multiple queries to x2.  Each query can give you 1 or more entities.  This will flatten those list of queries.
     * See getContactsByEmails()
     *
     * @param $list, a list of lists.  something like [0=>[0=>[firstname, lastname,etc],1=>[firstname,lastname,etc]],1=>....]
     * @return array|null, returns a
     */
    public function flattenEntityList($list, $idkeys = false){
        if(!is_array($list)){
            return null;
        }

        $flat = array();
        foreach($list as $items) {
            foreach($items as $item){
                if($idkeys){
                    $flat[$item['id']] = $item;
                } else {
                    $flat[] = $item;
                }
            }
        }

        return $flat;
    }

    public function getDropdown($fieldIdentifier){
        if(is_integer($fieldIdentifier)){

        }
    }

    public function getEmailFields($entity){
        $fields = $this->getFields($entity);
        $emailFields = array();
        foreach($fields as $field){
            // check by name name
            if (strpos($field['fieldName'],'email') !== false) {
                $emailFields[$field['fieldName']] = $field;
                continue;
            }

            // check by type
            if ( $field['type'] == 'email' ){
                $emailFields[$field['fieldName']] = $field;
                continue;
            }
        }

        return $emailFields;
    }

    public function getRequiredFields($entity){
        $fields = $this->getFields($entity);
        $emailFields = array();
        foreach($fields as $field){
            // check by name name
            if ($field['required']) {
                $emailFields[$field['fieldName']] = $field;
            }
        }

        return $emailFields;
    }
    /**
     * @param $entity, type of entity (Contacts, Accounts, etc...)
     * @param $name, name of the field
     * @param string $nameType, could also be attributeLabel
     * @return null
     */
    public function getFieldByName($entity, $name, $nameType = 'fieldName'){
        $fields = $this->getFields($entity);
        foreach($fields as $field){
            if($field[$nameType] == $name){
                return $field;
            }
        }
        return null;
    }

    public function getFields($entity){
        $res = $this->guzzle->get("$entity/fields");
        return $res->json();
    }

    public function getFieldNames($entity)
    {
        $fields = $this->getFields($entity);
        $data = array();
        foreach ($fields as $field) {
            $data[$field['fieldName']] = array('fieldName' => $field['fieldName'], 'attributeLabel' => $field['attributeLabel']);
        }
        return $data;
    }
}
